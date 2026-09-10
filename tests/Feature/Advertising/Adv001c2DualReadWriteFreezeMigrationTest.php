<?php

namespace Tests\Feature\Advertising;

use App\Models\AdvertisingCategory;
use App\Models\AdvertisingCategoryCalculationMethod;
use App\Models\AdvertisingMedium;
use App\Models\AdvertisingMediumCalculationMethod;
use App\Models\Calculation;
use App\Models\CalculationMethod;
use App\Models\CalculationPosition;
use App\Models\DispoOrder;
use App\Models\DispoOrderPosition;
use App\Support\Advertising\CanonicalAdvertisingCategories;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

/**
 * ADV-001c2: echte Migration up/down ohne RefreshDatabase-Transaktion
 * (SQLite kann FK-PRAGMA sonst nicht umschalten).
 */
class Adv001c2DualReadWriteFreezeMigrationTest extends TestCase
{
    use DatabaseMigrations;

    private const MIGRATION = 'migrations/2026_09_10_100000_adv001c2_dual_read_write_and_engine_freeze.php';

    /**
     * @return object{up: callable, down: callable}
     */
    private function migration(): object
    {
        return require database_path(self::MIGRATION);
    }

    public function test_after_migrations_known_spot_positions_are_backfilled(): void
    {
        $this->assertTrue(Schema::hasColumn('calculation_positions', 'engine_profile_key'));
        $this->assertTrue(Schema::hasColumn('dispo_order_positions', 'algorithm_version'));

        $kindColumn = collect(Schema::getColumns('advertising_media'))->firstWhere('name', 'kind');
        $this->assertTrue((bool) ($kindColumn['nullable'] ?? false));

        $spots = AdvertisingCategory::query()
            ->where('key', CanonicalAdvertisingCategories::SPOTS)
            ->firstOrFail();

        $assignments = AdvertisingCategoryCalculationMethod::query()
            ->where('advertising_category_id', $spots->id)
            ->with('calculationMethod')
            ->get()
            ->sortBy(fn ($row) => $row->calculationMethod?->sort)
            ->values();

        $this->assertCount(3, $assignments);
        $this->assertSame(
            ['average', 'calendar', 'fixed_price'],
            $assignments->map(fn ($row) => $row->calculationMethod?->key)->all(),
        );
        foreach ($assignments as $assignment) {
            $this->assertSame('spot_classic', $assignment->engine_profile_key);
            $this->assertTrue($assignment->is_active);
        }
        $this->assertSame('average', $spots->defaultCalculationMethod?->key);
        $this->assertSame(0, AdvertisingMediumCalculationMethod::query()->count());
        $this->assertSame(
            0,
            AdvertisingCategoryCalculationMethod::query()
                ->where('advertising_category_id', '!=', $spots->id)
                ->count(),
        );

        $medium = AdvertisingMedium::factory()->create(['code' => 'spot_c2_inherit_check']);
        $this->assertSame('inherit', (string) $medium->calculation_method_mode->value);
    }

    public function test_legacy_position_created_before_c2_is_backfilled_on_up(): void
    {
        $migration = $this->migration();
        $position = CalculationPosition::factory()->create([
            'kind' => 'spot_classic',
            'spot_method' => 'average',
        ]);
        $positionId = (int) $position->id;

        $calculationCount = Calculation::query()->count();
        $positionCount = CalculationPosition::query()->count();
        $dispoCount = DispoOrder::query()->count();
        $dispoPositionCount = DispoOrderPosition::query()->count();
        $snapshotCount = Schema::hasTable('configuration_snapshots')
            ? (int) DB::table('configuration_snapshots')->count()
            : 0;

        $migration->down();
        $this->assertFalse(Schema::hasColumn('calculation_positions', 'engine_profile_key'));

        $legacy = DB::table('calculation_positions')->where('id', $positionId)->first();
        $this->assertNotNull($legacy);
        $this->assertSame('spot_classic', $legacy->kind);
        $this->assertSame('average', $legacy->spot_method);

        $migration->up();

        $backfilled = DB::table('calculation_positions')->where('id', $positionId)->first();
        $this->assertSame('spot_classic', $backfilled->engine_profile_key);
        $this->assertSame('average', $backfilled->calculation_method_key);
        $this->assertSame('Durchschnitt', $backfilled->calculation_method_name);
        $this->assertSame('v1', $backfilled->algorithm_version);

        $this->assertSame($calculationCount, Calculation::query()->count());
        $this->assertSame($positionCount, CalculationPosition::query()->count());
        $this->assertSame($dispoCount, DispoOrder::query()->count());
        $this->assertSame($dispoPositionCount, DispoOrderPosition::query()->count());
        if (Schema::hasTable('configuration_snapshots')) {
            $this->assertSame($snapshotCount, (int) DB::table('configuration_snapshots')->count());
        }
    }

    public function test_counts_unchanged_through_up_after_down(): void
    {
        CalculationPosition::factory()->create([
            'kind' => 'spot_classic',
            'spot_method' => 'average',
        ]);

        $calculationCount = Calculation::query()->count();
        $positionCount = CalculationPosition::query()->count();
        $dispoCount = DispoOrder::query()->count();
        $dispoPositionCount = DispoOrderPosition::query()->count();
        $snapshotCount = Schema::hasTable('configuration_snapshots')
            ? (int) DB::table('configuration_snapshots')->count()
            : 0;

        $migration = $this->migration();
        $migration->down();
        $migration->up();

        $this->assertSame($calculationCount, Calculation::query()->count());
        $this->assertSame($positionCount, CalculationPosition::query()->count());
        $this->assertSame($dispoCount, DispoOrder::query()->count());
        $this->assertSame($dispoPositionCount, DispoOrderPosition::query()->count());
        if (Schema::hasTable('configuration_snapshots')) {
            $this->assertSame($snapshotCount, (int) DB::table('configuration_snapshots')->count());
        }
    }

    public function test_unknown_legacy_combo_fails_up_before_mutating(): void
    {
        $migration = $this->migration();
        $position = CalculationPosition::factory()->create([
            'kind' => 'spot_classic',
            'spot_method' => 'average',
        ]);
        $positionId = (int) $position->id;

        $migration->down();
        $this->assertFalse(Schema::hasColumn('calculation_positions', 'engine_profile_key'));

        DB::table('calculation_positions')->where('id', $positionId)->update([
            'spot_method' => 'calendar',
        ]);

        $hadFreezeColumnsBefore = Schema::hasColumn('calculation_positions', 'engine_profile_key');
        $this->assertFalse($hadFreezeColumnsBefore);

        $caught = null;
        try {
            try {
                $migration->up();
                $this->fail('Erwartete RuntimeException bei unbekannter Legacy-Kombination.');
            } catch (RuntimeException $exception) {
                $caught = $exception;
                $this->assertStringContainsString('unbekannte Legacy-Kombination', $exception->getMessage());
                $this->assertStringContainsString('Keine stille Abbildung', $exception->getMessage());
            }
        } finally {
            DB::table('calculation_positions')->where('id', $positionId)->delete();
            if (! Schema::hasColumn('calculation_positions', 'engine_profile_key')) {
                $migration->up();
            }
        }

        $this->assertInstanceOf(RuntimeException::class, $caught);
        $this->assertTrue(Schema::hasColumn('calculation_positions', 'engine_profile_key'));
        $this->assertTrue(Schema::hasColumn('advertising_media', 'kind'));
    }

    public function test_partial_freeze_is_rejected_on_up(): void
    {
        $migration = $this->migration();
        $position = CalculationPosition::factory()->create([
            'kind' => 'spot_classic',
            'spot_method' => 'average',
            'engine_profile_key' => 'spot_classic',
            'calculation_method_key' => 'average',
            'calculation_method_name' => 'Durchschnitt',
            'algorithm_version' => 'v1',
        ]);
        $positionId = (int) $position->id;

        $this->dropFreezeGuardsForTest('calculation_positions');

        DB::table('calculation_positions')->where('id', $positionId)->update([
            'engine_profile_key' => 'spot_classic',
            'calculation_method_key' => 'average',
            'calculation_method_name' => null,
            'algorithm_version' => 'v1',
        ]);

        $caught = null;
        try {
            try {
                $migration->up();
                $this->fail('Erwartete RuntimeException bei partiellem Freeze.');
            } catch (RuntimeException $exception) {
                $caught = $exception;
                $this->assertStringContainsString('partielle Freeze-Felder', $exception->getMessage());
            }
        } finally {
            $this->dropFreezeGuardsForTest('calculation_positions');
            DB::table('calculation_positions')->where('id', $positionId)->update([
                'engine_profile_key' => 'spot_classic',
                'calculation_method_key' => 'average',
                'calculation_method_name' => 'Durchschnitt',
                'algorithm_version' => 'v1',
            ]);
            $migration->up();
        }

        $this->assertInstanceOf(RuntimeException::class, $caught);
    }

    public function test_up_down_up_roundtrip_restores_schema_and_catalog(): void
    {
        $migration = $this->migration();
        $position = CalculationPosition::factory()->create([
            'kind' => 'spot_classic',
            'spot_method' => 'average',
        ]);
        $positionId = (int) $position->id;
        $medium = AdvertisingMedium::factory()->create(['code' => 'spot_c2_roundtrip']);

        $migration->down();
        $this->assertFalse(Schema::hasColumn('calculation_positions', 'engine_profile_key'));
        $this->assertFalse(Schema::hasColumn('dispo_order_positions', 'algorithm_version'));
        $this->assertSame(
            0,
            AdvertisingCategoryCalculationMethod::query()->count(),
        );
        $spots = AdvertisingCategory::query()
            ->where('key', CanonicalAdvertisingCategories::SPOTS)
            ->firstOrFail();
        $this->assertNull($spots->default_calculation_method_id);

        $kindColumnDown = collect(Schema::getColumns('advertising_media'))->firstWhere('name', 'kind');
        $this->assertFalse((bool) ($kindColumnDown['nullable'] ?? false));

        $migration->up();

        $this->assertTrue(Schema::hasColumn('calculation_positions', 'engine_profile_key'));
        $backfilled = DB::table('calculation_positions')->where('id', $positionId)->first();
        $this->assertSame('Durchschnitt', $backfilled->calculation_method_name);
        $this->assertSame('v1', $backfilled->algorithm_version);

        $spots->refresh();
        $this->assertSame('average', $spots->defaultCalculationMethod?->key);
        $this->assertCount(3, $spots->calculationMethodAssignments);

        $kindColumnUp = collect(Schema::getColumns('advertising_media'))->firstWhere('name', 'kind');
        $this->assertTrue((bool) ($kindColumnUp['nullable'] ?? false));
        $this->assertNotNull(DB::table('advertising_media')->where('id', $medium->id)->value('kind'));

        $migration->down();
        $migration->up();

        $again = DB::table('calculation_positions')->where('id', $positionId)->first();
        $this->assertSame('spot_classic', $again->engine_profile_key);
        $this->assertSame('average', $again->calculation_method_key);
    }

    public function test_down_refuses_when_kind_null_media_exist(): void
    {
        $migration = $this->migration();
        $medium = AdvertisingMedium::factory()->create(['code' => 'spot_c2_null_kind']);

        DB::table('advertising_media')->where('id', $medium->id)->update(['kind' => null]);

        $caught = null;
        try {
            try {
                $migration->down();
                $this->fail('Erwartete RuntimeException bei kind=NULL.');
            } catch (RuntimeException $exception) {
                $caught = $exception;
                $this->assertStringContainsString('kind=NULL', $exception->getMessage());
            }
        } finally {
            DB::table('advertising_media')->where('id', $medium->id)->update(['kind' => 'spot_classic']);
        }

        $this->assertInstanceOf(RuntimeException::class, $caught);
        $this->assertTrue(Schema::hasColumn('calculation_positions', 'engine_profile_key'));
    }

    public function test_down_refuses_when_spot_assignment_engine_profile_key_altered(): void
    {
        $migration = $this->migration();
        $spots = AdvertisingCategory::query()
            ->where('key', CanonicalAdvertisingCategories::SPOTS)
            ->firstOrFail();
        $average = CalculationMethod::query()->where('key', 'average')->firstOrFail();

        $assignment = AdvertisingCategoryCalculationMethod::query()
            ->where('advertising_category_id', $spots->id)
            ->where('calculation_method_id', $average->id)
            ->firstOrFail();

        DB::table('advertising_category_calculation_methods')
            ->where('id', $assignment->id)
            ->update(['engine_profile_key' => 'altered_profile']);

        $caught = null;
        try {
            try {
                $migration->down();
                $this->fail('Erwartete RuntimeException bei veränderter Spot-Zuordnung.');
            } catch (RuntimeException $exception) {
                $caught = $exception;
                $this->assertStringContainsString('nachträglich verändert', $exception->getMessage());
            }
        } finally {
            DB::table('advertising_category_calculation_methods')
                ->where('id', $assignment->id)
                ->update(['engine_profile_key' => 'spot_classic']);
        }

        $this->assertInstanceOf(RuntimeException::class, $caught);
        $this->assertTrue(Schema::hasColumn('calculation_positions', 'engine_profile_key'));
    }

    public function test_preexisting_exact_spot_assignment_rejected_before_ddl(): void
    {
        $migration = $this->migration();
        $migration->down();
        $this->assertFalse(Schema::hasColumn('calculation_positions', 'engine_profile_key'));

        $spotsId = (int) AdvertisingCategory::query()
            ->where('key', CanonicalAdvertisingCategories::SPOTS)
            ->value('id');
        $averageId = (int) CalculationMethod::query()->where('key', 'average')->value('id');

        $assignmentId = DB::table('advertising_category_calculation_methods')->insertGetId([
            'advertising_category_id' => $spotsId,
            'calculation_method_id' => $averageId,
            'engine_profile_key' => 'spot_classic',
            'is_active' => true,
            'sort' => 10,
            'lock_version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $caught = null;
        try {
            try {
                $migration->up();
                $this->fail('Erwartete RuntimeException bei vorbestehender Spot-Zuordnung.');
            } catch (RuntimeException $exception) {
                $caught = $exception;
                $this->assertStringContainsString('vorbestehende', $exception->getMessage());
                $this->assertStringContainsString('Eigentum', $exception->getMessage());
                $this->assertFalse(Schema::hasColumn('calculation_positions', 'engine_profile_key'));
                $this->assertFalse(Schema::hasColumn('dispo_order_positions', 'algorithm_version'));
            }
        } finally {
            DB::table('advertising_category_calculation_methods')->where('id', $assignmentId)->delete();
            if (! Schema::hasColumn('calculation_positions', 'engine_profile_key')) {
                $migration->up();
            }
        }

        $this->assertInstanceOf(RuntimeException::class, $caught);
    }

    public function test_preexisting_default_average_rejected_before_ddl(): void
    {
        $migration = $this->migration();
        $migration->down();
        $this->assertFalse(Schema::hasColumn('calculation_positions', 'engine_profile_key'));

        $spotsId = (int) AdvertisingCategory::query()
            ->where('key', CanonicalAdvertisingCategories::SPOTS)
            ->value('id');
        $averageId = (int) CalculationMethod::query()->where('key', 'average')->value('id');

        DB::table('advertising_categories')->where('id', $spotsId)->update([
            'default_calculation_method_id' => $averageId,
        ]);

        $caught = null;
        try {
            try {
                $migration->up();
                $this->fail('Erwartete RuntimeException bei vorbestehendem Spot-Default.');
            } catch (RuntimeException $exception) {
                $caught = $exception;
                $this->assertStringContainsString('vorbestehende', $exception->getMessage());
                $this->assertStringContainsString('Eigentum', $exception->getMessage());
                $this->assertFalse(Schema::hasColumn('calculation_positions', 'engine_profile_key'));
                $this->assertFalse(Schema::hasColumn('dispo_order_positions', 'algorithm_version'));
            }
        } finally {
            DB::table('advertising_categories')->where('id', $spotsId)->update([
                'default_calculation_method_id' => null,
            ]);
            if (! Schema::hasColumn('calculation_positions', 'engine_profile_key')) {
                $migration->up();
            }
        }

        $this->assertInstanceOf(RuntimeException::class, $caught);
    }

    public function test_preexisting_medium_assignment_rejected_before_ddl(): void
    {
        $migration = $this->migration();
        $migration->down();
        $this->assertFalse(Schema::hasColumn('calculation_positions', 'engine_profile_key'));

        $medium = AdvertisingMedium::factory()->create(['code' => 'spot_c2_preexist_med']);
        $averageId = (int) CalculationMethod::query()->where('key', 'average')->value('id');
        $assignmentId = DB::table('advertising_medium_calculation_methods')->insertGetId([
            'advertising_medium_id' => $medium->id,
            'calculation_method_id' => $averageId,
            'engine_profile_key' => 'spot_classic',
            'is_active' => true,
            'sort' => 10,
            'lock_version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $caught = null;
        try {
            try {
                $migration->up();
                $this->fail('Erwartete RuntimeException bei vorbestehender Medium-Zuordnung.');
            } catch (RuntimeException $exception) {
                $caught = $exception;
                $this->assertStringContainsString('vorbestehende', $exception->getMessage());
                $this->assertStringContainsString('Eigentum', $exception->getMessage());
                $this->assertFalse(Schema::hasColumn('calculation_positions', 'engine_profile_key'));
                $this->assertFalse(Schema::hasColumn('dispo_order_positions', 'algorithm_version'));
            }
        } finally {
            DB::table('advertising_medium_calculation_methods')->where('id', $assignmentId)->delete();
            if (! Schema::hasColumn('calculation_positions', 'engine_profile_key')) {
                $migration->up();
            }
        }

        $this->assertInstanceOf(RuntimeException::class, $caught);
    }

    public function test_blank_whitespace_freeze_values_rejected_with_table_id(): void
    {
        $migration = $this->migration();
        $position = CalculationPosition::factory()->create([
            'kind' => 'spot_classic',
            'spot_method' => 'average',
            'engine_profile_key' => 'spot_classic',
            'calculation_method_key' => 'average',
            'calculation_method_name' => 'Durchschnitt',
            'algorithm_version' => 'v1',
        ]);
        $positionId = (int) $position->id;

        $this->dropFreezeGuardsForTest('calculation_positions');
        DB::table('calculation_positions')->where('id', $positionId)->update([
            'engine_profile_key' => 'spot_classic',
            'calculation_method_key' => 'average',
            'calculation_method_name' => 'Durchschnitt',
            'algorithm_version' => '   ',
        ]);

        $caught = null;
        try {
            try {
                $migration->up();
                $this->fail('Erwartete RuntimeException bei Whitespace-Freeze.');
            } catch (RuntimeException $exception) {
                $caught = $exception;
                $this->assertStringContainsString("calculation_positions#{$positionId}", $exception->getMessage());
                $this->assertStringContainsString('Whitespace-Freeze-Wert', $exception->getMessage());
            }
        } finally {
            $this->dropFreezeGuardsForTest('calculation_positions');
            DB::table('calculation_positions')->where('id', $positionId)->update([
                'engine_profile_key' => 'spot_classic',
                'calculation_method_key' => 'average',
                'calculation_method_name' => 'Durchschnitt',
                'algorithm_version' => 'v1',
            ]);
            $migration->up();
        }

        $this->assertInstanceOf(RuntimeException::class, $caught);
    }

    public function test_down_refuses_when_spot_assignment_missing(): void
    {
        $migration = $this->migration();
        $spots = AdvertisingCategory::query()
            ->where('key', CanonicalAdvertisingCategories::SPOTS)
            ->firstOrFail();
        $fixed = CalculationMethod::query()->where('key', 'fixed_price')->firstOrFail();
        $assignment = AdvertisingCategoryCalculationMethod::query()
            ->where('advertising_category_id', $spots->id)
            ->where('calculation_method_id', $fixed->id)
            ->firstOrFail();
        $snapshot = (array) DB::table('advertising_category_calculation_methods')->where('id', $assignment->id)->first();

        DB::table('advertising_category_calculation_methods')->where('id', $assignment->id)->delete();

        $caught = null;
        try {
            try {
                $migration->down();
                $this->fail('Erwartete RuntimeException bei fehlender Spot-Zuordnung.');
            } catch (RuntimeException $exception) {
                $caught = $exception;
                $this->assertStringContainsString('nicht genau drei Methodenzuordnungen', $exception->getMessage());
                $this->assertStringContainsString('noch keine Mutation', $exception->getMessage());
            }
        } finally {
            unset($snapshot['id']);
            DB::table('advertising_category_calculation_methods')->insert($snapshot);
        }

        $this->assertInstanceOf(RuntimeException::class, $caught);
        $this->assertTrue(Schema::hasColumn('calculation_positions', 'engine_profile_key'));
        $this->assertTrue(Schema::hasColumn('dispo_order_positions', 'algorithm_version'));
    }

    public function test_down_refuses_when_spot_default_null(): void
    {
        $migration = $this->migration();
        $spots = AdvertisingCategory::query()
            ->where('key', CanonicalAdvertisingCategories::SPOTS)
            ->firstOrFail();
        $previous = $spots->default_calculation_method_id;

        DB::table('advertising_categories')->where('id', $spots->id)->update([
            'default_calculation_method_id' => null,
        ]);

        $caught = null;
        try {
            try {
                $migration->down();
                $this->fail('Erwartete RuntimeException bei Spot-Default NULL.');
            } catch (RuntimeException $exception) {
                $caught = $exception;
                $this->assertStringContainsString('Spot-Default fehlt (NULL)', $exception->getMessage());
                $this->assertStringContainsString('noch keine Mutation', $exception->getMessage());
            }
        } finally {
            DB::table('advertising_categories')->where('id', $spots->id)->update([
                'default_calculation_method_id' => $previous,
            ]);
        }

        $this->assertInstanceOf(RuntimeException::class, $caught);
        $this->assertTrue(Schema::hasColumn('calculation_positions', 'engine_profile_key'));
    }

    public function test_down_refuses_when_spot_default_changed(): void
    {
        $migration = $this->migration();
        $spots = AdvertisingCategory::query()
            ->where('key', CanonicalAdvertisingCategories::SPOTS)
            ->firstOrFail();
        $calendar = CalculationMethod::query()->where('key', 'calendar')->firstOrFail();
        $previous = $spots->default_calculation_method_id;

        DB::table('advertising_categories')->where('id', $spots->id)->update([
            'default_calculation_method_id' => $calendar->id,
        ]);

        $caught = null;
        try {
            try {
                $migration->down();
                $this->fail('Erwartete RuntimeException bei geändertem Spot-Default.');
            } catch (RuntimeException $exception) {
                $caught = $exception;
                $this->assertStringContainsString('Spot-Default weicht von average ab', $exception->getMessage());
                $this->assertStringContainsString('noch keine Mutation', $exception->getMessage());
            }
        } finally {
            DB::table('advertising_categories')->where('id', $spots->id)->update([
                'default_calculation_method_id' => $previous,
            ]);
        }

        $this->assertInstanceOf(RuntimeException::class, $caught);
        $this->assertTrue(Schema::hasColumn('calculation_positions', 'engine_profile_key'));
    }

    public function test_down_refuses_when_spot_assignment_deactivated(): void
    {
        $migration = $this->migration();
        $spots = AdvertisingCategory::query()
            ->where('key', CanonicalAdvertisingCategories::SPOTS)
            ->firstOrFail();
        $average = CalculationMethod::query()->where('key', 'average')->firstOrFail();
        $assignment = AdvertisingCategoryCalculationMethod::query()
            ->where('advertising_category_id', $spots->id)
            ->where('calculation_method_id', $average->id)
            ->firstOrFail();

        DB::table('advertising_category_calculation_methods')
            ->where('id', $assignment->id)
            ->update(['is_active' => false]);

        $caught = null;
        try {
            try {
                $migration->down();
                $this->fail('Erwartete RuntimeException bei deaktivierter Spot-Zuordnung.');
            } catch (RuntimeException $exception) {
                $caught = $exception;
                $this->assertStringContainsString('nachträglich verändert', $exception->getMessage());
                $this->assertStringContainsString('noch keine Mutation', $exception->getMessage());
            }
        } finally {
            DB::table('advertising_category_calculation_methods')
                ->where('id', $assignment->id)
                ->update(['is_active' => true]);
        }

        $this->assertInstanceOf(RuntimeException::class, $caught);
        $this->assertTrue(Schema::hasColumn('calculation_positions', 'engine_profile_key'));
    }

    public function test_down_refuses_when_spot_assignment_sort_changed(): void
    {
        $migration = $this->migration();
        $spots = AdvertisingCategory::query()
            ->where('key', CanonicalAdvertisingCategories::SPOTS)
            ->firstOrFail();
        $average = CalculationMethod::query()->where('key', 'average')->firstOrFail();
        $assignment = AdvertisingCategoryCalculationMethod::query()
            ->where('advertising_category_id', $spots->id)
            ->where('calculation_method_id', $average->id)
            ->firstOrFail();

        DB::table('advertising_category_calculation_methods')
            ->where('id', $assignment->id)
            ->update(['sort' => 99]);

        $caught = null;
        try {
            try {
                $migration->down();
                $this->fail('Erwartete RuntimeException bei geändertem Sort.');
            } catch (RuntimeException $exception) {
                $caught = $exception;
                $this->assertStringContainsString('nachträglich verändert', $exception->getMessage());
                $this->assertStringContainsString('noch keine Mutation', $exception->getMessage());
            }
        } finally {
            DB::table('advertising_category_calculation_methods')
                ->where('id', $assignment->id)
                ->update(['sort' => 10]);
        }

        $this->assertInstanceOf(RuntimeException::class, $caught);
        $this->assertTrue(Schema::hasColumn('calculation_positions', 'engine_profile_key'));
    }

    public function test_down_refuses_when_extra_spot_assignment_exists(): void
    {
        $migration = $this->migration();
        $spots = AdvertisingCategory::query()
            ->where('key', CanonicalAdvertisingCategories::SPOTS)
            ->firstOrFail();
        $tkp = CalculationMethod::query()->where('key', 'tkp')->firstOrFail();

        $extraId = DB::table('advertising_category_calculation_methods')->insertGetId([
            'advertising_category_id' => $spots->id,
            'calculation_method_id' => $tkp->id,
            'engine_profile_key' => 'spot_classic',
            'is_active' => true,
            'sort' => 40,
            'lock_version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $caught = null;
        try {
            try {
                $migration->down();
                $this->fail('Erwartete RuntimeException bei zusätzlicher Spot-Zuordnung.');
            } catch (RuntimeException $exception) {
                $caught = $exception;
                $this->assertStringContainsString('nicht genau drei Methodenzuordnungen', $exception->getMessage());
                $this->assertStringContainsString('noch keine Mutation', $exception->getMessage());
            }
        } finally {
            DB::table('advertising_category_calculation_methods')->where('id', $extraId)->delete();
        }

        $this->assertInstanceOf(RuntimeException::class, $caught);
        $this->assertTrue(Schema::hasColumn('calculation_positions', 'engine_profile_key'));
        $this->assertTrue(Schema::hasColumn('dispo_order_positions', 'algorithm_version'));
    }

    public function test_down_refuses_extra_foreign_category_assignment_without_mutation(): void
    {
        $migration = $this->migration();
        $snapshot = $this->c2CatalogSnapshot();
        $otherCategory = AdvertisingCategory::query()
            ->where('key', '!=', CanonicalAdvertisingCategories::SPOTS)
            ->firstOrFail();
        $average = CalculationMethod::query()->where('key', 'average')->firstOrFail();
        $extraId = DB::table('advertising_category_calculation_methods')->insertGetId([
            'advertising_category_id' => $otherCategory->id,
            'calculation_method_id' => $average->id,
            'engine_profile_key' => 'spot_classic',
            'is_active' => true,
            'sort' => 10,
            'lock_version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $caught = null;
        try {
            try {
                $migration->down();
                $this->fail('Erwartete RuntimeException bei fremder Kategorie-Zuordnung.');
            } catch (RuntimeException $exception) {
                $caught = $exception;
                $this->assertTrue(
                    str_contains($exception->getMessage(), 'außerhalb von Spots')
                    || str_contains($exception->getMessage(), 'Kategorie-Methodenzuordnung'),
                    $exception->getMessage(),
                );
                $this->assertStringContainsString('noch keine Mutation', $exception->getMessage());
            }
        } finally {
            DB::table('advertising_category_calculation_methods')->where('id', $extraId)->delete();
        }

        $this->assertInstanceOf(RuntimeException::class, $caught);
        $this->assertC2CatalogUnchanged($snapshot);
    }

    public function test_down_refuses_extra_foreign_category_default_without_mutation(): void
    {
        $migration = $this->migration();
        $snapshot = $this->c2CatalogSnapshot();
        $otherCategory = AdvertisingCategory::query()
            ->where('key', '!=', CanonicalAdvertisingCategories::SPOTS)
            ->firstOrFail();
        $average = CalculationMethod::query()->where('key', 'average')->firstOrFail();
        DB::table('advertising_categories')->where('id', $otherCategory->id)->update([
            'default_calculation_method_id' => $average->id,
        ]);

        $caught = null;
        try {
            try {
                $migration->down();
                $this->fail('Erwartete RuntimeException bei fremdem Kategorie-Default.');
            } catch (RuntimeException $exception) {
                $caught = $exception;
                $this->assertTrue(
                    str_contains($exception->getMessage(), 'außerhalb von Spots')
                    || str_contains($exception->getMessage(), 'Kategorie-Default'),
                    $exception->getMessage(),
                );
                $this->assertStringContainsString('noch keine Mutation', $exception->getMessage());
            }
        } finally {
            DB::table('advertising_categories')->where('id', $otherCategory->id)->update([
                'default_calculation_method_id' => null,
            ]);
        }

        $this->assertInstanceOf(RuntimeException::class, $caught);
        $this->assertC2CatalogUnchanged($snapshot);
    }

    public function test_down_refuses_medium_default_without_mutation(): void
    {
        $migration = $this->migration();
        $snapshot = $this->c2CatalogSnapshot();
        $medium = AdvertisingMedium::factory()->create(['code' => 'c2_down_medium_default']);
        $average = CalculationMethod::query()->where('key', 'average')->firstOrFail();
        DB::table('advertising_media')->where('id', $medium->id)->update([
            'default_calculation_method_id' => $average->id,
        ]);

        $caught = null;
        try {
            try {
                $migration->down();
                $this->fail('Erwartete RuntimeException bei Medium-Default.');
            } catch (RuntimeException $exception) {
                $caught = $exception;
                $this->assertStringContainsString('Medium-Defaultmethode', $exception->getMessage());
                $this->assertStringContainsString('noch keine Mutation', $exception->getMessage());
            }
        } finally {
            DB::table('advertising_media')->where('id', $medium->id)->update([
                'default_calculation_method_id' => null,
            ]);
            $medium->delete();
        }

        $this->assertInstanceOf(RuntimeException::class, $caught);
        $this->assertC2CatalogUnchanged($snapshot);
    }

    public function test_mixed_catalog_is_not_idempotent_complete_and_blocks_up(): void
    {
        $migration = $this->migration();
        $snapshot = $this->c2CatalogSnapshot();
        $otherCategory = AdvertisingCategory::query()
            ->where('key', '!=', CanonicalAdvertisingCategories::SPOTS)
            ->firstOrFail();
        $average = CalculationMethod::query()->where('key', 'average')->firstOrFail();

        $extraId = DB::table('advertising_category_calculation_methods')->insertGetId([
            'advertising_category_id' => $otherCategory->id,
            'calculation_method_id' => $average->id,
            'engine_profile_key' => 'spot_classic',
            'is_active' => true,
            'sort' => 10,
            'lock_version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $caught = null;
        try {
            try {
                $migration->up();
                $this->fail('Erwartete RuntimeException bei gemischtem Katalogzustand.');
            } catch (RuntimeException $exception) {
                $caught = $exception;
                $this->assertTrue(
                    str_contains($exception->getMessage(), 'partiell')
                    || str_contains($exception->getMessage(), 'Eigentum')
                    || str_contains($exception->getMessage(), 'Wiederaufnahme'),
                    $exception->getMessage(),
                );
            }
        } finally {
            DB::table('advertising_category_calculation_methods')->where('id', $extraId)->delete();
            // Idempotenter Endzustand wiederherstellen falls nötig.
            if (! Schema::hasColumn('calculation_positions', 'engine_profile_key')) {
                $migration->up();
            }
        }

        $this->assertInstanceOf(RuntimeException::class, $caught);
        $this->assertC2CatalogUnchanged($snapshot);
    }

    public function test_foreign_category_default_is_not_idempotent_complete_and_blocks_up(): void
    {
        $migration = $this->migration();
        $snapshot = $this->c2CatalogSnapshot();
        $otherCategory = AdvertisingCategory::query()
            ->where('key', '!=', CanonicalAdvertisingCategories::SPOTS)
            ->firstOrFail();
        $average = CalculationMethod::query()->where('key', 'average')->firstOrFail();
        DB::table('advertising_categories')->where('id', $otherCategory->id)->update([
            'default_calculation_method_id' => $average->id,
        ]);

        $caught = null;
        try {
            try {
                $migration->up();
                $this->fail('Erwartete RuntimeException bei fremdem Kategorie-Default.');
            } catch (RuntimeException $exception) {
                $caught = $exception;
                $this->assertStringContainsString('partiell', $exception->getMessage());
            }
        } finally {
            DB::table('advertising_categories')->where('id', $otherCategory->id)->update([
                'default_calculation_method_id' => null,
            ]);
            if (! Schema::hasColumn('calculation_positions', 'engine_profile_key')) {
                $migration->up();
            }
        }

        $this->assertInstanceOf(RuntimeException::class, $caught);
        $this->assertC2CatalogUnchanged($snapshot);
    }

    public function test_medium_default_is_not_idempotent_complete_and_blocks_up(): void
    {
        $migration = $this->migration();
        $snapshot = $this->c2CatalogSnapshot();
        $medium = AdvertisingMedium::factory()->create(['code' => 'c2_up_medium_default']);
        $average = CalculationMethod::query()->where('key', 'average')->firstOrFail();
        DB::table('advertising_media')->where('id', $medium->id)->update([
            'default_calculation_method_id' => $average->id,
        ]);

        $caught = null;
        try {
            try {
                $migration->up();
                $this->fail('Erwartete RuntimeException bei Medium-Default im Endzustand.');
            } catch (RuntimeException $exception) {
                $caught = $exception;
                $this->assertStringContainsString('partiell', $exception->getMessage());
            }
        } finally {
            DB::table('advertising_media')->where('id', $medium->id)->update([
                'default_calculation_method_id' => null,
            ]);
            $medium->delete();
            if (! Schema::hasColumn('calculation_positions', 'engine_profile_key')) {
                $migration->up();
            }
        }

        $this->assertInstanceOf(RuntimeException::class, $caught);
        $this->assertC2CatalogUnchanged($snapshot);
    }

    /**
     * @return array{
     *     spot_default: int|null,
     *     assignment_count: int,
     *     spot_assignment_count: int,
     *     category_defaults: int,
     *     medium_defaults: int,
     *     medium_assignments: int,
     *     freeze_calc: bool,
     *     freeze_dispo: bool,
     *     kind_nullable: bool
     * }
     */
    private function c2CatalogSnapshot(): array
    {
        $spotsId = (int) AdvertisingCategory::query()
            ->where('key', CanonicalAdvertisingCategories::SPOTS)
            ->value('id');

        $kindColumn = collect(Schema::getColumns('advertising_media'))->firstWhere('name', 'kind');

        return [
            'spot_default' => AdvertisingCategory::query()->where('id', $spotsId)->value('default_calculation_method_id'),
            'assignment_count' => (int) DB::table('advertising_category_calculation_methods')->count(),
            'spot_assignment_count' => (int) DB::table('advertising_category_calculation_methods')
                ->where('advertising_category_id', $spotsId)
                ->count(),
            'category_defaults' => (int) DB::table('advertising_categories')
                ->whereNotNull('default_calculation_method_id')
                ->count(),
            'medium_defaults' => (int) DB::table('advertising_media')
                ->whereNotNull('default_calculation_method_id')
                ->count(),
            'medium_assignments' => (int) DB::table('advertising_medium_calculation_methods')->count(),
            'freeze_calc' => Schema::hasColumn('calculation_positions', 'engine_profile_key'),
            'freeze_dispo' => Schema::hasColumn('dispo_order_positions', 'algorithm_version'),
            'kind_nullable' => (bool) ($kindColumn['nullable'] ?? false),
        ];
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function assertC2CatalogUnchanged(array $snapshot): void
    {
        $current = $this->c2CatalogSnapshot();
        $this->assertSame($snapshot, $current);
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
