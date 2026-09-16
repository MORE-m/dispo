<?php

namespace Tests\Feature\Calculation;

use App\Enums\DayGroup;
use App\Enums\PriceListStatus;
use App\Enums\Role;
use App\Models\DispoOrder;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\User;
use App\Services\Calculation\CalculationWriter;
use App\Services\DispoOrder\DispoOrderWriter;
use App\Services\DynamicField\ConfigurationSnapshotFreezeService;
use App\Support\PriceList\PriceListCalendar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesSavedCalculation;
use Tests\Concerns\CreatesSpotClassicCatalog;
use Tests\TestCase;

class SpotClassicCalendarCalculationTest extends TestCase
{
    use CreatesSavedCalculation;
    use CreatesSpotClassicCatalog;
    use RefreshDatabase;

    public function test_preview_matches_store_for_calendar_position(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $payload = $this->calendarPayload($catalog, [
            ['date' => '2026-09-14', 'hour' => 8, 'spot_count' => 10],
            ['date' => '2026-09-14', 'hour' => 14, 'spot_count' => 5],
        ]);

        $writer = app(CalculationWriter::class);
        $preview = $writer->preview($payload, $user)->toArray();

        $calculation = $writer->create($payload, $user);
        $position = $calculation->positions()->firstOrFail();

        $this->assertSame('calendar', $position->calculation_method_key);
        $this->assertSame(15, $position->total_spot_count);
        $this->assertSame($preview['media_gross'], (string) $calculation->media_gross);
        $this->assertSame($preview['positions'][0]['media_gross'], (string) $position->media_gross);
        $this->assertCount(2, $position->plannerEntries);
        $this->assertSame(0, $position->timeRanges()->count());
    }

    public function test_missing_price_fail_closed(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();

        $activeList = PriceList::query()
            ->where('inventory_id', $catalog['hamburg']->id)
            ->where('status', PriceListStatus::Active)
            ->firstOrFail();

        PriceListItem::query()
            ->where('price_list_id', $activeList->id)
            ->where('hour', 22)
            ->delete();

        $payload = $this->calendarPayload($catalog, [
            ['date' => '2026-09-14', 'hour' => 22, 'spot_count' => 1],
        ]);

        $this->expectExceptionMessage('fehlen Preise');

        app(CalculationWriter::class)->preview($payload, $user);
    }

    public function test_payload_roundtrip_includes_planner_entries(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $calculation = app(CalculationWriter::class)->create(
            $this->calendarPayload($catalog, [
                ['date' => '2026-09-19', 'hour' => 9, 'spot_count' => 3],
            ]),
            $user,
        );

        $roundtrip = app(CalculationWriter::class)->payloadFromCalculation(
            $calculation->fresh(['positions.plannerEntries', 'positions.planRows', 'positions.timeRanges', 'positions.discounts', 'orderDiscounts', 'configurationSnapshot', 'fieldValues']),
        );

        $this->assertSame('calendar', $roundtrip['positions'][0]['spot_method']);
        $this->assertSame([
            ['date' => '2026-09-19', 'hour' => 9, 'spot_count' => 3],
        ], $roundtrip['positions'][0]['planner_entries']);
    }

    public function test_validation_rejects_average_with_planner_entries(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $payload = $this->calendarPayload($catalog, [
            ['date' => '2026-09-14', 'hour' => 8, 'spot_count' => 1],
        ]);
        $payload['positions'][0]['spot_method'] = 'average';
        $payload['positions'][0]['calculation_method_key'] = 'average';
        $payload['positions'][0]['plan_rows'] = [['hour' => 8, 'day_group' => 'mo_fr']];

        $this->actingAs($user)->postJson(route('calculations.store'), $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['positions.0.planner_entries']);
    }

    public function test_average_to_calendar_method_change_keeps_price_list_pin(): void
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

        $writer = app(CalculationWriter::class);
        $payload = $writer->payloadFromCalculation(
            $calculation->fresh(['positions.planRows', 'positions.timeRanges', 'positions.discounts', 'orderDiscounts', 'configurationSnapshot', 'fieldValues']),
        );
        $payload['lock_version'] = $calculation->lock_version;
        $payload['positions'][0]['spot_method'] = 'calendar';
        $payload['positions'][0]['calculation_method_key'] = 'calendar';
        $payload['positions'][0]['planner_entries'] = [
            ['date' => '2026-09-14', 'hour' => 8, 'spot_count' => (int) $position->total_spot_count],
        ];
        $payload['positions'][0]['plan_rows'] = [];
        $payload['positions'][0]['time_ranges'] = [];

        $updated = $writer->update($calculation->fresh(), $payload, $user);
        $fresh = $updated->positions()->firstOrFail();

        $this->assertSame($pinnedId, $fresh->price_list_id);
        $this->assertSame($pinnedVersion, $fresh->price_list_version);
        $this->assertNotSame($newer->id, $fresh->price_list_id);
        $this->assertSame('calendar', $fresh->calculation_method_key);
    }

    public function test_calendar_to_average_method_change_keeps_price_list_pin(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $calculation = app(CalculationWriter::class)->create(
            $this->calendarPayload($catalog, [
                ['date' => '2026-09-14', 'hour' => 8, 'spot_count' => 5],
            ]),
            $user,
        );

        $position = $calculation->positions()->firstOrFail();
        $pinnedId = $position->price_list_id;
        $pinnedVersion = $position->price_list_version;

        $newer = $this->activateNewerListForSameYear($catalog['hamburg']->id, $pinnedId);

        $writer = app(CalculationWriter::class);
        $payload = $writer->payloadFromCalculation(
            $calculation->fresh(['positions.plannerEntries', 'positions.planRows', 'positions.timeRanges', 'positions.discounts', 'orderDiscounts', 'configurationSnapshot', 'fieldValues']),
        );
        $payload['lock_version'] = $calculation->lock_version;
        $payload['positions'][0]['spot_method'] = 'average';
        $payload['positions'][0]['calculation_method_key'] = 'average';
        $payload['positions'][0]['planner_entries'] = [];
        $payload['positions'][0]['plan_rows'] = [['hour' => 8, 'day_group' => 'mo_fr']];
        $payload['positions'][0]['time_ranges'] = [[
            'start_hour' => 8,
            'end_hour_exclusive' => 9,
            'day_group' => 'mo_fr',
            'spot_count' => 5,
            'sort' => 0,
        ]];

        $updated = $writer->update($calculation->fresh(), $payload, $user);
        $fresh = $updated->positions()->firstOrFail();

        $this->assertSame($pinnedId, $fresh->price_list_id);
        $this->assertSame($pinnedVersion, $fresh->price_list_version);
        $this->assertNotSame($newer->id, $fresh->price_list_id);
        $this->assertSame('average', $fresh->calculation_method_key);
    }

    public function test_multi_position_average_and_calendar(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $fingerprint = app(ConfigurationSnapshotFreezeService::class)
            ->resolveLiveSchemaForCalculationV3()['schema_fingerprint'];
        $positionFingerprint = app(ConfigurationSnapshotFreezeService::class)
            ->resolveLivePositionSchema((int) $catalog['medium']->id)['schema_fingerprint'];

        $payload = [
            'planning_mode' => 'manual',
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
                        'spot_count' => 2,
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
                        ['date' => '2026-09-14', 'hour' => 10, 'spot_count' => 4],
                    ],
                ],
            ],
        ];

        $calculation = app(CalculationWriter::class)->create($payload, $user);
        $positions = $calculation->positions()->orderBy('id')->get();

        $this->assertCount(2, $positions);
        $this->assertSame('average', $positions[0]->calculation_method_key);
        $this->assertSame(2, $positions[0]->total_spot_count);
        $this->assertSame('calendar', $positions[1]->calculation_method_key);
        $this->assertSame(4, $positions[1]->total_spot_count);
        $this->assertCount(1, $positions[1]->plannerEntries);
    }

    public function test_stale_lock_version_rejects_update(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $calculation = app(CalculationWriter::class)->create(
            $this->calendarPayload($catalog, [
                ['date' => '2026-09-14', 'hour' => 8, 'spot_count' => 1],
            ]),
            $user,
        );

        $writer = app(CalculationWriter::class);
        $payload = $writer->payloadFromCalculation(
            $calculation->fresh(['positions.plannerEntries', 'positions.planRows', 'positions.timeRanges', 'positions.discounts', 'orderDiscounts', 'configurationSnapshot', 'fieldValues']),
        );
        $payload['lock_version'] = 99;

        try {
            $writer->update($calculation->fresh(), $payload, $user);
            $this->fail('Erwartete ValidationException bei veraltetem lock_version.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                ['Die Kalkulation wurde parallel geändert. Bitte neu laden.'],
                $exception->errors()['lock_version'] ?? null,
            );
        }
    }

    public function test_sa_and_so_day_group_prices(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();

        $activeList = PriceList::query()
            ->where('inventory_id', $catalog['hamburg']->id)
            ->where('status', PriceListStatus::Active)
            ->firstOrFail();

        PriceListItem::query()
            ->where('price_list_id', $activeList->id)
            ->where('hour', 8)
            ->where('day_group', DayGroup::Sa)
            ->update(['second_price' => '2.0000']);
        PriceListItem::query()
            ->where('price_list_id', $activeList->id)
            ->where('hour', 8)
            ->where('day_group', DayGroup::So)
            ->update(['second_price' => '3.0000']);

        $payload = $this->calendarPayload($catalog, [
            ['date' => '2026-09-19', 'hour' => 8, 'spot_count' => 1],
            ['date' => '2026-09-20', 'hour' => 8, 'spot_count' => 1],
        ]);

        $preview = app(CalculationWriter::class)->preview($payload, $user)->toArray();
        $entries = $preview['positions'][0]['planner_entries'] ?? [];
        $this->assertCount(2, $entries);
        $this->assertSame('sa', $entries[0]['day_group']);
        $this->assertSame('so', $entries[1]['day_group']);
        $this->assertSame('60.00', $entries[0]['line_gross']);
        $this->assertSame('90.00', $entries[1]['line_gross']);
        $this->assertNotSame($entries[0]['line_gross'], $entries[1]['line_gross']);
    }

    public function test_http_rejects_negative_and_non_integer_spot_count(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();

        $negative = $this->calendarPayload($catalog, [
            ['date' => '2026-09-14', 'hour' => 8, 'spot_count' => -1],
        ]);
        $this->actingAs($user)->postJson(route('calculations.store'), $negative)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['positions.0.planner_entries.0.spot_count']);

        $nonInteger = $this->calendarPayload($catalog, [
            ['date' => '2026-09-14', 'hour' => 8, 'spot_count' => 1.5],
        ]);
        $this->actingAs($user)->postJson(route('calculations.store'), $nonInteger)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['positions.0.planner_entries.0.spot_count']);
    }

    public function test_planner_dates_in_price_year_succeed(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $year = PriceListCalendar::currentYear();
        $date = sprintf('%04d-09-14', $year);

        $calculation = app(CalculationWriter::class)->create(
            $this->calendarPayload($catalog, [
                ['date' => $date, 'hour' => 8, 'spot_count' => 2],
            ]),
            $user,
        );

        $this->assertCount(1, $calculation->positions()->firstOrFail()->plannerEntries);
    }

    public function test_planner_date_in_following_year_fail_closed(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $nextYear = PriceListCalendar::currentYear() + 1;
        $date = sprintf('%04d-01-15', $nextYear);

        $this->expectException(ValidationException::class);

        try {
            app(CalculationWriter::class)->preview(
                $this->calendarPayload($catalog, [
                    ['date' => $date, 'hour' => 8, 'spot_count' => 1],
                ]),
                $user,
            );
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('positions.0.planner_entries.0.date', $exception->errors());
            $message = implode(' ', $exception->errors()['positions.0.planner_entries.0.date']);
            $this->assertStringContainsString($date, $message);
            $this->assertStringContainsString('getrennte Position', $message);

            throw $exception;
        }
    }

    public function test_mixed_planner_years_in_one_position_fail_closed(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $year = PriceListCalendar::currentYear();
        $nextYear = $year + 1;

        $this->expectException(ValidationException::class);

        try {
            app(CalculationWriter::class)->preview(
                $this->calendarPayload($catalog, [
                    ['date' => sprintf('%04d-09-14', $year), 'hour' => 8, 'spot_count' => 1],
                    ['date' => sprintf('%04d-01-10', $nextYear), 'hour' => 9, 'spot_count' => 1],
                ]),
                $user,
            );
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('positions.0.planner_entries.1.date', $exception->errors());

            throw $exception;
        }
    }

    public function test_explicit_price_year_change_with_matching_dates_succeeds(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $nextYear = PriceListCalendar::currentYear() + 1;
        $nextList = $this->createActivePriceListForYear($catalog['hamburg']->id, $nextYear, 'calendar-next-RH');
        $date = sprintf('%04d-03-20', $nextYear);

        $calculation = app(CalculationWriter::class)->create(
            $this->calendarPayload($catalog, [
                ['date' => $date, 'hour' => 8, 'spot_count' => 4],
            ], priceYear: $nextYear, expectedPriceListId: $nextList->id),
            $user,
        );

        $position = $calculation->positions()->firstOrFail();
        $this->assertSame($nextList->id, $position->price_list_id);
        $this->assertSame($date, $position->plannerEntries->first()->dateIso());
    }

    public function test_explicit_price_year_change_with_wrong_dates_fail_closed(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $currentYear = PriceListCalendar::currentYear();
        $nextYear = $currentYear + 1;
        $nextList = $this->createActivePriceListForYear($catalog['hamburg']->id, $nextYear, 'calendar-next-mismatch');

        $this->expectException(ValidationException::class);

        try {
            app(CalculationWriter::class)->preview(
                $this->calendarPayload($catalog, [
                    ['date' => sprintf('%04d-09-14', $currentYear), 'hour' => 8, 'spot_count' => 1],
                ], priceYear: $nextYear, expectedPriceListId: $nextList->id),
                $user,
            );
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('positions.0.planner_entries.0.date', $exception->errors());

            throw $exception;
        }
    }

    public function test_year_contract_preview_matches_store(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $year = PriceListCalendar::currentYear();
        $payload = $this->calendarPayload($catalog, [
            ['date' => sprintf('%04d-12-01', $year), 'hour' => 10, 'spot_count' => 3],
        ]);

        $writer = app(CalculationWriter::class);
        $preview = $writer->preview($payload, $user)->toArray();

        try {
            $writer->create($payload, $user);
        } catch (ValidationException $exception) {
            $this->fail('Store sollte dieselbe Jahresprüfung wie Preview bestehen: '.json_encode($exception->errors()));
        }

        $this->assertSame(
            $preview['positions'][0]['planner_entries'][0]['date'] ?? null,
            sprintf('%04d-12-01', $year),
        );
    }

    public function test_dispo_from_calendar_includes_planner_entries_in_show_props(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $year = PriceListCalendar::currentYear();
        $date = sprintf('%04d-09-19', $year);

        $calculation = app(CalculationWriter::class)->create(
            $this->calendarPayload($catalog, [
                ['date' => $date, 'hour' => 9, 'spot_count' => 3],
            ]),
            $user,
        );
        $position = $calculation->positions()->firstOrFail();

        $order = app(DispoOrderWriter::class)
            ->createFromCalculation($calculation, [$position->id], $user)
            ->order;

        $snapshot = json_encode($order->positions()->firstOrFail()->planner_entries_snapshot);
        $this->assertStringContainsString($date, (string) $snapshot);

        $this->actingAs($user)
            ->get(route('dispo-orders.show', $order))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('dispo-orders/show')
                ->has('order.positions', 1)
                ->where('order.positions.0.planner_entries.0.date', $date)
                ->where('order.positions.0.planner_entries.0.hour', 9)
                ->where('order.positions.0.planner_entries.0.spot_count', 3));
    }

    public function test_dispo_planner_snapshot_unchanged_after_calculation_update(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $year = PriceListCalendar::currentYear();
        $date = sprintf('%04d-09-14', $year);

        $calculation = app(CalculationWriter::class)->create(
            $this->calendarPayload($catalog, [
                ['date' => $date, 'hour' => 8, 'spot_count' => 5],
            ]),
            $user,
        );
        $position = $calculation->positions()->firstOrFail();

        app(DispoOrderWriter::class)->createFromCalculation($calculation, [$position->id], $user);
        $order = DispoOrder::query()->with('positions')->firstOrFail();
        $originalSnapshot = $order->positions->first()->planner_entries_snapshot;

        $writer = app(CalculationWriter::class);
        $payload = $writer->payloadFromCalculation(
            $calculation->fresh(['positions.plannerEntries', 'positions.planRows', 'positions.timeRanges', 'positions.discounts', 'orderDiscounts', 'configurationSnapshot', 'fieldValues']),
        );
        $payload['lock_version'] = $calculation->lock_version;
        $payload['positions'][0]['planner_entries'] = [
            ['date' => $date, 'hour' => 14, 'spot_count' => 99],
        ];
        $writer->update($calculation->fresh(), $payload, $user);

        $order->refresh()->load('positions');
        $this->assertSame($originalSnapshot, $order->positions->first()->planner_entries_snapshot);
    }

    public function test_zero_spot_count_entry_is_skipped(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $payload = $this->calendarPayload($catalog, [
            ['date' => '2026-09-14', 'hour' => 8, 'spot_count' => 0],
            ['date' => '2026-09-14', 'hour' => 9, 'spot_count' => 2],
        ]);

        $calculation = app(CalculationWriter::class)->create($payload, $user);
        $position = $calculation->positions()->firstOrFail();

        $this->assertSame(2, $position->total_spot_count);
        $this->assertCount(1, $position->plannerEntries);
        $this->assertSame(9, $position->plannerEntries->first()->hour);
    }

    private function createActivePriceListForYear(int $inventoryId, int $year, string $version): PriceList
    {
        $list = PriceList::factory()->create([
            'inventory_id' => $inventoryId,
            'year' => $year,
            'status' => PriceListStatus::Active,
            'version' => $version,
            'valid_from' => sprintf('%04d-01-01', $year),
        ]);

        foreach (range(0, 23) as $hour) {
            foreach ([DayGroup::MoFr, DayGroup::Sa, DayGroup::So] as $group) {
                PriceListItem::factory()->create([
                    'price_list_id' => $list->id,
                    'hour' => $hour,
                    'day_group' => $group,
                    'second_price' => '2.0000',
                ]);
            }
        }

        return $list;
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
            'version' => 'calendar-newer-RH',
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
     * @param  list<array{date: string, hour: int, spot_count: int}>  $entries
     * @return array<string, mixed>
     */
    private function calendarPayload(
        array $catalog,
        array $entries,
        ?int $priceYear = null,
        ?int $expectedPriceListId = null,
    ): array {
        $fingerprint = app(ConfigurationSnapshotFreezeService::class)
            ->resolveLiveSchemaForCalculationV3()['schema_fingerprint'];
        $positionFingerprint = app(ConfigurationSnapshotFreezeService::class)
            ->resolveLivePositionSchema((int) $catalog['medium']->id)['schema_fingerprint'];

        $position = [
            'inventory_id' => $catalog['hamburg']->id,
            'advertising_medium_id' => $catalog['medium']->id,
            'schema_fingerprint' => $positionFingerprint,
            'spot_method' => 'calendar',
            'calculation_method_key' => 'calendar',
            'length_seconds' => 30,
            'position_discount_percent' => '0',
            'ae_percent' => '0',
            'planner_entries' => $entries,
        ];

        if ($priceYear !== null) {
            $position['price_year'] = $priceYear;
        }
        if ($expectedPriceListId !== null) {
            $position['expected_price_list_id'] = $expectedPriceListId;
        }

        return [
            'planning_mode' => 'manual',
            'order_discount_percent' => '0',
            'schema_fingerprint' => $fingerprint,
            'positions' => [$position],
        ];
    }
}
