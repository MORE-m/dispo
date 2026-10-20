<?php

namespace Tests\Feature\Calculation;

use App\Enums\CalculationKind;
use App\Enums\Role;
use App\Models\Calculation;
use App\Models\DispoOrderPosition;
use App\Models\User;
use App\Services\Calculation\CalculationWriter;
use App\Services\DispoOrder\DispoOrderWriter;
use App\Support\PriceList\PriceListCalendar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesPromoAverageCatalog;
use Tests\TestCase;

/**
 * PO-SWF-SETTLEMENT-1: Promo-Familien-Acceptance Average/Calendar × Fixed Settlement.
 */
class SwfSettlementAcceptanceTest extends TestCase
{
    use CreatesPromoAverageCatalog;
    use RefreshDatabase;

    public function test_promo_average_fixed_settlement_save_reload_dispo(): void
    {
        $catalog = $this->createPromoAverageCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $payload = $this->promoPayload([
            array_merge($this->promoPosition($catalog['a'], $catalog['promo'], 10), [
                'pricing_settlement_mode' => 'fixed_price',
                'fixed_price_nn' => '450.00',
                'ae_percent' => '15',
            ]),
        ]);
        $payload['ae_enabled'] = true;

        $preview = $this->actingAs($user)->postJson(route('calculations.preview'), $payload);
        $preview->assertOk();
        $this->assertSame('600.00', $preview->json('totals.media_gross'));
        $this->assertSame('450.00', $preview->json('totals.positions.0.nn_invest'));
        $this->assertSame('529.41', $preview->json('totals.positions.0.after_order_discount'));
        $this->assertSame('79.41', $preview->json('totals.positions.0.ae_amount'));
        $this->assertSame('25.0000', $preview->json('totals.positions.0.effective_discount_percent'));
        $this->assertSame('75.0000', $preview->json('totals.positions.0.effective_pay_factor_percent'));
        $this->assertSame(100, $preview->json('totals.positions.0.length_index'));

        $this->actingAs($user)->post(route('calculations.store'), $payload)->assertRedirect();
        $calculation = Calculation::query()->firstOrFail()->load('positions');
        $position = $calculation->positions[0];
        $this->assertSame(CalculationKind::SwfPromo, $position->kind);
        $this->assertSame('average', $position->calculation_method_key);
        $this->assertSame('fixed_price', $position->pricing_settlement_mode->value);
        $this->assertSame('450.00', (string) $position->fixed_price_nn);
        $this->assertSame('600.00', (string) $calculation->media_gross);
        $this->assertSame(20, $position->length_seconds);
        $this->assertEqualsWithDelta(50.0, (float) $position->surcharge_percent, 0.00001);

        $this->actingAs($user)->get(route('calculations.edit', $calculation))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('calculation.positions.0.pricing_settlement_mode', 'fixed_price')
                ->where('calculation.positions.0.fixed_price_nn', '450.00'));

        $dispo = app(DispoOrderWriter::class)
            ->createFromCalculation($calculation, [$position->id], $user)
            ->order
            ->positions()
            ->first();
        $this->assertInstanceOf(DispoOrderPosition::class, $dispo);
        $this->assertSame('fixed_price', $dispo->pricing_settlement_mode->value);
        $this->assertSame('450.00', (string) $dispo->fixed_price_nn);
        $this->assertSame('600.00', (string) $dispo->media_gross);
        $this->assertSame('450.00', (string) $dispo->nn_invest);
    }

    public function test_promo_calendar_fixed_settlement_planner_and_snapshot_isolation(): void
    {
        $catalog = $this->createPromoAverageCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $year = PriceListCalendar::currentYear();
        $date = sprintf('%04d-09-14', $year);
        $payload = $this->promoPayload([[
            'inventory_id' => $catalog['a']->id,
            'advertising_medium_id' => $catalog['promo']->id,
            'spot_method' => 'calendar',
            'calculation_method_key' => 'calendar',
            'length_seconds' => 20,
            'position_discount_percent' => '0',
            'ae_percent' => '0',
            'pricing_settlement_mode' => 'fixed_price',
            'fixed_price_nn' => '500.00',
            'planner_entries' => [
                ['date' => $date, 'hour' => 8, 'spot_count' => 10],
                ['date' => $date, 'hour' => 14, 'spot_count' => 5],
            ],
        ]]);

        $preview = $this->actingAs($user)->postJson(route('calculations.preview'), $payload);
        $preview->assertOk();
        // Baseline 900
        $this->assertSame('900.00', $preview->json('totals.media_gross'));
        $this->assertSame('500.00', $preview->json('totals.positions.0.nn_invest'));
        $this->assertSame(15, $preview->json('totals.positions.0.spot_count'));

        $this->actingAs($user)->post(route('calculations.store'), $payload)->assertRedirect();
        $calculation = Calculation::query()->firstOrFail()->load(['positions.plannerEntries']);
        $position = $calculation->positions[0];
        $this->assertCount(2, $position->plannerEntries);
        $this->assertSame('fixed_price', $position->pricing_settlement_mode->value);
        $this->assertSame('500.00', (string) $position->fixed_price_nn);

        $dispo = app(DispoOrderWriter::class)
            ->createFromCalculation($calculation, [$position->id], $user)
            ->order
            ->positions()
            ->firstOrFail();

        $catalog['ruleA']->update([
            'default_length_seconds' => 25,
            'surcharge_percent' => '55',
        ]);

        $writerPayload = app(CalculationWriter::class)->payloadFromCalculation(
            $calculation->fresh(['positions.plannerEntries', 'positions.planRows', 'positions.timeRanges', 'positions.discounts', 'orderDiscounts', 'configurationSnapshot', 'fieldValues']),
        );
        $writerPayload['lock_version'] = $calculation->lock_version;
        $this->actingAs($user)->put(route('calculations.update', $calculation), $writerPayload)->assertRedirect();

        $this->assertSame('900.00', (string) $calculation->fresh()->media_gross);
        $this->assertSame('500.00', (string) $calculation->fresh()->positions()->first()->fixed_price_nn);
        $this->assertSame('900.00', (string) $dispo->fresh()->media_gross);
        $this->assertSame('500.00', (string) $dispo->fresh()->fixed_price_nn);
        $this->assertEqualsWithDelta(50.0, (float) $calculation->fresh()->positions()->first()->surcharge_percent, 0.00001);

        $again = $this->actingAs($user)->postJson(route('calculations.preview'), $this->promoPayload([[
            'inventory_id' => $catalog['a']->id,
            'advertising_medium_id' => $catalog['promo']->id,
            'spot_method' => 'calendar',
            'calculation_method_key' => 'calendar',
            'length_seconds' => 25,
            'position_discount_percent' => '0',
            'ae_percent' => '0',
            'pricing_settlement_mode' => 'fixed_price',
            'fixed_price_nn' => '500.00',
            'planner_entries' => [['date' => $date, 'hour' => 8, 'spot_count' => 10]],
        ]]));
        $again->assertOk();
        // 10 × 2 × 25 × 1,55 = 775
        $this->assertSame('775.00', $again->json('totals.media_gross'));
        $this->assertSame('500.00', $again->json('totals.positions.0.nn_invest'));
    }
}
