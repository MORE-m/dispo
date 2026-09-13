<?php

namespace Tests\Feature\PriceList;

use App\Enums\BudgetProposalStatus;
use App\Enums\DayGroup;
use App\Enums\PlanningMode;
use App\Enums\PriceListStatus;
use App\Enums\Role;
use App\Models\BudgetProposal;
use App\Models\Calculation;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\User;
use App\Services\Calculation\BudgetProposalFingerprint;
use App\Services\DispoOrder\DispoOrderWriter;
use App\Support\PriceList\PriceListCalendar;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesSavedCalculation;
use Tests\Concerns\CreatesSpotClassicCatalog;
use Tests\TestCase;

class PriceListRuntimeYearAndHistoryTest extends TestCase
{
    use CreatesSavedCalculation;
    use CreatesSpotClassicCatalog;
    use RefreshDatabase;

    public function test_current_year_is_default_despite_future_active_list(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $currentYear = PriceListCalendar::currentYear();
        $hamburgList = PriceList::query()
            ->where('inventory_id', $catalog['hamburg']->id)
            ->where('status', PriceListStatus::Active)
            ->firstOrFail();

        $future = PriceList::factory()->create([
            'inventory_id' => $catalog['hamburg']->id,
            'year' => $currentYear + 1,
            'status' => PriceListStatus::Active,
            'version' => 'future-RH',
            'valid_from' => sprintf('%04d-01-01', $currentYear + 1),
        ]);
        foreach ([DayGroup::MoFr, DayGroup::Sa, DayGroup::So] as $group) {
            PriceListItem::factory()->create([
                'price_list_id' => $future->id,
                'hour' => 8,
                'day_group' => $group,
                'second_price' => '9.0000',
            ]);
        }

        $user = User::factory()->role(Role::Sales)->create();
        $this->actingAs($user)->post(
            route('calculations.store'),
            $this->withLiveSchemaFingerprint([
                'planning_mode' => 'manual',
                'order_discount_percent' => '0',
                'positions' => [[
                    'inventory_id' => $catalog['hamburg']->id,
                    'advertising_medium_id' => $catalog['medium']->id,
                    'spot_method' => 'average',
                    'length_seconds' => 30,
                    'total_spot_count' => 1,
                    'position_discount_percent' => '0',
                    'ae_percent' => '0',
                    'plan_rows' => [['hour' => 8, 'day_group' => 'mo_fr']],
                ]],
            ]),
        )->assertRedirect();

        $position = Calculation::query()->firstOrFail()->positions()->firstOrFail();
        $this->assertSame($hamburgList->id, $position->price_list_id);
        $this->assertSame('2026-RH', $position->price_list_version);
        $this->assertNotSame($future->id, $position->price_list_id);
    }

    public function test_missing_current_year_list_does_not_fall_back(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        PriceList::query()
            ->where('inventory_id', $catalog['hamburg']->id)
            ->update(['year' => PriceListCalendar::currentYear() + 1]);

        $user = User::factory()->role(Role::Sales)->create();
        $this->actingAs($user)
            ->post(
                route('calculations.store'),
                $this->withLiveSchemaFingerprint([
                    'planning_mode' => 'manual',
                    'order_discount_percent' => '0',
                    'positions' => [[
                        'inventory_id' => $catalog['hamburg']->id,
                        'advertising_medium_id' => $catalog['medium']->id,
                        'spot_method' => 'average',
                        'length_seconds' => 30,
                        'total_spot_count' => 1,
                        'position_discount_percent' => '0',
                        'ae_percent' => '0',
                        'plan_rows' => [['hour' => 8, 'day_group' => 'mo_fr']],
                    ]],
                ]),
            )
            ->assertSessionHasErrors('positions');
    }

    public function test_year_change_with_controlled_clock(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00', 'Europe/Berlin'));
        $catalog = $this->createSpotClassicCatalog();
        $list2026 = PriceList::query()
            ->where('inventory_id', $catalog['hamburg']->id)
            ->where('status', PriceListStatus::Active)
            ->firstOrFail();
        $this->assertSame(2026, (int) $list2026->year);

        $list2027 = PriceList::factory()->create([
            'inventory_id' => $catalog['hamburg']->id,
            'year' => 2027,
            'status' => PriceListStatus::Active,
            'version' => '2027-RH',
        ]);
        foreach (range(0, 23) as $hour) {
            foreach ([DayGroup::MoFr, DayGroup::Sa, DayGroup::So] as $group) {
                PriceListItem::factory()->create([
                    'price_list_id' => $list2027->id,
                    'hour' => $hour,
                    'day_group' => $group,
                    'second_price' => '2.0000',
                ]);
            }
        }

        $user = User::factory()->role(Role::Sales)->create();
        $payload = $this->withLiveSchemaFingerprint([
            'planning_mode' => 'manual',
            'order_discount_percent' => '0',
            'positions' => [[
                'inventory_id' => $catalog['hamburg']->id,
                'advertising_medium_id' => $catalog['medium']->id,
                'spot_method' => 'average',
                'length_seconds' => 30,
                'total_spot_count' => 1,
                'position_discount_percent' => '0',
                'ae_percent' => '0',
                'plan_rows' => [['hour' => 8, 'day_group' => 'mo_fr']],
            ]],
        ]);
        $this->actingAs($user)->post(route('calculations.store'), $payload);
        $this->assertSame($list2026->id, Calculation::query()->firstOrFail()->positions()->first()?->price_list_id);

        Carbon::setTestNow(Carbon::parse('2027-01-01 00:30:00', 'Europe/Berlin'));
        $this->actingAs($user)->post(route('calculations.store'), $payload);
        $this->assertSame(
            $list2027->id,
            Calculation::query()->orderByDesc('id')->firstOrFail()->positions()->first()?->price_list_id,
        );

        Carbon::setTestNow();
    }

    public function test_existing_position_keeps_archived_list_and_extra_hour_uses_same_list(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $calculation = $this->createSavedCalculation($catalog, [
            ['inventory_id' => $catalog['hamburg']->id, 'hour' => 8, 'total_spot_count' => 1],
        ], $user);
        $position = $calculation->positions()->firstOrFail();
        $oldListId = (int) $position->price_list_id;
        $oldVersion = $position->price_list_version;
        $oldHourEight = (string) $position->planRows()->where('hour', 8)->value('second_price');

        $oldList = PriceList::query()->findOrFail($oldListId);
        $oldList->status = PriceListStatus::Archived;
        $oldList->save();

        $successor = PriceList::factory()->create([
            'inventory_id' => $catalog['hamburg']->id,
            'year' => (int) $oldList->year,
            'status' => PriceListStatus::Active,
            'version' => 'nachfolger',
            'name' => 'Neue aktive Liste',
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

        $payload = [
            'planning_mode' => 'manual',
            'lock_version' => $calculation->lock_version,
            'schema_fingerprint' => $this->liveSchemaFingerprint(),
            'order_discount_percent' => '0',
            'positions' => $this->withPositionSchemaFingerprints($calculation, [[
                'id' => $position->id,
                'client_key' => $position->client_key,
                'inventory_id' => $catalog['hamburg']->id,
                'advertising_medium_id' => $catalog['medium']->id,
                'spot_method' => 'average',
                'length_seconds' => 30,
                'total_spot_count' => 2,
                'position_discount_percent' => (string) $position->position_discount_percent,
                'ae_percent' => (string) $position->ae_percent,
                'plan_rows' => [
                    ['hour' => 8, 'day_group' => 'mo_fr'],
                    ['hour' => 9, 'day_group' => 'mo_fr'],
                ],
            ]]),
        ];

        $this->actingAs($user)
            ->put(route('calculations.update', $calculation), $payload)
            ->assertRedirect();

        $position->refresh();
        $oldHourNine = (string) PriceListItem::query()
            ->where('price_list_id', $oldListId)
            ->where('hour', 9)
            ->where('day_group', DayGroup::MoFr)
            ->value('second_price');

        $this->assertSame($oldListId, (int) $position->price_list_id);
        $this->assertSame($oldVersion, $position->price_list_version);
        $this->assertSame($oldHourEight, (string) $position->planRows()->where('hour', 8)->value('second_price'));
        $this->assertSame($oldHourNine, (string) $position->planRows()->where('hour', 9)->value('second_price'));
        $this->assertNotSame('9.0000', (string) $position->planRows()->where('hour', 9)->value('second_price'));
        $this->assertSame('Neue aktive Liste', $successor->fresh()->name);
        $this->assertSame('Preisliste', $oldList->fresh()->name);
    }

    public function test_budget_fingerprint_distinguishes_same_version_string_of_different_lists(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $year = PriceListCalendar::currentYear();
        $first = PriceList::query()->where('inventory_id', $catalog['hamburg']->id)->firstOrFail();
        $second = PriceList::factory()->create([
            'inventory_id' => $catalog['rock']->id,
            'year' => $year + 1,
            'status' => PriceListStatus::Active,
            'version' => $first->version,
        ]);
        foreach ([DayGroup::MoFr, DayGroup::Sa, DayGroup::So] as $group) {
            PriceListItem::factory()->create([
                'price_list_id' => $second->id,
                'hour' => 8,
                'day_group' => $group,
                'second_price' => '1.0000',
            ]);
        }

        $fingerprint = app(BudgetProposalFingerprint::class);
        $left = $fingerprint->compute($fingerprint->inputFromElements(
            ['target_budget_nn' => '1000', 'ae_enabled' => false, 'order_discounts' => []],
            [],
            [$catalog['hamburg']->id => ['priceList' => $first]],
        ));
        $right = $fingerprint->compute($fingerprint->inputFromElements(
            ['target_budget_nn' => '1000', 'ae_enabled' => false, 'order_discounts' => []],
            [],
            [$catalog['hamburg']->id => ['priceList' => $second]],
        ));
        $this->assertNotSame($left, $right);

        $staleStored = ['input_fingerprint' => hash('sha256', json_encode(['price_list_versions' => [$first->version]]))];
        $this->assertTrue($fingerprint->isStale($staleStored, [
            'target_budget_nn' => '1000',
            'ae_enabled' => false,
            'budget_elements' => [],
        ]));
    }

    public function test_new_calculation_uses_successor_active_list(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $old = PriceList::query()
            ->where('inventory_id', $catalog['hamburg']->id)
            ->where('status', PriceListStatus::Active)
            ->firstOrFail();
        $successor = $this->activateSuccessor($catalog['hamburg']->id, (int) $old->year, $old);

        $user = User::factory()->role(Role::Sales)->create();
        $this->actingAs($user)->post(
            route('calculations.store'),
            $this->withLiveSchemaFingerprint([
                'planning_mode' => 'manual',
                'order_discount_percent' => '0',
                'positions' => [[
                    'inventory_id' => $catalog['hamburg']->id,
                    'advertising_medium_id' => $catalog['medium']->id,
                    'spot_method' => 'average',
                    'length_seconds' => 30,
                    'total_spot_count' => 1,
                    'position_discount_percent' => '0',
                    'ae_percent' => '0',
                    'plan_rows' => [['hour' => 8, 'day_group' => 'mo_fr']],
                ]],
            ]),
        )->assertRedirect();

        $position = Calculation::query()->latest('id')->firstOrFail()->positions()->firstOrFail();
        $this->assertSame($successor->id, (int) $position->price_list_id);
        $this->assertSame('nachfolger', $position->price_list_version);
        $this->assertSame('9.0000', (string) $position->planRows()->where('hour', 8)->value('second_price'));
    }

    public function test_historical_dispo_keeps_archived_price_list(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $calculation = $this->createSavedCalculation($catalog, [
            ['inventory_id' => $catalog['hamburg']->id, 'hour' => 8, 'total_spot_count' => 1],
        ], $user);
        $calcPosition = $calculation->positions()->firstOrFail();
        $pinnedId = (int) $calcPosition->price_list_id;
        $pinnedVersion = $calcPosition->price_list_version;
        $pinnedPrice = (string) $calcPosition->planRows()->where('hour', 8)->value('second_price');

        $order = app(DispoOrderWriter::class)
            ->createFromCalculation($calculation, [$calcPosition->id], $user)
            ->order;
        $dispoPosition = $order->positions()->firstOrFail();
        $this->assertSame($pinnedId, (int) $dispoPosition->price_list_id);
        $this->assertSame($pinnedVersion, $dispoPosition->price_list_version);

        $old = PriceList::query()->findOrFail($pinnedId);
        $this->activateSuccessor($catalog['hamburg']->id, (int) $old->year, $old);

        $dispoPosition->refresh();
        $this->assertSame($pinnedId, (int) $dispoPosition->price_list_id);
        $this->assertSame($pinnedVersion, $dispoPosition->price_list_version);
        $this->assertSame($pinnedPrice, (string) $calcPosition->fresh()->planRows()->where('hour', 8)->value('second_price'));
        $this->assertSame(PriceListStatus::Archived, $old->fresh()->status);
        $this->assertSame('Preisliste', $old->fresh()->name);
    }

    public function test_extra_hour_without_price_on_pinned_list_fails(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $calculation = $this->createSavedCalculation($catalog, [
            ['inventory_id' => $catalog['hamburg']->id, 'hour' => 8, 'total_spot_count' => 1],
        ], $user);
        $position = $calculation->positions()->firstOrFail();
        $oldListId = (int) $position->price_list_id;

        PriceListItem::query()
            ->where('price_list_id', $oldListId)
            ->where('hour', 9)
            ->delete();

        $old = PriceList::query()->findOrFail($oldListId);
        $old->status = PriceListStatus::Archived;
        $old->save();
        $this->activateSuccessor($catalog['hamburg']->id, (int) $old->year, $old, archivePredecessor: false);

        $this->actingAs($user)
            ->put(route('calculations.update', $calculation), [
                'planning_mode' => 'manual',
                'lock_version' => $calculation->lock_version,
                'schema_fingerprint' => $this->liveSchemaFingerprint(),
                'order_discount_percent' => '0',
                'positions' => $this->withPositionSchemaFingerprints($calculation, [[
                    'id' => $position->id,
                    'client_key' => $position->client_key,
                    'inventory_id' => $catalog['hamburg']->id,
                    'advertising_medium_id' => $catalog['medium']->id,
                    'spot_method' => 'average',
                    'length_seconds' => 30,
                    'total_spot_count' => 2,
                    'position_discount_percent' => (string) $position->position_discount_percent,
                    'ae_percent' => (string) $position->ae_percent,
                    'plan_rows' => [
                        ['hour' => 8, 'day_group' => 'mo_fr'],
                        ['hour' => 9, 'day_group' => 'mo_fr'],
                    ],
                ]]),
            ])
            ->assertSessionHasErrors('positions');
        $this->assertSame($oldListId, (int) $position->fresh()->price_list_id);
    }

    public function test_budget_apply_is_blocked_after_successor_activation(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();

        $this->actingAs($user)->post(route('calculations.store'), [
            'planning_mode' => PlanningMode::Budget->value,
            'schema_fingerprint' => $this->liveSchemaFingerprint(),
            'target_budget_nn' => '500',
            'order_discount_percent' => '0',
            'positions' => [],
        ]);

        $calculation = Calculation::query()->firstOrFail();
        $propose = $this->actingAs($user)->postJson(route('calculations.budget-propose'), [
            'planning_mode' => PlanningMode::Budget->value,
            'target_budget_nn' => '500',
            'budget_elements' => [
                [
                    'client_id' => 'hamburg',
                    'inventory_id' => $catalog['hamburg']->id,
                    'spot_length_seconds' => 30,
                    'distribution_ranges' => [[
                        'start_hour' => 8,
                        'end_hour_exclusive' => 12,
                        'day_group' => DayGroup::MoFr->value,
                    ]],
                    'position_discounts' => [],
                ],
                [
                    'client_id' => 'rock',
                    'inventory_id' => $catalog['rock']->id,
                    'spot_length_seconds' => 30,
                    'distribution_ranges' => [[
                        'start_hour' => 8,
                        'end_hour_exclusive' => 12,
                        'day_group' => DayGroup::MoFr->value,
                    ]],
                    'position_discounts' => [],
                ],
            ],
            'order_discount_percent' => '0',
            'order_discounts' => [],
            'ae_enabled' => false,
            'positions' => [],
            'calculation_id' => $calculation->id,
        ]);
        $propose->assertOk();
        $proposalId = $propose->json('proposal.id');

        $old = PriceList::query()
            ->where('inventory_id', $catalog['hamburg']->id)
            ->where('status', PriceListStatus::Active)
            ->firstOrFail();
        $this->activateSuccessor($catalog['hamburg']->id, (int) $old->year, $old);

        $this->actingAs($user)
            ->from(route('calculations.edit', $calculation))
            ->post(route('calculations.budget-apply', [
                'calculation' => $calculation,
                'proposal' => $proposalId,
            ]))
            ->assertRedirect()
            ->assertSessionHasErrors('proposal');

        $this->assertSame(BudgetProposalStatus::Stale, BudgetProposal::query()->findOrFail($proposalId)->status);
        $this->assertSame(0, $calculation->fresh()->positions()->count());
    }

    private function activateSuccessor(int $inventoryId, int $year, PriceList $predecessor, bool $archivePredecessor = true): PriceList
    {
        if ($archivePredecessor && $predecessor->status !== PriceListStatus::Archived) {
            $predecessor->status = PriceListStatus::Archived;
            $predecessor->save();
        }

        $successor = PriceList::factory()->create([
            'inventory_id' => $inventoryId,
            'year' => $year,
            'status' => PriceListStatus::Active,
            'version' => 'nachfolger',
            'name' => 'Neue aktive Liste',
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

        return $successor;
    }
}
