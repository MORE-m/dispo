<?php

namespace Tests\Feature\Advertising;

use App\Enums\CalculationKind;
use App\Enums\CalculationMethodMode;
use App\Models\AdvertisingMedium;
use App\Models\AdvertisingMediumCalculationMethod;
use App\Models\CalculationPosition;
use App\Models\InventoryMediumRule;
use App\Services\InventoryMediumRule\Catalog\InitialCatalogBootstrapper;
use App\Services\InventoryMediumRule\Import\CombinationMatrixImporter;
use App\Support\Calculation\EngineProfileRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * PO-SWF-CONTEST-1: Migration setzt Kind/Override und korrigiert 30/0 → 25/50.
 */
class SwfContestMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'migrations/2026_10_19_160000_po_swf_contest_1_catalog.php';

    public function test_up_down_reup_sets_initial_values_and_leaves_them_on_down(): void
    {
        app(InitialCatalogBootstrapper::class)->bootstrap();
        app(CombinationMatrixImporter::class)->import();

        $contest = AdvertisingMedium::query()->where('code', 'contest_payoff')->firstOrFail();
        $rule = InventoryMediumRule::query()
            ->where('advertising_medium_id', $contest->id)
            ->firstOrFail();
        $this->assertSame(25, $rule->default_length_seconds);
        $this->assertSame('50.0000', $rule->surcharge_percent);
        $this->assertCount(4, InventoryMediumRule::query()->where('advertising_medium_id', $contest->id)->get());

        $migration = require database_path(self::MIGRATION);
        $migration->down();

        $rolled = AdvertisingMedium::query()->findOrFail($contest->id);
        $this->assertNull($rolled->kind);
        $this->assertSame(CalculationMethodMode::Inherit, $rolled->calculation_method_mode);
        $this->assertSame(
            0,
            AdvertisingMediumCalculationMethod::query()
                ->where('advertising_medium_id', $contest->id)
                ->count(),
        );

        // Simuliere Altzustand vor Slice: 30/0 bleibt nach down erhalten, dann auf 30/0 setzen.
        $kept = InventoryMediumRule::query()->findOrFail($rule->id);
        $this->assertSame(25, $kept->default_length_seconds);
        $kept->update(['default_length_seconds' => 30, 'surcharge_percent' => '0']);

        $migration->up();

        $activated = AdvertisingMedium::query()->findOrFail($contest->id);
        $this->assertSame(CalculationKind::SwfContest, $activated->kind);
        $this->assertSame(CalculationMethodMode::Override, $activated->calculation_method_mode);
        $this->assertSame(
            EngineProfileRegistry::PROFILE_SWF_CONTEST,
            AdvertisingMediumCalculationMethod::query()
                ->where('advertising_medium_id', $activated->id)
                ->value('engine_profile_key'),
        );

        $fixed = InventoryMediumRule::query()->findOrFail($rule->id);
        $this->assertSame(25, $fixed->default_length_seconds);
        $this->assertSame('50.0000', $fixed->surcharge_percent);

        $fixed->update(['default_length_seconds' => 30, 'surcharge_percent' => '55']);
        $migration->down();

        $afterDown = InventoryMediumRule::query()->findOrFail($rule->id);
        $this->assertSame(30, $afterDown->default_length_seconds);
        $this->assertSame('55.0000', $afterDown->surcharge_percent);
        $this->assertNull(AdvertisingMedium::query()->findOrFail($contest->id)->kind);

        $migration->up();
        $again = InventoryMediumRule::query()->findOrFail($rule->id);
        $this->assertSame(25, $again->default_length_seconds);
        $this->assertSame('50.0000', $again->surcharge_percent);
    }

    public function test_down_is_refused_when_contest_positions_exist(): void
    {
        app(InitialCatalogBootstrapper::class)->bootstrap();
        $contest = AdvertisingMedium::query()->where('code', 'contest_payoff')->firstOrFail();
        CalculationPosition::factory()->create([
            'kind' => CalculationKind::SwfContest,
            'advertising_medium_id' => $contest->id,
        ]);

        $migration = require database_path(self::MIGRATION);
        $this->expectException(RuntimeException::class);
        $migration->down();
    }
}
