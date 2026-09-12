<?php

namespace Tests\Feature\DynamicField;

use App\Enums\FieldAppliesTo;
use App\Enums\FieldScope;
use App\Enums\FieldType;
use App\Enums\Role;
use App\Models\CalculationFieldValue;
use App\Models\CalculationPositionFieldValue;
use App\Models\DispoOrderFieldValue;
use App\Models\DispoOrderPositionFieldValue;
use App\Models\FieldDefinition;
use App\Models\FieldSet;
use App\Models\FieldSetVersion;
use App\Models\User;
use App\Services\Calculation\CalculationWriter;
use App\Services\DispoOrder\DispoOrderWriter;
use App\Services\DynamicField\Admin\AdminFieldSetCatalog;
use App\Services\DynamicField\Admin\FieldDefinitionCustomWriter;
use App\Services\DynamicField\Admin\FieldSetVersionAdminWriter;
use App\Services\DynamicField\ConfigurationSnapshotMaterializer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesSavedCalculation;
use Tests\Concerns\CreatesSpotClassicCatalog;
use Tests\TestCase;

/**
 * DF-3-REST-C1: Migration value_json – Daten-Erhalt, Roundtrip, Rollback.
 */
class ChoiceValueJsonMigrationTest extends TestCase
{
    use CreatesSavedCalculation;
    use CreatesSpotClassicCatalog;
    use RefreshDatabase;

    /**
     * @var list<string>
     */
    private array $tables = [
        'calculation_field_values',
        'calculation_position_field_values',
        'dispo_order_field_values',
        'dispo_order_position_field_values',
    ];

    public function test_value_json_columns_exist_and_are_nullable(): void
    {
        foreach ($this->tables as $table) {
            $this->assertTrue(Schema::hasColumn($table, 'value_json'), $table);
        }

        if (DB::connection()->getDriverName() === 'mysql') {
            foreach ($this->tables as $table) {
                $column = DB::selectOne(
                    'SELECT IS_NULLABLE as is_nullable, DATA_TYPE as data_type
                     FROM information_schema.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE()
                       AND TABLE_NAME = ?
                       AND COLUMN_NAME = ?',
                    [$table, 'value_json'],
                );
                $this->assertNotNull($column);
                $this->assertSame('YES', $column->is_nullable);
                // Laravel `json()` → MySQL `json`; unter MariaDB typischerweise `longtext`
                // wie options_json – ohne JSON-Validierungs-CHECK.
                $this->assertContains(
                    strtolower((string) $column->data_type),
                    ['json', 'longtext'],
                    $table.' value_json DATA_TYPE',
                );
            }
        }
    }

    public function test_existing_scalar_rows_survive_down_and_up_on_all_tables(): void
    {
        $rows = $this->seedAllFourScalarRows();

        foreach ($this->tables as $table) {
            $this->assertSame(0, DB::table($table)->whereNotNull('value_json')->count(), $table);
            $this->assertSame(
                'keep-'.$table,
                DB::table($table)->where('id', $rows[$table])->value('value_string'),
            );
        }

        $migration = require database_path(
            'migrations/2026_09_11_180000_add_value_json_to_dynamic_field_value_tables.php',
        );

        $migration->down();
        foreach ($this->tables as $table) {
            $this->assertFalse(Schema::hasColumn($table, 'value_json'), $table);
            $this->assertSame(
                'keep-'.$table,
                DB::table($table)->where('id', $rows[$table])->value('value_string'),
            );
        }

        $migration->up();
        foreach ($this->tables as $table) {
            $this->assertTrue(Schema::hasColumn($table, 'value_json'), $table);
            $row = DB::table($table)->where('id', $rows[$table])->first();
            $this->assertNotNull($row);
            $this->assertSame('keep-'.$table, $row->value_string);
            $this->assertNull($row->value_json);
        }
    }

    public function test_json_string_and_array_roundtrip_via_eloquent_cast(): void
    {
        $rows = $this->seedAllFourScalarRows();

        $header = CalculationFieldValue::query()->findOrFail($rows['calculation_field_values']);
        $header->value_string = null;
        $header->value_json = 'opt_a';
        $header->save();
        $header->refresh();
        $this->assertSame('opt_a', $header->value_json);
        $rawSelect = DB::table('calculation_field_values')->where('id', $header->id)->value('value_json');
        $this->assertSame('opt_a', json_decode((string) $rawSelect, true, 512, JSON_THROW_ON_ERROR));

        $pos = CalculationPositionFieldValue::query()->findOrFail($rows['calculation_position_field_values']);
        $pos->value_string = null;
        $pos->value_json = ['opt_b', 'opt_a'];
        $pos->save();
        $pos->refresh();
        $this->assertSame(['opt_b', 'opt_a'], $pos->value_json);
        // MySQL native JSON kann beim Lesen Spaces nach Kommas einfügen; fachlich zählt Decode.
        $rawMulti = DB::table('calculation_position_field_values')->where('id', $pos->id)->value('value_json');
        $this->assertSame(
            ['opt_b', 'opt_a'],
            json_decode((string) $rawMulti, true, 512, JSON_THROW_ON_ERROR),
        );

        $dispoHeader = DispoOrderFieldValue::query()->findOrFail($rows['dispo_order_field_values']);
        $dispoHeader->value_string = null;
        $dispoHeader->value_json = [];
        $dispoHeader->save();
        $dispoHeader->refresh();
        $this->assertSame([], $dispoHeader->value_json);
        $rawEmpty = DB::table('dispo_order_field_values')->where('id', $dispoHeader->id)->value('value_json');
        $this->assertSame([], json_decode((string) $rawEmpty, true, 512, JSON_THROW_ON_ERROR));

        $dispoPos = DispoOrderPositionFieldValue::query()->findOrFail($rows['dispo_order_position_field_values']);
        $dispoPos->value_string = null;
        $dispoPos->value_json = 'opt_z';
        $dispoPos->save();
        $dispoPos->refresh();
        $this->assertSame('opt_z', $dispoPos->value_json);
        $rawDispoSelect = DB::table('dispo_order_position_field_values')->where('id', $dispoPos->id)->value('value_json');
        $this->assertSame('opt_z', json_decode((string) $rawDispoSelect, true, 512, JSON_THROW_ON_ERROR));
    }

    public function test_migration_down_and_up_are_symmetric(): void
    {
        $migration = require database_path(
            'migrations/2026_09_11_180000_add_value_json_to_dynamic_field_value_tables.php',
        );

        $migration->down();
        foreach ($this->tables as $table) {
            $this->assertFalse(Schema::hasColumn($table, 'value_json'), $table);
        }

        $migration->up();
        foreach ($this->tables as $table) {
            $this->assertTrue(Schema::hasColumn($table, 'value_json'), $table);
        }
    }

    /**
     * @return array<string, int>
     */
    private function seedAllFourScalarRows(): array
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $headerDef = $this->activateShortText($admin, FieldScope::Header, FieldAppliesTo::Both);
        $positionDef = $this->activateShortText($admin, FieldScope::Position, FieldAppliesTo::Both);

        $user = User::factory()->role(Role::Sales)->create();
        $catalog = $this->createSpotClassicCatalog();
        $writer = app(CalculationWriter::class);
        $calculation = $writer->create($this->withLiveSchemaFingerprint([
            'planning_mode' => 'manual',
            'customer_name' => 'Kunde',
            'agency_name' => 'Agentur',
            'campaign' => 'Kampagne',
            'product_title' => 'Produkt',
            'order_discount_percent' => '0',
            'ae_enabled' => false,
            'dynamic_field_values' => [
                $headerDef->key => 'keep-calculation_field_values',
            ],
            'positions' => [
                array_merge($this->positionPayload($catalog), [
                    'dynamic_field_values' => [
                        $positionDef->key => 'keep-calculation_position_field_values',
                    ],
                ]),
            ],
        ]), $user);

        $order = app(DispoOrderWriter::class)
            ->createFromCalculation($calculation, $calculation->positions()->pluck('id')->all(), $user)
            ->order;

        $calcHeader = CalculationFieldValue::query()
            ->where('calculation_id', $calculation->id)
            ->whereHas('snapshotFieldDefinition', fn ($q) => $q->where('key', $headerDef->key))
            ->firstOrFail();
        $calcPos = CalculationPositionFieldValue::query()
            ->whereHas('snapshotFieldDefinition', fn ($q) => $q->where('key', $positionDef->key))
            ->firstOrFail();
        $dispoHeader = DispoOrderFieldValue::query()
            ->where('dispo_order_id', $order->id)
            ->whereHas('snapshotFieldDefinition', fn ($q) => $q->where('key', $headerDef->key))
            ->firstOrFail();
        $dispoPos = DispoOrderPositionFieldValue::query()
            ->whereHas('snapshotFieldDefinition', fn ($q) => $q->where('key', $positionDef->key))
            ->firstOrFail();

        // Dispo-Copy behält Calc-Text; für eindeutige Survive-Assertions angleichen.
        $dispoHeader->forceFill(['value_string' => 'keep-dispo_order_field_values'])->save();
        $dispoPos->forceFill(['value_string' => 'keep-dispo_order_position_field_values'])->save();

        return [
            'calculation_field_values' => (int) $calcHeader->id,
            'calculation_position_field_values' => (int) $calcPos->id,
            'dispo_order_field_values' => (int) $dispoHeader->id,
            'dispo_order_position_field_values' => (int) $dispoPos->id,
        ];
    }

    private function activateShortText(
        User $admin,
        FieldScope $scope,
        FieldAppliesTo $appliesTo,
    ): FieldDefinition {
        $definition = app(FieldDefinitionCustomWriter::class)->create([
            'label' => 'Text '.$scope->value.' '.bin2hex(random_bytes(2)),
            'field_type' => FieldType::ShortText,
            'scope' => $scope,
            'applies_to' => $appliesTo,
            'sort_default' => 70,
            'reportable' => false,
        ], $admin);

        $setWriter = app(FieldSetVersionAdminWriter::class);
        foreach ([AdminFieldSetCatalog::CALCULATION_CORE, AdminFieldSetCatalog::DISPO_ORDER_CORE] as $setKey) {
            $fieldSet = FieldSet::query()->where('key', $setKey)->firstOrFail();
            $source = FieldSetVersion::query()->whereKey($fieldSet->active_version_id)->firstOrFail();
            $draft = $setWriter->createDraftFromVersion($fieldSet, $source, $admin, $fieldSet->lock_version);
            $fieldSet->refresh();
            $setWriter->addCustomMembership($fieldSet, $draft, [
                'field_definition_id' => $definition->id,
                'field_definition_revision_id' => (int) $definition->current_revision_id,
                'sort' => 70,
                'required_override' => null,
                'visible_override' => null,
                'lock_version' => $fieldSet->lock_version,
            ], $admin);
            $fieldSet->refresh();
            $setWriter->activateDraft($fieldSet, $draft, $admin, $fieldSet->lock_version);
        }

        app(ConfigurationSnapshotMaterializer::class)
            ->materializeFromActiveSet(AdminFieldSetCatalog::CALCULATION_CORE);

        return $definition->fresh(['currentRevision']) ?? $definition;
    }

    /**
     * @param  array<string, mixed>  $catalog
     * @return array<string, mixed>
     */
    private function positionPayload(array $catalog): array
    {
        return [
            'inventory_id' => $catalog['hamburg']->id,
            'advertising_medium_id' => $catalog['medium']->id,
            'spot_method' => 'average',
            'length_seconds' => 30,
            'total_spot_count' => 10,
            'position_discount_percent' => '0',
            'ae_percent' => '15',
            'plan_rows' => [['hour' => 8, 'day_group' => 'mo_fr']],
        ];
    }
}
