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
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * PO-ALLONGE-1: Katalog, Override und Matrix-Schutz.
 */
class SwfAllongeCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_bootstrap_assigns_only_allonge_override_and_keeps_trailer_category_profile(): void
    {
        app(InitialCatalogBootstrapper::class)->bootstrap();
        app(InitialCatalogBootstrapper::class)->bootstrap();

        $allonge = AdvertisingMedium::query()->where('code', 'allonge')->firstOrFail();
        $this->assertSame(CalculationKind::SwfAllonge, $allonge->kind);
        $this->assertSame(CalculationMethodMode::Override, $allonge->calculation_method_mode);

        $assignments = AdvertisingMediumCalculationMethod::query()
            ->where('advertising_medium_id', $allonge->id)
            ->with('calculationMethod')
            ->get();
        $this->assertCount(1, $assignments);
        $this->assertSame('average', $assignments[0]->calculationMethod?->key);
        $this->assertSame(EngineProfileRegistry::PROFILE_SWF_ALLONGE, $assignments[0]->engine_profile_key);

        $trailer = AdvertisingMedium::query()->where('code', 'trailer_station_voice')->firstOrFail();
        $this->assertSame(CalculationKind::SwfTrailer, $trailer->kind);
        $this->assertSame(CalculationMethodMode::Inherit, $trailer->calculation_method_mode);

        $categoryId = $trailer->category_id;
        $categoryProfile = AdvertisingCategoryCalculationMethod::query()
            ->where('advertising_category_id', $categoryId)
            ->value('engine_profile_key');
        $this->assertSame(EngineProfileRegistry::PROFILE_SWF_TRAILER, $categoryProfile);

        $bookability = app(AdvertisingMediumLiveBookability::class);
        $this->assertTrue($bookability->evaluate($allonge)->isBookableForNewPositions);
        $this->assertSame('average', $bookability->evaluate($allonge)->calculationMethodKey);
        $promo = AdvertisingMedium::query()->where('code', 'promo_moderation')->firstOrFail();
        $this->assertTrue($bookability->evaluate($promo)->isBookableForNewPositions);
        $this->assertSame(EngineProfileRegistry::PROFILE_SWF_PROMO, $bookability->evaluate($promo)->engineProfileKey);
    }

    public function test_matrix_create_is_null_and_reimport_keeps_confirmed_values(): void
    {
        Carbon::setTestNow('2026-10-14 15:00:00');
        app(InitialCatalogBootstrapper::class)->bootstrap();
        app(CombinationMatrixImporter::class)->import();

        $allonge = AdvertisingMedium::query()->where('code', 'allonge')->firstOrFail();
        $rules = InventoryMediumRule::query()->where('advertising_medium_id', $allonge->id)->get();
        $this->assertNotEmpty($rules);
        foreach ($rules as $rule) {
            $this->assertNull($rule->default_length_seconds);
            $this->assertNull($rule->surcharge_percent);
            $this->assertNull($rule->swf_allonge_confirmed_at);
        }

        $trailer = AdvertisingMedium::query()->where('code', 'trailer_station_voice')->firstOrFail();
        $trailerRule = InventoryMediumRule::query()->where('advertising_medium_id', $trailer->id)->first();
        $this->assertNotNull($trailerRule);
        $this->assertNull($trailerRule->default_length_seconds);
        $this->assertNull($trailerRule->surcharge_percent);

        $spot = AdvertisingMedium::query()->where('code', 'spot_classic')->firstOrFail();
        $spotRule = InventoryMediumRule::query()->where('advertising_medium_id', $spot->id)->firstOrFail();
        $this->assertSame(30, $spotRule->default_length_seconds);
        $this->assertSame('0.0000', $spotRule->surcharge_percent);

        $rule = $rules->first();
        $this->assertNotNull($rule);
        $rule->default_length_seconds = 10;
        $rule->surcharge_percent = '30';
        $rule->swf_allonge_confirmed_at = now();
        $rule->booking_code = 'TEMP';
        $rule->save();

        app(CombinationMatrixImporter::class)->import();
        $fresh = $rule->fresh();
        $this->assertNotNull($fresh);
        $this->assertSame(10, $fresh->default_length_seconds);
        $this->assertSame('30.0000', $fresh->surcharge_percent);
        $this->assertSame('2026-10-14 15:00:00', $fresh->swf_allonge_confirmed_at?->format('Y-m-d H:i:s'));
        $this->assertNotSame('TEMP', $fresh->booking_code);
        Carbon::setTestNow();
    }
}
