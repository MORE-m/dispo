<?php

namespace Tests\Feature\Calculation;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Concerns\CreatesSavedCalculation;
use Tests\Concerns\CreatesSpotClassicCatalog;
use Tests\TestCase;

/**
 * ADV-001c4a: parallele Positions-Updates – Lock verhindert Lost Updates / gemischte Freeze-Werte.
 */
class Adv001c4aCalculationPositionUpdateConcurrencyTest extends TestCase
{
    use CreatesSavedCalculation;
    use CreatesSpotClassicCatalog;
    use DatabaseMigrations;

    public function test_parallel_position_updates_preserve_freeze_and_use_lock_version(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Paralleler Update-Test erfordert MySQL (GitHub-Job mysql / dispo_test).');
        }

        $catalog = $this->createSpotClassicCatalog();
        User::factory()->role(Role::Sales)->create();
        $calculation = $this->createSavedCalculation($catalog, [
            ['inventory_id' => $catalog['hamburg']->id],
        ]);
        $lockVersion = (int) $calculation->lock_version;
        $position = $calculation->positions()->firstOrFail();
        $freezeBefore = [
            $position->engine_profile_key,
            $position->calculation_method_key,
            $position->calculation_method_name,
            $position->algorithm_version,
            $position->spot_method->value,
            $position->kind->value,
        ];

        $runDir = sys_get_temp_dir().'/dispo-c4a-concurrency-'.Str::uuid();
        if (! mkdir($runDir, 0700, true) && ! is_dir($runDir)) {
            $this->fail('Temporäres Barrier-Verzeichnis konnte nicht erstellt werden.');
        }

        try {
            $script = base_path('tests/concurrency/calculation_position_update_worker.php');
            $env = $this->workerEnvironment();

            $worker0 = new Process([
                PHP_BINARY,
                $script,
                $runDir,
                '0',
                (string) $calculation->id,
                (string) $lockVersion,
                'Worker-A',
            ], null, $env);
            $worker1 = new Process([
                PHP_BINARY,
                $script,
                $runDir,
                '1',
                (string) $calculation->id,
                (string) $lockVersion,
                'Worker-B',
            ], null, $env);

            $worker0->start();
            $worker1->start();
            $worker0Exit = $worker0->wait();
            $worker1Exit = $worker1->wait();

            $this->assertSame(0, $worker0Exit, $worker0->getErrorOutput() ?: $worker0->getOutput());
            $this->assertSame(0, $worker1Exit, $worker1->getErrorOutput() ?: $worker1->getOutput());

            $results = [];
            foreach (glob($runDir.'/worker-*.result') ?: [] as $resultFile) {
                $results[] = trim((string) file_get_contents($resultFile));
            }

            sort($results);
            $this->assertCount(2, $results);
            $oks = array_values(array_filter($results, fn (string $row): bool => str_starts_with($row, 'ok:')));
            $conflicts = array_values(array_filter($results, fn (string $row): bool => str_starts_with($row, 'conflict:')));
            $this->assertCount(1, $oks);
            $this->assertCount(1, $conflicts);
            $this->assertStringContainsString('parallel geändert', $conflicts[0]);

            $fresh = $calculation->fresh(['positions']);
            $this->assertSame($lockVersion + 1, $fresh->lock_version);
            $winnerCampaign = substr($oks[0], 3);
            $this->assertSame($winnerCampaign, $fresh->campaign);

            $freshPosition = $fresh->positions->firstOrFail();
            $this->assertSame($freezeBefore, [
                $freshPosition->engine_profile_key,
                $freshPosition->calculation_method_key,
                $freshPosition->calculation_method_name,
                $freshPosition->algorithm_version,
                $freshPosition->spot_method->value,
                $freshPosition->kind->value,
            ]);
        } finally {
            foreach (glob($runDir.'/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($runDir);
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
