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
use Tests\Concerns\CreatesSpotSingleCatalog;
use Tests\TestCase;

/**
 * PO-SPOT-SINGLE-1: Single × Average auf spot_classic (+50 %, Fail-closed, Snapshot, MAT-003).
 */
class SpotSingleAverageAcceptanceTest extends TestCase
{
    use CreatesSpotSingleCatalog;
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

    public function test_single_average_applies_fifty_percent_preview_save_reload(): void
    {
        $catalog = $this->createSpotSingleCatalog();
        $user = $this->sales();
        $payload = $this->payload([
            $this->averagePosition($catalog['hamburg']->id, $catalog['single']->id),
        ]);

        // 10 × 1,00 × 30 × 1,00 × 1,50 = 450,00
        $preview = $this->actingAs($user)->postJson(route('calculations.preview'), $payload);
        $preview->assertOk();
        $this->assertSame('450.00', $preview->json('totals.media_gross'));

        $this->actingAs($user)->post(route('calculations.store'), $payload)->assertRedirect();
        $calculation = Calculation::query()->firstOrFail()->load('positions');
        $this->assertSame('450.00', (string) $calculation->media_gross);
        $this->assertEqualsWithDelta(50.0, (float) $calculation->positions[0]->surcharge_percent, 0.00001);
        $this->assertSame(CalculationKind::SpotClassic, $calculation->positions[0]->kind);
        $this->assertSame('spot_classic', $calculation->positions[0]->engine_profile_key);

        $fresh = $calculation->fresh(['positions']);
        $this->assertSame('450.00', (string) $fresh->media_gross);
        $this->assertEqualsWithDelta(50.0, (float) $fresh->positions[0]->surcharge_percent, 0.00001);
    }

    public function test_allowed_inventories_all_bookable_with_fifty_percent(): void
    {
        $catalog = $this->createSpotSingleCatalog();
        $user = $this->sales();

        // 2 Spots × Ø-Preis × 30 s × 1,50
        $expectedByCode = [
            'RH' => '90.00',
            'RAH' => '72.00',
            'OAH' => '63.00',
            'CAR' => '54.00',
            'MHK' => '108.00',
        ];

        foreach ($catalog['allowedInventories'] as $inventory) {
            $payload = $this->payload([
                $this->averagePosition($inventory->id, $catalog['single']->id, 2),
            ]);
            $preview = $this->actingAs($user)->postJson(route('calculations.preview'), $payload);
            $preview->assertOk();
            $this->assertSame(
                $expectedByCode[$inventory->code],
                $preview->json('totals.media_gross'),
                $inventory->code,
            );
        }
    }

    public function test_mat003_blocked_inventories_fail_closed(): void
    {
        $catalog = $this->createSpotSingleCatalog();
        $user = $this->sales();

        foreach ([$catalog['moreKombiPlus'], $catalog['bollerwagen'], $catalog['ffn']] as $inventory) {
            $payload = $this->payload([
                $this->averagePosition($inventory->id, $catalog['single']->id),
            ]);
            $this->actingAs($user)->postJson(route('calculations.preview'), $payload)
                ->assertStatus(422)
                ->assertJsonValidationErrors(['positions']);
        }
        $this->assertSame(0, Calculation::query()->count());
    }

    public function test_index_and_surcharge_compound(): void
    {
        $catalog = $this->createSpotSingleCatalog();
        $user = $this->sales();
        $payload = $this->payload([
            $this->averagePosition($catalog['hamburg']->id, $catalog['single']->id, 10, [
                'length_seconds' => 20, // Index 105
            ]),
        ]);

        // 10 × 1,00 × 20 × 1,05 × 1,50 = 315,00
        $preview = $this->actingAs($user)->postJson(route('calculations.preview'), $payload);
        $preview->assertOk();
        $this->assertSame('315.00', $preview->json('totals.media_gross'));
        $this->assertSame(105, $preview->json('totals.positions.0.length_index'));
    }

    public function test_discount_and_ae_after_surcharge(): void
    {
        $catalog = $this->createSpotSingleCatalog();
        $user = $this->sales();
        $payload = $this->withLiveSchemaFingerprint([
            'planning_mode' => 'manual',
            'order_discount_percent' => '10',
            'ae_enabled' => true,
            'positions' => [
                $this->averagePosition($catalog['hamburg']->id, $catalog['single']->id, 10, [
                    'position_discount_percent' => '5',
                    'ae_percent' => '15',
                ]),
            ],
        ]);

        // Brutto 450 → Positionsrabatt 5 % → 427,50 → Auftragsrabatt 10 % → 384,75
        // → AE 15 % → 57,7125 → N/N 327,0375
        $preview = $this->actingAs($user)->postJson(route('calculations.preview'), $payload);
        $preview->assertOk();
        $this->assertSame('450.00', $preview->json('totals.media_gross'));
        $this->assertSame('427.50', $preview->json('totals.positions.0.after_position_discount'));
        $this->assertSame('384.75', $preview->json('totals.positions.0.after_order_discount'));
        $this->assertSame('57.71', $preview->json('totals.positions.0.ae_amount'));
        $this->assertSame('327.04', $preview->json('totals.positions.0.nn_invest'));
    }

    public function test_multi_inventory_single(): void
    {
        $catalog = $this->createSpotSingleCatalog();
        $user = $this->sales();
        $payload = $this->payload([
            $this->averagePosition($catalog['hamburg']->id, $catalog['single']->id, 10),
            $this->averagePosition($catalog['rock']->id, $catalog['single']->id, 5),
        ]);

        // Hamburg: 450,00; Rock: 5 × 0,80 × 30 × 1,00 × 1,50 = 180,00; Summe 630,00
        $preview = $this->actingAs($user)->postJson(route('calculations.preview'), $payload);
        $preview->assertOk();
        $this->assertSame('630.00', $preview->json('totals.media_gross'));
    }

    public function test_fixed_price_settlement_after_surcharge_gross(): void
    {
        $catalog = $this->createSpotSingleCatalog();
        $user = $this->sales();
        $payload = $this->payload([
            $this->averagePosition($catalog['hamburg']->id, $catalog['single']->id, 10, [
                'pricing_settlement_mode' => 'fixed_price',
                'fixed_price_nn' => '400.00',
            ]),
        ]);

        $preview = $this->actingAs($user)->postJson(route('calculations.preview'), $payload);
        $preview->assertOk();
        $this->assertSame('450.00', $preview->json('totals.media_gross'));
        $this->assertSame('400.00', $preview->json('totals.nn_invest'));
        $this->assertSame('fixed_price', $preview->json('totals.positions.0.pricing_settlement_mode'));
    }

    public function test_null_surcharge_fails_closed(): void
    {
        $catalog = $this->createSpotSingleCatalog();
        $catalog['ruleSingleHamburg']->update(['surcharge_percent' => null]);
        $user = $this->sales();
        $payload = $this->payload([
            $this->averagePosition($catalog['hamburg']->id, $catalog['single']->id),
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
        $catalog = $this->createSpotSingleCatalog();
        $catalog['ruleSingleHamburg']->update(['surcharge_percent' => '0']);
        $user = $this->sales();
        $payload = $this->payload([
            $this->averagePosition($catalog['hamburg']->id, $catalog['single']->id),
        ]);

        $response = $this->actingAs($user)->postJson(route('calculations.preview'), $payload);
        $response->assertStatus(422)->assertJsonValidationErrors(['positions']);
        $this->assertStringContainsString('ungültig', (string) $response->json('errors.positions.0'));
        $this->assertSame(0, Calculation::query()->count());
    }

    public function test_negative_surcharge_fails_closed(): void
    {
        $catalog = $this->createSpotSingleCatalog();
        $catalog['ruleSingleHamburg']->update(['surcharge_percent' => '-5']);
        $user = $this->sales();
        $payload = $this->payload([
            $this->averagePosition($catalog['hamburg']->id, $catalog['single']->id),
        ]);

        $this->actingAs($user)->postJson(route('calculations.preview'), $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['positions']);
    }

    public function test_missing_rule_fails_closed(): void
    {
        $catalog = $this->createSpotSingleCatalog();
        $catalog['ruleSingleHamburg']->delete();
        $user = $this->sales();
        $payload = $this->payload([
            $this->averagePosition($catalog['hamburg']->id, $catalog['single']->id),
        ]);

        $this->actingAs($user)->postJson(route('calculations.preview'), $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['positions']);
    }

    public function test_missing_price_list_fails_closed(): void
    {
        $catalog = $this->createSpotSingleCatalog();
        PriceList::query()->where('inventory_id', $catalog['hamburg']->id)->delete();
        $user = $this->sales();
        $payload = $this->payload([
            $this->averagePosition($catalog['hamburg']->id, $catalog['single']->id),
        ]);

        $this->actingAs($user)->postJson(route('calculations.preview'), $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['positions']);
    }

    public function test_snapshot_retains_surcharge_after_rule_change(): void
    {
        $catalog = $this->createSpotSingleCatalog();
        $user = $this->sales();
        $payload = $this->payload([
            $this->averagePosition($catalog['hamburg']->id, $catalog['single']->id),
        ]);

        $this->actingAs($user)->post(route('calculations.store'), $payload)->assertRedirect();
        $calculation = Calculation::query()->firstOrFail()->load('positions');
        $this->assertEqualsWithDelta(50.0, (float) $calculation->positions[0]->surcharge_percent, 0.00001);
        $this->assertSame('450.00', (string) $calculation->media_gross);

        $catalog['ruleSingleHamburg']->update(['surcharge_percent' => '60']);

        $fresh = $calculation->fresh(['positions']);
        $this->assertEqualsWithDelta(50.0, (float) $fresh->positions[0]->surcharge_percent, 0.00001);
        $this->assertSame('450.00', (string) $fresh->media_gross);

        $newPreview = $this->actingAs($user)->postJson(route('calculations.preview'), $payload);
        $newPreview->assertOk();
        // 10 × 1,00 × 30 × 1,00 × 1,60 = 480,00
        $this->assertSame('480.00', $newPreview->json('totals.media_gross'));
    }

    public function test_dispo_snapshot_freezes_fifty_percent(): void
    {
        $catalog = $this->createSpotSingleCatalog();
        $user = $this->sales();
        $this->actingAs($user)->post(route('calculations.store'), $this->payload([
            $this->averagePosition($catalog['hamburg']->id, $catalog['single']->id),
        ]))->assertRedirect();
        $calculation = Calculation::query()->firstOrFail()->load('positions');

        $result = app(DispoOrderWriter::class)->createFromCalculation(
            $calculation,
            [$calculation->positions[0]->id],
            $user,
        );
        $dispo = $result->order->positions()->firstOrFail();

        $this->assertEqualsWithDelta(50.0, (float) $dispo->surcharge_percent, 0.00001);
        $this->assertSame('450.00', (string) $dispo->media_gross);

        $catalog['ruleSingleHamburg']->update(['surcharge_percent' => '60']);
        $this->assertEqualsWithDelta(50.0, (float) $dispo->fresh()->surcharge_percent, 0.00001);
    }

    public function test_classic_remains_unchanged_with_zero_surcharge(): void
    {
        $catalog = $this->createSpotSingleCatalog();
        $user = $this->sales();
        $payload = $this->payload([
            $this->averagePosition($catalog['hamburg']->id, $catalog['classic']->id),
        ]);

        // 10 × 1,00 × 30 × 1,00 × 1,00 = 300,00
        $preview = $this->actingAs($user)->postJson(route('calculations.preview'), $payload);
        $preview->assertOk();
        $this->assertSame('300.00', $preview->json('totals.media_gross'));
    }

    public function test_first_and_last_remain_thirty_percent(): void
    {
        $catalog = $this->createSpotSingleCatalog();
        $user = $this->sales();

        foreach ([$catalog['first'], $catalog['last']] as $medium) {
            $preview = $this->actingAs($user)->postJson(route('calculations.preview'), $this->payload([
                $this->averagePosition($catalog['hamburg']->id, $medium->id),
            ]));
            $preview->assertOk();
            $this->assertSame('390.00', $preview->json('totals.media_gross'));
        }
    }

    public function test_positive_admin_surcharge_other_than_fifty_remains_allowed(): void
    {
        $catalog = $this->createSpotSingleCatalog();
        $catalog['ruleSingleHamburg']->update(['surcharge_percent' => '60']);
        $user = $this->sales();
        $payload = $this->payload([
            $this->averagePosition($catalog['hamburg']->id, $catalog['single']->id),
        ]);

        // 10 × 1,00 × 30 × 1,00 × 1,60 = 480,00
        $preview = $this->actingAs($user)->postJson(route('calculations.preview'), $payload);
        $preview->assertOk();
        $this->assertSame('480.00', $preview->json('totals.media_gross'));
    }

    public function test_unequal_hour_prices_with_surcharge(): void
    {
        $catalog = $this->createSpotSingleCatalog();
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
            'advertising_medium_id' => $catalog['single']->id,
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

        // 08–09: 10 × 2,00 × 20 × 1,05 × 1,50 = 630,00
        // 14–15:  5 × 3,00 × 20 × 1,05 × 1,50 = 472,50
        // Summe = 1102,50
        $preview = $this->actingAs($user)->postJson(route('calculations.preview'), $payload);
        $preview->assertOk();
        $this->assertSame('1102.50', $preview->json('totals.media_gross'));
    }
}
