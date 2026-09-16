<?php

namespace Tests\Feature\Calculation;

use App\Enums\DayGroup;
use App\Enums\PriceListStatus;
use App\Enums\Role;
use App\Models\AdvertisingMedium;
use App\Models\CalculationPosition;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\User;
use App\Services\Calculation\CalculationWriter;
use App\Services\Calculation\CatalogResolver;
use App\Support\Calculation\CalculationMethodFreezeDescriptor;
use App\Support\Calculation\CalculationMethodFreezeResolver;
use App\Support\Calculation\EngineProfileRegistry;
use App\Support\PriceList\PriceListCalendar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreatesSavedCalculation;
use Tests\Concerns\CreatesSpotClassicCatalog;
use Tests\TestCase;

/**
 * BL-P4-02a: Methodenwechsel bei gleichem Inventar/Jahr behält historischen Preislisten-Pin.
 *
 * Jahrwechsel-/409-Verträge bleiben in BL-P4-01c-Tests (PriceListRuntimeYearAndHistoryTest).
 */
class PriceListPinOnMethodChangeTest extends TestCase
{
    use CreatesSavedCalculation;
    use CreatesSpotClassicCatalog;
    use RefreshDatabase;

    public function test_method_change_keeps_historical_price_list_pin_after_newer_active(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $calculation = $this->createSavedCalculation($catalog, [
            ['inventory_id' => $catalog['hamburg']->id],
        ], $user);

        $position = $calculation->positions()->firstOrFail();
        $pinnedId = $position->price_list_id;
        $pinnedVersion = $position->price_list_version;

        $newer = $this->activateNewerListForSameYear($catalog['hamburg']->id, $pinnedId);

        $payload = $this->positionPayloadFromExisting($position, [
            'calculation_method_key' => 'calendar',
            'spot_method' => 'calendar',
            'planner_entries' => [
                ['date' => '2026-09-14', 'hour' => 8, 'spot_count' => (int) $position->total_spot_count],
            ],
            'time_ranges' => [],
            'plan_rows' => [],
        ]);

        $resolved = (new CatalogResolver)->resolvePosition($payload, $position->fresh());

        $this->assertSame($pinnedId, $resolved['priceList']->id);
        $this->assertSame($pinnedVersion, $resolved['priceList']->version);
        $this->assertNotSame($newer->id, $resolved['priceList']->id);
        $this->assertSame('calendar', $resolved['freeze']->calculationMethodKey);
        $this->assertSame('average', $position->fresh()->calculation_method_key); // nur Resolve, kein Save
        $this->assertSame($pinnedId, $position->fresh()->price_list_id);
    }

    public function test_noop_save_keeps_pin_after_newer_active_list(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $calculation = $this->createSavedCalculation($catalog, [
            ['inventory_id' => $catalog['hamburg']->id],
        ], $user);
        $position = $calculation->positions()->firstOrFail();
        $pinnedId = $position->price_list_id;
        $pinnedVersion = $position->price_list_version;

        $this->activateNewerListForSameYear($catalog['hamburg']->id, $pinnedId);

        $writer = app(CalculationWriter::class);
        $payload = $writer->payloadFromCalculation(
            $calculation->fresh(['positions.planRows', 'positions.timeRanges', 'positions.discounts', 'orderDiscounts', 'configurationSnapshot', 'fieldValues']),
        );
        $payload['lock_version'] = $calculation->lock_version;

        $updated = $writer->update($calculation->fresh(), $payload, $user);
        $fresh = $updated->positions()->firstOrFail();

        $this->assertSame($pinnedId, $fresh->price_list_id);
        $this->assertSame($pinnedVersion, $fresh->price_list_version);
        $this->assertSame('average', $fresh->calculation_method_key);
    }

    public function test_planned_fixed_price_method_change_is_rejected_without_rebinding_pin(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $calculation = $this->createSavedCalculation($catalog, [
            ['inventory_id' => $catalog['hamburg']->id],
        ], $user);
        $position = $calculation->positions()->firstOrFail();
        $pinnedId = $position->price_list_id;
        $pinnedVersion = $position->price_list_version;

        $this->activateNewerListForSameYear($catalog['hamburg']->id, $pinnedId);

        $writer = app(CalculationWriter::class);
        $payload = $writer->payloadFromCalculation(
            $calculation->fresh(['positions.planRows', 'positions.timeRanges', 'positions.discounts', 'orderDiscounts', 'configurationSnapshot', 'fieldValues']),
        );
        $payload['lock_version'] = $calculation->lock_version;
        $payload['positions'][0]['calculation_method_key'] = 'fixed_price';
        $payload['positions'][0]['spot_method'] = 'fixed_price';

        try {
            $writer->update($calculation->fresh(), $payload, $user);
            $this->fail('Erwartete ValidationException bei geplanter Methode.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                ['Die Berechnungsmethode ist für dieses Werbemittel nicht mehr verfügbar. Bitte Auswahl aktualisieren.'],
                $exception->errors()['positions'] ?? null,
            );
        }

        $fresh = $calculation->fresh()->positions()->firstOrFail();
        $this->assertSame($pinnedId, $fresh->price_list_id);
        $this->assertSame($pinnedVersion, $fresh->price_list_version);
        $this->assertSame('average', $fresh->calculation_method_key);
        $this->assertSame(
            'planned',
            EngineProfileRegistry::pairStatus(
                EngineProfileRegistry::PROFILE_SPOT_CLASSIC,
                'fixed_price',
            )->value,
        );
    }

    public function test_new_position_still_binds_active_list(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $calculation = $this->createSavedCalculation($catalog, [
            ['inventory_id' => $catalog['hamburg']->id],
        ], $user);
        $position = $calculation->positions()->firstOrFail();
        $pinnedId = $position->price_list_id;

        $newer = $this->activateNewerListForSameYear($catalog['hamburg']->id, $pinnedId);

        $writer = app(CalculationWriter::class);
        $payload = $writer->payloadFromCalculation(
            $calculation->fresh(['positions.planRows', 'positions.timeRanges', 'positions.discounts', 'orderDiscounts', 'configurationSnapshot', 'fieldValues']),
        );
        $payload['lock_version'] = $calculation->lock_version;
        $payload['positions'][] = [
            'inventory_id' => $catalog['hamburg']->id,
            'advertising_medium_id' => $catalog['medium']->id,
            'schema_fingerprint' => $payload['positions'][0]['schema_fingerprint'],
            'spot_method' => 'average',
            'calculation_method_key' => 'average',
            'length_seconds' => 30,
            'total_spot_count' => 1,
            'position_discount_percent' => '0',
            'ae_percent' => '0',
            'plan_rows' => [['hour' => 8, 'day_group' => 'mo_fr']],
            'price_year' => PriceListCalendar::currentYear(),
            'expected_price_list_id' => $newer->id,
        ];

        $updated = $writer->update($calculation->fresh(), $payload, $user);
        $positions = $updated->positions()->orderBy('id')->get();

        $this->assertSame($pinnedId, $positions[0]->price_list_id);
        $this->assertSame($newer->id, $positions[1]->price_list_id);
    }

    public function test_inventory_change_still_live_binds_active_list(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $calculation = $this->createSavedCalculation($catalog, [
            ['inventory_id' => $catalog['hamburg']->id],
        ], $user);
        $position = $calculation->positions()->firstOrFail();
        $pinnedId = $position->price_list_id;
        $pinnedVersion = $position->price_list_version;

        $this->activateNewerListForSameYear($catalog['hamburg']->id, $pinnedId);

        $rockActive = PriceList::query()
            ->where('inventory_id', $catalog['rock']->id)
            ->where('status', PriceListStatus::Active)
            ->where('year', PriceListCalendar::currentYear())
            ->firstOrFail();

        $writer = app(CalculationWriter::class);
        $payload = $writer->payloadFromCalculation(
            $calculation->fresh(['positions.planRows', 'positions.timeRanges', 'positions.discounts', 'orderDiscounts', 'configurationSnapshot', 'fieldValues']),
        );
        $payload['lock_version'] = $calculation->lock_version;
        $payload['positions'][0]['inventory_id'] = $catalog['rock']->id;
        $payload['positions'][0]['price_year'] = PriceListCalendar::currentYear();
        $payload['positions'][0]['expected_price_list_id'] = $rockActive->id;

        $updated = $writer->update($calculation->fresh(), $payload, $user);
        $fresh = $updated->positions()->firstOrFail();

        $this->assertSame($rockActive->id, $fresh->price_list_id);
        $this->assertNotSame($pinnedId, $fresh->price_list_id);
        $this->assertNotSame($pinnedVersion, $fresh->price_list_version);
        $this->assertSame($catalog['rock']->id, $fresh->inventory_id);
    }

    private function calendarAllowingFreezeResolver(): CalculationMethodFreezeResolver
    {
        return new class extends CalculationMethodFreezeResolver
        {
            public function resolveForNewCombination(
                AdvertisingMedium $medium,
                ?string $requestedMethodKey,
            ): CalculationMethodFreezeDescriptor {
                $requested = $requestedMethodKey !== null ? trim($requestedMethodKey) : '';

                if ($requested === 'calendar') {
                    return new CalculationMethodFreezeDescriptor(
                        engineProfileKey: EngineProfileRegistry::PROFILE_SPOT_CLASSIC,
                        calculationMethodKey: 'calendar',
                        calculationMethodName: 'Kalenderplaner',
                        algorithmVersion: 'v1',
                    );
                }

                return parent::resolveForNewCombination($medium, $requestedMethodKey);
            }
        };
    }

    private function activateNewerListForSameYear(int $inventoryId, int $previousActiveId): PriceList
    {
        $year = PriceListCalendar::currentYear();
        /** @var PriceList $previous */
        $previous = PriceList::query()->whereKey($previousActiveId)->firstOrFail();
        $previous->update(['status' => PriceListStatus::Archived]);

        $newer = PriceList::factory()->create([
            'inventory_id' => $inventoryId,
            'year' => $year,
            'status' => PriceListStatus::Active,
            'version' => 'newer-RH',
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

        return $newer;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function positionPayloadFromExisting(CalculationPosition $position, array $overrides = []): array
    {
        $position->loadMissing(['planRows', 'timeRanges']);

        $base = [
            'inventory_id' => (int) $position->inventory_id,
            'advertising_medium_id' => (int) $position->advertising_medium_id,
            'spot_method' => $position->spot_method?->value ?? 'average',
            'calculation_method_key' => $position->calculation_method_key,
            'length_seconds' => (int) $position->length_seconds,
            'total_spot_count' => (int) $position->total_spot_count,
            'position_discount_percent' => (string) $position->position_discount_percent,
            'ae_percent' => (string) $position->ae_percent,
            'plan_rows' => $position->planRows->map(fn ($row): array => [
                'hour' => (int) $row->hour,
                'day_group' => $row->day_group->value,
            ])->all(),
            'time_ranges' => $position->timeRanges->map(fn ($range): array => [
                'start_hour' => (int) $range->start_hour,
                'end_hour_exclusive' => (int) $range->end_hour_exclusive,
                'day_group' => $range->day_group->value,
                'spot_count' => (int) $range->spot_count,
                'sort' => (int) $range->sort,
            ])->all(),
            'price_year' => PriceListCalendar::currentYear(),
        ];

        return array_merge($base, $overrides);
    }
}
