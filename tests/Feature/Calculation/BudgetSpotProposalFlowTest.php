<?php

namespace Tests\Feature\Calculation;

use App\Enums\BudgetProposalStatus;
use App\Enums\DayGroup;
use App\Enums\PlanningMode;
use App\Enums\Role;
use App\Models\BudgetProposal;
use App\Models\Calculation;
use App\Models\User;
use App\Services\Calculation\CalculationWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesSpotClassicCatalog;
use Tests\TestCase;

class BudgetSpotProposalFlowTest extends TestCase
{
    use CreatesSpotClassicCatalog;
    use RefreshDatabase;

    public function test_proposal_without_positions_or_spot_counts(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();

        $response = $this->actingAs($user)->postJson(route('calculations.budget-propose'), [
            'planning_mode' => PlanningMode::Budget->value,
            'target_budget_nn' => '500',
            'budget_wish_inventory_ids' => [
                $catalog['hamburg']->id,
                $catalog['rock']->id,
            ],
            'budget_spot_length_seconds' => 30,
            'budget_distribution_ranges' => [
                [
                    'start_hour' => 8,
                    'end_hour_exclusive' => 12,
                    'day_group' => DayGroup::MoFr->value,
                ],
            ],
            'order_discount_percent' => '0',
            'order_discounts' => [],
            'ae_enabled' => false,
            'positions' => [],
        ]);

        $response->assertOk();
        $response->assertJsonPath('proposal.spots_per_sender', fn ($value): bool => (int) $value > 0);
        $response->assertJsonMissingPath('proposal.positions.0.time_ranges.0.spot_count_required');
    }

    public function test_inventory_position_discounts_affect_proposal(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();

        $withoutDiscount = $this->actingAs($user)->postJson(route('calculations.budget-propose'), $this->budgetPayload($catalog));
        $withDiscount = $this->actingAs($user)->postJson(route('calculations.budget-propose'), $this->budgetPayload($catalog, [
            [
                'inventory_id' => $catalog['hamburg']->id,
                'discounts' => [
                    ['type' => 'quantity', 'custom_label' => null, 'percent' => '10'],
                ],
            ],
        ]));

        $withoutDiscount->assertOk();
        $withDiscount->assertOk();

        $this->assertGreaterThan(
            '0.00',
            $withDiscount->json('proposal.positions.0.position_discount_amount'),
        );
    }

    public function test_order_discounts_and_ae_are_considered(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();

        $base = $this->actingAs($user)->postJson(route('calculations.budget-propose'), $this->budgetPayload($catalog));
        $discounted = $this->actingAs($user)->postJson(route('calculations.budget-propose'), $this->budgetPayload($catalog, orderDiscounts: [
            ['type' => 'quantity', 'custom_label' => null, 'percent' => '10'],
        ]));
        $withAe = $this->actingAs($user)->postJson(route('calculations.budget-propose'), $this->budgetPayload($catalog, aeEnabled: true));

        $base->assertOk();
        $discounted->assertOk();
        $withAe->assertOk();

        $this->assertGreaterThan(
            '0.00',
            $discounted->json('proposal.order_discount_total'),
        );
        $this->assertGreaterThan(
            '0.00',
            $withAe->json('proposal.ae_total'),
        );
        $this->assertNotSame(
            (int) $base->json('proposal.spots_per_sender'),
            (int) $withAe->json('proposal.spots_per_sender'),
        );
    }

    public function test_apply_creates_positions_and_hourly_ranges(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();

        $this->actingAs($user)->post(route('calculations.store'), [
            'planning_mode' => PlanningMode::Budget->value,
            'target_budget_nn' => '500',
            'order_discount_percent' => '0',
            'order_discounts' => [],
            'ae_enabled' => false,
            'positions' => [],
        ]);

        $calculation = Calculation::query()->firstOrFail();

        $propose = $this->actingAs($user)->postJson(route('calculations.budget-propose'), [
            ...$this->budgetPayload($catalog),
            'calculation_id' => $calculation->id,
        ]);
        $propose->assertOk();
        $this->assertNotNull($propose->json('proposal.id'));
        $this->assertGreaterThan(0, count($propose->json('proposal.positions')));

        $proposalId = $propose->json('proposal.id');
        $proposalModel = BudgetProposal::query()->findOrFail($proposalId);
        $merged = app(CalculationWriter::class)->mergeProposalHourlyDistribution(
            [],
            $proposalModel->payloadArray(),
        );
        $this->assertNotEmpty($merged);

        $this->actingAs($user)
            ->post(route('calculations.budget-apply', [
                'calculation' => $calculation,
                'proposal' => $proposalId,
            ]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $calculation->refresh()->load(['positions.timeRanges']);
        $this->assertGreaterThan(0, $calculation->positions->count());
        $this->assertTrue($calculation->positions->every(fn ($position): bool => $position->timeRanges->isNotEmpty()));
    }

    public function test_stale_fingerprint_blocks_apply(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();

        $this->actingAs($user)->post(route('calculations.store'), [
            'planning_mode' => PlanningMode::Budget->value,
            'target_budget_nn' => '500',
            'order_discount_percent' => '0',
            'positions' => [],
        ]);

        $calculation = Calculation::query()->firstOrFail();
        $proposal = BudgetProposal::query()->create([
            'calculation_id' => $calculation->id,
            'strategy' => 'equal_spot_count',
            'status' => BudgetProposalStatus::Stale,
            'target_budget_nn' => '500',
            'lock_version' => $calculation->lock_version,
            'payload' => ['positions' => []],
            'created_by' => $user->id,
        ]);

        $this->actingAs($user)
            ->post(route('calculations.budget-apply', [
                'calculation' => $calculation,
                'proposal' => $proposal,
            ]))
            ->assertSessionHasErrors('proposal');
    }

    public function test_manual_mode_still_requires_spot_counts(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();

        $this->actingAs($user)
            ->post(route('calculations.store'), [
                'planning_mode' => PlanningMode::Manual->value,
                'order_discount_percent' => '0',
                'positions' => [
                    [
                        'inventory_id' => $catalog['hamburg']->id,
                        'advertising_medium_id' => $catalog['medium']->id,
                        'spot_method' => 'average',
                        'length_seconds' => 30,
                        'total_spot_count' => 0,
                        'position_discount_percent' => '0',
                        'ae_percent' => '0',
                        'plan_rows' => [],
                        'time_ranges' => [],
                    ],
                ],
            ])
            ->assertSessionHasErrors('positions.0.time_ranges');
    }

    /**
     * @param  array{hamburg: mixed, rock: mixed, medium: mixed}  $catalog
     * @param  list<array{inventory_id: int, discounts: list<array{type: string, custom_label: string|null, percent: string}>}>  $positionDiscounts
     * @param  list<array{type: string, custom_label: string|null, percent: string}>  $orderDiscounts
     * @return array<string, mixed>
     */
    private function budgetPayload(
        array $catalog,
        array $positionDiscounts = [],
        array $orderDiscounts = [],
        bool $aeEnabled = false,
    ): array {
        return [
            'planning_mode' => PlanningMode::Budget->value,
            'target_budget_nn' => '500',
            'budget_wish_inventory_ids' => [
                $catalog['hamburg']->id,
                $catalog['rock']->id,
            ],
            'budget_spot_length_seconds' => 30,
            'budget_distribution_ranges' => [
                [
                    'start_hour' => 8,
                    'end_hour_exclusive' => 12,
                    'day_group' => DayGroup::MoFr->value,
                ],
            ],
            'budget_position_discounts_by_inventory' => $positionDiscounts,
            'order_discount_percent' => '0',
            'order_discounts' => $orderDiscounts,
            'ae_enabled' => $aeEnabled,
            'positions' => [],
        ];
    }
}
