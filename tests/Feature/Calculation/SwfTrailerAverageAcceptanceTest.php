<?php

namespace Tests\Feature\Calculation;

use App\Enums\CalculationKind;
use App\Enums\DayGroup;
use App\Enums\PriceListStatus;
use App\Enums\Role;
use App\Models\Calculation;
use App\Models\Inventory;
use App\Models\InventoryMediumRule;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\User;
use App\Services\Calculation\CalculationWriter;
use App\Services\DispoOrder\DispoOrderWriter;
use App\Support\Advertising\AdvertisingMediumLiveBookability;
use App\Support\PriceList\PriceListCalendar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CreatesTrailerAverageCatalog;
use Tests\TestCase;

/**
 * BL-P5-01a / SWF-001–SWF-005 / PO-BLP501A-1 (A1 + B1): SWF Trailer × Durchschnitt.
 *
 * Formel je Zeitraum: Anzahl × Ø-Sekundenpreis × Länge × (1 + Aufschlag / 100). Kein Spotlängenindex.
 */
class SwfTrailerAverageAcceptanceTest extends TestCase
{
    use CreatesTrailerAverageCatalog;
    use RefreshDatabase;

    private function sales(): User
    {
        return User::factory()->role(Role::Sales)->create();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function preview(User $user, array $payload): TestResponse
    {
        return $this->actingAs($user)->postJson(route('calculations.preview'), $payload);
    }

    public function test_two_inventories_use_own_length_and_surcharge_without_cross_effect(): void
    {
        $catalog = $this->createTrailerAverageCatalog();
        $payload = $this->trailerPayload([
            $this->trailerPosition($catalog['a'], $catalog['trailer'], 10),
            $this->trailerPosition($catalog['b'], $catalog['trailer'], 5),
        ]);

        $preview = $this->preview($this->sales(), $payload);
        $preview->assertOk();

        // A: 10 × 2,00 × 20 × 1,30 = 520,00 · B: 5 × 1,50 × 15 × 1,50 = 168,75
        $this->assertSame('520.00', $preview->json('totals.positions.0.media_gross'));
        $this->assertSame('168.75', $preview->json('totals.positions.1.media_gross'));
        $this->assertSame('688.75', $preview->json('totals.media_gross'));
        $this->assertSame(100, $preview->json('totals.positions.0.length_index'));
        $this->assertSame(100, $preview->json('totals.positions.1.length_index'));
    }

    public function test_preview_save_reload_parity_and_frozen_trailer_snapshot(): void
    {
        $catalog = $this->createTrailerAverageCatalog();
        $user = $this->sales();
        $payload = $this->trailerPayload([
            $this->trailerPosition($catalog['a'], $catalog['trailer'], 10),
            $this->trailerPosition($catalog['b'], $catalog['trailer'], 5),
        ]);

        $preview = $this->preview($user, $payload);
        $preview->assertOk();

        $this->actingAs($user)->post(route('calculations.store'), $payload)->assertRedirect();
        $calculation = Calculation::query()->firstOrFail()->load('positions.timeRanges');

        $this->assertSame($preview->json('totals.media_gross'), (string) $calculation->media_gross);
        $this->assertSame('688.75', (string) $calculation->media_gross);

        $positionA = $calculation->positions[0];
        $positionB = $calculation->positions[1];

        $this->assertSame(CalculationKind::SwfTrailer, $positionA->kind);
        $this->assertSame('swf_trailer', $positionA->engine_profile_key);
        $this->assertSame('average', $positionA->calculation_method_key);
        $this->assertSame('v1', $positionA->algorithm_version);
        $this->assertSame(20, $positionA->length_seconds);
        $this->assertSame(15, $positionB->length_seconds);
        $this->assertSame(100, $positionA->length_index);
        $this->assertSame(100, $positionB->length_index);
        $this->assertEqualsWithDelta(30.0, (float) $positionA->surcharge_percent, 0.00001);
        $this->assertEqualsWithDelta(50.0, (float) $positionB->surcharge_percent, 0.00001);
        $this->assertSame($catalog['listA']->id, $positionA->price_list_id);
        $this->assertSame($catalog['listB']->id, $positionB->price_list_id);
        $this->assertSame('520.00', (string) $positionA->media_gross);
        $this->assertSame('168.75', (string) $positionB->media_gross);
        $this->assertCount(1, $positionA->timeRanges);

        // DB-Reload (Edit-Seite) bleibt paritätisch.
        $this->actingAs($user)->get(route('calculations.edit', $calculation))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('calculation.positions.0.length_seconds', 20)
                ->where('calculation.positions.1.length_seconds', 15)
                ->where('savedSummary.media_gross', '688.75'));

        // Unveränderter Re-Save ändert nichts (Snapshot-Pfad).
        $writerPayload = app(CalculationWriter::class)->payloadFromCalculation(
            $calculation->fresh(['positions.planRows', 'positions.timeRanges', 'positions.discounts', 'orderDiscounts', 'configurationSnapshot', 'fieldValues']),
        );
        $writerPayload['lock_version'] = $calculation->lock_version;
        $this->actingAs($user)->put(route('calculations.update', $calculation), $writerPayload)->assertRedirect();
        $this->assertSame('688.75', (string) $calculation->fresh()->media_gross);
    }

    public function test_multi_hour_average_weights_hours_equally(): void
    {
        $catalog = $this->createTrailerAverageCatalog();
        PriceListItem::query()
            ->where('price_list_id', $catalog['listA']->id)
            ->where('hour', 9)
            ->where('day_group', DayGroup::MoFr)
            ->update(['second_price' => '4.0000']);

        $payload = $this->trailerPayload([
            $this->trailerPosition($catalog['a'], $catalog['trailer'], 10, [
                'time_ranges' => [[
                    'start_hour' => 8,
                    'end_hour_exclusive' => 10,
                    'day_group' => 'mo_fr',
                    'spot_count' => 10,
                    'sort' => 0,
                ]],
            ]),
        ]);

        // Ø (2,00 + 4,00) / 2 = 3,00 → 10 × 3,00 × 20 × 1,30 = 780,00
        $preview = $this->preview($this->sales(), $payload);
        $preview->assertOk();
        $this->assertSame('780.00', $preview->json('totals.media_gross'));
        $this->assertSame('3.0000', $preview->json('totals.positions.0.average_second_price'));
    }

    public function test_multiple_time_ranges_are_computed_separately_and_added(): void
    {
        $catalog = $this->createTrailerAverageCatalog();
        PriceListItem::query()
            ->where('price_list_id', $catalog['listA']->id)
            ->where('hour', 14)
            ->where('day_group', DayGroup::MoFr)
            ->update(['second_price' => '3.0000']);

        $payload = $this->trailerPayload([
            $this->trailerPosition($catalog['a'], $catalog['trailer'], 10, [
                'time_ranges' => [
                    ['start_hour' => 8, 'end_hour_exclusive' => 9, 'day_group' => 'mo_fr', 'spot_count' => 10, 'sort' => 0],
                    ['start_hour' => 14, 'end_hour_exclusive' => 15, 'day_group' => 'mo_fr', 'spot_count' => 5, 'sort' => 1],
                ],
            ]),
        ]);

        // 10 × 2,00 × 20 × 1,30 = 520,00 · 5 × 3,00 × 20 × 1,30 = 390,00 → 910,00
        $preview = $this->preview($this->sales(), $payload);
        $preview->assertOk();
        $this->assertSame('910.00', $preview->json('totals.media_gross'));
        $this->assertSame(15, $preview->json('totals.positions.0.spot_count'));
    }

    public function test_spot_length_index_never_influences_trailer(): void
    {
        $catalog = $this->createTrailerAverageCatalog();
        $user = $this->sales();

        // 20 s wäre bei Spot Classic Index 105 (546,00) – Trailer bleibt 520,00.
        $payload = $this->trailerPayload([
            $this->trailerPosition($catalog['a'], $catalog['trailer'], 10, ['length_seconds' => 20]),
        ]);
        $this->assertSame('520.00', $this->preview($user, $payload)->json('totals.media_gross'));

        // 10 s (Spot-Index 110): 10 × 2,00 × 10 × 1,30 = 260,00
        $payload = $this->trailerPayload([
            $this->trailerPosition($catalog['a'], $catalog['trailer'], 10, ['length_seconds' => 10]),
        ]);
        $preview = $this->preview($user, $payload);
        $this->assertSame('260.00', $preview->json('totals.media_gross'));
        $this->assertSame(100, $preview->json('totals.positions.0.length_index'));

        // 40 s (Spot-Index 95): 10 × 2,00 × 40 × 1,30 = 1040,00
        $payload = $this->trailerPayload([
            $this->trailerPosition($catalog['a'], $catalog['trailer'], 10, ['length_seconds' => 40]),
        ]);
        $preview = $this->preview($user, $payload);
        $this->assertSame('1040.00', $preview->json('totals.media_gross'));
        $this->assertSame(100, $preview->json('totals.positions.0.length_index'));
    }

    public function test_sales_may_edit_length_but_not_surcharge(): void
    {
        $catalog = $this->createTrailerAverageCatalog();
        $user = $this->sales();

        $payload = $this->trailerPayload([
            $this->trailerPosition($catalog['a'], $catalog['trailer'], 10, [
                'length_seconds' => 25,
                'surcharge_percent' => '99',
            ]),
        ]);

        // 10 × 2,00 × 25 × 1,30 = 650,00 – Aufschlag-Override aus dem Payload wird ignoriert.
        $preview = $this->preview($user, $payload);
        $preview->assertOk();
        $this->assertSame('650.00', $preview->json('totals.media_gross'));

        $this->actingAs($user)->post(route('calculations.store'), $payload)->assertRedirect();
        $position = Calculation::query()->firstOrFail()->positions()->firstOrFail();
        $this->assertSame(25, $position->length_seconds);
        $this->assertEqualsWithDelta(30.0, (float) $position->surcharge_percent, 0.00001);
    }

    public function test_explicit_zero_percent_surcharge_is_valid(): void
    {
        $catalog = $this->createTrailerAverageCatalog();
        $catalog['ruleA']->update(['surcharge_percent' => '0']);

        $payload = $this->trailerPayload([
            $this->trailerPosition($catalog['a'], $catalog['trailer'], 10),
        ]);

        // 10 × 2,00 × 20 × (1 + 0/100) = 400,00
        $preview = $this->preview($this->sales(), $payload);
        $preview->assertOk();
        $this->assertSame('400.00', $preview->json('totals.media_gross'));
    }

    public function test_missing_surcharge_fails_closed_and_is_not_treated_as_zero(): void
    {
        $catalog = $this->createTrailerAverageCatalog();
        $catalog['ruleA']->update(['surcharge_percent' => null]);

        $payload = $this->trailerPayload([
            $this->trailerPosition($catalog['a'], $catalog['trailer'], 10),
        ]);
        $user = $this->sales();

        $this->preview($user, $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['positions']);
        $this->assertStringContainsString(
            'kein Trailer-Aufschlag konfiguriert',
            (string) $this->preview($user, $payload)->json('errors.positions.0'),
        );

        $this->actingAs($user)->post(route('calculations.store'), $payload)->assertSessionHasErrors('positions');
        $this->assertSame(0, Calculation::query()->count());
    }

    public function test_missing_rule_length_fails_closed_without_medium_default_fallback(): void
    {
        $catalog = $this->createTrailerAverageCatalog();
        $catalog['ruleA']->update(['default_length_seconds' => null]);

        $payload = $this->trailerPayload([
            $this->trailerPosition($catalog['a'], $catalog['trailer'], 10),
        ]);

        $response = $this->preview($this->sales(), $payload);
        $response->assertStatus(422)->assertJsonValidationErrors(['positions']);
        $this->assertStringContainsString('keine Trailer-Länge konfiguriert', (string) $response->json('errors.positions.0'));
        // Medium-Default 30 s darf nicht still greifen.
        $this->assertSame(30, (int) $catalog['trailer']->default_length_seconds);
    }

    public function test_incomplete_inventory_does_not_block_other_inventories(): void
    {
        $catalog = $this->createTrailerAverageCatalog();
        $catalog['ruleB']->update(['surcharge_percent' => null, 'default_length_seconds' => null]);
        $user = $this->sales();

        $onlyA = $this->trailerPayload([
            $this->trailerPosition($catalog['a'], $catalog['trailer'], 10),
        ]);
        $this->assertSame('520.00', $this->preview($user, $onlyA)->json('totals.media_gross'));

        $onlyB = $this->trailerPayload([
            $this->trailerPosition($catalog['b'], $catalog['trailer'], 5),
        ]);
        $this->preview($user, $onlyB)->assertStatus(422);
    }

    public function test_missing_price_cell_fails_closed(): void
    {
        $catalog = $this->createTrailerAverageCatalog();
        PriceListItem::query()
            ->where('price_list_id', $catalog['listA']->id)
            ->where('hour', 9)
            ->where('day_group', DayGroup::MoFr)
            ->delete();

        $payload = $this->trailerPayload([
            $this->trailerPosition($catalog['a'], $catalog['trailer'], 10, [
                'time_ranges' => [[
                    'start_hour' => 8,
                    'end_hour_exclusive' => 10,
                    'day_group' => 'mo_fr',
                    'spot_count' => 10,
                    'sort' => 0,
                ]],
            ]),
        ]);

        $this->preview($this->sales(), $payload)->assertStatus(422);
        $this->actingAs($this->sales())->post(route('calculations.store'), $payload)->assertSessionHasErrors();
        $this->assertSame(0, Calculation::query()->count());
    }

    public function test_other_swf_media_stay_unbookable(): void
    {
        $catalog = $this->createTrailerAverageCatalog();

        $result = app(AdvertisingMediumLiveBookability::class)->evaluate($catalog['otherSwf']);
        $this->assertFalse($result->isBookableForNewPositions);

        $trailer = app(AdvertisingMediumLiveBookability::class)->evaluate($catalog['trailer']);
        $this->assertTrue($trailer->isBookableForNewPositions);
        $this->assertSame('swf_trailer', $trailer->engineProfileKey);
        $this->assertSame('average', $trailer->calculationMethodKey);
        $this->assertSame('v1', $trailer->algorithmVersion);

        $payload = $this->trailerPayload([
            $this->trailerPosition($catalog['a'], $catalog['otherSwf'], 10),
        ]);
        $this->preview($this->sales(), $payload)->assertStatus(422);
    }

    public function test_calendar_fixed_price_and_components_are_rejected_for_trailer(): void
    {
        $catalog = $this->createTrailerAverageCatalog();
        $user = $this->sales();

        $calendar = $this->trailerPayload([[
            'inventory_id' => $catalog['a']->id,
            'advertising_medium_id' => $catalog['trailer']->id,
            'spot_method' => 'calendar',
            'position_discount_percent' => '0',
            'ae_percent' => '0',
            'planner_entries' => [['date' => now()->toDateString(), 'hour' => 8, 'spot_count' => 2]],
        ]]);
        $this->preview($user, $calendar)->assertStatus(422);

        $fixed = $this->trailerPayload([
            $this->trailerPosition($catalog['a'], $catalog['trailer'], 10, [
                'pricing_settlement_mode' => 'fixed_price',
                'fixed_price_nn' => '400.00',
            ]),
        ]);
        $this->preview($user, $fixed)->assertStatus(422);

        $components = $this->trailerPayload([
            $this->trailerPosition($catalog['a'], $catalog['trailer'], 10, [
                'components' => [
                    ['role' => 'main_spot', 'label' => 'Hauptspot', 'length_seconds' => 20, 'sort' => 0],
                    ['role' => 'allonge', 'label' => 'Allonge', 'length_seconds' => 5, 'sort' => 1],
                ],
            ]),
        ]);
        $this->preview($user, $components)->assertStatus(422);
    }

    public function test_snapshot_isolation_after_admin_changes_to_rule_and_price_list(): void
    {
        $catalog = $this->createTrailerAverageCatalog();
        $user = $this->sales();
        $payload = $this->trailerPayload([
            $this->trailerPosition($catalog['a'], $catalog['trailer'], 10),
        ]);
        $this->actingAs($user)->post(route('calculations.store'), $payload)->assertRedirect();
        $calculation = Calculation::query()->firstOrFail();
        $this->assertSame('520.00', (string) $calculation->media_gross);

        // Admin-Änderungen nach dem Speichern: Aufschlag, Länge, Preise.
        $catalog['ruleA']->update(['surcharge_percent' => '90', 'default_length_seconds' => 45]);
        PriceListItem::query()->where('price_list_id', $catalog['listA']->id)->update(['second_price' => '9.0000']);

        $writerPayload = app(CalculationWriter::class)->payloadFromCalculation(
            $calculation->fresh(['positions.planRows', 'positions.timeRanges', 'positions.discounts', 'orderDiscounts', 'configurationSnapshot', 'fieldValues']),
        );
        $writerPayload['lock_version'] = $calculation->lock_version;
        $writerPayload['positions'][0]['time_ranges'][0]['spot_count'] = 20;

        $this->actingAs($user)->put(route('calculations.update', $calculation), $writerPayload)->assertRedirect();
        $fresh = $calculation->fresh()->load('positions');

        // 20 × 2,00 × 20 × 1,30 = 1040,00 (eingefrorene Preise/Aufschlag/Länge)
        $this->assertSame('1040.00', (string) $fresh->media_gross);
        $this->assertEqualsWithDelta(30.0, (float) $fresh->positions[0]->surcharge_percent, 0.00001);
        $this->assertSame(20, $fresh->positions[0]->length_seconds);

        // Neue Position nutzt dagegen die neuen Admin-Werte.
        $newPayload = $this->trailerPayload([
            $this->trailerPosition($catalog['a'], $catalog['trailer'], 10),
        ]);
        // 10 × 9,00 × 45 × 1,90 = 7695,00
        $this->assertSame('7695.00', $this->preview($user, $newPayload)->json('totals.media_gross'));
    }

    public function test_inventory_rebind_uses_target_inventory_rule_and_prices_only_for_that_position(): void
    {
        $catalog = $this->createTrailerAverageCatalog();
        $user = $this->sales();
        $payload = $this->trailerPayload([
            $this->trailerPosition($catalog['a'], $catalog['trailer'], 10),
            $this->trailerPosition($catalog['b'], $catalog['trailer'], 5),
        ]);
        $this->actingAs($user)->post(route('calculations.store'), $payload)->assertRedirect();
        $calculation = Calculation::query()->firstOrFail()->load('positions');
        $this->assertSame('688.75', (string) $calculation->media_gross);

        $writerPayload = app(CalculationWriter::class)->payloadFromCalculation(
            $calculation->fresh(['positions.planRows', 'positions.timeRanges', 'positions.discounts', 'orderDiscounts', 'configurationSnapshot', 'fieldValues']),
        );
        $writerPayload['lock_version'] = $calculation->lock_version;
        // Position 0: A → B; Länge bewusst durch Vertrieb gesetzt (keine Übernahme von A-Konfiguration).
        $writerPayload['positions'][0]['inventory_id'] = $catalog['b']->id;
        $writerPayload['positions'][0]['length_seconds'] = 15;

        $this->actingAs($user)->put(route('calculations.update', $calculation), $writerPayload)->assertRedirect();
        $fresh = $calculation->fresh()->load('positions');

        // Pos 0 jetzt B: 10 × 1,50 × 15 × 1,50 = 337,50; Pos 1 unverändert 168,75.
        $this->assertSame('337.50', (string) $fresh->positions[0]->media_gross);
        $this->assertSame($catalog['listB']->id, $fresh->positions[0]->price_list_id);
        $this->assertEqualsWithDelta(50.0, (float) $fresh->positions[0]->surcharge_percent, 0.00001);
        $this->assertSame('168.75', (string) $fresh->positions[1]->media_gross);
        $this->assertSame('506.25', (string) $fresh->media_gross);
    }

    public function test_rebind_to_unconfigured_inventory_fails_without_partial_update(): void
    {
        $catalog = $this->createTrailerAverageCatalog();
        $user = $this->sales();
        $this->actingAs($user)->post(route('calculations.store'), $this->trailerPayload([
            $this->trailerPosition($catalog['a'], $catalog['trailer'], 10),
        ]))->assertRedirect();
        $calculation = Calculation::query()->firstOrFail();

        $catalog['ruleB']->update(['surcharge_percent' => null]);

        $writerPayload = app(CalculationWriter::class)->payloadFromCalculation(
            $calculation->fresh(['positions.planRows', 'positions.timeRanges', 'positions.discounts', 'orderDiscounts', 'configurationSnapshot', 'fieldValues']),
        );
        $writerPayload['lock_version'] = $calculation->lock_version;
        $writerPayload['positions'][0]['inventory_id'] = $catalog['b']->id;

        $this->actingAs($user)->put(route('calculations.update', $calculation), $writerPayload)
            ->assertSessionHasErrors('positions');

        $fresh = $calculation->fresh()->load('positions');
        $this->assertSame($catalog['a']->id, $fresh->positions[0]->inventory_id);
        $this->assertSame('520.00', (string) $fresh->media_gross);
    }

    public function test_rights_only_calculation_managers_may_create(): void
    {
        $catalog = $this->createTrailerAverageCatalog();
        $payload = $this->trailerPayload([
            $this->trailerPosition($catalog['a'], $catalog['trailer'], 10),
        ]);

        $disposition = User::factory()->role(Role::Disposition)->create();
        $this->actingAs($disposition)->post(route('calculations.store'), $payload)->assertForbidden();
        $this->assertSame(0, Calculation::query()->count());

        foreach ([Role::Sales, Role::Admin, Role::Management] as $role) {
            Calculation::query()->delete();
            $user = User::factory()->role($role)->create();
            $this->actingAs($user)->post(route('calculations.store'), $payload)->assertRedirect();
            $this->assertSame(1, Calculation::query()->count());
        }
    }

    public function test_position_order_discount_and_ae_follow_existing_contracts(): void
    {
        $catalog = $this->createTrailerAverageCatalog();
        $user = $this->sales();
        $payload = $this->trailerPayload(
            [$this->trailerPosition($catalog['a'], $catalog['trailer'], 10, [
                'position_discount_percent' => '10',
                'ae_percent' => '15',
            ])],
            ['order_discount_percent' => '5', 'ae_enabled' => true],
        );

        // Mediabrutto 520,00 → −10 % = 468,00 → −5 % = 444,60 → AE 15 % = 66,69 → N/N 377,91
        $preview = $this->preview($user, $payload);
        $preview->assertOk();
        $this->assertSame('520.00', $preview->json('totals.media_gross'));
        $this->assertSame('444.60', $preview->json('totals.positions.0.after_order_discount'));
        $this->assertSame('66.69', $preview->json('totals.positions.0.ae_amount'));
        $this->assertSame('377.91', $preview->json('totals.positions.0.nn_invest'));

        $this->actingAs($user)->post(route('calculations.store'), $payload)->assertRedirect();
        $this->assertSame('377.91', (string) Calculation::query()->firstOrFail()->nn_invest);
    }

    public function test_dispo_order_takes_over_frozen_trailer_snapshot(): void
    {
        $catalog = $this->createTrailerAverageCatalog();
        $user = $this->sales();
        $this->actingAs($user)->post(route('calculations.store'), $this->trailerPayload([
            $this->trailerPosition($catalog['a'], $catalog['trailer'], 10),
            $this->trailerPosition($catalog['b'], $catalog['trailer'], 5),
        ]))->assertRedirect();
        $calculation = Calculation::query()->firstOrFail()->load('positions');

        $result = app(DispoOrderWriter::class)->createFromCalculation(
            $calculation,
            [$calculation->positions[0]->id, $calculation->positions[1]->id],
            $user,
        );
        $dispo = $result->order->positions()->orderBy('id')->get();

        $this->assertCount(2, $dispo);
        $this->assertSame(CalculationKind::SwfTrailer, $dispo[0]->kind);
        $this->assertSame('swf_trailer', $dispo[0]->engine_profile_key);
        $this->assertSame('average', $dispo[0]->calculation_method_key);
        $this->assertSame(20, $dispo[0]->length_seconds);
        $this->assertSame(15, $dispo[1]->length_seconds);
        $this->assertSame(100, $dispo[0]->length_index);
        $this->assertEqualsWithDelta(30.0, (float) $dispo[0]->surcharge_percent, 0.00001);
        $this->assertEqualsWithDelta(50.0, (float) $dispo[1]->surcharge_percent, 0.00001);
        $this->assertSame('520.00', (string) $dispo[0]->media_gross);
        $this->assertSame('168.75', (string) $dispo[1]->media_gross);
        $this->assertNotNull($dispo[0]->booking_code);
    }

    public function test_spot_classic_still_applies_length_index_and_is_not_affected(): void
    {
        $catalog = $this->createTrailerAverageCatalog();
        $user = $this->sales();

        $payload = $this->trailerPayload([[
            'inventory_id' => $catalog['a']->id,
            'advertising_medium_id' => $catalog['spot']->id,
            'spot_method' => 'average',
            'length_seconds' => 20,
            'position_discount_percent' => '0',
            'ae_percent' => '0',
            'time_ranges' => [[
                'start_hour' => 8,
                'end_hour_exclusive' => 9,
                'day_group' => 'mo_fr',
                'spot_count' => 10,
                'sort' => 0,
            ]],
        ]]);

        // Spot Classic: 10 × 2,00 × 20 × 1,05 × (1 + 0/100) = 420,00
        $preview = $this->preview($user, $payload);
        $preview->assertOk();
        $this->assertSame('420.00', $preview->json('totals.media_gross'));
        $this->assertSame(105, $preview->json('totals.positions.0.length_index'));

        $this->actingAs($user)->post(route('calculations.store'), $payload)->assertRedirect();
        $position = Calculation::query()->firstOrFail()->positions()->firstOrFail();
        $this->assertSame(CalculationKind::SpotClassic, $position->kind);
        $this->assertSame('spot_classic', $position->engine_profile_key);
    }

    public function test_trailer_and_spot_positions_can_share_one_calculation(): void
    {
        $catalog = $this->createTrailerAverageCatalog();
        $payload = $this->trailerPayload([
            $this->trailerPosition($catalog['a'], $catalog['trailer'], 10),
            $this->trailerPosition($catalog['a'], $catalog['spot'], 10, ['length_seconds' => 20]),
        ]);

        $preview = $this->preview($this->sales(), $payload);
        $preview->assertOk();
        // 520,00 (Trailer) + 420,00 (Spot, Index 105)
        $this->assertSame('940.00', $preview->json('totals.media_gross'));
    }

    public function test_trailer_year_change_rebinds_to_target_year_price_list(): void
    {
        $catalog = $this->createTrailerAverageCatalog();
        $user = $this->sales();
        $currentYear = PriceListCalendar::currentYear();
        $nextYear = $currentYear + 1;

        $nextList = PriceList::factory()->create([
            'inventory_id' => $catalog['a']->id,
            'status' => PriceListStatus::Active,
            'year' => $nextYear,
            'version' => 'trailer-next-year',
            'valid_from' => sprintf('%d-01-01', $nextYear),
        ]);
        foreach (range(0, 23) as $hour) {
            foreach ([DayGroup::MoFr, DayGroup::Sa, DayGroup::So] as $group) {
                PriceListItem::factory()->create([
                    'price_list_id' => $nextList->id,
                    'hour' => $hour,
                    'day_group' => $group,
                    'second_price' => '4.0000',
                ]);
            }
        }

        $this->actingAs($user)->post(route('calculations.store'), $this->trailerPayload([
            $this->trailerPosition($catalog['a'], $catalog['trailer'], 10),
        ]))->assertRedirect();
        $calculation = Calculation::query()->firstOrFail();

        $writerPayload = app(CalculationWriter::class)->payloadFromCalculation(
            $calculation->fresh(['positions.planRows', 'positions.timeRanges', 'positions.discounts', 'orderDiscounts', 'configurationSnapshot', 'fieldValues']),
        );
        $writerPayload['lock_version'] = $calculation->lock_version;
        $writerPayload['positions'][0]['price_year'] = $nextYear;
        $writerPayload['positions'][0]['expected_price_list_id'] = $nextList->id;

        $this->actingAs($user)->put(route('calculations.update', $calculation), $writerPayload)->assertRedirect();
        $fresh = $calculation->fresh()->load(['positions.priceList']);

        $this->assertSame($nextList->id, $fresh->positions[0]->price_list_id);
        $this->assertSame($nextYear, (int) $fresh->positions[0]->priceList->year);
        // 10 × 4,00 × 20 × 1,30 = 1040,00
        $this->assertSame('1040.00', (string) $fresh->media_gross);
    }

    public function test_spot_to_trailer_medium_change_clears_components_and_uses_trailer_math(): void
    {
        $catalog = $this->createTrailerAverageCatalog();
        $user = $this->sales();

        $spotPayload = $this->trailerPayload([[
            'inventory_id' => $catalog['a']->id,
            'advertising_medium_id' => $catalog['spot']->id,
            'spot_method' => 'average',
            'length_seconds' => 30,
            'position_discount_percent' => '0',
            'ae_percent' => '0',
            'components' => [
                ['role' => 'main_spot', 'label' => 'Hauptspot', 'length_seconds' => 20, 'sort' => 0],
                ['role' => 'allonge', 'label' => 'Allonge', 'length_seconds' => 10, 'sort' => 1],
            ],
            'component_calculation_strategy' => 'shared_total_length',
            'time_ranges' => [[
                'start_hour' => 8,
                'end_hour_exclusive' => 9,
                'day_group' => 'mo_fr',
                'spot_count' => 10,
                'sort' => 0,
            ]],
        ]]);
        $this->actingAs($user)->post(route('calculations.store'), $spotPayload)->assertRedirect();
        $calculation = Calculation::query()->firstOrFail()->load('positions.components');
        $this->assertSame(CalculationKind::SpotClassic, $calculation->positions[0]->kind);
        $this->assertGreaterThan(0, $calculation->positions[0]->components->count());

        $writerPayload = app(CalculationWriter::class)->payloadFromCalculation(
            $calculation->fresh(['positions.planRows', 'positions.timeRanges', 'positions.discounts', 'positions.components', 'orderDiscounts', 'configurationSnapshot', 'fieldValues']),
        );
        $writerPayload['lock_version'] = $calculation->fresh()->lock_version;
        $writerPayload['positions'][0]['advertising_medium_id'] = $catalog['trailer']->id;
        $writerPayload['positions'][0]['length_seconds'] = 20;
        $writerPayload['positions'][0]['components'] = [];
        $writerPayload['positions'][0]['component_calculation_strategy'] = null;
        $writerPayload['positions'][0]['pricing_settlement_mode'] = 'normal';
        unset($writerPayload['positions'][0]['fixed_price_nn']);
        // Mediumwechsel: erwartete Preisliste bleibt Inventar A (gleicher Pin).
        $writerPayload['positions'][0]['expected_price_list_id'] = $catalog['listA']->id;
        $writerPayload['positions'][0]['price_year'] = PriceListCalendar::currentYear();
        $writerPayload['positions'] = $this->withPositionSchemaFingerprints(
            $calculation,
            $writerPayload['positions'],
        );

        $this->actingAs($user)->put(route('calculations.update', $calculation), $writerPayload)->assertRedirect();
        $fresh = $calculation->fresh()->load(['positions.components']);

        $this->assertSame(CalculationKind::SwfTrailer, $fresh->positions[0]->kind);
        $this->assertSame(0, $fresh->positions[0]->components->count());
        $this->assertSame('520.00', (string) $fresh->media_gross);

        // Rückwechsel Trailer → Spot ohne Komponenten.
        $back = app(CalculationWriter::class)->payloadFromCalculation(
            $fresh->fresh(['positions.planRows', 'positions.timeRanges', 'positions.discounts', 'positions.components', 'orderDiscounts', 'configurationSnapshot', 'fieldValues']),
        );
        $back['lock_version'] = $fresh->fresh()->lock_version;
        $back['positions'][0]['advertising_medium_id'] = $catalog['spot']->id;
        $back['positions'][0]['length_seconds'] = 20;
        $back['positions'][0]['components'] = [];
        $back['positions'][0]['component_calculation_strategy'] = null;
        $back['positions'][0]['expected_price_list_id'] = $catalog['listA']->id;
        $back['positions'][0]['price_year'] = PriceListCalendar::currentYear();
        $back['positions'] = $this->withPositionSchemaFingerprints($fresh, $back['positions']);

        $this->actingAs($user)->put(route('calculations.update', $calculation), $back)->assertRedirect();
        $spotAgain = $calculation->fresh()->load('positions');
        $this->assertSame(CalculationKind::SpotClassic, $spotAgain->positions[0]->kind);
        $this->assertSame('spot_classic', $spotAgain->positions[0]->engine_profile_key);
        // Rückwechsel darf nicht auf Trailer-Betrag 520,00 bleiben.
        $this->assertNotSame('520.00', (string) $spotAgain->media_gross);
        $this->assertGreaterThan(0, (float) $spotAgain->media_gross);
    }

    public function test_quantity_change_after_successor_list_keeps_trailer_pin(): void
    {
        $catalog = $this->createTrailerAverageCatalog();
        $user = $this->sales();
        $this->actingAs($user)->post(route('calculations.store'), $this->trailerPayload([
            $this->trailerPosition($catalog['a'], $catalog['trailer'], 10),
        ]))->assertRedirect();
        $calculation = Calculation::query()->firstOrFail()->load('positions');
        $pinnedId = (int) $calculation->positions[0]->price_list_id;
        $pinnedVersion = (string) $calculation->positions[0]->price_list_version;

        $previous = PriceList::query()->whereKey($pinnedId)->firstOrFail();
        $previous->update(['status' => PriceListStatus::Archived]);
        $successor = PriceList::factory()->create([
            'inventory_id' => $catalog['a']->id,
            'year' => PriceListCalendar::currentYear(),
            'status' => PriceListStatus::Active,
            'version' => 'trailer-successor',
            'revision_number' => ((int) $previous->revision_number) + 1,
            'valid_from' => $previous->valid_from,
        ]);
        foreach (range(0, 23) as $hour) {
            foreach ([DayGroup::MoFr, DayGroup::Sa, DayGroup::So] as $group) {
                PriceListItem::factory()->create([
                    'price_list_id' => $successor->id,
                    'hour' => $hour,
                    'day_group' => $group,
                    'second_price' => '9.0000',
                ]);
            }
        }

        $writerPayload = app(CalculationWriter::class)->payloadFromCalculation(
            $calculation->fresh(['positions.planRows', 'positions.timeRanges', 'positions.discounts', 'orderDiscounts', 'configurationSnapshot', 'fieldValues']),
        );
        $writerPayload['lock_version'] = $calculation->lock_version;
        $writerPayload['positions'][0]['time_ranges'][0]['spot_count'] = 20;

        $this->actingAs($user)->put(route('calculations.update', $calculation), $writerPayload)->assertRedirect();
        $fresh = $calculation->fresh()->load('positions');

        $this->assertSame($pinnedId, (int) $fresh->positions[0]->price_list_id);
        $this->assertSame($pinnedVersion, (string) $fresh->positions[0]->price_list_version);
        $this->assertNotSame($successor->id, (int) $fresh->positions[0]->price_list_id);
        // 20 × 2,00 × 20 × 1,30 = 1040,00 (Pin-Preis, nicht Nachfolger 9,00)
        $this->assertSame('1040.00', (string) $fresh->media_gross);
    }

    public function test_trailer_without_matrix_rule_is_rejected_without_mutation(): void
    {
        $catalog = $this->createTrailerAverageCatalog();
        $user = $this->sales();
        $this->actingAs($user)->post(route('calculations.store'), $this->trailerPayload([
            $this->trailerPosition($catalog['a'], $catalog['trailer'], 10),
        ]))->assertRedirect();
        $calculation = Calculation::query()->firstOrFail();

        $foreign = Inventory::factory()->create([
            'organization_id' => $catalog['organization']->id,
            'name' => 'Ohne Trailer-Matrix',
            'code' => 'NOMAT',
        ]);
        // Spot-Regel vorhanden, bewusst keine Trailer-Regel (unzulässige Matrix-Kombi).
        InventoryMediumRule::factory()->create([
            'inventory_id' => $foreign->id,
            'advertising_medium_id' => $catalog['spot']->id,
            'default_length_seconds' => 30,
            'surcharge_percent' => '0',
        ]);
        $this->createTrailerPriceList($foreign, '2.0000');

        $writerPayload = app(CalculationWriter::class)->payloadFromCalculation(
            $calculation->fresh(['positions.planRows', 'positions.timeRanges', 'positions.discounts', 'orderDiscounts', 'configurationSnapshot', 'fieldValues']),
        );
        $writerPayload['lock_version'] = $calculation->lock_version;
        $writerPayload['positions'][0]['inventory_id'] = $foreign->id;

        $this->actingAs($user)->put(route('calculations.update', $calculation), $writerPayload)
            ->assertSessionHasErrors();

        $fresh = $calculation->fresh()->load('positions');
        $this->assertSame($catalog['a']->id, $fresh->positions[0]->inventory_id);
        $this->assertSame('520.00', (string) $fresh->media_gross);
    }
}
