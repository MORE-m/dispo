<?php

namespace Tests\Feature\Calculation;

use App\Enums\Role;
use App\Models\AuditEvent;
use App\Models\Calculation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesSpotClassicCatalog;
use Tests\TestCase;

class SpotClassicCalculationTest extends TestCase
{
    use CreatesSpotClassicCatalog;
    use RefreshDatabase;

    public function test_cal_001_stores_multi_sender_spot_classic_example(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();

        $response = $this->actingAs($user)->post(route('calculations.store'), $this->payload($catalog, [
            [
                'inventory_id' => $catalog['hamburg']->id,
                'length_seconds' => 30,
                'total_spot_count' => 10,
                'hour' => 8,
            ],
            [
                'inventory_id' => $catalog['rock']->id,
                'length_seconds' => 20,
                'total_spot_count' => 5,
                'hour' => 10,
            ],
        ]));

        $calculation = Calculation::query()->firstOrFail();
        $response->assertRedirect(route('calculations.edit', $calculation));

        $calculation->load('positions.planRows');

        $this->assertCount(2, $calculation->positions);
        $this->assertSame(10, $calculation->positions[0]->total_spot_count);
        $this->assertSame(5, $calculation->positions[1]->total_spot_count);
        $this->assertSame('384.00', (string) $calculation->nn_invest);
        $this->assertTrue(AuditEvent::query()->where('action', 'calculation.created')->exists());
    }

    public function test_spt_015_length_is_free_and_recalculated(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();

        $this->actingAs($user)->post(route('calculations.store'), $this->payload($catalog, [
            [
                'inventory_id' => $catalog['hamburg']->id,
                'length_seconds' => 20,
                'total_spot_count' => 10,
                'hour' => 8,
            ],
        ]));

        $calculation = Calculation::query()->firstOrFail();
        $this->assertSame(20, $calculation->positions()->first()?->length_seconds);
        $this->assertSame('210.00', (string) $calculation->media_gross);
    }

    public function test_bud_008_proposal_does_not_change_calculation_until_applied(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $this->actingAs($user)->post(route('calculations.store'), $this->payload($catalog, [
            [
                'inventory_id' => $catalog['hamburg']->id,
                'length_seconds' => 30,
                'total_spot_count' => 1,
                'hour' => 8,
            ],
            [
                'inventory_id' => $catalog['rock']->id,
                'length_seconds' => 30,
                'total_spot_count' => 1,
                'hour' => 8,
            ],
        ], [
            'position_discount_percent' => '0',
            'ae_percent' => '0',
        ]));

        $calculation = Calculation::query()->firstOrFail();
        $nnBefore = (string) $calculation->nn_invest;

        $propose = $this->actingAs($user)->postJson(route('calculations.budget-propose'), [
            ...$this->payload($catalog, [
                [
                    'inventory_id' => $catalog['hamburg']->id,
                    'length_seconds' => 30,
                    'total_spot_count' => 1,
                    'hour' => 8,
                ],
                [
                    'inventory_id' => $catalog['rock']->id,
                    'length_seconds' => 30,
                    'total_spot_count' => 1,
                    'hour' => 8,
                ],
            ], [
                'position_discount_percent' => '0',
                'ae_percent' => '0',
            ]),
            'planning_mode' => 'budget',
            'target_budget_nn' => '500',
            'budget_strategy' => 'equal_budget',
            'calculation_id' => $calculation->id,
            'positions' => $calculation->load('positions')->positions->map(fn ($position): array => [
                'id' => $position->id,
                'client_key' => $position->client_key,
                'inventory_id' => $position->inventory_id,
                'advertising_medium_id' => $position->advertising_medium_id,
                'spot_method' => 'average',
                'length_seconds' => $position->length_seconds,
                'total_spot_count' => $position->total_spot_count,
                'position_discount_percent' => '0',
                'ae_percent' => '0',
                'plan_rows' => [['hour' => 8, 'day_group' => 'mo_fr']],
            ])->all(),
        ]);

        $propose->assertOk();
        $calculation->refresh();
        $this->assertSame($nnBefore, (string) $calculation->nn_invest);

        $proposalId = $propose->json('proposal.id');
        $this->actingAs($user)->post(route('calculations.budget-apply', [
            'calculation' => $calculation,
            'proposal' => $proposalId,
        ]));

        $calculation->refresh()->load('positions');
        $this->assertNotSame($nnBefore, (string) $calculation->nn_invest);
        $this->assertGreaterThan(2, (int) $calculation->positions->sum('total_spot_count'));
    }

    public function test_auth_007_product_management_cannot_open_calculations(): void
    {
        $user = User::factory()->role(Role::ProductManagement)->create();

        $this->actingAs($user)->get(route('calculations.index'))->assertForbidden();
        $this->actingAs($user)->get(route('standard-offers.index'))->assertOk();
    }

    public function test_disposition_can_view_but_not_create(): void
    {
        $user = User::factory()->role(Role::Disposition)->create();

        $this->actingAs($user)->get(route('calculations.index'))->assertOk();
        $this->actingAs($user)->get(route('calculations.create'))->assertForbidden();
    }

    /**
     * @param  array{hamburg: mixed, rock: mixed, medium: mixed}  $catalog
     * @param  list<array{inventory_id: int, length_seconds: int, total_spot_count: int, hour: int}>  $spots
     * @param  array{position_discount_percent?: string, ae_percent?: string}  $conditions
     * @return array<string, mixed>
     */
    private function payload(array $catalog, array $spots, array $conditions = []): array
    {
        return [
            'planning_mode' => 'manual',
            'order_discount_percent' => '0',
            'positions' => array_map(fn (array $spot): array => [
                'inventory_id' => $spot['inventory_id'],
                'advertising_medium_id' => $catalog['medium']->id,
                'spot_method' => 'average',
                'length_seconds' => $spot['length_seconds'],
                'total_spot_count' => $spot['total_spot_count'],
                'position_discount_percent' => $conditions['position_discount_percent'] ?? '0',
                'ae_percent' => $conditions['ae_percent'] ?? '0',
                'plan_rows' => [[
                    'hour' => $spot['hour'],
                    'day_group' => 'mo_fr',
                ]],
            ], $spots),
        ];
    }
}
