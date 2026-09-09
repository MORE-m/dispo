<?php

namespace Tests\Feature\Advertising;

use App\Models\AdvertisingCategoryCalculationMethod;
use App\Models\AdvertisingMedium;
use App\Models\AdvertisingMediumCalculationMethod;
use App\Models\Calculation;
use App\Models\CalculationMethod;
use App\Models\CalculationPosition;
use App\Models\DispoOrder;
use App\Models\DispoOrderPosition;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

/**
 * ADV-001c1: echte Migration up/down ohne RefreshDatabase-Transaktion
 * (SQLite kann FK-PRAGMA sonst nicht umschalten).
 */
class CalculationMethodFoundationMigrationTest extends TestCase
{
    use DatabaseMigrations;

    /**
     * @var list<string>
     */
    private const EXPECTED_METHOD_KEYS = [
        'average',
        'calendar',
        'fixed_price',
        'tkp',
        'free_position',
    ];

    public function test_upgrade_from_pre_c1_schema_sets_inherit_without_assignments(): void
    {
        $medium = AdvertisingMedium::factory()->create(['code' => 'spot_classic_upgrade_c1']);
        $mediumId = (int) $medium->id;
        $kindBefore = (string) DB::table('advertising_media')->where('id', $mediumId)->value('kind');

        $calculationCount = Calculation::query()->count();
        $positionCount = CalculationPosition::query()->count();
        $dispoCount = DispoOrder::query()->count();
        $dispoPositionCount = DispoOrderPosition::query()->count();
        $snapshotCount = Schema::hasTable('configuration_snapshots')
            ? (int) DB::table('configuration_snapshots')->count()
            : 0;

        /** @var object{up: callable, down: callable} $migration */
        $migration = require database_path('migrations/2026_09_09_160000_create_calculation_method_foundation_tables.php');
        $migration->down();

        $this->assertFalse(Schema::hasTable('calculation_methods'));
        $this->assertFalse(Schema::hasColumn('advertising_media', 'calculation_method_mode'));
        $this->assertFalse(Schema::hasColumn('advertising_media', 'default_calculation_method_id'));
        $this->assertTrue(Schema::hasColumn('advertising_media', 'kind'));

        $preUpgrade = DB::table('advertising_media')->where('id', $mediumId)->first();
        $this->assertNotNull($preUpgrade);
        $this->assertSame('spot_classic', $preUpgrade->kind);
        $this->assertSame($kindBefore, $preUpgrade->kind);

        $migration->up();

        $upgraded = DB::table('advertising_media')->where('id', $mediumId)->first();
        $this->assertNotNull($upgraded);
        $this->assertSame($mediumId, (int) $upgraded->id);
        $this->assertSame('spot_classic', $upgraded->kind);
        $this->assertSame('inherit', $upgraded->calculation_method_mode);
        $this->assertNull($upgraded->default_calculation_method_id);

        $this->assertSame(
            self::EXPECTED_METHOD_KEYS,
            CalculationMethod::query()->orderBy('sort')->pluck('key')->all(),
        );
        $this->assertSame(0, AdvertisingCategoryCalculationMethod::query()->count());
        $this->assertSame(0, AdvertisingMediumCalculationMethod::query()->count());

        $this->assertSame($calculationCount, Calculation::query()->count());
        $this->assertSame($positionCount, CalculationPosition::query()->count());
        $this->assertSame($dispoCount, DispoOrder::query()->count());
        $this->assertSame($dispoPositionCount, DispoOrderPosition::query()->count());
        if (Schema::hasTable('configuration_snapshots')) {
            $this->assertSame($snapshotCount, (int) DB::table('configuration_snapshots')->count());
        }
    }

    public function test_migration_down_up_roundtrip_restores_schema_and_guards(): void
    {
        $medium = AdvertisingMedium::factory()->create(['code' => 'spot_classic_roundtrip_c1']);
        $mediumId = (int) $medium->id;
        $mediumCode = (string) $medium->code;

        /** @var object{up: callable, down: callable} $migration */
        $migration = require database_path('migrations/2026_09_09_160000_create_calculation_method_foundation_tables.php');

        $migration->down();
        $this->assertFalse(Schema::hasTable('calculation_methods'));
        $this->assertFalse(Schema::hasTable('advertising_category_calculation_methods'));
        $this->assertFalse(Schema::hasTable('advertising_medium_calculation_methods'));
        $this->assertFalse(Schema::hasColumn('advertising_media', 'calculation_method_mode'));
        $this->assertFalse(Schema::hasColumn('advertising_media', 'default_calculation_method_id'));
        $this->assertFalse(Schema::hasColumn('advertising_categories', 'default_calculation_method_id'));
        $this->assertTrue(Schema::hasColumn('advertising_media', 'kind'));
        $this->assertFalse($this->sqliteModeTriggersExist());
        $this->assertFalse($this->mysqlModeCheckExists());

        $preserved = DB::table('advertising_media')->where('id', $mediumId)->first();
        $this->assertNotNull($preserved);
        $this->assertSame($mediumCode, $preserved->code);
        $this->assertSame('spot_classic', $preserved->kind);
        $this->assertKindColumnNotNullable();

        $migration->up();
        $this->assertSame(
            self::EXPECTED_METHOD_KEYS,
            CalculationMethod::query()->orderBy('sort')->pluck('key')->all(),
        );
        $this->assertCount(5, CalculationMethod::query()->get());

        $restored = DB::table('advertising_media')->where('id', $mediumId)->first();
        $this->assertSame('inherit', $restored->calculation_method_mode);
        $this->assertNull($restored->default_calculation_method_id);

        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            $this->assertTrue($this->sqliteModeTriggersExist());
        }
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            $this->assertTrue($this->mysqlModeCheckExists());
        }

        $this->expectException(QueryException::class);
        DB::table('advertising_media')->where('id', $mediumId)->update([
            'calculation_method_mode' => 'merge',
        ]);
    }

    public function test_partial_schema_state_is_fail_closed(): void
    {
        /** @var object{up: callable, down: callable} $migration */
        $migration = require database_path('migrations/2026_09_09_160000_create_calculation_method_foundation_tables.php');
        $migration->down();

        Schema::create('calculation_methods', function ($table): void {
            $table->id();
            $table->string('key', 64)->unique();
            $table->string('name');
            $table->timestamps();
        });

        $caught = null;
        try {
            try {
                $migration->up();
                $this->fail('Erwartete RuntimeException bei unvollständigem Schema-Zustand.');
            } catch (RuntimeException $exception) {
                $caught = $exception;
                $this->assertStringContainsString('unvollständiger Schema-Zustand', $exception->getMessage());
            }
        } finally {
            Schema::dropIfExists('calculation_methods');
            Schema::dropIfExists('advertising_category_calculation_methods');
            Schema::dropIfExists('advertising_medium_calculation_methods');
            $migration->up();
        }

        $this->assertInstanceOf(RuntimeException::class, $caught);
        $this->assertTrue(Schema::hasTable('calculation_methods'));
        $this->assertTrue(Schema::hasColumn('advertising_media', 'calculation_method_mode'));
    }

    private function assertKindColumnNotNullable(): void
    {
        $column = collect(Schema::getColumns('advertising_media'))
            ->firstWhere('name', 'kind');
        $this->assertNotNull($column);
        $this->assertFalse($column['nullable'], 'advertising_media.kind muss NOT NULL bleiben.');
    }

    private function sqliteModeTriggersExist(): bool
    {
        if (Schema::getConnection()->getDriverName() !== 'sqlite') {
            return false;
        }

        $rows = DB::select(
            "SELECT name FROM sqlite_master WHERE type = 'trigger' AND name LIKE 'advertising_media_calc_method_mode_%'",
        );

        return count($rows) >= 2;
    }

    private function mysqlModeCheckExists(): bool
    {
        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return false;
        }

        $database = Schema::getConnection()->getDatabaseName();

        return DB::table('information_schema.TABLE_CONSTRAINTS')
            ->where('CONSTRAINT_SCHEMA', $database)
            ->where('TABLE_NAME', 'advertising_media')
            ->where('CONSTRAINT_NAME', 'advertising_media_calc_method_mode_chk')
            ->where('CONSTRAINT_TYPE', 'CHECK')
            ->exists();
    }
}
