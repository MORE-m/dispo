<?php

namespace Tests\Feature\Advertising;

use App\Enums\Role;
use App\Models\CalculationPosition;
use App\Models\User;
use App\Services\DispoOrder\DispoOrderWriter;
use App\Support\Calculation\CalculationMethodFreezeResolver;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesSavedCalculation;
use Tests\Concerns\CreatesSpotClassicCatalog;
use Tests\TestCase;

/**
 * ADV-001c2: CHECK/Trigger-Guards für Freeze-Vollständigkeit (NULL-Quartett oder vollgültig).
 */
class Adv001c2FreezeGuardConstraintTest extends TestCase
{
    use CreatesSavedCalculation;
    use CreatesSpotClassicCatalog;
    use DatabaseMigrations;

    public function test_calculation_positions_full_null_freeze_allowed_on_insert_and_update(): void
    {
        $baseId = $this->seedCalculationPositionRow();

        DB::table('calculation_positions')->where('id', $baseId)->update($this->nullFreeze());
        $this->assertNullFreeze('calculation_positions', $baseId);

        $insertId = $this->insertCalculationPositionClone($baseId, $this->nullFreeze());
        $this->assertNullFreeze('calculation_positions', $insertId);
    }

    public function test_calculation_positions_full_valid_freeze_allowed_on_insert_and_update(): void
    {
        $baseId = $this->seedCalculationPositionRow();

        DB::table('calculation_positions')->where('id', $baseId)->update($this->validFreeze());
        $this->assertValidFreeze('calculation_positions', $baseId);

        $insertId = $this->insertCalculationPositionClone($baseId, $this->validFreeze());
        $this->assertValidFreeze('calculation_positions', $insertId);
    }

    public function test_calculation_positions_rejects_empty_whitespace_partial_and_mixed_freeze(): void
    {
        $baseId = $this->seedCalculationPositionRow();
        DB::table('calculation_positions')->where('id', $baseId)->update($this->validFreeze());

        $this->assertUpdateRejected('calculation_positions', $baseId, [
            'engine_profile_key' => '',
            'calculation_method_key' => '',
            'calculation_method_name' => '',
            'algorithm_version' => '',
        ]);
        $this->assertUpdateRejected('calculation_positions', $baseId, [
            'engine_profile_key' => '   ',
            'calculation_method_key' => "\t",
            'calculation_method_name' => '  ',
            'algorithm_version' => "\n",
        ]);
        $this->assertUpdateRejected('calculation_positions', $baseId, [
            'engine_profile_key' => 'spot_classic',
            'calculation_method_key' => 'average',
            'calculation_method_name' => 'Durchschnitt',
            'algorithm_version' => '',
        ]);
        $this->assertUpdateRejected('calculation_positions', $baseId, [
            'engine_profile_key' => 'spot_classic',
            'calculation_method_key' => 'average',
            'calculation_method_name' => null,
            'algorithm_version' => 'v1',
        ]);

        $this->expectException(QueryException::class);
        $this->insertCalculationPositionClone($baseId, [
            'engine_profile_key' => '',
            'calculation_method_key' => '',
            'calculation_method_name' => '',
            'algorithm_version' => '',
        ]);
    }

    public function test_dispo_order_positions_full_null_freeze_allowed_on_insert_and_update(): void
    {
        [$orderId, $baseId] = $this->seedDispoPositionRow();

        $this->dropFreezeGuardsForTest('dispo_order_positions');
        DB::table('dispo_order_positions')->where('id', $baseId)->update($this->nullFreeze());
        $this->reinstallFreezeGuardsViaMigrationUp();

        DB::table('dispo_order_positions')->where('id', $baseId)->update($this->nullFreeze());
        $this->assertNullFreeze('dispo_order_positions', $baseId);

        $insertId = $this->insertDispoPositionClone($orderId, $baseId, $this->nullFreeze());
        $this->assertNullFreeze('dispo_order_positions', $insertId);
    }

    public function test_dispo_order_positions_full_valid_freeze_allowed_on_insert_and_update(): void
    {
        [$orderId, $baseId] = $this->seedDispoPositionRow();

        DB::table('dispo_order_positions')->where('id', $baseId)->update($this->validFreeze());
        $this->assertValidFreeze('dispo_order_positions', $baseId);

        $insertId = $this->insertDispoPositionClone($orderId, $baseId, $this->validFreeze());
        $this->assertValidFreeze('dispo_order_positions', $insertId);
    }

    public function test_dispo_order_positions_rejects_empty_whitespace_partial_and_mixed_freeze(): void
    {
        [$orderId, $baseId] = $this->seedDispoPositionRow();

        $this->assertUpdateRejected('dispo_order_positions', $baseId, [
            'engine_profile_key' => '',
            'calculation_method_key' => '',
            'calculation_method_name' => '',
            'algorithm_version' => '',
        ]);
        $this->assertUpdateRejected('dispo_order_positions', $baseId, [
            'engine_profile_key' => '   ',
            'calculation_method_key' => "\t",
            'calculation_method_name' => '  ',
            'algorithm_version' => "\n",
        ]);
        $this->assertUpdateRejected('dispo_order_positions', $baseId, [
            'engine_profile_key' => 'spot_classic',
            'calculation_method_key' => 'average',
            'calculation_method_name' => 'Durchschnitt',
            'algorithm_version' => '',
        ]);
        $this->assertUpdateRejected('dispo_order_positions', $baseId, [
            'engine_profile_key' => 'spot_classic',
            'calculation_method_key' => null,
            'calculation_method_name' => 'Durchschnitt',
            'algorithm_version' => 'v1',
        ]);

        $this->expectException(QueryException::class);
        $this->insertDispoPositionClone($orderId, $baseId, [
            'engine_profile_key' => '  ',
            'calculation_method_key' => 'average',
            'calculation_method_name' => 'Durchschnitt',
            'algorithm_version' => 'v1',
        ]);
    }

    /**
     * @return array{
     *     engine_profile_key: null,
     *     calculation_method_key: null,
     *     calculation_method_name: null,
     *     algorithm_version: null
     * }
     */
    private function nullFreeze(): array
    {
        return [
            'engine_profile_key' => null,
            'calculation_method_key' => null,
            'calculation_method_name' => null,
            'algorithm_version' => null,
        ];
    }

    /**
     * @return array{
     *     engine_profile_key: string,
     *     calculation_method_key: string,
     *     calculation_method_name: string,
     *     algorithm_version: string
     * }
     */
    private function validFreeze(): array
    {
        return CalculationMethodFreezeResolver::LEGACY_SPOT_CLASSIC_AVERAGE;
    }

    private function seedCalculationPositionRow(): int
    {
        $this->dropFreezeGuardsForTest('calculation_positions');
        $position = CalculationPosition::factory()->create([
            'kind' => 'spot_classic',
            'spot_method' => 'average',
        ]);
        DB::table('calculation_positions')->where('id', $position->id)->update($this->nullFreeze());
        $this->reinstallFreezeGuardsViaMigrationUp();

        return (int) $position->id;
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function seedDispoPositionRow(): array
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $calculation = $this->createSavedCalculation($catalog, [
            ['inventory_id' => $catalog['hamburg']->id],
        ], $user);
        $positionId = (int) $calculation->positions()->value('id');

        $order = app(DispoOrderWriter::class)
            ->createFromCalculation($calculation, [$positionId], $user)
            ->order;

        $dispoPositionId = (int) $order->positions()->value('id');

        return [(int) $order->id, $dispoPositionId];
    }

    /**
     * @param  array<string, mixed>  $freeze
     */
    private function insertCalculationPositionClone(int $sourceId, array $freeze): int
    {
        $row = (array) DB::table('calculation_positions')->where('id', $sourceId)->first();
        unset($row['id']);
        $row = array_merge($row, $freeze, [
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return (int) DB::table('calculation_positions')->insertGetId($row);
    }

    /**
     * @param  array<string, mixed>  $freeze
     */
    private function insertDispoPositionClone(int $orderId, int $sourceId, array $freeze): int
    {
        $row = (array) DB::table('dispo_order_positions')->where('id', $sourceId)->first();
        unset($row['id']);
        $row = array_merge($row, $freeze, [
            'dispo_order_id' => $orderId,
            'sort' => ((int) ($row['sort'] ?? 0)) + 100,
            'calculation_position_id' => null,
            'effective_configuration_snapshot_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return (int) DB::table('dispo_order_positions')->insertGetId($row);
    }

    /**
     * @param  array<string, mixed>  $freeze
     */
    private function assertUpdateRejected(string $table, int $id, array $freeze): void
    {
        try {
            DB::table($table)->where('id', $id)->update($freeze);
            $this->fail("Erwartete QueryException bei Freeze-Update auf {$table}#{$id}.");
        } catch (QueryException) {
            // erwartet
        }
    }

    private function assertNullFreeze(string $table, int $id): void
    {
        $row = DB::table($table)->where('id', $id)->first();
        $this->assertNotNull($row);
        $this->assertNull($row->engine_profile_key);
        $this->assertNull($row->calculation_method_key);
        $this->assertNull($row->calculation_method_name);
        $this->assertNull($row->algorithm_version);
    }

    private function assertValidFreeze(string $table, int $id): void
    {
        $row = DB::table($table)->where('id', $id)->first();
        $expected = $this->validFreeze();
        $this->assertNotNull($row);
        $this->assertSame($expected['engine_profile_key'], $row->engine_profile_key);
        $this->assertSame($expected['calculation_method_key'], $row->calculation_method_key);
        $this->assertSame($expected['calculation_method_name'], $row->calculation_method_name);
        $this->assertSame($expected['algorithm_version'], $row->algorithm_version);
    }

    private function reinstallFreezeGuardsViaMigrationUp(): void
    {
        $migration = require database_path('migrations/2026_09_10_100000_adv001c2_dual_read_write_and_engine_freeze.php');
        $migration->up();
    }

    private function dropFreezeGuardsForTest(string $table): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            DB::statement("DROP TRIGGER IF EXISTS {$table}_calc_method_freeze_update");
            DB::statement("DROP TRIGGER IF EXISTS {$table}_calc_method_freeze_insert");

            return;
        }

        if ($driver === 'mysql') {
            $constraint = $table.'_calc_method_freeze_chk';
            $schema = Schema::getConnection()->getDatabaseName();
            $exists = DB::table('information_schema.TABLE_CONSTRAINTS')
                ->where('CONSTRAINT_SCHEMA', $schema)
                ->where('TABLE_NAME', $table)
                ->where('CONSTRAINT_NAME', $constraint)
                ->where('CONSTRAINT_TYPE', 'CHECK')
                ->exists();

            if (! $exists) {
                return;
            }

            $version = (string) (DB::selectOne('select version() as v')->v ?? '');
            $isMariaDb = str_contains(strtolower($version), 'mariadb');
            if ($isMariaDb) {
                DB::statement("ALTER TABLE {$table} DROP CONSTRAINT {$constraint}");
            } else {
                DB::statement("ALTER TABLE {$table} DROP CHECK {$constraint}");
            }
        }
    }
}
