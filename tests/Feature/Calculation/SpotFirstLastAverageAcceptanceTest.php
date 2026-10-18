<?php

namespace Tests\Feature\Calculation;

use App\Enums\CalculationKind;
use App\Enums\DayGroup;
use App\Enums\PriceListStatus;
use App\Enums\Role;
use App\Models\Calculation;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\User;
use App\Services\DispoOrder\DispoOrderWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesSpotFirstLastCatalog;
use Tests\TestCase;

/**
 * PO-SPOT-SURCHARGE-1: Erst/Letzt × Average auf spot_classic (+30 %, Fail-closed, Snapshot).
 */
class SpotFirstLastAverageAcceptanceTest extends TestCase
{
    use CreatesSpotFirstLastCatalog;
    use RefreshDatabase;

    private function sales(): User
    {
        return User::factory()->role(Role::Sales)->create();
    }

    /**
     * @param  list<array<string, mixed>>  $positions
     * @return array<string, mixed>
     */
    private function payload(array $positions): array
    {
        return $this->withLiveSchemaFingerprint([
            'planning_mode' => 'manual',
            'order_discount_percent' => '0',
            'positions' => $positions,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function averagePosition(
        int $inventoryId,
        int $mediumId,
        int $spots = 10,
        array $overrides = [],
    ): array {
        return array_merge([
            'inventory_id' => $inventoryId,
            'advertising_medium_id' => $mediumId,
            'spot_method' => 'average',
            'length_seconds' => 30,
            'position_discount_percent' => '0',
            'ae_percent' => '0',
            'time_ranges' => [[
                'start_hour' => 8,
                'end_hour_exclusive' => 9,
                'day_group' => 'mo_fr',
                'spot_count' => $spots,
                'sort' => 0,
            ]],
        ], $overrides);
    }

    public function test_first_average_applies_thirty_percent_preview_save_reload(): void
    {
        $catalog = $this->createSpotFirstLastCatalog();
        $user = $this->sales();
        $payload = $this->payload([
            $this->averagePosition($catalog['hamburg']->id, $catalog['first']->id),
        ]);

        // 10 × 1,00 × 30 × 1,00 × 1,30 = 390,00
        $preview = $this->actingAs($user)->postJson(route('calculations.preview'), $payload);
        $preview->assertOk();
        $this->assertSame('390.00', $preview->json('totals.media_gross'));

        $this->actingAs($user)->post(route('calculations.store'), $payload)->assertRedirect();
        $calculation = Calculation::query()->firstOrFail()->load('positions');
        $this->assertSame('390.00', (string) $calculation->media_gross);
        $this->assertEqualsWithDelta(30.0, (float) $calculation->positions[0]->surcharge_percent, 0.00001);
        $this->assertSame(CalculationKind::SpotClassic, $calculation->positions[0]->kind);
        $this->assertSame('spot_classic', $calculation->positions[0]->engine_profile_key);

        $fresh = $calculation->fresh(['positions']);
        $this->assertSame('390.00', (string) $fresh->media_gross);
        $this->assertEqualsWithDelta(30.0, (float) $fresh->positions[0]->surcharge_percent, 0.00001);
    }

    public function test_last_average_applies_thirty_percent(): void
    {
        $catalog = $this->createSpotFirstLastCatalog();
        $user = $this->sales();
        $payload = $this->payload([
            $this->averagePosition($catalog['hamburg']->id, $catalog['last']->id),
        ]);

        $preview = $this->actingAs($user)->postJson(route('calculations.preview'), $payload);
        $preview->assertOk();
        $this->assertSame('390.00', $preview->json('totals.media_gross'));

        $this->actingAs($user)->post(route('calculations.store'), $payload)->assertRedirect();
        $position = Calculation::query()->firstOrFail()->positions()->firstOrFail();
        $this->assertEqualsWithDelta(30.0, (float) $position->surcharge_percent, 0.00001);
        $this->assertSame('390.00', (string) Calculation::query()->firstOrFail()->media_gross);
    }

    public function test_index_and_surcharge_compound(): void
    {
        $catalog = $this->createSpotFirstLastCatalog();
        $user = $this->sales();
        $payload = $this->payload([
            $this->averagePosition($catalog['hamburg']->id, $catalog['first']->id, 10, [
                'length_seconds' => 20, // Index 105
            ]),
        ]);

        // 10 × 1,00 × 20 × 1,05 × 1,30 = 273,00
        $preview = $this->actingAs($user)->postJson(route('calculations.preview'), $payload);
        $preview->assertOk();
        $this->assertSame('273.00', $preview->json('totals.media_gross'));
        $this->assertSame(105, $preview->json('totals.positions.0.length_index'));
    }

    public function test_discount_and_ae_after_surcharge(): void
    {
        $catalog = $this->createSpotFirstLastCatalog();
        $user = $this->sales();
        $payload = $this->withLiveSchemaFingerprint([
            'planning_mode' => 'manual',
            'order_discount_percent' => '10',
            'ae_enabled' => true,
            'positions' => [
                $this->averagePosition($catalog['hamburg']->id, $catalog['first']->id, 10, [
                    'position_discount_percent' => '5',
                    'ae_percent' => '15',
                ]),
            ],
        ]);

        // Brutto 390 → Positionsrabatt 5 % → 370,50 → Auftragsrabatt 10 % → 333,45
        // → AE 15 % → 50,0175 → N/N 283,4325 → gerundet nach Engine
        $preview = $this->actingAs($user)->postJson(route('calculations.preview'), $payload);
        $preview->assertOk();
        $this->assertSame('390.00', $preview->json('totals.media_gross'));
        $this->assertSame('370.50', $preview->json('totals.positions.0.after_position_discount'));
        $this->assertSame('333.45', $preview->json('totals.positions.0.after_order_discount'));
        $this->assertSame('50.02', $preview->json('totals.positions.0.ae_amount'));
        $this->assertSame('283.43', $preview->json('totals.positions.0.nn_invest'));
    }

    public function test_multi_inventory_first_and_last(): void
    {
        $catalog = $this->createSpotFirstLastCatalog();
        $user = $this->sales();
        $payload = $this->payload([
            $this->averagePosition($catalog['hamburg']->id, $catalog['first']->id, 10),
            $this->averagePosition($catalog['rock']->id, $catalog['last']->id, 5),
        ]);

        // Hamburg Erst: 390,00; Rock Letzt: 5 × 0,80 × 30 × 1,00 × 1,30 = 156,00; Summe 546,00
        $preview = $this->actingAs($user)->postJson(route('calculations.preview'), $payload);
        $preview->assertOk();
        $this->assertSame('546.00', $preview->json('totals.media_gross'));
    }

    public function test_null_surcharge_fails_closed(): void
    {
        $catalog = $this->createSpotFirstLastCatalog();
        $catalog['ruleFirstHamburg']->update(['surcharge_percent' => null]);
        $user = $this->sales();
        $payload = $this->payload([
            $this->averagePosition($catalog['hamburg']->id, $catalog['first']->id),
        ]);

        $this->actingAs($user)->postJson(route('calculations.preview'), $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['positions']);
        $this->assertStringContainsString(
            'kein Aufschlag konfiguriert',
            (string) $this->actingAs($user)->postJson(route('calculations.preview'), $payload)->json('errors.positions.0'),
        );
        $this->actingAs($user)->post(route('calculations.store'), $payload)->assertSessionHasErrors('positions');
        $this->assertSame(0, Calculation::query()->count());
    }

    public function test_import_zero_surcharge_fails_closed(): void
    {
        $catalog = $this->createSpotFirstLastCatalog();
        $catalog['ruleFirstHamburg']->update(['surcharge_percent' => '0']);
        $user = $this->sales();
        $payload = $this->payload([
            $this->averagePosition($catalog['hamburg']->id, $catalog['first']->id),
        ]);

        $response = $this->actingAs($user)->postJson(route('calculations.preview'), $payload);
        $response->assertStatus(422)->assertJsonValidationErrors(['positions']);
        $this->assertStringContainsString('ungültig', (string) $response->json('errors.positions.0'));
        $this->assertSame(0, Calculation::query()->count());
    }

    public function test_negative_surcharge_fails_closed(): void
    {
        $catalog = $this->createSpotFirstLastCatalog();
        $catalog['ruleLastHamburg']->update(['surcharge_percent' => '-5']);
        $user = $this->sales();
        $payload = $this->payload([
            $this->averagePosition($catalog['hamburg']->id, $catalog['last']->id),
        ]);

        $this->actingAs($user)->postJson(route('calculations.preview'), $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['positions']);
    }

    public function test_missing_rule_fails_closed(): void
    {
        $catalog = $this->createSpotFirstLastCatalog();
        $catalog['ruleFirstHamburg']->delete();
        $user = $this->sales();
        $payload = $this->payload([
            $this->averagePosition($catalog['hamburg']->id, $catalog['first']->id),
        ]);

        $this->actingAs($user)->postJson(route('calculations.preview'), $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['positions']);
    }

    public function test_missing_price_list_fails_closed(): void
    {
        $catalog = $this->createSpotFirstLastCatalog();
        PriceList::query()->where('inventory_id', $catalog['hamburg']->id)->delete();
        $user = $this->sales();
        $payload = $this->payload([
            $this->averagePosition($catalog['hamburg']->id, $catalog['first']->id),
        ]);

        $this->actingAs($user)->postJson(route('calculations.preview'), $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['positions']);
    }

    public function test_snapshot_retains_surcharge_after_rule_change(): void
    {
        $catalog = $this->createSpotFirstLastCatalog();
        $user = $this->sales();
        $payload = $this->payload([
            $this->averagePosition($catalog['hamburg']->id, $catalog['first']->id),
        ]);

        $this->actingAs($user)->post(route('calculations.store'), $payload)->assertRedirect();
        $calculation = Calculation::query()->firstOrFail()->load('positions');
        $this->assertEqualsWithDelta(30.0, (float) $calculation->positions[0]->surcharge_percent, 0.00001);
        $this->assertSame('390.00', (string) $calculation->media_gross);

        $catalog['ruleFirstHamburg']->update(['surcharge_percent' => '90']);

        $fresh = $calculation->fresh(['positions']);
        $this->assertEqualsWithDelta(30.0, (float) $fresh->positions[0]->surcharge_percent, 0.00001);
        $this->assertSame('390.00', (string) $fresh->media_gross);
    }

    public function test_dispo_snapshot_freezes_thirty_percent(): void
    {
        $catalog = $this->createSpotFirstLastCatalog();
        $user = $this->sales();
        $this->actingAs($user)->post(route('calculations.store'), $this->payload([
            $this->averagePosition($catalog['hamburg']->id, $catalog['first']->id),
            $this->averagePosition($catalog['hamburg']->id, $catalog['last']->id, 5),
        ]))->assertRedirect();
        $calculation = Calculation::query()->firstOrFail()->load('positions');

        $result = app(DispoOrderWriter::class)->createFromCalculation(
            $calculation,
            [$calculation->positions[0]->id, $calculation->positions[1]->id],
            $user,
        );
        $dispo = $result->order->positions()->orderBy('id')->get();

        $this->assertCount(2, $dispo);
        $this->assertEqualsWithDelta(30.0, (float) $dispo[0]->surcharge_percent, 0.00001);
        $this->assertEqualsWithDelta(30.0, (float) $dispo[1]->surcharge_percent, 0.00001);
        $this->assertSame('390.00', (string) $dispo[0]->media_gross);

        $catalog['ruleFirstHamburg']->update(['surcharge_percent' => '50']);
        $this->assertEqualsWithDelta(30.0, (float) $dispo[0]->fresh()->surcharge_percent, 0.00001);
    }

    public function test_classic_remains_unchanged_with_zero_surcharge(): void
    {
        $catalog = $this->createSpotFirstLastCatalog();
        $user = $this->sales();
        $payload = $this->payload([
            $this->averagePosition($catalog['hamburg']->id, $catalog['classic']->id),
        ]);

        // 10 × 1,00 × 30 × 1,00 × 1,00 = 300,00
        $preview = $this->actingAs($user)->postJson(route('calculations.preview'), $payload);
        $preview->assertOk();
        $this->assertSame('300.00', $preview->json('totals.media_gross'));

        $this->actingAs($user)->post(route('calculations.store'), $payload)->assertRedirect();
        $this->assertEqualsWithDelta(
            0.0,
            (float) Calculation::query()->firstOrFail()->positions()->firstOrFail()->surcharge_percent,
            0.00001,
        );
    }

    public function test_classic_null_surcharge_still_treated_as_zero(): void
    {
        $catalog = $this->createSpotFirstLastCatalog();
        $catalog['ruleClassicHamburg']->update(['surcharge_percent' => null]);
        $user = $this->sales();
        $payload = $this->payload([
            $this->averagePosition($catalog['hamburg']->id, $catalog['classic']->id),
        ]);

        $preview = $this->actingAs($user)->postJson(route('calculations.preview'), $payload);
        $preview->assertOk();
        $this->assertSame('300.00', $preview->json('totals.media_gross'));
    }

    public function test_positive_admin_surcharge_other_than_thirty_remains_allowed(): void
    {
        $catalog = $this->createSpotFirstLastCatalog();
        $catalog['ruleFirstHamburg']->update(['surcharge_percent' => '40']);
        $user = $this->sales();
        $payload = $this->payload([
            $this->averagePosition($catalog['hamburg']->id, $catalog['first']->id),
        ]);

        // 10 × 1,00 × 30 × 1,00 × 1,40 = 420,00
        $preview = $this->actingAs($user)->postJson(route('calculations.preview'), $payload);
        $preview->assertOk();
        $this->assertSame('420.00', $preview->json('totals.media_gross'));
    }

    public function test_unequal_hour_prices_with_surcharge(): void
    {
        $catalog = $this->createSpotFirstLastCatalog();
        $user = $this->sales();
        $activeList = PriceList::query()
            ->where('inventory_id', $catalog['hamburg']->id)
            ->where('status', PriceListStatus::Active)
            ->firstOrFail();

        PriceListItem::query()
            ->where('price_list_id', $activeList->id)
            ->where('hour', 8)
            ->where('day_group', DayGroup::MoFr)
            ->update(['second_price' => '2.0000']);
        PriceListItem::query()
            ->where('price_list_id', $activeList->id)
            ->where('hour', 14)
            ->where('day_group', DayGroup::MoFr)
            ->update(['second_price' => '3.0000']);

        $payload = $this->payload([[
            'inventory_id' => $catalog['hamburg']->id,
            'advertising_medium_id' => $catalog['first']->id,
            'spot_method' => 'average',
            'length_seconds' => 20,
            'position_discount_percent' => '0',
            'ae_percent' => '0',
            'time_ranges' => [
                [
                    'start_hour' => 8,
                    'end_hour_exclusive' => 9,
                    'day_group' => 'mo_fr',
                    'spot_count' => 10,
                    'sort' => 0,
                ],
                [
                    'start_hour' => 14,
                    'end_hour_exclusive' => 15,
                    'day_group' => 'mo_fr',
                    'spot_count' => 5,
                    'sort' => 1,
                ],
            ],
        ]]);

        // 08–09: 10 × 2,00 × 20 × 1,05 × 1,30 = 546,00
        // 14–15:  5 × 3,00 × 20 × 1,05 × 1,30 = 409,50
        // Summe = 955,50
        $preview = $this->actingAs($user)->postJson(route('calculations.preview'), $payload);
        $preview->assertOk();
        $this->assertSame('955.50', $preview->json('totals.media_gross'));
    }
}
