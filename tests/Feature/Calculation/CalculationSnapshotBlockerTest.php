<?php

namespace Tests\Feature\Calculation;

use App\Enums\Role;
use App\Models\AuditEvent;
use App\Models\Calculation;
use App\Models\InventoryMediumRule;
use App\Models\PriceListItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesSpotClassicCatalog;
use Tests\TestCase;

class CalculationSnapshotBlockerTest extends TestCase
{
    use CreatesSpotClassicCatalog;
    use RefreshDatabase;

    public function test_spt_003_average_prices_remain_stable_after_resave(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $list = $catalog['hamburg']->priceLists()->firstOrFail();
        PriceListItem::query()->where('price_list_id', $list->id)->where('hour', 8)->update(['second_price' => '1.0000']);
        PriceListItem::query()->where('price_list_id', $list->id)->where('hour', 10)->update(['second_price' => '3.0000']);

        $user = User::factory()->role(Role::Sales)->create();
        $payload = $this->payload($catalog, hours: [8, 10], totalSpots: 10, length: 30);

        $this->actingAs($user)->post(route('calculations.store'), $payload);
        $calculation = Calculation::query()->firstOrFail()->load('positions.planRows');
        $position = $calculation->positions->firstOrFail();

        $this->assertSame('2.0000', (string) $position->average_second_price);
        $prices = $position->planRows->pluck('second_price')->map(fn ($p) => (string) $p)->sort()->values()->all();
        $this->assertSame(['1.0000', '3.0000'], $prices);

        $nnBefore = (string) $calculation->nn_invest;
        $mediaBefore = (string) $calculation->media_gross;

        $this->actingAs($user)->put(route('calculations.update', $calculation), [
            ...$payload,
            'lock_version' => $calculation->lock_version,
            'briefing' => 'Nur Briefing',
            'positions' => $this->positionsFromCalculation($calculation),
        ]);

        $calculation->refresh()->load('positions.planRows');
        $position = $calculation->positions->firstOrFail();
        $this->assertSame('2.0000', (string) $position->average_second_price);
        $this->assertSame($nnBefore, (string) $calculation->nn_invest);
        $this->assertSame($mediaBefore, (string) $calculation->media_gross);
        $this->assertSame(['1.0000', '3.0000'], $position->planRows->pluck('second_price')->map(fn ($p) => (string) $p)->sort()->values()->all());
    }

    public function test_pri_006_browser_price_manipulation_is_ignored(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $payload = $this->payload($catalog, hours: [8], totalSpots: 10, length: 30);
        $payload['positions'][0]['plan_rows'][0]['second_price'] = '0.0100';

        $this->actingAs($user)->post(route('calculations.store'), $payload);
        $calculation = Calculation::query()->firstOrFail()->load('positions.planRows');

        $this->assertSame('1.0000', (string) $calculation->positions->first()?->planRows->first()?->second_price);
        $this->assertSame('300.00', (string) $calculation->media_gross);

        $audit = AuditEvent::query()->where('action', 'calculation.created')->firstOrFail();
        $price = $audit->new_values['positions'][0]['plan_rows'][0]['second_price'] ?? null;
        $this->assertSame('1.0000', $price);
    }

    public function test_snapshot_rule_change_does_not_affect_existing_calculation(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $payload = $this->payload($catalog, hours: [8], totalSpots: 5, length: 30);

        $this->actingAs($user)->post(route('calculations.store'), $payload);
        $calculation = Calculation::query()->firstOrFail();
        $position = $calculation->positions()->firstOrFail();
        $surchargeBefore = (string) $position->surcharge_percent;

        InventoryMediumRule::query()
            ->where('inventory_id', $catalog['hamburg']->id)
            ->update(['surcharge_percent' => 99, 'is_discountable' => false, 'is_ae_eligible' => false]);

        $this->actingAs($user)->put(route('calculations.update', $calculation), [
            ...$payload,
            'lock_version' => $calculation->lock_version,
            'campaign' => 'Kopf',
            'positions' => $this->positionsFromCalculation($calculation->fresh(['positions.planRows'])),
        ]);

        $position->refresh();
        $this->assertSame($surchargeBefore, (string) $position->surcharge_percent);
        $this->assertTrue($position->is_discountable);
    }

    public function test_inventory_change_uses_new_price_list(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $payload = $this->payload($catalog, hours: [8], totalSpots: 5, length: 30, inventoryId: $catalog['hamburg']->id);

        $this->actingAs($user)->post(route('calculations.store'), $payload);
        $calculation = Calculation::query()->firstOrFail()->load('positions.planRows');
        $hamburgListId = $calculation->positions->first()?->price_list_id;

        $positions = $this->positionsFromCalculation($calculation);
        $positions[0]['inventory_id'] = $catalog['rock']->id;

        $this->actingAs($user)->put(route('calculations.update', $calculation), [
            ...$payload,
            'lock_version' => $calculation->lock_version,
            'positions' => $positions,
        ]);

        $calculation->refresh()->load('positions.planRows');
        $position = $calculation->positions->firstOrFail();
        $this->assertNotSame($hamburgListId, $position->price_list_id);
        $this->assertSame('2026-RAH', $position->price_list_version);
        $this->assertSame('0.8000', (string) $position->planRows->first()?->second_price);
    }

    public function test_audit_contains_persisted_positions_after_create_and_update(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $payload = $this->payload($catalog, hours: [8], totalSpots: 1, length: 30);

        $this->actingAs($user)->post(route('calculations.store'), $payload);
        $calculation = Calculation::query()->firstOrFail();
        $created = AuditEvent::query()->where('action', 'calculation.created')->firstOrFail();
        $this->assertCount(1, $created->new_values['positions']);

        $positions = $this->positionsFromCalculation($calculation->fresh(['positions.planRows']));
        $positions[] = [
            'client_key' => '550e8400-e29b-41d4-a716-446655440001',
            'inventory_id' => $catalog['rock']->id,
            'advertising_medium_id' => $catalog['medium']->id,
            'spot_method' => 'average',
            'length_seconds' => 30,
            'total_spot_count' => 2,
            'position_discount_percent' => '0',
            'ae_percent' => '0',
            'plan_rows' => [['hour' => 10, 'day_group' => 'mo_fr']],
        ];

        $this->actingAs($user)->put(route('calculations.update', $calculation), [
            ...$payload,
            'lock_version' => $calculation->lock_version,
            'positions' => $positions,
        ]);

        $updated = AuditEvent::query()->where('action', 'calculation.updated')->latest('id')->firstOrFail();
        $this->assertCount(2, $updated->new_values['positions']);
    }

    public function test_bud_008_budget_proposal_only_changes_total_spot_count(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $payload = $this->twoPositionPayload($catalog);

        $this->actingAs($user)->post(route('calculations.store'), $payload);
        $calculation = Calculation::query()->firstOrFail()->load('positions.planRows');
        $hoursBefore = $calculation->positions->map(fn ($p) => $p->planRows->pluck('hour')->all())->all();

        $propose = $this->actingAs($user)->postJson(route('calculations.budget-propose'), [
            ...$payload,
            'planning_mode' => 'budget',
            'target_budget_nn' => '500',
            'budget_strategy' => 'equal_budget',
            'calculation_id' => $calculation->id,
            'positions' => $this->positionsFromCalculation($calculation),
        ]);
        $proposalId = $propose->json('proposal.id');

        $this->actingAs($user)->post(route('calculations.budget-apply', [
            'calculation' => $calculation,
            'proposal' => $proposalId,
        ]));

        $calculation->refresh()->load('positions.planRows');
        $hoursAfter = $calculation->positions->map(fn ($p) => $p->planRows->pluck('hour')->all())->all();
        $this->assertSame($hoursBefore, $hoursAfter);
        $this->assertGreaterThan(2, (int) $calculation->positions->sum('total_spot_count'));
    }

    public function test_gen_001_sequence_table_assigns_unique_numbers(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $payload = $this->payload($catalog, hours: [8], totalSpots: 1, length: 30);

        $this->actingAs($user)->post(route('calculations.store'), $payload);
        $this->actingAs($user)->post(route('calculations.store'), $payload);

        $numbers = Calculation::query()->pluck('number')->all();
        $this->assertCount(2, $numbers);
        $this->assertSame(2, count(array_unique($numbers)));
    }

    public function test_gen_001_concurrent_create_retries_without_duplicate_numbers(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Echter Paralleltest nur auf MySQL verfügbar.');
        }

        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $payload = $this->payload($catalog, hours: [8], totalSpots: 1, length: 30);

        DB::transaction(function () use ($user, $payload): void {
            $this->actingAs($user)->post(route('calculations.store'), $payload);
        });
        DB::transaction(function () use ($user, $payload): void {
            $this->actingAs($user)->post(route('calculations.store'), $payload);
        });

        $this->assertSame(2, Calculation::query()->count());
        $this->assertSame(2, Calculation::query()->distinct('number')->count('number'));
    }

    /**
     * @param  array{hamburg: mixed, rock: mixed, medium: mixed}  $catalog
     * @param  list<int>  $hours
     * @return array<string, mixed>
     */
    private function payload(array $catalog, array $hours, int $totalSpots, int $length, ?int $inventoryId = null): array
    {
        return [
            'planning_mode' => 'manual',
            'order_discount_percent' => '0',
            'positions' => [[
                'inventory_id' => $inventoryId ?? $catalog['hamburg']->id,
                'advertising_medium_id' => $catalog['medium']->id,
                'spot_method' => 'average',
                'length_seconds' => $length,
                'total_spot_count' => $totalSpots,
                'position_discount_percent' => '0',
                'ae_percent' => '0',
                'plan_rows' => array_map(
                    fn (int $hour): array => ['hour' => $hour, 'day_group' => 'mo_fr'],
                    $hours,
                ),
            ]],
        ];
    }

    /**
     * @param  array{hamburg: mixed, rock: mixed, medium: mixed}  $catalog
     * @return array<string, mixed>
     */
    private function twoPositionPayload(array $catalog): array
    {
        return [
            'planning_mode' => 'manual',
            'order_discount_percent' => '0',
            'positions' => [
                [
                    'inventory_id' => $catalog['hamburg']->id,
                    'advertising_medium_id' => $catalog['medium']->id,
                    'spot_method' => 'average',
                    'length_seconds' => 30,
                    'total_spot_count' => 1,
                    'position_discount_percent' => '0',
                    'ae_percent' => '0',
                    'plan_rows' => [['hour' => 8, 'day_group' => 'mo_fr']],
                ],
                [
                    'inventory_id' => $catalog['rock']->id,
                    'advertising_medium_id' => $catalog['medium']->id,
                    'spot_method' => 'average',
                    'length_seconds' => 30,
                    'total_spot_count' => 1,
                    'position_discount_percent' => '0',
                    'ae_percent' => '0',
                    'plan_rows' => [['hour' => 8, 'day_group' => 'mo_fr']],
                ],
            ],
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
            ])->all(),
        ])->all();
    }
}
