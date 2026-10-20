<?php

namespace Tests\Feature\Calculation;

use App\Enums\CalculationKind;
use App\Enums\Role;
use App\Models\Calculation;
use App\Models\DispoOrderPosition;
use App\Models\Inventory;
use App\Models\User;
use App\Services\Calculation\CalculationWriter;
use App\Services\DispoOrder\DispoOrderWriter;
use App\Services\InventoryMediumRule\Admin\InventoryMediumRuleAdminWriter;
use App\Support\PriceList\PriceListCalendar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\CreatesAllongeAverageCatalog;
use Tests\TestCase;

/**
 * PO-ALLONGE-1 / SWF-Medium Allonge × Durchschnitt.
 *
 * Synthetisch, keine operativen Startwerte:
 * 10 × 2,00 × 15 × 1,30 = 390,00.
 */
class SwfAllongeAverageAcceptanceTest extends TestCase
{
    use CreatesAllongeAverageCatalog;
    use RefreshDatabase;

    public function test_unconfirmed_legacy_30_0_is_fail_closed_and_does_not_block_other_inventory(): void
    {
        $catalog = $this->createAllongeAverageCatalog();
        $user = User::factory()->role(Role::Sales)->create();

        $this->assertSame(30, $catalog['ruleC']->default_length_seconds);
        $this->assertSame('0.0000', $catalog['ruleC']->surcharge_percent);
        $this->assertNull($catalog['ruleC']->swf_allonge_confirmed_at);

        $blocked = $this->actingAs($user)->postJson(route('calculations.preview'), $this->allongePayload([
            $this->allongePosition($catalog['c'], $catalog['allonge'], 10),
        ]));
        $blocked->assertStatus(422);
        $this->assertStringContainsString('nicht bestätigt', (string) $blocked->json('message'));

        $open = $this->actingAs($user)->postJson(route('calculations.preview'), $this->allongePayload([
            $this->allongePosition($catalog['a'], $catalog['allonge'], 10, ['length_seconds' => 15]),
        ]));
        $open->assertOk();
        $this->assertSame('390.00', $open->json('totals.media_gross'));
    }

    public function test_admin_save_of_explicit_30_0_confirms_and_client_timestamp_is_ignored(): void
    {
        Carbon::setTestNow('2026-10-14 12:00:00');
        $catalog = $this->createAllongeAverageCatalog();
        $admin = User::factory()->role(Role::Admin)->create();
        $sales = User::factory()->role(Role::Sales)->create();
        $rule = $catalog['ruleC'];

        $this->actingAs($sales)->put(route('administration.combinations.update', $rule), [
            'lock_version' => $rule->lock_version,
            'booking_code' => $rule->booking_code,
            'planning_responsibility_key' => $rule->planning_responsibility_key,
            'default_length_seconds' => 30,
            'surcharge_percent' => '0',
            'swf_allonge_confirmed_at' => '2000-01-01 00:00:00',
        ])->assertForbidden();
        $this->assertNull($rule->fresh()->swf_allonge_confirmed_at);

        $this->actingAs($admin)->put(route('administration.combinations.update', $rule), [
            'lock_version' => $rule->lock_version,
            'booking_code' => $rule->booking_code,
            'planning_responsibility_key' => $rule->planning_responsibility_key,
            'default_length_seconds' => 30,
            'surcharge_percent' => '0',
            'swf_allonge_confirmed_at' => '2000-01-01 00:00:00',
        ])->assertRedirect();

        $rule->refresh();
        $this->assertSame(30, $rule->default_length_seconds);
        $this->assertSame('0.0000', $rule->surcharge_percent);
        $this->assertSame('2026-10-14 12:00:00', $rule->swf_allonge_confirmed_at?->format('Y-m-d H:i:s'));

        $this->actingAs($admin)->get(route('administration.combinations.show', $rule))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('rule.show_allonge_confirmation', true)
                ->where('rule.allonge_confirmation_label', 'Allonge-Konfiguration bestätigt am 14.10.2026 12:00'));

        $preview = $this->actingAs($sales)->postJson(route('calculations.preview'), $this->allongePayload([
            $this->allongePosition($catalog['c'], $catalog['allonge'], 10),
        ]));
        $preview->assertOk();
        // 10 × 2,00 × 30 × 1,00 = 600,00
        $this->assertSame('600.00', $preview->json('totals.media_gross'));
        Carbon::setTestNow();
    }

    public function test_missing_length_or_surcharge_clears_confirmation_without_wiping_other_fields(): void
    {
        $catalog = $this->createAllongeAverageCatalog();
        $admin = User::factory()->role(Role::Admin)->create();
        $rule = $catalog['ruleA'];
        $booking = $rule->booking_code;

        $this->actingAs($admin)->put(route('administration.combinations.update', $rule), [
            'lock_version' => $rule->lock_version,
            'booking_code' => $booking,
            'planning_responsibility_key' => $rule->planning_responsibility_key,
            'hint_text' => 'bleibt',
            'default_length_seconds' => 10,
            'surcharge_percent' => '',
        ])->assertRedirect();

        $rule->refresh();
        $this->assertSame(10, $rule->default_length_seconds);
        $this->assertNull($rule->surcharge_percent);
        $this->assertNull($rule->swf_allonge_confirmed_at);
        $this->assertSame($booking, $rule->booking_code);
        $this->assertSame('bleibt', $rule->hint_text);

        $this->actingAs($admin)->get(route('administration.combinations.show', $rule))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('rule.allonge_confirmation_label', 'Allonge-Konfiguration nicht bestätigt'));
    }

    public function test_create_does_not_confirm_even_with_client_timestamp(): void
    {
        Carbon::setTestNow('2026-10-14 13:00:00');
        $catalog = $this->createAllongeAverageCatalog();
        $admin = User::factory()->role(Role::Admin)->create();
        $inventory = Inventory::factory()->create([
            'organization_id' => $catalog['organization']->id,
            'name' => 'Allonge Neuanlage',
            'code' => 'ATN',
        ]);

        $created = app(InventoryMediumRuleAdminWriter::class)->create([
            'inventory_id' => $inventory->id,
            'advertising_medium_id' => $catalog['allonge']->id,
            'booking_code' => $catalog['ruleA']->booking_code,
            'planning_responsibility_key' => $catalog['ruleA']->planning_responsibility_key,
            'default_length_seconds' => 30,
            'surcharge_percent' => '0',
            'swf_allonge_confirmed_at' => '1999-01-01 00:00:00',
        ], $admin);

        $this->assertSame(30, $created->default_length_seconds);
        $this->assertSame('0.0000', $created->surcharge_percent);
        $this->assertNull($created->swf_allonge_confirmed_at);
        Carbon::setTestNow();
    }

    public function test_user_length_is_priced_with_admin_surcharge_through_dispo(): void
    {
        $catalog = $this->createAllongeAverageCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $payload = $this->allongePayload([
            $this->allongePosition($catalog['a'], $catalog['allonge'], 10, [
                'length_seconds' => 15,
                'surcharge_percent' => '99',
                'swf_allonge_confirmed_at' => '1999-01-01 00:00:00',
            ]),
            $this->allongePosition($catalog['b'], $catalog['allonge'], 4, [
                'length_seconds' => 20,
            ]),
        ]);

        $preview = $this->actingAs($user)->postJson(route('calculations.preview'), $payload);
        $preview->assertOk();
        // A: 10 × 2,00 × 15 × 1,30 = 390,00 · B: 4 × 3,00 × 20 × 1,10 = 264,00
        $this->assertSame('390.00', $preview->json('totals.positions.0.media_gross'));
        $this->assertSame('264.00', $preview->json('totals.positions.1.media_gross'));
        $this->assertSame(100, $preview->json('totals.positions.0.length_index'));

        $this->actingAs($user)->post(route('calculations.store'), $payload)->assertRedirect();
        $calculation = Calculation::query()->firstOrFail()->load('positions');
        $this->assertSame('654.00', (string) $calculation->media_gross);
        $this->assertSame(CalculationKind::SwfAllonge, $calculation->positions[0]->kind);
        $this->assertSame('swf_allonge', $calculation->positions[0]->engine_profile_key);
        $this->assertSame('average', $calculation->positions[0]->calculation_method_key);
        $this->assertSame(15, $calculation->positions[0]->length_seconds);
        $this->assertEqualsWithDelta(30.0, (float) $calculation->positions[0]->surcharge_percent, 0.00001);
        $this->assertSame($catalog['listA']->id, $calculation->positions[0]->price_list_id);

        $this->actingAs($user)->get(route('calculations.edit', $calculation))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('calculation.positions.0.length_seconds', 15));

        $result = app(DispoOrderWriter::class)->createFromCalculation(
            $calculation,
            [$calculation->positions[0]->id],
            $user,
        );
        $dispo = $result->order->positions()->first();
        $this->assertInstanceOf(DispoOrderPosition::class, $dispo);
        $this->assertSame(15, $dispo->length_seconds);
        $this->assertSame('390.00', (string) $dispo->media_gross);
        $this->assertSame(CalculationKind::SwfAllonge, $dispo->kind);

        $catalog['ruleA']->update([
            'default_length_seconds' => 99,
            'surcharge_percent' => '80',
            'swf_allonge_confirmed_at' => null,
        ]);
        $writerPayload = app(CalculationWriter::class)->payloadFromCalculation(
            $calculation->fresh(['positions.planRows', 'positions.timeRanges', 'positions.discounts', 'orderDiscounts', 'configurationSnapshot', 'fieldValues']),
        );
        $writerPayload['lock_version'] = $calculation->lock_version;
        $writerPayload['positions'][0]['length_seconds'] = 15;
        $this->actingAs($user)->put(route('calculations.update', $calculation), $writerPayload)->assertRedirect();

        $this->assertSame('390.00', (string) $dispo->fresh()->media_gross);
        $this->assertSame('654.00', (string) $calculation->fresh()->media_gross);
        $this->assertSame(15, $calculation->fresh()->positions()->first()->length_seconds);
    }

    public function test_inventory_rebind_and_price_year_rebind_follow_live_configuration(): void
    {
        $catalog = $this->createAllongeAverageCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $this->actingAs($user)->post(route('calculations.store'), $this->allongePayload([
            $this->allongePosition($catalog['a'], $catalog['allonge'], 10, ['length_seconds' => 15]),
            $this->allongePosition($catalog['b'], $catalog['allonge'], 4),
        ]))->assertRedirect();
        $calculation = Calculation::query()->firstOrFail();

        $blocked = app(CalculationWriter::class)->payloadFromCalculation(
            $calculation->fresh(['positions.planRows', 'positions.timeRanges', 'positions.discounts', 'orderDiscounts', 'configurationSnapshot', 'fieldValues']),
        );
        $blocked['lock_version'] = $calculation->lock_version;
        $blocked['positions'][0]['inventory_id'] = $catalog['c']->id;
        $blocked['positions'][0]['length_seconds'] = 15;
        $this->actingAs($user)->put(route('calculations.update', $calculation), $blocked)->assertSessionHasErrors('positions');
        $this->assertSame($catalog['a']->id, $calculation->fresh()->positions[0]->inventory_id);
        $this->assertSame('654.00', (string) $calculation->fresh()->media_gross);

        $rebind = app(CalculationWriter::class)->payloadFromCalculation(
            $calculation->fresh(['positions.planRows', 'positions.timeRanges', 'positions.discounts', 'orderDiscounts', 'configurationSnapshot', 'fieldValues']),
        );
        $rebind['lock_version'] = $calculation->fresh()->lock_version;
        $rebind['positions'][0]['inventory_id'] = $catalog['b']->id;
        $rebind['positions'][0]['length_seconds'] = 20;
        $this->actingAs($user)->put(route('calculations.update', $calculation), $rebind)->assertRedirect();
        $fresh = $calculation->fresh()->load('positions');
        // Pos0 jetzt B: 10 × 3,00 × 20 × 1,10 = 660,00; Pos1 unverändert 264,00
        $this->assertSame(20, $fresh->positions[0]->length_seconds);
        $this->assertSame('660.00', (string) $fresh->positions[0]->media_gross);
        $this->assertEqualsWithDelta(10.0, (float) $fresh->positions[0]->surcharge_percent, 0.00001);
        $this->assertSame('264.00', (string) $fresh->positions[1]->media_gross);

        $nextYear = PriceListCalendar::currentYear() + 1;
        $nextList = $this->createAllongePriceList($catalog['b'], '4.0000', $nextYear);
        $catalog['ruleB']->update(['surcharge_percent' => '50']);
        $yearPayload = app(CalculationWriter::class)->payloadFromCalculation(
            $fresh->fresh(['positions.planRows', 'positions.timeRanges', 'positions.discounts', 'orderDiscounts', 'configurationSnapshot', 'fieldValues']),
        );
        $yearPayload['lock_version'] = $fresh->fresh()->lock_version;
        $yearPayload['positions'][0]['price_year'] = $nextYear;
        $yearPayload['positions'][0]['expected_price_list_id'] = $nextList->id;
        $yearPayload['positions'][0]['length_seconds'] = 20;
        $this->actingAs($user)->put(route('calculations.update', $calculation), $yearPayload)->assertRedirect();
        $yeared = $calculation->fresh()->load('positions');
        $this->assertSame(20, $yeared->positions[0]->length_seconds);
        $this->assertSame($nextList->id, $yeared->positions[0]->price_list_id);
        // 10 × 4,00 × 20 × 1,50 = 1200,00
        $this->assertSame('1200.00', (string) $yeared->positions[0]->media_gross);
        $this->assertSame('264.00', (string) $yeared->positions[1]->media_gross);
    }

    public function test_new_position_uses_changed_admin_values_while_snapshot_stays(): void
    {
        $catalog = $this->createAllongeAverageCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $this->actingAs($user)->post(route('calculations.store'), $this->allongePayload([
            $this->allongePosition($catalog['a'], $catalog['allonge'], 10, ['length_seconds' => 15]),
        ]))->assertRedirect();

        $catalog['ruleA']->update([
            'default_length_seconds' => 12,
            'surcharge_percent' => '0',
        ]);

        $again = $this->actingAs($user)->postJson(route('calculations.preview'), $this->allongePayload([
            $this->allongePosition($catalog['a'], $catalog['allonge'], 10),
        ]));
        $again->assertOk();
        // 10 × 2,00 × 12 × 1,00 = 240,00
        $this->assertSame('240.00', $again->json('totals.media_gross'));
        $this->assertSame('390.00', (string) Calculation::query()->firstOrFail()->media_gross);
    }

    public function test_settlement_allowed_production_and_components_rejected(): void
    {
        $catalog = $this->createAllongeAverageCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $base = $this->allongePosition($catalog['a'], $catalog['allonge'], 10, ['length_seconds' => 15]);

        // PO-SWF-SETTLEMENT-1: Settlement freigegeben; Produktion/Komponenten bleiben OUT.
        $settled = $this->actingAs($user)->postJson(route('calculations.preview'), $this->allongePayload([
            array_merge($base, ['pricing_settlement_mode' => 'fixed_price', 'fixed_price_nn' => '300.00']),
        ]));
        $settled->assertOk();
        // 10 × 2 × 15 × 1,3 = 390
        $this->assertSame('390.00', $settled->json('totals.media_gross'));
        $this->assertSame('300.00', $settled->json('totals.positions.0.nn_invest'));

        $this->actingAs($user)->postJson(route('calculations.preview'), $this->allongePayload([
            array_merge($base, ['components' => [
                ['role' => 'main_spot', 'label' => 'Hauptspot', 'length_seconds' => 10, 'sort' => 0],
                ['role' => 'allonge', 'label' => 'Allonge', 'length_seconds' => 5, 'sort' => 1],
            ]]),
        ]))->assertStatus(422);

        $this->actingAs($user)->postJson(route('calculations.preview'), $this->allongePayload([
            array_merge($base, ['production_lines' => [[
                'production_type' => 'spot_production',
                'quantity' => 1,
            ]]]),
        ]))->assertStatus(422);
    }
}
