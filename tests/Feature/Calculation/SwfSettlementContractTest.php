<?php

namespace Tests\Feature\Calculation;

use App\Enums\Role;
use App\Models\User;
use App\Support\Advertising\AdvertisingMediumLiveBookability;
use App\Support\Calculation\EngineProfileRegistry;
use App\Support\PriceList\PriceListCalendar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAllongeAverageCatalog;
use Tests\Concerns\CreatesContestAverageCatalog;
use Tests\Concerns\CreatesPromoAverageCatalog;
use Tests\Concerns\CreatesTrailerAverageCatalog;
use Tests\TestCase;

/**
 * PO-SWF-SETTLEMENT-1: Contract Average/Calendar × Normal/Fixed für vier SWF-Profile.
 */
class SwfSettlementContractTest extends TestCase
{
    use CreatesAllongeAverageCatalog;
    use CreatesContestAverageCatalog;
    use CreatesPromoAverageCatalog;
    use CreatesTrailerAverageCatalog;
    use RefreshDatabase;

    public function test_registry_supports_settlement_capability_without_method_fixed_price(): void
    {
        foreach ([
            EngineProfileRegistry::PROFILE_SWF_TRAILER,
            EngineProfileRegistry::PROFILE_SWF_ALLONGE,
            EngineProfileRegistry::PROFILE_SWF_PROMO,
            EngineProfileRegistry::PROFILE_SWF_CONTEST,
        ] as $profile) {
            $this->assertTrue(EngineProfileRegistry::supportsFixedPriceSettlement($profile));
            $this->assertFalse(EngineProfileRegistry::hasMethodForProfile($profile, 'fixed_price'));
        }
    }

    public function test_trailer_average_and_calendar_fixed_settlement(): void
    {
        $catalog = $this->createTrailerAverageCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $year = PriceListCalendar::currentYear();

        $avg = $this->actingAs($user)->postJson(route('calculations.preview'), $this->trailerPayload([[
            'inventory_id' => $catalog['a']->id,
            'advertising_medium_id' => $catalog['trailer']->id,
            'spot_method' => 'average',
            'calculation_method_key' => 'average',
            'length_seconds' => 20,
            'position_discount_percent' => '0',
            'ae_percent' => '0',
            'pricing_settlement_mode' => 'fixed_price',
            'fixed_price_nn' => '400.00',
            'time_ranges' => [[
                'start_hour' => 8,
                'end_hour_exclusive' => 9,
                'day_group' => 'mo_fr',
                'spot_count' => 10,
                'sort' => 0,
            ]],
        ]]));
        $avg->assertOk();
        // Baseline 520 (10×2×20×1,3)
        $this->assertSame('520.00', $avg->json('totals.media_gross'));
        $this->assertSame('400.00', $avg->json('totals.positions.0.nn_invest'));
        $this->assertSame(100, $avg->json('totals.positions.0.length_index'));

        $cal = $this->actingAs($user)->postJson(route('calculations.preview'), $this->trailerPayload([[
            'inventory_id' => $catalog['a']->id,
            'advertising_medium_id' => $catalog['trailer']->id,
            'spot_method' => 'calendar',
            'calculation_method_key' => 'calendar',
            'length_seconds' => 20,
            'position_discount_percent' => '0',
            'ae_percent' => '0',
            'pricing_settlement_mode' => 'fixed_price',
            'fixed_price_nn' => '400.00',
            'planner_entries' => [['date' => sprintf('%04d-09-14', $year), 'hour' => 8, 'spot_count' => 10]],
        ]]));
        $cal->assertOk();
        $this->assertSame('520.00', $cal->json('totals.media_gross'));
        $this->assertSame('400.00', $cal->json('totals.positions.0.nn_invest'));
    }

    public function test_promo_fixed_settlement_bookable(): void
    {
        $promo = $this->createPromoAverageCatalog();
        $bookability = new AdvertisingMediumLiveBookability;
        $this->assertTrue($bookability->evaluate($promo['promo'], 'average')->isBookableForNewPositions);
        $this->assertTrue($bookability->evaluate($promo['promo'], 'calendar')->isBookableForNewPositions);

        $user = User::factory()->role(Role::Sales)->create();
        $preview = $this->actingAs($user)->postJson(route('calculations.preview'), $this->promoPayload([
            array_merge($this->promoPosition($promo['a'], $promo['promo'], 10), [
                'pricing_settlement_mode' => 'fixed_price',
                'fixed_price_nn' => '450.00',
            ]),
        ]));
        $preview->assertOk();
        $this->assertSame('600.00', $preview->json('totals.media_gross'));
        $this->assertSame('450.00', $preview->json('totals.positions.0.nn_invest'));
        $this->assertSame('25.0000', $preview->json('totals.positions.0.effective_discount_percent'));
    }

    public function test_contest_fixed_settlement_bookable(): void
    {
        $contest = $this->createContestAverageCatalog();
        $bookability = new AdvertisingMediumLiveBookability;
        $this->assertTrue($bookability->evaluate($contest['contest'], 'average')->isBookableForNewPositions);

        $user = User::factory()->role(Role::Sales)->create();
        $preview = $this->actingAs($user)->postJson(route('calculations.preview'), $this->contestPayload([
            array_merge($this->contestPosition($contest['a'], $contest['contest'], 10), [
                'pricing_settlement_mode' => 'fixed_price',
                'fixed_price_nn' => '500.00',
            ]),
        ]));
        $preview->assertOk();
        $this->assertSame('750.00', $preview->json('totals.media_gross'));
        $this->assertSame('500.00', $preview->json('totals.positions.0.nn_invest'));
    }

    public function test_allonge_confirm_blocks_fixed_settlement_until_confirmed(): void
    {
        $catalog = $this->createAllongeAverageCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $year = PriceListCalendar::currentYear();
        $catalog['ruleA']->update(['swf_allonge_confirmed_at' => null]);

        $baseAvg = array_merge($this->allongePosition($catalog['a'], $catalog['allonge'], 10, ['length_seconds' => 10]), [
            'pricing_settlement_mode' => 'fixed_price',
            'fixed_price_nn' => '200.00',
        ]);
        $this->actingAs($user)->postJson(route('calculations.preview'), $this->allongePayload([$baseAvg]))
            ->assertStatus(422);

        $baseCal = [
            'inventory_id' => $catalog['a']->id,
            'advertising_medium_id' => $catalog['allonge']->id,
            'spot_method' => 'calendar',
            'calculation_method_key' => 'calendar',
            'length_seconds' => 10,
            'position_discount_percent' => '0',
            'ae_percent' => '0',
            'pricing_settlement_mode' => 'fixed_price',
            'fixed_price_nn' => '200.00',
            'planner_entries' => [['date' => sprintf('%04d-09-14', $year), 'hour' => 8, 'spot_count' => 10]],
        ];
        $this->actingAs($user)->postJson(route('calculations.preview'), $this->allongePayload([$baseCal]))
            ->assertStatus(422);

        $catalog['ruleA']->update(['swf_allonge_confirmed_at' => now()]);
        $okAvg = $this->actingAs($user)->postJson(route('calculations.preview'), $this->allongePayload([$baseAvg]));
        $okAvg->assertOk();
        // 10 × 2 × 10 × 1,3 = 260
        $this->assertSame('260.00', $okAvg->json('totals.media_gross'));
        $this->assertSame('200.00', $okAvg->json('totals.positions.0.nn_invest'));

        $okCal = $this->actingAs($user)->postJson(route('calculations.preview'), $this->allongePayload([$baseCal]));
        $okCal->assertOk();
        $this->assertSame('260.00', $okCal->json('totals.media_gross'));
        $this->assertSame('200.00', $okCal->json('totals.positions.0.nn_invest'));
    }

    public function test_method_key_fixed_price_still_rejected_for_promo(): void
    {
        $catalog = $this->createPromoAverageCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $base = $this->promoPosition($catalog['a'], $catalog['promo'], 10);

        $this->actingAs($user)->postJson(route('calculations.preview'), $this->promoPayload([
            array_merge($base, [
                'spot_method' => 'fixed_price',
                'calculation_method_key' => 'fixed_price',
            ]),
        ]))->assertStatus(422);
    }

    public function test_production_still_rejected_with_settlement(): void
    {
        $catalog = $this->createPromoAverageCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $base = $this->promoPosition($catalog['a'], $catalog['promo'], 10);

        $this->actingAs($user)->postJson(route('calculations.preview'), $this->promoPayload([
            array_merge($base, [
                'pricing_settlement_mode' => 'fixed_price',
                'fixed_price_nn' => '450.00',
                'production_lines' => [[
                    'production_type' => 'spot_production',
                    'quantity' => 1,
                ]],
            ]),
        ]))->assertStatus(422);
    }

    public function test_non_operative_swf_settlement_still_rejected(): void
    {
        $catalog = $this->createTrailerAverageCatalog();
        $user = User::factory()->role(Role::Sales)->create();

        $this->actingAs($user)->postJson(route('calculations.preview'), $this->trailerPayload([
            $this->trailerPosition($catalog['a'], $catalog['otherSwf'], 10, [
                'pricing_settlement_mode' => 'fixed_price',
                'fixed_price_nn' => '400.00',
            ]),
        ]))->assertStatus(422);
    }
}
