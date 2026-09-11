<?php

namespace Tests\Feature\DynamicField;

use App\Enums\FieldAppliesTo;
use App\Enums\FieldScope;
use App\Enums\FieldType;
use App\Enums\Role;
use App\Models\CalculationFieldValue;
use App\Models\FieldDefinition;
use App\Models\FieldSet;
use App\Models\FieldSetVersion;
use App\Models\SnapshotFieldDefinition;
use App\Models\User;
use App\Services\Calculation\CalculationWriter;
use App\Services\DynamicField\Admin\AdminFieldSetCatalog;
use App\Services\DynamicField\Admin\FieldDefinitionCustomWriter;
use App\Services\DynamicField\Admin\FieldDefinitionOptionsWriter;
use App\Services\DynamicField\Admin\FieldSetVersionAdminWriter;
use App\Services\DynamicField\ConfigurationSnapshotMaterializer;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Concerns\CreatesSpotClassicCatalog;
use Tests\TestCase;

/**
 * DF-3-REST-C1: echte parallele Choice-Partial-Saves gegen dispo_test.
 *
 * Abgedeckt: parallele Calc-Select-Updates mit gleicher lock_version → genau
 * ein Winner, ein 409, Winner in DB.
 *
 * Bewusst nicht in C1 (Lücken): parallele native Dispo-Saves, Dispo-Create
 * während Calc-Änderung, Multi-Select-Concurrency, Inactive-Previous unter
 * Parallelität, Options-Admin vs. Freeze (Freeze-Isolation indirekt über
 * Runtime-Vertrag abgesichert).
 */
class ChoiceValueConcurrencyTest extends TestCase
{
    use CreatesSpotClassicCatalog;
    use DatabaseMigrations;

    public function test_parallel_choice_updates_one_wins_other_conflicts(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Choice-Concurrency erfordert MySQL (dispo_test).');
        }

        $admin = User::factory()->role(Role::Admin)->create();
        $definition = $this->activateSelect($admin);
        $user = User::factory()->role(Role::Sales)->create();
        $catalog = $this->createSpotClassicCatalog();

        $calculation = app(CalculationWriter::class)->create($this->withLiveSchemaFingerprint([
            'planning_mode' => 'manual',
            'customer_name' => 'Kunde',
            'agency_name' => 'Agentur',
            'campaign' => 'Kampagne',
            'product_title' => 'Produkt',
            'order_discount_percent' => '0',
            'ae_enabled' => false,
            'dynamic_field_values' => [
                $definition->key => 'opt_a',
            ],
            'positions' => [[
                'inventory_id' => $catalog['hamburg']->id,
                'advertising_medium_id' => $catalog['medium']->id,
                'spot_method' => 'average',
                'length_seconds' => 30,
                'total_spot_count' => 10,
                'position_discount_percent' => '0',
                'ae_percent' => '15',
                'plan_rows' => [['hour' => 8, 'day_group' => 'mo_fr']],
            ]],
        ]), $user);

        $lockVersion = (int) $calculation->lock_version;
        $runDir = sys_get_temp_dir().'/dispo-c1-choice-concurrency-'.Str::uuid();
        if (! mkdir($runDir, 0700, true) && ! is_dir($runDir)) {
            $this->fail('Temporäres Barrier-Verzeichnis konnte nicht erstellt werden.');
        }

        try {
            $script = base_path('tests/concurrency/choice_value_calc_update_worker.php');
            $env = $this->workerEnvironment();

            $worker0 = new Process([
                PHP_BINARY,
                $script,
                $runDir,
                '0',
                (string) $calculation->id,
                (string) $lockVersion,
                $definition->key,
                'opt_b',
            ], null, $env);
            $worker1 = new Process([
                PHP_BINARY,
                $script,
                $runDir,
                '1',
                (string) $calculation->id,
                (string) $lockVersion,
                $definition->key,
                'opt_c',
            ], null, $env);

            $worker0->start();
            $worker1->start();
            $this->assertSame(0, $worker0->wait(), $worker0->getErrorOutput() ?: $worker0->getOutput());
            $this->assertSame(0, $worker1->wait(), $worker1->getErrorOutput() ?: $worker1->getOutput());

            $results = [];
            foreach (glob($runDir.'/worker-*.result') ?: [] as $resultFile) {
                $results[] = trim((string) file_get_contents($resultFile));
            }
            sort($results);
            $oks = array_values(array_filter($results, fn (string $row): bool => str_starts_with($row, 'ok:')));
            $conflicts = array_values(array_filter($results, fn (string $row): bool => str_starts_with($row, 'conflict:')));
            $this->assertCount(1, $oks);
            $this->assertCount(1, $conflicts);

            $winner = substr($oks[0], 3);
            $this->assertContains($winner, ['opt_b', 'opt_c']);

            $snapDef = SnapshotFieldDefinition::query()
                ->where('configuration_snapshot_id', $calculation->configuration_snapshot_id)
                ->where('key', $definition->key)
                ->firstOrFail();
            $row = CalculationFieldValue::query()
                ->where('calculation_id', $calculation->id)
                ->where('snapshot_field_definition_id', $snapDef->id)
                ->firstOrFail();
            $this->assertSame($winner, $row->value_json);
            $this->assertSame($lockVersion + 1, (int) $calculation->fresh()->lock_version);
        } finally {
            foreach (glob($runDir.'/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($runDir);
        }
    }

    private function activateSelect(User $admin): FieldDefinition
    {
        $definition = app(FieldDefinitionCustomWriter::class)->create([
            'label' => 'Choice Race',
            'field_type' => FieldType::Select,
            'scope' => FieldScope::Header,
            'applies_to' => FieldAppliesTo::Both,
            'sort_default' => 80,
            'reportable' => false,
        ], $admin);

        app(FieldDefinitionOptionsWriter::class)->replace($definition, [
            'lock_version' => $definition->lock_version,
            'options' => [
                ['key' => 'opt_a', 'label' => 'A', 'sort' => 1],
                ['key' => 'opt_b', 'label' => 'B', 'sort' => 2],
                ['key' => 'opt_c', 'label' => 'C', 'sort' => 3],
            ],
        ], $admin);
        $definition->refresh();

        $setWriter = app(FieldSetVersionAdminWriter::class);
        $fieldSet = FieldSet::query()->where('key', AdminFieldSetCatalog::CALCULATION_CORE)->firstOrFail();
        $source = FieldSetVersion::query()->whereKey($fieldSet->active_version_id)->firstOrFail();
        $draft = $setWriter->createDraftFromVersion($fieldSet, $source, $admin, $fieldSet->lock_version);
        $fieldSet->refresh();
        $setWriter->addCustomMembership($fieldSet, $draft, [
            'field_definition_id' => $definition->id,
            'field_definition_revision_id' => (int) $definition->current_revision_id,
            'sort' => 80,
            'lock_version' => $fieldSet->lock_version,
        ], $admin);
        $fieldSet->refresh();
        $setWriter->activateDraft($fieldSet, $draft, $admin, $fieldSet->lock_version);
        app(ConfigurationSnapshotMaterializer::class)
            ->materializeFromActiveSet(AdminFieldSetCatalog::CALCULATION_CORE);

        return $definition->fresh(['currentRevision']) ?? $definition;
    }

    /**
     * @return array<string, string>
     */
    private function workerEnvironment(): array
    {
        return [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'mysql',
            'DB_DATABASE' => 'dispo_test',
            'DB_HOST' => (string) env('DB_HOST', '127.0.0.1'),
            'DB_PORT' => (string) env('DB_PORT', '3306'),
            'DB_USERNAME' => (string) env('DB_USERNAME', 'root'),
            'DB_PASSWORD' => (string) env('DB_PASSWORD', ''),
        ];
    }
}
