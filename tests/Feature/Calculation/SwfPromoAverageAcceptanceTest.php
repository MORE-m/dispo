<?php

namespace Tests\Feature\Calculation;

use App\Enums\CalculationKind;
use App\Enums\Role;
use App\Models\Calculation;
use App\Models\DispoOrderPosition;
use App\Models\User;
use App\Services\Calculation\CalculationWriter;
use App\Services\DispoOrder\DispoOrderWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesPromoAverageCatalog;
use Tests\TestCase;

/**
 * PO-SWF-PROMO-1 / SWF-Medium Promo/Moderation × Durchschnitt.
 *
 * Happy Path: 10 × 2,00 × 20 × 1,50 = 600,00.
 */
class SwfPromoAverageAcceptanceTest extends TestCase
{
    use CreatesPromoAverageCatalog;
    use RefreshDatabase;

    public function test_happy_path_preview_save_reload_dispo_with_20_and_50(): void
    {
        $catalog = $this->createPromoAverageCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $payload = $this->promoPayload([
            $this->promoPosition($catalog['a'], $catalog['promo'], 10),
        ]);

        $preview = $this->actingAs($user)->postJson(route('calculations.preview'), $payload);
        $preview->assertOk();
        $this->assertSame('600.00', $preview->json('totals.media_gross'));
        $this->assertSame(100, $preview->json('totals.positions.0.length_index'));

        $this->actingAs($user)->post(route('calculations.store'), $payload)->assertRedirect();
        $calculation = Calculation::query()->firstOrFail()->load('positions');
        $this->assertSame('600.00', (string) $calculation->media_gross);
        $this->assertSame(CalculationKind::SwfPromo, $calculation->positions[0]->kind);
        $this->assertSame('swf_promo', $calculation->positions[0]->engine_profile_key);
        $this->assertSame('average', $calculation->positions[0]->calculation_method_key);
        $this->assertSame(20, $calculation->positions[0]->length_seconds);
        $this->assertEqualsWithDelta(50.0, (float) $calculation->positions[0]->surcharge_percent, 0.00001);

        $this->actingAs($user)->get(route('calculations.edit', $calculation))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('calculation.positions.0.length_seconds', 20));

        $result = app(DispoOrderWriter::class)->createFromCalculation(
            $calculation,
            [$calculation->positions[0]->id],
            $user,
        );
        $dispo = $result->order->positions()->first();
        $this->assertInstanceOf(DispoOrderPosition::class, $dispo);
        $this->assertSame(20, $dispo->length_seconds);
        $this->assertSame('600.00', (string) $dispo->media_gross);
        $this->assertSame(CalculationKind::SwfPromo, $dispo->kind);
        $this->assertEqualsWithDelta(50.0, (float) $dispo->surcharge_percent, 0.00001);
    }

    public function test_variable_length_uses_position_length_with_admin_surcharge(): void
    {
        $catalog = $this->createPromoAverageCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $payload = $this->promoPayload([
            $this->promoPosition($catalog['a'], $catalog['promo'], 10, [
                'length_seconds' => 15,
                'surcharge_percent' => '99',
            ]),
        ]);

        $preview = $this->actingAs($user)->postJson(route('calculations.preview'), $payload);
        $preview->assertOk();
        // 10 × 2,00 × 15 × 1,50 = 450,00; Client-Aufschlag wird ignoriert
        $this->assertSame('450.00', $preview->json('totals.media_gross'));
        $this->assertSame(100, $preview->json('totals.positions.0.length_index'));

        $this->actingAs($user)->post(route('calculations.store'), $payload)->assertRedirect();
        $position = Calculation::query()->firstOrFail()->positions()->firstOrFail();
        $this->assertSame(15, $position->length_seconds);
        $this->assertEqualsWithDelta(50.0, (float) $position->surcharge_percent, 0.00001);
    }

    public function test_discount_and_ae_follow_swf_contract(): void
    {
        $catalog = $this->createPromoAverageCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $payload = $this->promoPayload([
            $this->promoPosition($catalog['a'], $catalog['promo'], 10, [
                'position_discount_percent' => '10',
                'ae_percent' => '15',
            ]),
        ], ['order_discount_percent' => '5', 'ae_enabled' => true]);

        // Mediabrutto 600,00 → −10 % = 540,00 → −5 % = 513,00 → AE 15 % = 76,95 → N/N 436,05
        $preview = $this->actingAs($user)->postJson(route('calculations.preview'), $payload);
        $preview->assertOk();
        $this->assertSame('600.00', $preview->json('totals.media_gross'));
        $this->assertSame('513.00', $preview->json('totals.positions.0.after_order_discount'));
        $this->assertSame('76.95', $preview->json('totals.positions.0.ae_amount'));
        $this->assertSame('436.05', $preview->json('totals.positions.0.nn_invest'));
    }

    public function test_fail_closed_for_invalid_surcharge_length_calendar_settlement(): void
    {
        $catalog = $this->createPromoAverageCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $base = $this->promoPosition($catalog['a'], $catalog['promo'], 10);

        foreach (['null', '0', '-10'] as $bad) {
            if ($bad === 'null') {
                $catalog['ruleA']->update(['surcharge_percent' => null]);
            } else {
                $catalog['ruleA']->update(['surcharge_percent' => $bad]);
            }
            $this->actingAs($user)->postJson(route('calculations.preview'), $this->promoPayload([$base]))
                ->assertStatus(422);
        }
        $catalog['ruleA']->update(['surcharge_percent' => '50']);

        $catalog['ruleA']->update(['default_length_seconds' => null]);
        $this->actingAs($user)->postJson(route('calculations.preview'), $this->promoPayload([
            $this->promoPosition($catalog['a'], $catalog['promo'], 10, ['length_seconds' => 20]),
        ]))->assertStatus(422);

        $catalog['ruleA']->update(['default_length_seconds' => 0]);
        $this->actingAs($user)->postJson(route('calculations.preview'), $this->promoPayload([
            $this->promoPosition($catalog['a'], $catalog['promo'], 10, ['length_seconds' => 20]),
        ]))->assertStatus(422);
        $catalog['ruleA']->update(['default_length_seconds' => 20]);

        $this->actingAs($user)->postJson(route('calculations.preview'), $this->promoPayload([
            $this->promoPosition($catalog['c'], $catalog['promo'], 10, ['length_seconds' => 20]),
        ]))->assertStatus(422);

        $this->actingAs($user)->postJson(route('calculations.preview'), $this->promoPayload([
            array_merge($base, [
                'spot_method' => 'calendar',
                'planner_entries' => [['date' => now()->toDateString(), 'hour' => 8, 'spot_count' => 2]],
            ]),
        ]))->assertStatus(422);

        $this->actingAs($user)->postJson(route('calculations.preview'), $this->promoPayload([
            array_merge($base, ['pricing_settlement_mode' => 'fixed_price', 'fixed_price_nn' => '100.00']),
        ]))->assertStatus(422);

        $this->actingAs($user)->postJson(route('calculations.preview'), $this->promoPayload([
            array_merge($base, ['production_lines' => [[
                'production_type' => 'spot_production',
                'quantity' => 1,
            ]]]),
        ]))->assertStatus(422);

        $this->actingAs($user)->postJson(route('calculations.preview'), $this->promoPayload([
            array_merge($base, ['components' => [
                ['role' => 'main_spot', 'label' => 'Hauptspot', 'length_seconds' => 15, 'sort' => 0],
                ['role' => 'allonge', 'label' => 'Allonge', 'length_seconds' => 5, 'sort' => 1],
            ]]),
        ]))->assertStatus(422);
    }

    public function test_snapshot_stays_when_live_config_changes(): void
    {
        $catalog = $this->createPromoAverageCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $this->actingAs($user)->post(route('calculations.store'), $this->promoPayload([
            $this->promoPosition($catalog['a'], $catalog['promo'], 10),
        ]))->assertRedirect();

        $calculation = Calculation::query()->firstOrFail();
        $result = app(DispoOrderWriter::class)->createFromCalculation(
            $calculation,
            [$calculation->positions[0]->id],
            $user,
        );
        $dispo = $result->order->positions()->firstOrFail();

        $catalog['ruleA']->update([
            'default_length_seconds' => 25,
            'surcharge_percent' => '55',
        ]);

        $writerPayload = app(CalculationWriter::class)->payloadFromCalculation(
            $calculation->fresh(['positions.planRows', 'positions.timeRanges', 'positions.discounts', 'orderDiscounts', 'configurationSnapshot', 'fieldValues']),
        );
        $writerPayload['lock_version'] = $calculation->lock_version;
        $writerPayload['positions'][0]['length_seconds'] = 20;
        $this->actingAs($user)->put(route('calculations.update', $calculation), $writerPayload)->assertRedirect();

        $this->assertSame('600.00', (string) $dispo->fresh()->media_gross);
        $this->assertSame('600.00', (string) $calculation->fresh()->media_gross);
        $this->assertEqualsWithDelta(50.0, (float) $calculation->fresh()->positions()->first()->surcharge_percent, 0.00001);

        $again = $this->actingAs($user)->postJson(route('calculations.preview'), $this->promoPayload([
            $this->promoPosition($catalog['a'], $catalog['promo'], 10),
        ]));
        $again->assertOk();
        // 10 × 2,00 × 25 × 1,55 = 775,00
        $this->assertSame('775.00', $again->json('totals.media_gross'));
    }

    public function test_missing_price_list_is_fail_closed(): void
    {
        $catalog = $this->createPromoAverageCatalog();
        $catalog['listA']->delete();
        $user = User::factory()->role(Role::Sales)->create();

        $this->actingAs($user)->postJson(route('calculations.preview'), $this->promoPayload([
            $this->promoPosition($catalog['a'], $catalog['promo'], 10),
        ]))->assertStatus(422);
    }
}
