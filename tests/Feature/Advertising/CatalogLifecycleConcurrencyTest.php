<?php

namespace Tests\Feature\Advertising;

use App\Enums\CalculationKind;
use App\Enums\Role;
use App\Models\AdvertisingCategory;
use App\Models\AdvertisingMedium;
use App\Models\User;
use App\Services\Advertising\Admin\AdvertisingCategoryAdminWriter;
use App\Services\Advertising\Admin\AdvertisingMediumAdminWriter;
use App\Services\Advertising\Admin\CatalogImpactPreviewService;
use App\Support\Advertising\CanonicalAdvertisingCategories;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * ADV-001b C-RACE-01..03: echte parallele MySQL-Transaktionen.
 *
 * Invariante: nie Kategorie inaktiv + Medium aktiv.
 */
class CatalogLifecycleConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_c_race_01_category_deactivate_versus_medium_create(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('C-RACE-01 erfordert MySQL (GitHub-Job mysql).');
        }

        $admin = User::factory()->role(Role::Admin)->create();
        $spots = AdvertisingCategory::query()
            ->where('key', CanonicalAdvertisingCategories::SPOTS)
            ->firstOrFail();

        // Spots muss für Create-Win deaktivierbar sein → keine aktiven Medien.
        AdvertisingMedium::query()
            ->where('category_id', $spots->id)
            ->where('is_active', true)
            ->update(['is_active' => false]);

        $spots->refresh();
        $this->assertTrue($spots->is_active);
        $this->assertSame(0, AdvertisingMedium::query()
            ->where('category_id', $spots->id)
            ->where('is_active', true)
            ->count());

        $code = 'race_create_'.bin2hex(random_bytes(3));

        $results = $this->runParallelWorkers(
            [
                'action' => 'create_medium',
                'payload' => [
                    'actor_id' => $admin->id,
                    'code' => $code,
                    'name' => 'Race Create',
                    'kind' => CalculationKind::SpotClassic->value,
                    'category_id' => $spots->id,
                ],
            ],
            [
                'action' => 'deactivate_category',
                'payload' => [
                    'actor_id' => $admin->id,
                    'category_id' => $spots->id,
                    'lock_version' => $spots->lock_version,
                ],
            ],
        );

        $this->assertInvariantNoActiveMediumUnderInactiveCategory();
        $this->assertRaceResolvedWithoutUncontrolledFailure($results);

        $spots->refresh();
        $created = AdvertisingMedium::query()->where('code', $code)->first();

        if ($created !== null && $created->is_active) {
            $this->assertTrue($spots->is_active, 'Create-Win: Kategorie muss aktiv bleiben.');
            $this->assertTrue(
                collect($results)->contains(fn (string $line): bool => str_starts_with($line, 'OK:create_medium')),
            );
            $this->assertTrue(
                collect($results)->contains(fn (string $line): bool => str_starts_with($line, 'ERROR:')),
                'Deactivate muss bei aktivem Medium scheitern.',
            );
        } else {
            $this->assertFalse($spots->is_active, 'Deactivate-Win: Kategorie muss inaktiv sein.');
            if ($created !== null) {
                $this->assertFalse(
                    $created->is_active,
                    'Create darf kein aktives Medium unter inaktiver Kategorie hinterlassen.',
                );
            }
        }
    }

    public function test_c_race_02_category_deactivate_versus_medium_reactivate(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('C-RACE-02 erfordert MySQL (GitHub-Job mysql).');
        }

        $admin = User::factory()->role(Role::Admin)->create();
        $spots = AdvertisingCategory::query()
            ->where('key', CanonicalAdvertisingCategories::SPOTS)
            ->firstOrFail();

        AdvertisingMedium::query()
            ->where('category_id', $spots->id)
            ->where('is_active', true)
            ->update(['is_active' => false]);

        $medium = AdvertisingMedium::factory()->create([
            'category_id' => $spots->id,
            'code' => 'race_react_'.bin2hex(random_bytes(3)),
            'is_active' => false,
        ]);

        $results = $this->runParallelWorkers(
            [
                'action' => 'reactivate_medium',
                'payload' => [
                    'actor_id' => $admin->id,
                    'medium_id' => $medium->id,
                    'lock_version' => $medium->lock_version,
                ],
            ],
            [
                'action' => 'deactivate_category',
                'payload' => [
                    'actor_id' => $admin->id,
                    'category_id' => $spots->id,
                    'lock_version' => $spots->lock_version,
                ],
            ],
        );

        $this->assertInvariantNoActiveMediumUnderInactiveCategory();
        $this->assertRaceResolvedWithoutUncontrolledFailure($results);

        $medium->refresh();
        $spots->refresh();

        if ($medium->is_active) {
            $this->assertTrue($spots->is_active);
        } else {
            // Deactivate-Win oder Reaktivieren abgelehnt: Medium bleibt inaktiv.
            $this->assertFalse($medium->is_active);
        }
    }

    public function test_c_race_03_target_category_deactivate_versus_category_change(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('C-RACE-03 erfordert MySQL (GitHub-Job mysql).');
        }

        $admin = User::factory()->role(Role::Admin)->create();
        $spots = AdvertisingCategory::query()
            ->where('key', CanonicalAdvertisingCategories::SPOTS)
            ->firstOrFail();
        $online = AdvertisingCategory::query()
            ->where('key', CanonicalAdvertisingCategories::ONLINE_AUDIO)
            ->firstOrFail();

        AdvertisingMedium::query()
            ->where('category_id', $spots->id)
            ->where('is_active', true)
            ->update(['is_active' => false]);

        $medium = AdvertisingMedium::factory()->create([
            'category_id' => $spots->id,
            'code' => 'race_change_'.bin2hex(random_bytes(3)),
            'is_active' => true,
        ]);
        // Start außerhalb spots, Wechsel zurück nach spots (Reparatur-Szenario).
        DB::table('advertising_media')->where('id', $medium->id)->update([
            'category_id' => $online->id,
        ]);
        $medium->refresh();

        $preview = app(CatalogImpactPreviewService::class)->previewMediumCategoryChange($medium, [
            'target_category_id' => $spots->id,
        ]);
        $this->assertTrue($preview['can_proceed']);

        $frozenName = $spots->name;
        $snapshotId = DB::table('configuration_snapshots')->insertGetId([
            'field_set_id' => DB::table('field_sets')->where('key', 'system_calculation_core')->value('id'),
            'field_set_version_id' => DB::table('field_sets')->where('key', 'system_calculation_core')->value('active_version_id'),
            'source' => 'seed_active',
            'format_version' => 3,
            'schema_fingerprint' => str_repeat('c', 64),
            'context_advertising_medium_id' => $medium->id,
            'context_advertising_medium_code' => $medium->code,
            'context_advertising_medium_name' => $medium->name,
            'context_advertising_category_id' => $online->id,
            'context_advertising_category_key' => $online->key,
            'context_advertising_category_name' => $online->name,
            'created_at' => now(),
        ]);

        $results = $this->runParallelWorkers(
            [
                'action' => 'change_category',
                'payload' => [
                    'actor_id' => $admin->id,
                    'medium_id' => $medium->id,
                    'target_category_id' => $spots->id,
                    'lock_version' => $medium->lock_version,
                    'fingerprint' => $preview['fingerprint'],
                ],
            ],
            [
                'action' => 'deactivate_category',
                'payload' => [
                    'actor_id' => $admin->id,
                    'category_id' => $spots->id,
                    'lock_version' => $spots->lock_version,
                ],
            ],
        );

        $this->assertInvariantNoActiveMediumUnderInactiveCategory();
        $this->assertRaceResolvedWithoutUncontrolledFailure($results);

        $medium->refresh();
        $spots->refresh();
        $row = DB::table('configuration_snapshots')->where('id', $snapshotId)->first();
        $this->assertSame($online->id, (int) $row->context_advertising_category_id);
        $this->assertSame($online->key, $row->context_advertising_category_key);
        $this->assertSame($online->name, $row->context_advertising_category_name);
        $this->assertSame($frozenName, $spots->is_active ? $spots->name : $frozenName);

        if ($medium->category_id === $spots->id && $medium->is_active) {
            $this->assertTrue($spots->is_active);
        }
    }

    public function test_sequential_locks_still_block_inactive_category_create(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $spots = AdvertisingCategory::query()
            ->where('key', CanonicalAdvertisingCategories::SPOTS)
            ->firstOrFail();

        AdvertisingMedium::query()
            ->where('category_id', $spots->id)
            ->update(['is_active' => false]);

        $preview = app(CatalogImpactPreviewService::class)->previewCategoryDeactivate($spots->fresh());
        app(AdvertisingCategoryAdminWriter::class)->deactivate(
            $spots,
            [
                'lock_version' => $spots->fresh()->lock_version,
                'fingerprint' => $preview['fingerprint'],
            ],
            $admin,
        );

        $this->expectException(ValidationException::class);
        app(AdvertisingMediumAdminWriter::class)->create([
            'code' => 'seq_block_'.bin2hex(random_bytes(3)),
            'name' => 'Blocked',
            'kind' => CalculationKind::SpotClassic->value,
            'category_id' => $spots->id,
        ], $admin);
    }

    /**
     * @param  array{action: string, payload: array<string, mixed>}  $workerA
     * @param  array{action: string, payload: array<string, mixed>}  $workerB
     * @return list<string>
     */
    private function runParallelWorkers(array $workerA, array $workerB): array
    {
        $runDir = sys_get_temp_dir().'/dispo-catalog-concurrency-'.Str::uuid();
        if (! mkdir($runDir, 0700, true) && ! is_dir($runDir)) {
            $this->fail('Temporäres Barrier-Verzeichnis konnte nicht erstellt werden.');
        }

        try {
            $script = base_path('tests/concurrency/catalog_lifecycle_worker.php');
            $env = $this->workerEnvironment();

            $processA = new Process(
                [
                    PHP_BINARY,
                    $script,
                    $runDir,
                    '0',
                    $workerA['action'],
                    json_encode($workerA['payload'], JSON_THROW_ON_ERROR),
                ],
                null,
                $env,
            );
            $processB = new Process(
                [
                    PHP_BINARY,
                    $script,
                    $runDir,
                    '1',
                    $workerB['action'],
                    json_encode($workerB['payload'], JSON_THROW_ON_ERROR),
                ],
                null,
                $env,
            );

            $processA->setTimeout(60);
            $processB->setTimeout(60);
            $processA->start();
            $processB->start();
            $processA->wait();
            $processB->wait();

            $results = [];
            foreach (glob($runDir.'/worker-*.result') ?: [] as $resultFile) {
                $results[] = trim((string) file_get_contents($resultFile));
            }

            $this->assertCount(2, $results, 'Beide Worker müssen ein Resultat schreiben.');

            return $results;
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
     * @param  list<string>  $results
     */
    private function assertRaceResolvedWithoutUncontrolledFailure(array $results): void
    {
        foreach ($results as $line) {
            $this->assertTrue(
                str_starts_with($line, 'OK:') || str_starts_with($line, 'ERROR:'),
                'Unerwartetes Worker-Resultat: '.$line,
            );
            $this->assertStringNotContainsString('Deadlock', $line);
            $this->assertStringNotContainsString('1213', $line);
        }

        $ok = array_values(array_filter($results, fn (string $line): bool => str_starts_with($line, 'OK:')));
        $errors = array_values(array_filter($results, fn (string $line): bool => str_starts_with($line, 'ERROR:')));
        $this->assertGreaterThanOrEqual(1, count($ok) + count($errors));
        $this->assertNotSame([], $ok, 'Mindestens eine Aktion muss fachlich durchkommen oder klar scheitern – leer ist unzulässig.');
        // Genau ein Winner ist nicht zwingend (beide können ERROR sein wenn Setup kollidiert),
        // aber mindestens eine Entscheidung muss ERROR oder OK sein und Invariante halten.
        $this->assertTrue(count($ok) >= 1 || count($errors) === 2);
    }

    private function assertInvariantNoActiveMediumUnderInactiveCategory(): void
    {
        $violations = DB::table('advertising_media as m')
            ->join('advertising_categories as c', 'c.id', '=', 'm.category_id')
            ->where('m.is_active', true)
            ->where('c.is_active', false)
            ->count();

        $this->assertSame(0, $violations, 'Invariante verletzt: aktives Medium unter inaktiver Kategorie.');
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
