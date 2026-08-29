<?php

namespace Tests\Feature\Calculation;

use App\Enums\Role;
use App\Models\Calculation;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Concerns\CreatesSpotClassicCatalog;
use Tests\TestCase;

class CalculationNumberConcurrencyTest extends TestCase
{
    use CreatesSpotClassicCatalog;
    use DatabaseMigrations;

    public function test_gen_001_mysql_parallel_workers_create_calculations_with_unique_numbers(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Paralleler Writer-Test erfordert MySQL (GitHub-Job mysql).');
        }

        $catalog = $this->createSpotClassicCatalog();
        User::factory()->role(Role::Sales)->create();

        $runDir = sys_get_temp_dir().'/dispo-concurrency-'.Str::uuid();
        if (! mkdir($runDir, 0700, true) && ! is_dir($runDir)) {
            $this->fail('Temporäres Barrier-Verzeichnis konnte nicht erstellt werden.');
        }

        try {
            $script = base_path('tests/concurrency/calculation_create_worker.php');
            $env = $this->workerEnvironment();

            $worker0 = new Process([
                PHP_BINARY,
                $script,
                $runDir,
                '0',
                (string) $catalog['hamburg']->id,
                (string) $catalog['medium']->id,
            ], null, $env);
            $worker1 = new Process([
                PHP_BINARY,
                $script,
                $runDir,
                '1',
                (string) $catalog['hamburg']->id,
                (string) $catalog['medium']->id,
            ], null, $env);

            $worker0->start();
            $worker1->start();

            $worker0ExitCode = $worker0->wait();
            $worker1ExitCode = $worker1->wait();

            $this->assertSame(
                0,
                $worker0ExitCode,
                $worker0->getErrorOutput() ?: $worker0->getOutput(),
            );
            $this->assertSame(
                0,
                $worker1ExitCode,
                $worker1->getErrorOutput() ?: $worker1->getOutput(),
            );

            $numbers = [];
            foreach (glob($runDir.'/worker-*.result') ?: [] as $resultFile) {
                $numbers[] = trim((string) file_get_contents($resultFile));
            }

            usort($numbers);

            $this->assertCount(2, $numbers);
            $this->assertSame(2, count(array_unique($numbers)));
            foreach ($numbers as $number) {
                $this->assertDoesNotMatchRegularExpression('/^ERROR:/', $number);
                $this->assertMatchesRegularExpression('/^K-\d{4}-\d{5}$/', $number);
            }

            preg_match('/^K-(\d{4})-(\d{5})$/', $numbers[0], $first);
            preg_match('/^K-(\d{4})-(\d{5})$/', $numbers[1], $second);
            $this->assertSame($first[1], $second[1], 'Beide Nummern müssen im selben Jahr liegen.');
            $this->assertSame(1, abs((int) $first[2] - (int) $second[2]), 'Sequenz muss fortlaufend ohne Doppelvergabe sein.');

            $persisted = Calculation::query()->whereIn('number', $numbers)->orderBy('number')->get();
            $this->assertCount(2, $persisted);
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
