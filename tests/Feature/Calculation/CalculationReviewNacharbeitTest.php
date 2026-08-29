<?php

namespace Tests\Feature\Calculation;

use App\Enums\DayGroup;
use App\Enums\PriceListStatus;
use App\Enums\Role;
use App\Models\BudgetProposal;
use App\Models\Calculation;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesSpotClassicCatalog;
use Tests\TestCase;

class CalculationReviewNacharbeitTest extends TestCase
{
    use CreatesSpotClassicCatalog;
    use RefreshDatabase;

    public function test_pri_006_price_list_change_does_not_alter_saved_calculation(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $payload = $this->basePayload($catalog, totalSpots: 10);

        $this->actingAs($user)->post(route('calculations.store'), $payload);
        $calculation = Calculation::query()->firstOrFail();
        $nnBefore = (string) $calculation->nn_invest;
        $priceListId = $calculation->positions()->first()?->price_list_id;

        PriceList::query()->whereKey($priceListId)->update(['status' => PriceListStatus::Archived]);
        $newList = PriceList::factory()->create([
            'inventory_id' => $catalog['hamburg']->id,
            'status' => PriceListStatus::Active,
            'version' => '2026-RH-v2',
        ]);
        PriceListItem::factory()->create([
            'price_list_id' => $newList->id,
            'hour' => 8,
            'day_group' => DayGroup::MoFr,
            'second_price' => '9.9999',
        ]);

        $updatePayload = [
            ...$payload,
            'lock_version' => $calculation->lock_version,
            'briefing' => 'Nur Briefing geändert',
            'positions' => $this->positionsFromCalculation($calculation),
        ];

        $this->actingAs($user)->put(route('calculations.update', $calculation), $updatePayload);
        $calculation->refresh();

        $this->assertSame($nnBefore, (string) $calculation->nn_invest);
        $this->assertSame('2026-RH', $calculation->positions()->first()?->price_list_version);
    }

    public function test_header_only_change_does_not_change_prices(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $payload = $this->basePayload($catalog, totalSpots: 5);

        $this->actingAs($user)->post(route('calculations.store'), $payload);
        $calculation = Calculation::query()->firstOrFail();
        $mediaBefore = (string) $calculation->media_gross;

        $this->actingAs($user)->put(route('calculations.update', $calculation), [
            ...$payload,
            'lock_version' => $calculation->lock_version,
            'campaign' => 'Neue Kampagne',
            'positions' => $this->positionsFromCalculation($calculation),
        ]);

        $calculation->refresh();
        $this->assertSame($mediaBefore, (string) $calculation->media_gross);
        $this->assertSame('Neue Kampagne', $calculation->campaign);
    }

    public function test_pri_006_two_active_price_lists_resolve_deterministically(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        PriceList::factory()->create([
            'inventory_id' => $catalog['hamburg']->id,
            'status' => PriceListStatus::Active,
            'version' => '2026-RH-alt',
            'valid_from' => now()->subYear()->toDateString(),
        ]);

        $user = User::factory()->role(Role::Sales)->create();
        $this->actingAs($user)->post(route('calculations.store'), $this->basePayload($catalog, totalSpots: 1));

        $calculation = Calculation::query()->firstOrFail();
        $this->assertSame('2026-RH', $calculation->positions()->first()?->price_list_version);
    }

    public function test_bud_009_stale_budget_proposal_is_rejected(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $payload = $this->basePayload($catalog, totalSpots: 1);

        $this->actingAs($user)->post(route('calculations.store'), $payload);
        $calculation = Calculation::query()->firstOrFail();

        $proposal = BudgetProposal::query()->create([
            'calculation_id' => $calculation->id,
            'strategy' => 'equal_budget',
            'target_budget_nn' => '500',
            'lock_version' => $calculation->lock_version,
            'payload' => ['positions' => []],
            'created_by' => $user->id,
        ]);

        $this->actingAs($user)->put(route('calculations.update', $calculation), [
            ...$payload,
            'lock_version' => $calculation->lock_version,
            'customer_name' => 'Geändert',
            'positions' => $this->positionsFromCalculation($calculation),
        ]);

        $calculation->refresh();

        $this->actingAs($user)
            ->post(route('calculations.budget-apply', [
                'calculation' => $calculation,
                'proposal' => $proposal,
            ]))
            ->assertSessionHasErrors('lock_version');
    }

    public function test_read_only_disposition_sees_saved_summary_without_preview(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $sales = User::factory()->role(Role::Sales)->create();
        $this->actingAs($sales)->post(route('calculations.store'), $this->basePayload($catalog, totalSpots: 3));
        $calculation = Calculation::query()->firstOrFail();

        $disposition = User::factory()->role(Role::Disposition)->create();
        $response = $this->actingAs($disposition)->get(route('calculations.edit', $calculation));
        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('calculations/wizard')
            ->where('canEdit', false)
            ->where('savedSummary.nn_invest', (string) $calculation->nn_invest)
            ->has('savedSummary.positions', 1));

        $management = User::factory()->role(Role::Management)->create();
        $this->actingAs($management)->get(route('calculations.edit', $calculation))
            ->assertInertia(fn ($page) => $page->where('canEdit', true));

        $this->actingAs(User::factory()->role(Role::ProductManagement)->create())
            ->get(route('calculations.index'))
            ->assertForbidden();
    }

    public function test_gen_001_parallel_calculation_numbers_are_unique(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $payload = $this->basePayload($catalog, totalSpots: 1);

        $this->actingAs($user)->post(route('calculations.store'), $payload);
        $this->actingAs($user)->post(route('calculations.store'), $payload);

        $numbers = Calculation::query()->pluck('number')->all();
        $this->assertCount(2, $numbers);
        $this->assertSame(2, count(array_unique($numbers)));
    }

    public function test_lock_version_conflict_on_parallel_update(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $payload = $this->basePayload($catalog, totalSpots: 1);

        $this->actingAs($user)->post(route('calculations.store'), $payload);
        $calculation = Calculation::query()->firstOrFail();

        $this->actingAs($user)->put(route('calculations.update', $calculation), [
            ...$payload,
            'lock_version' => 99,
            'positions' => $this->positionsFromCalculation($calculation),
        ])->assertSessionHasErrors('lock_version');
    }

    public function test_com_008_non_discountable_position_skips_discounts(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $catalog['medium']->update(['is_discountable' => false, 'is_ae_eligible' => false]);
        $user = User::factory()->role(Role::Sales)->create();

        $payload = $this->basePayload($catalog, totalSpots: 10);
        $payload['order_discount_percent'] = '10';
        $payload['positions'][0]['position_discount_percent'] = '10';

        $this->actingAs($user)->post(route('calculations.store'), $payload);
        $calculation = Calculation::query()->firstOrFail();

        $this->assertSame('300.00', (string) $calculation->media_gross);
        $this->assertSame('0.00', (string) $calculation->position_discount_total);
        $this->assertSame('0.00', (string) $calculation->order_discount_total);
    }

    public function test_multiple_positions_same_sender_use_position_keys(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();

        $payload = $this->basePayload($catalog, totalSpots: 2);
        $payload['positions'][] = [
            ...$payload['positions'][0],
            'client_key' => '550e8400-e29b-41d4-a716-446655440000',
            'total_spot_count' => 5,
            'plan_rows' => [['hour' => 10, 'day_group' => 'mo_fr']],
        ];
        unset($payload['positions'][1]['id']);

        $this->actingAs($user)->post(route('calculations.store'), $payload);
        $calculation = Calculation::query()->firstOrFail()->load('positions');

        $this->assertCount(2, $calculation->positions);
        $this->assertNotSame(
            $calculation->positions[0]->client_key,
            $calculation->positions[1]->client_key,
        );
    }

    /**
     * @param  array{hamburg: mixed, rock: mixed, medium: mixed}  $catalog
     * @return array<string, mixed>
     */
    private function basePayload(array $catalog, int $totalSpots): array
    {
        return [
            'planning_mode' => 'manual',
            'order_discount_percent' => '0',
            'positions' => [[
                'inventory_id' => $catalog['hamburg']->id,
                'advertising_medium_id' => $catalog['medium']->id,
                'spot_method' => 'average',
                'length_seconds' => 30,
                'total_spot_count' => $totalSpots,
                'position_discount_percent' => '0',
                'ae_percent' => '0',
                'plan_rows' => [['hour' => 8, 'day_group' => 'mo_fr']],
            ]],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function positionsFromCalculation(Calculation $calculation): array
    {
        $calculation->load('positions.planRows');

        return $calculation->positions->map(fn ($position): array => [
            'id' => $position->id,
            'client_key' => $position->client_key,
            'inventory_id' => $position->inventory_id,
            'advertising_medium_id' => $position->advertising_medium_id,
            'spot_method' => $position->spot_method->value,
            'length_seconds' => $position->length_seconds,
            'total_spot_count' => $position->total_spot_count,
            'position_discount_percent' => (string) $position->position_discount_percent,
            'ae_percent' => (string) $position->ae_percent,
            'plan_rows' => $position->planRows->map(fn ($row): array => [
                'hour' => $row->hour,
                'day_group' => $row->day_group->value,
                'second_price' => (string) $row->second_price,
            ])->all(),
        ])->all();
    }
}
