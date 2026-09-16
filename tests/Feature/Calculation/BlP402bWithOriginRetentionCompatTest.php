<?php

namespace Tests\Feature\Calculation;

use App\Enums\DayGroup;
use App\Enums\PriceListStatus;
use App\Enums\Role;
use App\Models\CalculationPosition;
use App\Models\ConfigurationSnapshot;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\User;
use App\Services\Calculation\CalculationWriter;
use App\Services\DispoOrder\DispoOrderWriter;
use App\Services\DynamicField\ConfigurationSnapshotFreezeService;
use App\Support\PriceList\PriceListCalendar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesSpotClassicCatalog;
use Tests\TestCase;

/**
 * BL-P4-02b: Kalenderposition + historische Dispo-Origin-Retention (analog 02a).
 */
class BlP402bWithOriginRetentionCompatTest extends TestCase
{
    use CreatesSpotClassicCatalog;
    use RefreshDatabase;

    public function test_noop_then_remove_calendar_position_keeps_pin_and_dispo_origin(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $fingerprint = app(ConfigurationSnapshotFreezeService::class)
            ->resolveLiveSchemaForCalculationV3()['schema_fingerprint'];
        $positionFingerprint = app(ConfigurationSnapshotFreezeService::class)
            ->resolveLivePositionSchema((int) $catalog['medium']->id)['schema_fingerprint'];

        $calculation = app(CalculationWriter::class)->create([
            'planning_mode' => 'manual',
            'customer_name' => 'Kalender Compat GmbH',
            'order_discount_percent' => '0',
            'schema_fingerprint' => $fingerprint,
            'positions' => [
                [
                    'inventory_id' => $catalog['hamburg']->id,
                    'advertising_medium_id' => $catalog['medium']->id,
                    'schema_fingerprint' => $positionFingerprint,
                    'spot_method' => 'average',
                    'calculation_method_key' => 'average',
                    'length_seconds' => 30,
                    'position_discount_percent' => '0',
                    'ae_percent' => '0',
                    'plan_rows' => [['hour' => 8, 'day_group' => 'mo_fr']],
                    'time_ranges' => [[
                        'start_hour' => 8,
                        'end_hour_exclusive' => 9,
                        'day_group' => 'mo_fr',
                        'spot_count' => 5,
                        'sort' => 0,
                    ]],
                ],
                [
                    'inventory_id' => $catalog['rock']->id,
                    'advertising_medium_id' => $catalog['medium']->id,
                    'schema_fingerprint' => $positionFingerprint,
                    'spot_method' => 'calendar',
                    'calculation_method_key' => 'calendar',
                    'length_seconds' => 30,
                    'position_discount_percent' => '0',
                    'ae_percent' => '0',
                    'planner_entries' => [
                        ['date' => '2026-09-14', 'hour' => 8, 'spot_count' => 3],
                    ],
                ],
            ],
        ], $user);

        $positions = $calculation->positions()->orderBy('id')->get();
        $this->assertCount(2, $positions);
        $kept = $positions[0];
        $removed = $positions[1];
        $keptPinId = (int) $kept->price_list_id;
        $keptPinVersion = (string) $kept->price_list_version;
        $historicalCalcEffectiveId = (int) $removed->effective_configuration_snapshot_id;

        $order = app(DispoOrderWriter::class)
            ->createFromCalculation($calculation, [(int) $removed->id], $user)
            ->order;
        $dispoEffectiveId = (int) $order->positions()->firstOrFail()->effective_configuration_snapshot_id;
        $this->assertSame(
            $historicalCalcEffectiveId,
            (int) ConfigurationSnapshot::query()->findOrFail($dispoEffectiveId)->source_configuration_snapshot_id,
        );

        /** @var PriceList $previous */
        $previous = PriceList::query()->whereKey($keptPinId)->firstOrFail();
        $previous->update(['status' => PriceListStatus::Archived]);
        $newer = PriceList::factory()->create([
            'inventory_id' => $catalog['hamburg']->id,
            'year' => PriceListCalendar::currentYear(),
            'status' => PriceListStatus::Active,
            'version' => 'calendar-compat-newer-RH',
            'revision_number' => ((int) $previous->revision_number) + 1,
            'valid_from' => $previous->valid_from,
        ]);
        foreach (range(0, 23) as $hour) {
            foreach ([DayGroup::MoFr, DayGroup::Sa, DayGroup::So] as $group) {
                PriceListItem::factory()->create([
                    'price_list_id' => $newer->id,
                    'hour' => $hour,
                    'day_group' => $group,
                    'second_price' => '9.0000',
                ]);
            }
        }

        $writer = app(CalculationWriter::class);
        $payload = $writer->payloadFromCalculation(
            $calculation->fresh([
                'positions.plannerEntries',
                'positions.planRows',
                'positions.timeRanges',
                'positions.discounts',
                'orderDiscounts',
                'configurationSnapshot',
                'fieldValues',
            ]),
        );
        $payload['lock_version'] = $calculation->lock_version;
        $this->assertCount(2, $payload['positions']);

        $afterNoop = $writer->update($calculation->fresh(), $payload, $user);
        $this->assertCount(2, $afterNoop->positions);
        $keptFresh = $afterNoop->positions()->whereKey($kept->id)->firstOrFail();
        $this->assertSame($keptPinId, (int) $keptFresh->price_list_id);
        $this->assertSame($keptPinVersion, (string) $keptFresh->price_list_version);
        $this->assertNotSame($newer->id, (int) $keptFresh->price_list_id);

        $payload = $writer->payloadFromCalculation(
            $afterNoop->fresh([
                'positions.plannerEntries',
                'positions.planRows',
                'positions.timeRanges',
                'positions.discounts',
                'orderDiscounts',
                'configurationSnapshot',
                'fieldValues',
            ]),
        );
        $payload['lock_version'] = $afterNoop->lock_version;
        $payload['positions'] = array_values(array_filter(
            $payload['positions'],
            fn (array $row): bool => (int) ($row['id'] ?? 0) === (int) $kept->id,
        ));

        $updated = $writer->update($afterNoop->fresh(), $payload, $user);

        $this->assertSame(1, $updated->positions()->count());
        $this->assertNull(CalculationPosition::query()->find($removed->id));
        $this->assertTrue(ConfigurationSnapshot::query()->whereKey($historicalCalcEffectiveId)->exists());

        $dispoEffective = ConfigurationSnapshot::query()->findOrFail($dispoEffectiveId);
        $this->assertSame($historicalCalcEffectiveId, (int) $dispoEffective->source_configuration_snapshot_id);
        $dispoEffective->assertReadable();

        $remaining = $updated->positions()->firstOrFail();
        $this->assertSame($keptPinId, (int) $remaining->price_list_id);
        $this->assertSame($keptPinVersion, (string) $remaining->price_list_version);
    }
}
