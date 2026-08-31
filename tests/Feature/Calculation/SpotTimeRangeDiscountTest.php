<?php

namespace Tests\Feature\Calculation;

use App\Enums\Role;
use App\Models\Calculation;
use App\Models\PriceListItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesSpotClassicCatalog;
use Tests\TestCase;

class SpotTimeRangeDiscountTest extends TestCase
{
    use CreatesSpotClassicCatalog;
    use RefreshDatabase;

    public function test_single_hour_payload_migrates_to_equivalent_time_range(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();

        $this->actingAs($user)->post(route('calculations.store'), $this->legacyPayload($catalog, [
            ['inventory_id' => $catalog['hamburg']->id, 'hour' => 8, 'spots' => 10, 'length' => 30],
        ]));

        $calculation = Calculation::query()->firstOrFail()->load(['positions.timeRanges']);
        $range = $calculation->positions[0]->timeRanges->first();

        $this->assertSame('300.00', (string) $calculation->media_gross);
        $this->assertNotNull($range);
        $this->assertSame(8, $range->start_hour);
        $this->assertSame(9, $range->end_hour_exclusive);
        $this->assertSame(10, $range->spot_count);
        $this->assertFalse($calculation->positions[0]->needs_spot_redistribution);
    }

    public function test_ambiguous_legacy_hours_are_not_auto_distributed(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $payload = $this->legacyPayload($catalog, [
            ['inventory_id' => $catalog['hamburg']->id, 'hour' => 8, 'spots' => 10, 'length' => 30],
        ]);
        $payload['positions'][0]['plan_rows'] = [
            ['hour' => 8, 'day_group' => 'mo_fr'],
            ['hour' => 10, 'day_group' => 'mo_fr'],
        ];

        $this->actingAs($user)->post(route('calculations.store'), $payload);
        $calculation = Calculation::query()->firstOrFail()->load(['positions.timeRanges']);

        $this->assertTrue($calculation->positions[0]->needs_spot_redistribution);
        $this->assertCount(0, $calculation->positions[0]->timeRanges);
        $this->assertSame('300.00', (string) $calculation->media_gross);
    }

    public function test_two_time_ranges_are_weighted_separately(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $list = $catalog['hamburg']->priceLists()->firstOrFail();
        PriceListItem::query()->where('price_list_id', $list->id)->where('hour', '>=', 14)->update(['second_price' => '1.5000']);

        $payload = $this->rangePayload($catalog, [
            ['start' => 8, 'end' => 9, 'spots' => 10],
            ['start' => 14, 'end' => 15, 'spots' => 20],
        ]);

        $preview = $this->actingAs($user)->postJson(route('calculations.preview'), $payload);
        $preview->assertOk();
        $this->assertSame('1200.00', $preview->json('totals.media_gross'));
        $this->assertSame(30, $preview->json('totals.positions.0.spot_count'));

        $this->actingAs($user)->post(route('calculations.store'), $payload);
        $calculation = Calculation::query()->firstOrFail()->load(['positions.timeRanges', 'positions.discounts']);

        $this->assertSame($preview->json('totals.nn_invest'), (string) $calculation->nn_invest);
        $this->assertSame('1200.00', (string) $calculation->media_gross);
        $this->assertSame(30, $calculation->positions[0]->total_spot_count);
        $this->assertCount(2, $calculation->positions[0]->timeRanges);
    }

    public function test_overlapping_ranges_in_same_day_group_are_rejected(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();

        $this->actingAs($user)->postJson(route('calculations.store'), $this->rangePayload($catalog, [
            ['start' => 8, 'end' => 13, 'spots' => 10],
            ['start' => 12, 'end' => 18, 'spots' => 10],
        ]))->assertUnprocessable();
    }

    public function test_same_hours_in_different_day_groups_are_allowed(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();

        $payload = $this->rangePayload($catalog, [
            ['start' => 8, 'end' => 12, 'spots' => 10, 'day' => 'mo_fr'],
            ['start' => 8, 'end' => 12, 'spots' => 5, 'day' => 'sa'],
        ]);

        $this->actingAs($user)->post(route('calculations.store'), $payload)->assertRedirect();
        $this->assertSame(15, Calculation::query()->firstOrFail()->positions()->first()?->total_spot_count);
    }

    public function test_zero_and_decimal_spots_are_rejected(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();

        $this->actingAs($user)->postJson(route('calculations.store'), $this->rangePayload($catalog, [
            ['start' => 8, 'end' => 9, 'spots' => 0],
        ]))->assertUnprocessable();

        $payload = $this->rangePayload($catalog, [
            ['start' => 8, 'end' => 9, 'spots' => 10],
        ]);
        $payload['positions'][0]['time_ranges'][0]['spot_count'] = 1.5;

        $this->actingAs($user)->postJson(route('calculations.store'), $payload)->assertUnprocessable();
    }

    public function test_missing_prices_name_sender_day_group_and_hour(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $list = $catalog['hamburg']->priceLists()->firstOrFail();
        PriceListItem::query()->where('price_list_id', $list->id)->where('hour', 9)->delete();

        $response = $this->actingAs($user)->postJson(route('calculations.preview'), $this->rangePayload($catalog, [
            ['start' => 8, 'end' => 12, 'spots' => 10],
        ]));

        $response->assertUnprocessable();
        $this->assertStringContainsString('Radio Hamburg', $response->json('message') ?? json_encode($response->json('errors')));
        $this->assertStringContainsString('Mo–Fr', $response->json('message') ?? json_encode($response->json('errors')));
        $this->assertStringContainsString('09:00', $response->json('message') ?? json_encode($response->json('errors')));
        $this->assertStringContainsString('fehlen Preise für', $response->json('message') ?? json_encode($response->json('errors')));
    }

    public function test_layered_discount_preview_exposes_display_totals_for_summary(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $list = $catalog['hamburg']->priceLists()->firstOrFail();
        PriceListItem::query()->where('price_list_id', $list->id)->where('hour', '>=', 14)->update(['second_price' => '1.5000']);

        $payload = $this->rangePayload($catalog, [
            ['start' => 8, 'end' => 12, 'spots' => 10],
            ['start' => 14, 'end' => 18, 'spots' => 20],
        ]);
        $payload['positions'][0]['position_discounts'] = [
            ['type' => 'quantity', 'percent' => '10'],
            ['type' => 'special', 'percent' => '5'],
        ];
        $payload['order_discounts'] = [
            ['type' => 'quantity', 'percent' => '10'],
        ];
        $payload['ae_enabled'] = false;

        $preview = $this->actingAs($user)->postJson(route('calculations.preview'), $payload);
        $preview->assertOk();
        $this->assertSame('1200.00', $preview->json('totals.media_gross'));
        $this->assertSame('174.00', $preview->json('totals.position_discount_total'));
        $this->assertSame('1026.00', $preview->json('totals.after_position_discount_total'));
        $this->assertSame('102.60', $preview->json('totals.order_discounts.0.amount'));
        $this->assertSame('923.40', $preview->json('totals.after_order_discount_total'));
        $this->assertSame('1026.00', $preview->json('totals.positions.0.after_position_discount'));
        $this->assertSame('923.40', $preview->json('totals.positions.0.nn_invest'));
        $this->assertSame('923.40', $preview->json('totals.nn_invest'));

        $this->actingAs($user)->post(route('calculations.store'), $payload);
        $calculation = Calculation::query()->firstOrFail();

        $this->assertSame('923.40', (string) $calculation->nn_invest);
        $this->assertSame(
            '1026.00',
            number_format((float) $calculation->media_gross - (float) $calculation->position_discount_total, 2, '.', ''),
        );
    }

    public function test_layered_discounts_and_ae_checkbox_round_like_the_engine(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $list = $catalog['hamburg']->priceLists()->firstOrFail();
        PriceListItem::query()->where('price_list_id', $list->id)->update(['second_price' => '4.0000']);

        $payload = $this->rangePayload($catalog, [
            ['start' => 8, 'end' => 9, 'spots' => 10],
        ], length: 25);
        $payload['positions'][0]['position_discounts'] = [
            ['type' => 'quantity', 'percent' => '10'],
            ['type' => 'special', 'percent' => '5'],
        ];
        $payload['order_discounts'] = [
            ['type' => 'quantity', 'percent' => '10'],
        ];
        $payload['ae_enabled'] = true;

        $preview = $this->actingAs($user)->postJson(route('calculations.preview'), $payload);
        $preview->assertOk();
        $this->assertSame('1000.00', $preview->json('totals.media_gross'));
        $this->assertSame('855.00', $preview->json('totals.positions.0.after_position_discount'));
        $this->assertSame('769.50', $preview->json('totals.positions.0.after_order_discount'));
        $this->assertSame('654.08', $preview->json('totals.nn_invest'));

        $this->actingAs($user)->post(route('calculations.store'), $payload);
        $calculation = Calculation::query()->firstOrFail()->load(['positions.discounts', 'orderDiscounts']);

        $this->assertTrue($calculation->ae_enabled);
        $this->assertSame('654.08', (string) $calculation->nn_invest);
        $this->assertCount(2, $calculation->positions[0]->discounts);
        $this->assertCount(1, $calculation->orderDiscounts);
    }

    public function test_custom_discount_label_is_persisted(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $payload = $this->rangePayload($catalog, [
            ['start' => 8, 'end' => 9, 'spots' => 10],
        ]);
        $payload['positions'][0]['position_discounts'] = [
            ['type' => 'other', 'custom_label' => 'Kampagnenrabatt', 'percent' => '10'],
        ];

        $this->actingAs($user)->post(route('calculations.store'), $payload);
        $this->assertSame(
            'Kampagnenrabatt',
            Calculation::query()->firstOrFail()->positions()->first()?->discounts()->first()?->custom_label,
        );
    }

    /**
     * @param  array{hamburg: mixed, medium: mixed}  $catalog
     * @param  list<array{inventory_id: int, hour: int, spots: int, length: int}>  $spots
     * @return array<string, mixed>
     */
    private function legacyPayload(array $catalog, array $spots): array
    {
        return [
            'planning_mode' => 'manual',
            'order_discount_percent' => '0',
            'positions' => array_map(fn (array $spot): array => [
                'inventory_id' => $spot['inventory_id'],
                'advertising_medium_id' => $catalog['medium']->id,
                'spot_method' => 'average',
                'length_seconds' => $spot['length'],
                'total_spot_count' => $spot['spots'],
                'position_discount_percent' => '0',
                'ae_percent' => '0',
                'plan_rows' => [['hour' => $spot['hour'], 'day_group' => 'mo_fr']],
            ], $spots),
        ];
    }

    /**
     * @param  array{hamburg: mixed, medium: mixed}  $catalog
     * @param  list<array{start: int, end: int, spots: int, day?: string}>  $ranges
     * @return array<string, mixed>
     */
    private function rangePayload(array $catalog, array $ranges, int $length = 30): array
    {
        return [
            'planning_mode' => 'manual',
            'order_discount_percent' => '0',
            'ae_enabled' => false,
            'positions' => [[
                'inventory_id' => $catalog['hamburg']->id,
                'advertising_medium_id' => $catalog['medium']->id,
                'spot_method' => 'average',
                'length_seconds' => $length,
                'position_discount_percent' => '0',
                'ae_percent' => '0',
                'time_ranges' => array_map(fn (array $range): array => [
                    'start_hour' => $range['start'],
                    'end_hour_exclusive' => $range['end'],
                    'day_group' => $range['day'] ?? 'mo_fr',
                    'spot_count' => $range['spots'],
                ], $ranges),
            ]],
        ];
    }
}
