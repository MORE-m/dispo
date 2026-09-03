<?php

namespace Tests\Feature\DispoOrder;

use App\Enums\Role;
use App\Models\DispoOrder;
use App\Models\DispoOrderNumberSequence;
use App\Models\DispoOrderPosition;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Concerns\CreatesSavedCalculation;
use Tests\Concerns\CreatesSpotClassicCatalog;
use Tests\TestCase;

class DispoOrderNumberConcurrencyTest extends TestCase
{
    use CreatesSavedCalculation;
    use CreatesSpotClassicCatalog;
    use DatabaseMigrations;

    public function test_gen_001_mysql_parallel_workers_create_dispo_orders_with_unique_numbers(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Paralleler Dispo-Writer-Test erfordert MySQL (GitHub-Job mysql).');
        }

        $year = (int) now('Europe/Berlin')->format('Y');
        DispoOrderNumberSequence::query()->where('year', $year)->delete();

        $catalog = $this->createSpotClassicCatalog();
        User::factory()->role(Role::Sales)->create();
        $calculation = $this->createSavedCalculation($catalog, [
            ['inventory_id' => $catalog['hamburg']->id],
            ['inventory_id' => $catalog['rock']->id, 'total_spot_count' => 3, 'hour' => 10],
        ]);
        $calculation->load('positions');
        $positions = $calculation->positions->values();

        $this->runParallelWorkers(
            (string) $calculation->id,
            (string) $positions[0]->id,
            (string) $calculation->id,
            (string) $positions[1]->id,
        );

        $orders = DispoOrder::query()->where('calculation_id', $calculation->id)->orderBy('number')->get();
        $this->assertCount(2, $orders);
        $this->assertSame(2, $orders->pluck('number')->unique()->count());
        $this->assertSame([1, 2], $orders->pluck('number_calc_seq')->sort()->values()->all());

        foreach ($orders as $order) {
            $this->assertMatchesRegularExpression('/^DA-\d{4}-\d{6}-\d{2}$/', $order->number);
            $this->assertSame(1, $order->positions()->count());
            $this->assertSame(DispoOrderPosition::class, $order->positions()->first()::class);
        }

        $this->assertSame(2, DispoOrderNumberSequence::query()->where('year', $year)->value('last_seq'));
    }

    public function test_parallel_workers_from_different_calculations_receive_unique_org_sequences(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Paralleler Dispo-Writer-Test erfordert MySQL (GitHub-Job mysql).');
        }

        $year = (int) now('Europe/Berlin')->format('Y');
        DispoOrderNumberSequence::query()->where('year', $year)->delete();

        $catalog = $this->createSpotClassicCatalog();
        User::factory()->role(Role::Sales)->create();

        $firstCalculation = $this->createSavedCalculation($catalog, [
            ['inventory_id' => $catalog['hamburg']->id],
        ]);
        $secondCalculation = $this->createSavedCalculation($catalog, [
            ['inventory_id' => $catalog['rock']->id, 'total_spot_count' => 2, 'hour' => 9],
        ]);

        $firstCalculation->load('positions');
        $secondCalculation->load('positions');

        $this->runParallelWorkers(
            (string) $firstCalculation->id,
            (string) $firstCalculation->positions->first()->id,
            (string) $secondCalculation->id,
            (string) $secondCalculation->positions->first()->id,
        );

        $numbers = DispoOrder::query()->orderBy('number_org_seq')->pluck('number')->all();
        $this->assertCount(2, $numbers);
        $this->assertSame(2, count(array_unique($numbers)));

        preg_match('/^DA-(\d{4})-(\d{6})-01$/', $numbers[0], $firstParts);
        preg_match('/^DA-(\d{4})-(\d{6})-01$/', $numbers[1], $secondParts);
        $this->assertSame($firstParts[1], $secondParts[1]);
        $this->assertSame(1, abs((int) $firstParts[2] - (int) $secondParts[2]));
    }

    /**
     * @param  non-empty-string  $calculationIdA
     * @param  non-empty-string  $positionIdA
     */
    private function runParallelWorkers(
        string $calculationIdA,
        string $positionIdA,
        string $calculationIdB,
        string $positionIdB,
    ): void {
        $runDir = sys_get_temp_dir().'/dispo-order-concurrency-'.Str::uuid();
        if (! mkdir($runDir, 0700, true) && ! is_dir($runDir)) {
            $this->fail('Temporäres Barrier-Verzeichnis konnte nicht erstellt werden.');
        }

        try {
            $script = base_path('tests/concurrency/dispo_order_create_worker.php');
            $env = $this->workerEnvironment();

            $worker0 = new Process([PHP_BINARY, $script, $runDir, '0', $calculationIdA, $positionIdA], null, $env);
            $worker1 = new Process([PHP_BINARY, $script, $runDir, '1', $calculationIdB, $positionIdB], null, $env);

            $worker0->start();
            $worker1->start();

            $this->assertSame(0, $worker0->wait(), $worker0->getErrorOutput() ?: $worker0->getOutput());
            $this->assertSame(0, $worker1->wait(), $worker1->getErrorOutput() ?: $worker1->getOutput());

            $results = [];
            foreach (glob($runDir.'/worker-*.result') ?: [] as $resultFile) {
                $results[] = trim((string) file_get_contents($resultFile));
            }

            $this->assertCount(2, $results);
            foreach ($results as $result) {
                $this->assertDoesNotMatchRegularExpression('/^ERROR:/', $result);
            }
        } finally {
            foreach (glob($runDir.'/*') ?: [] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            if (is_dir($runDir)) {
                rmdir($runDir);
            }
        }
    }

    /**
     * @return array<string, string>
     */
    private function workerEnvironment(): array
    {
        $vars = [
            'APP_KEY', 'APP_ENV', 'DB_CONNECTION', 'DB_HOST', 'DB_PORT',
            'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD', 'DB_URL',
        ];

        $env = [];
        foreach ($vars as $var) {
            $value = getenv($var);
            if ($value !== false) {
                $env[$var] = (string) $value;
            }
        }

        return $env;
    }
}
