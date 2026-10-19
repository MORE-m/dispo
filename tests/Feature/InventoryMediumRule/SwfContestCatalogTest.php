<?php

namespace Tests\Feature\InventoryMediumRule;

use App\Enums\CalculationKind;
use App\Enums\CalculationMethodMode;
use App\Models\AdvertisingCategoryCalculationMethod;
use App\Models\AdvertisingMedium;
use App\Models\AdvertisingMediumCalculationMethod;
use App\Models\InventoryMediumRule;
use App\Services\InventoryMediumRule\Catalog\InitialCatalogBootstrapper;
use App\Services\InventoryMediumRule\Import\CombinationMatrixImporter;
use App\Support\Advertising\AdvertisingMediumLiveBookability;
use App\Support\Calculation\EngineProfileRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PO-SWF-CONTEST-1: Katalog, Override und Matrix 25/50.
 */
class SwfContestCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_bootstrap_assigns_contest_override_and_keeps_trailer_category_profile(): void
    {
        app(InitialCatalogBootstrapper::class)->bootstrap();
        app(InitialCatalogBootstrapper::class)->bootstrap();

        $contest = AdvertisingMedium::query()->where('code', 'contest_payoff')->firstOrFail();
        $this->assertSame(CalculationKind::SwfContest, $contest->kind);
        $this->assertSame(CalculationMethodMode::Override, $contest->calculation_method_mode);

        $assignments = AdvertisingMediumCalculationMethod::query()
            ->where('advertising_medium_id', $contest->id)
            ->with('calculationMethod')
            ->get();
        $this->assertCount(1, $assignments);
        $this->assertSame('average', $assignments[0]->calculationMethod?->key);
        $this->assertSame(EngineProfileRegistry::PROFILE_SWF_CONTEST, $assignments[0]->engine_profile_key);

        $trailer = AdvertisingMedium::query()->where('code', 'trailer_station_voice')->firstOrFail();
        $this->assertSame(CalculationKind::SwfTrailer, $trailer->kind);
        $this->assertSame(CalculationMethodMode::Inherit, $trailer->calculation_method_mode);

        $categoryProfile = AdvertisingCategoryCalculationMethod::query()
            ->where('advertising_category_id', $trailer->category_id)
            ->value('engine_profile_key');
        $this->assertSame(EngineProfileRegistry::PROFILE_SWF_TRAILER, $categoryProfile);

        $promo = AdvertisingMedium::query()->where('code', 'promo_moderation')->firstOrFail();
        $this->assertSame(CalculationKind::SwfPromo, $promo->kind);

        $bookability = app(AdvertisingMediumLiveBookability::class);
        $this->assertTrue($bookability->evaluate($contest)->isBookableForNewPositions);
        $this->assertSame('average', $bookability->evaluate($contest)->calculationMethodKey);
        $this->assertSame(EngineProfileRegistry::PROFILE_SWF_CONTEST, $bookability->evaluate($contest)->engineProfileKey);
    }

    public function test_matrix_create_uses_25_50_and_reimport_keeps_admin_values(): void
    {
        app(InitialCatalogBootstrapper::class)->bootstrap();
        app(CombinationMatrixImporter::class)->import();

        $contest = AdvertisingMedium::query()->where('code', 'contest_payoff')->firstOrFail();
        $rules = InventoryMediumRule::query()
            ->where('advertising_medium_id', $contest->id)
            ->with('inventory')
            ->get();

        $this->assertEqualsCanonicalizing(
            ['Radio Hamburg', 'ROCK ANTENNE Hamburg', '80er 90er OLDIE ANTENNE Hamburg', 'CARAVAN.fm'],
            $rules->pluck('inventory.name')->all(),
        );
        $this->assertCount(4, $rules);
        foreach ($rules as $rule) {
            $this->assertSame(25, $rule->default_length_seconds, $rule->inventory->name);
            $this->assertSame('50.0000', $rule->surcharge_percent, $rule->inventory->name);
        }

        $rule = $rules->first();
        $this->assertNotNull($rule);
        $rule->default_length_seconds = 30;
        $rule->surcharge_percent = '55';
        $rule->booking_code = 'TEMP';
        $rule->save();

        app(CombinationMatrixImporter::class)->import();
        $fresh = $rule->fresh();
        $this->assertNotNull($fresh);
        $this->assertSame(30, $fresh->default_length_seconds);
        $this->assertSame('55.0000', $fresh->surcharge_percent);
        $this->assertNotSame('TEMP', $fresh->booking_code);
    }
}
