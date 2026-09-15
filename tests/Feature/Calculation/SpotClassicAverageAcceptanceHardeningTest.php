<?php

namespace Tests\Feature\Calculation;

use App\Enums\Role;
use App\Models\Calculation;
use App\Models\User;
use App\Services\Calculation\CalculationWriter;
use App\Services\DispoOrder\DispoOrderWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesSpotClassicCatalog;
use Tests\TestCase;

/**
 * BL-P4-02a: gezielte Average-Abnahme-Härtung für AT-01/03/23/24 (fehlende Ebenen).
 *
 * Bereits abgedeckt und hier nicht dupliziert:
 * - AT-01 Kernformel: CalculationEngineTest / TimeRangeCalculationTest / SpotTimeRangeDiscountTest
 * - AT-23 Mehrsender-Kern: SpotClassicCalculationTest::test_cal_001_*
 * - AT-24 Länge frei: SpotClassicCalculationTest::test_spt_015_*
 */
class SpotClassicAverageAcceptanceHardeningTest extends TestCase
{
    use CreatesSpotClassicCatalog;
    use RefreshDatabase;

    public function test_at_01_two_mo_fr_ranges_with_surcharge_and_index_persist(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $catalog['hamburg']->mediumRules()->where('advertising_medium_id', $catalog['medium']->id)
            ->update(['surcharge_percent' => '10']);

        $payload = $this->withLiveSchemaFingerprint([
            'planning_mode' => 'manual',
            'order_discount_percent' => '0',
            'positions' => [[
                'inventory_id' => $catalog['hamburg']->id,
                'advertising_medium_id' => $catalog['medium']->id,
                'spot_method' => 'average',
                'length_seconds' => 30,
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
            ]],
        ]);

        // 10 Spots × 1.00 × 30 × 1.00 × 1.10 + 5 Spots × 1.00 × 30 × 1.00 × 1.10 = 330 + 165 = 495
        $preview = $this->actingAs($user)->postJson(route('calculations.preview'), $payload);
        $preview->assertOk();
        $this->assertSame('495.00', $preview->json('totals.media_gross'));
        $this->assertSame(15, $preview->json('totals.positions.0.spot_count'));
        $this->assertSame(100, $preview->json('totals.positions.0.length_index'));

        $this->actingAs($user)->post(route('calculations.store'), $payload)->assertRedirect();
        $calculation = Calculation::query()->firstOrFail()->load('positions.timeRanges');
        $this->assertSame('495.00', (string) $calculation->media_gross);
        $this->assertSame(100, $calculation->positions[0]->length_index);
        $this->assertCount(2, $calculation->positions[0]->timeRanges);
    }

    public function test_at_03_long_single_spots_remain_calculable_with_index_95(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();

        foreach ([46, 100] as $length) {
            $payload = $this->withLiveSchemaFingerprint([
                'planning_mode' => 'manual',
                'order_discount_percent' => '0',
                'positions' => [[
                    'inventory_id' => $catalog['hamburg']->id,
                    'advertising_medium_id' => $catalog['medium']->id,
                    'spot_method' => 'average',
                    'length_seconds' => $length,
                    'total_spot_count' => 1,
                    'position_discount_percent' => '0',
                    'ae_percent' => '0',
                    'plan_rows' => [['hour' => 8, 'day_group' => 'mo_fr']],
                ]],
            ]);

            $preview = $this->actingAs($user)->postJson(route('calculations.preview'), $payload);
            $preview->assertOk();
            $this->assertSame(95, $preview->json('totals.positions.0.length_index'));

            Calculation::query()->delete();
            $this->actingAs($user)->post(route('calculations.store'), $payload)->assertRedirect();
            $position = Calculation::query()->firstOrFail()->positions()->firstOrFail();
            $this->assertSame($length, $position->length_seconds);
            $this->assertSame(95, $position->length_index);
        }
    }

    public function test_at_23_multi_sender_positions_remain_independently_editable(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();

        $create = $this->withLiveSchemaFingerprint([
            'planning_mode' => 'manual',
            'order_discount_percent' => '0',
            'positions' => [
                [
                    'inventory_id' => $catalog['hamburg']->id,
                    'advertising_medium_id' => $catalog['medium']->id,
                    'spot_method' => 'average',
                    'length_seconds' => 30,
                    'total_spot_count' => 10,
                    'position_discount_percent' => '10',
                    'ae_percent' => '0',
                    'plan_rows' => [['hour' => 8, 'day_group' => 'mo_fr']],
                ],
                [
                    'inventory_id' => $catalog['rock']->id,
                    'advertising_medium_id' => $catalog['medium']->id,
                    'spot_method' => 'average',
                    'length_seconds' => 20,
                    'total_spot_count' => 5,
                    'position_discount_percent' => '0',
                    'ae_percent' => '0',
                    'plan_rows' => [['hour' => 10, 'day_group' => 'mo_fr']],
                ],
            ],
        ]);

        $this->actingAs($user)->post(route('calculations.store'), $create)->assertRedirect();
        $calculation = Calculation::query()->firstOrFail()->load('positions');
        $nnBefore = (string) $calculation->nn_invest;
        $hamburgId = $calculation->positions[0]->id;
        $rockSpotsBefore = $calculation->positions[1]->total_spot_count;

        $payload = app(CalculationWriter::class)
            ->payloadFromCalculation(
                $calculation->fresh(['positions.planRows', 'positions.timeRanges', 'positions.discounts', 'orderDiscounts', 'configurationSnapshot', 'fieldValues']),
            );
        $payload['lock_version'] = $calculation->lock_version;
        $payload['positions'][0]['total_spot_count'] = 12;
        if (($payload['positions'][0]['time_ranges'][0] ?? null) !== null) {
            $payload['positions'][0]['time_ranges'][0]['spot_count'] = 12;
        }

        $this->actingAs($user)->put(route('calculations.update', $calculation), $payload)->assertRedirect();
        $fresh = $calculation->fresh()->load('positions');

        $this->assertSame(12, $fresh->positions->firstWhere('id', $hamburgId)?->total_spot_count);
        $this->assertSame($rockSpotsBefore, $fresh->positions->firstWhere('id', '!=', $hamburgId)?->total_spot_count);
        $this->assertNotSame($nnBefore, (string) $fresh->nn_invest);
        $this->assertCount(2, $fresh->positions);
    }

    public function test_at_24_edited_length_is_used_in_dispo_snapshot_and_reload(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();

        $payload = $this->withLiveSchemaFingerprint([
            'planning_mode' => 'manual',
            'order_discount_percent' => '0',
            'positions' => [[
                'inventory_id' => $catalog['hamburg']->id,
                'advertising_medium_id' => $catalog['medium']->id,
                'spot_method' => 'average',
                'length_seconds' => 30,
                'total_spot_count' => 2,
                'position_discount_percent' => '0',
                'ae_percent' => '0',
                'plan_rows' => [['hour' => 8, 'day_group' => 'mo_fr']],
            ]],
        ]);

        $this->actingAs($user)->post(route('calculations.store'), $payload)->assertRedirect();
        $calculation = Calculation::query()->firstOrFail();
        $this->assertSame(30, $calculation->positions()->first()?->length_seconds);

        $writerPayload = app(CalculationWriter::class)
            ->payloadFromCalculation(
                $calculation->fresh(['positions.planRows', 'positions.timeRanges', 'positions.discounts', 'orderDiscounts', 'configurationSnapshot', 'fieldValues']),
            );
        $writerPayload['lock_version'] = $calculation->lock_version;
        $writerPayload['positions'][0]['length_seconds'] = 22;

        $this->actingAs($user)->put(route('calculations.update', $calculation), $writerPayload)->assertRedirect();
        $calculation = $calculation->fresh()->load('positions');
        $this->assertSame(22, $calculation->positions[0]->length_seconds);
        $this->assertSame(105, $calculation->positions[0]->length_index);

        $result = app(DispoOrderWriter::class)->createFromCalculation(
            $calculation,
            [$calculation->positions[0]->id],
            $user,
        );
        $dispoPosition = $result->order->positions()->firstOrFail();
        $this->assertSame(22, $dispoPosition->length_seconds);
        $this->assertSame(105, $dispoPosition->length_index);

        $this->actingAs($user)->get(route('calculations.edit', $calculation))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('calculation.positions.0.length_seconds', 22));
    }
}
