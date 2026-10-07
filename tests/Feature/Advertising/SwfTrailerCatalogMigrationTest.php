<?php

namespace Tests\Feature\Advertising;

use App\Enums\CalculationKind;
use App\Enums\Role;
use App\Models\AdvertisingCategory;
use App\Models\AdvertisingCategoryCalculationMethod;
use App\Models\AdvertisingMedium;
use App\Models\CalculationMethod;
use App\Models\InventoryMediumRule;
use App\Models\User;
use App\Support\Advertising\AdvertisingKindCategoryCompatibility;
use App\Support\Advertising\CanonicalAdvertisingCategories;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\Concerns\CreatesTrailerAverageCatalog;
use Tests\TestCase;

/**
 * BL-P5-01a: Migration (nullable Aufschlag, Trailer-kind, Trailer-Regeln NULL, Kategorie-Zuordnung).
 */
class SwfTrailerCatalogMigrationTest extends TestCase
{
    use CreatesTrailerAverageCatalog;
    use RefreshDatabase;

    private const MIGRATION = 'migrations/2026_10_07_120000_bl_p5_01a_swf_trailer_catalog_and_nullable_surcharge.php';

    private function migration(): object
    {
        return require database_path(self::MIGRATION);
    }

    public function test_surcharge_column_is_nullable_without_default(): void
    {
        $column = collect(Schema::getColumns('inventory_medium_rules'))->firstWhere('name', 'surcharge_percent');
        $this->assertTrue((bool) $column['nullable']);
        $this->assertNull($column['default']);

        $catalog = $this->createTrailerAverageCatalog();
        $catalog['ruleA']->update(['surcharge_percent' => null]);
        $this->assertNull($catalog['ruleA']->fresh()->surcharge_percent);

        $catalog['ruleA']->update(['surcharge_percent' => '0']);
        $this->assertSame('0.0000', $catalog['ruleA']->fresh()->surcharge_percent);
    }

    public function test_up_nulls_trailer_rules_sets_kind_and_assigns_category_but_keeps_spot_rules(): void
    {
        $catalog = $this->createTrailerAverageCatalog();
        $migration = $this->migration();
        $migration->down();

        // Legacy-Zustand vor BL-P5-01a: Trailer kind=NULL, Regeln mit Länge 30 / Aufschlag 0.
        $this->assertNull(AdvertisingMedium::query()->whereKey($catalog['trailer']->id)->value('kind'));
        $this->assertSame(
            0,
            AdvertisingCategoryCalculationMethod::query()
                ->where('engine_profile_key', 'swf_trailer')
                ->count(),
        );
        InventoryMediumRule::query()
            ->where('advertising_medium_id', $catalog['trailer']->id)
            ->update(['default_length_seconds' => 30, 'surcharge_percent' => 0]);
        InventoryMediumRule::query()
            ->where('advertising_medium_id', $catalog['spot']->id)
            ->update(['default_length_seconds' => 30, 'surcharge_percent' => 0]);
        InventoryMediumRule::query()
            ->where('advertising_medium_id', $catalog['otherSwf']->id)
            ->update(['default_length_seconds' => 30, 'surcharge_percent' => 0]);

        $migration->up();

        $this->assertSame(
            CalculationKind::SwfTrailer,
            AdvertisingMedium::query()->findOrFail($catalog['trailer']->id)->kind,
        );
        $this->assertNull(AdvertisingMedium::query()->findOrFail($catalog['otherSwf']->id)->kind);
        $this->assertSame(
            CalculationKind::SpotClassic,
            AdvertisingMedium::query()->findOrFail($catalog['spot']->id)->kind,
        );

        foreach (InventoryMediumRule::query()->where('advertising_medium_id', $catalog['trailer']->id)->get() as $rule) {
            $this->assertNull($rule->surcharge_percent);
            $this->assertNull($rule->default_length_seconds);
        }
        foreach (InventoryMediumRule::query()->where('advertising_medium_id', $catalog['spot']->id)->get() as $rule) {
            $this->assertSame('0.0000', $rule->surcharge_percent);
            $this->assertSame(30, $rule->default_length_seconds);
        }
        foreach (InventoryMediumRule::query()->where('advertising_medium_id', $catalog['otherSwf']->id)->get() as $rule) {
            $this->assertSame('0.0000', $rule->surcharge_percent);
            $this->assertSame(30, $rule->default_length_seconds);
        }

        $category = AdvertisingCategory::query()
            ->where('key', CanonicalAdvertisingCategories::SPECIAL_ADVERTISING_FORMATS)
            ->firstOrFail();
        $average = CalculationMethod::query()->where('key', 'average')->firstOrFail();
        $assignment = AdvertisingCategoryCalculationMethod::query()
            ->where('advertising_category_id', $category->id)
            ->get();
        $this->assertCount(1, $assignment);
        $this->assertSame($average->id, (int) $assignment[0]->calculation_method_id);
        $this->assertSame('swf_trailer', $assignment[0]->engine_profile_key);
        $this->assertSame($average->id, (int) $category->fresh()->default_calculation_method_id);

        // Idempotent.
        $migration->up();
        $this->assertSame(
            1,
            AdvertisingCategoryCalculationMethod::query()->where('advertising_category_id', $category->id)->count(),
        );
    }

    public function test_up_without_trailer_medium_leaves_category_catalog_untouched(): void
    {
        $migration = $this->migration();
        $migration->down();
        $migration->up();

        $swf = AdvertisingCategory::query()
            ->where('key', CanonicalAdvertisingCategories::SPECIAL_ADVERTISING_FORMATS)
            ->firstOrFail();
        $this->assertSame(
            0,
            AdvertisingCategoryCalculationMethod::query()->where('advertising_category_id', $swf->id)->count(),
        );
        $this->assertNull($swf->fresh()->default_calculation_method_id);
    }

    public function test_down_is_refused_when_swf_trailer_positions_exist(): void
    {
        $catalog = $this->createTrailerAverageCatalog();
        $this->assertNotNull($catalog['ruleA']);
        $user = User::factory()->role(Role::Sales)->create();
        $this->actingAs($user)->post(route('calculations.store'), $this->trailerPayload([
            $this->trailerPosition($catalog['a'], $catalog['trailer'], 10),
        ]))->assertRedirect();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Rollback verweigert');
        $this->migration()->down();
    }

    public function test_kind_category_compatibility_binds_swf_trailer_to_special_formats_only(): void
    {
        $this->assertSame(
            [CanonicalAdvertisingCategories::SPECIAL_ADVERTISING_FORMATS],
            AdvertisingKindCategoryCompatibility::allowedCategoryKeysFor(CalculationKind::SwfTrailer),
        );
        $this->assertTrue(AdvertisingKindCategoryCompatibility::isCompatible(
            CalculationKind::SwfTrailer,
            CanonicalAdvertisingCategories::SPECIAL_ADVERTISING_FORMATS,
        ));
        $this->assertFalse(AdvertisingKindCategoryCompatibility::isCompatible(
            CalculationKind::SwfTrailer,
            CanonicalAdvertisingCategories::SPOTS,
        ));
        $this->assertFalse(AdvertisingKindCategoryCompatibility::isCompatible(
            CalculationKind::SpotClassic,
            CanonicalAdvertisingCategories::SPECIAL_ADVERTISING_FORMATS,
        ));
    }

    public function test_up_aborts_when_individual_trailer_values_exist_while_kind_null(): void
    {
        $catalog = $this->createTrailerAverageCatalog();
        $migration = $this->migration();
        $migration->down();

        AdvertisingMedium::query()->whereKey($catalog['trailer']->id)->update(['kind' => null]);
        InventoryMediumRule::query()
            ->where('advertising_medium_id', $catalog['trailer']->id)
            ->update(['default_length_seconds' => 20, 'surcharge_percent' => 30]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('individuellen Werten');
        $migration->up();
    }

    public function test_up_preserves_configured_trailer_values_on_rerun_after_kind_active(): void
    {
        $catalog = $this->createTrailerAverageCatalog();
        $migration = $this->migration();

        // kind bereits swf_trailer (Factory); Admin-Pflege nach Erstlauf.
        $catalog['ruleA']->update([
            'default_length_seconds' => 20,
            'surcharge_percent' => '30',
        ]);
        $catalog['ruleB']->update([
            'default_length_seconds' => 15,
            'surcharge_percent' => '0',
        ]);

        $migration->up();

        $ruleA = $catalog['ruleA']->fresh();
        $ruleB = $catalog['ruleB']->fresh();
        $this->assertSame(20, $ruleA->default_length_seconds);
        $this->assertSame('30.0000', $ruleA->surcharge_percent);
        $this->assertSame(15, $ruleB->default_length_seconds);
        $this->assertSame('0.0000', $ruleB->surcharge_percent);
    }

    public function test_down_keeps_category_default_and_only_removes_swf_trailer_assignment(): void
    {
        $catalog = $this->createTrailerAverageCatalog();
        $this->assertNotNull($catalog['trailer']);
        $migration = $this->migration();

        $category = AdvertisingCategory::query()
            ->where('key', CanonicalAdvertisingCategories::SPECIAL_ADVERTISING_FORMATS)
            ->firstOrFail();
        $average = CalculationMethod::query()->where('key', 'average')->firstOrFail();
        $this->assertSame($average->id, (int) $category->fresh()->default_calculation_method_id);
        $this->assertSame(
            1,
            AdvertisingCategoryCalculationMethod::query()
                ->where('advertising_category_id', $category->id)
                ->where('engine_profile_key', 'swf_trailer')
                ->count(),
        );

        $migration->down();

        // Default kann vor/durch Migration gesetzt sein – down() entfernt ihn nicht still.
        $this->assertSame($average->id, (int) $category->fresh()->default_calculation_method_id);
        $this->assertSame(
            0,
            AdvertisingCategoryCalculationMethod::query()
                ->where('advertising_category_id', $category->id)
                ->where('engine_profile_key', 'swf_trailer')
                ->count(),
        );
    }
}
