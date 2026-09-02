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

    public function test_apply_save_reload_shows_applied_state(): void
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
        $proposalId = $propose->json('proposal.id');

        $this->actingAs($user)
            ->post(route('calculations.budget-apply', [
                'calculation' => $calculation,
                'proposal' => $proposalId,
            ]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $calculation->refresh()->load(['positions.timeRanges']);
        $this->assertSame(BudgetProposalStatus::Applied, $calculation->budget_proposal_status);
        $this->assertGreaterThan(0, $calculation->positions->count());

        $this->actingAs($user)
            ->put(route('calculations.update', $calculation), $this->appliedSavePayload($calculation))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $response = $this->actingAs($user)->get(route('calculations.edit', $calculation));
        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('calculations/wizard')
            ->where('calculation.budget_proposal_status', BudgetProposalStatus::Applied->value)
            ->where('calculation.positions', fn ($positions): bool => count($positions) > 0)
            ->has('appliedBudgetProposal')
            ->where('latestBudgetProposal', null)
        );
        $response->assertDontSee('Noch kein Budgetvorschlag berechnet', false);
    }

    public function test_manual_edit_after_apply_sets_manual_status(): void
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
        $propose = $this->actingAs($user)->postJson(route('calculations.budget-propose'), [
            ...$this->budgetPayload($catalog),
            'calculation_id' => $calculation->id,
        ]);
        $proposalId = $propose->json('proposal.id');

        $this->actingAs($user)->post(route('calculations.budget-apply', [
            'calculation' => $calculation,
            'proposal' => $proposalId,
        ]));

        $calculation->refresh()->load(['positions.timeRanges']);
        $payload = $this->appliedSavePayload($calculation);
        $payload['budget_proposal_manual'] = true;
        $payload['positions'][0]['time_ranges'][0]['spot_count'] = (int) $payload['positions'][0]['time_ranges'][0]['spot_count'] + 1;

        $this->actingAs($user)
            ->put(route('calculations.update', $calculation), $payload)
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $calculation->refresh();
        $this->assertSame(BudgetProposalStatus::Manual, $calculation->budget_proposal_status);

        $response = $this->actingAs($user)->get(route('calculations.edit', $calculation));
        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('calculation.budget_proposal_status', BudgetProposalStatus::Manual->value)
        );
        $response->assertDontSee('Noch kein Budgetvorschlag berechnet', false);
    }

    public function test_individual_element_time_ranges_in_proposal(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();

        $response = $this->actingAs($user)->postJson(route('calculations.budget-propose'), [
            'planning_mode' => PlanningMode::Budget->value,
            'target_budget_nn' => '5000',
            'budget_elements' => [
                [
                    'client_id' => 'rh',
                    'inventory_id' => $catalog['hamburg']->id,
                    'spot_length_seconds' => 30,
                    'distribution_ranges' => [
                        [
                            'start_hour' => 6,
                            'end_hour_exclusive' => 12,
                            'day_group' => DayGroup::MoFr->value,
                        ],
                    ],
                    'position_discounts' => [],
                ],
                [
                    'client_id' => 'rock',
                    'inventory_id' => $catalog['rock']->id,
                    'spot_length_seconds' => 30,
                    'distribution_ranges' => [
                        [
                            'start_hour' => 14,
                            'end_hour_exclusive' => 18,
                            'day_group' => DayGroup::MoFr->value,
                        ],
                    ],
                    'position_discounts' => [],
                ],
            ],
            'order_discount_percent' => '0',
            'order_discounts' => [],
            'ae_enabled' => false,
            'positions' => [],
        ]);

        $response->assertOk();
        $positions = $response->json('proposal.positions');
        $this->assertCount(2, $positions);
        $this->assertSame(
            $positions[0]['total_spot_count'],
            $positions[1]['total_spot_count'],
        );

        foreach ($positions[0]['time_ranges'] as $range) {
            $this->assertGreaterThanOrEqual(6, $range['start_hour']);
            $this->assertLessThan(12, $range['start_hour']);
        }

        foreach ($positions[1]['time_ranges'] as $range) {
            $this->assertGreaterThanOrEqual(14, $range['start_hour']);
            $this->assertLessThan(18, $range['start_hour']);
        }
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
        $discountByInventory = [];
        foreach ($positionDiscounts as $row) {
            $discountByInventory[(int) $row['inventory_id']] = $row['discounts'] ?? [];
        }

        return [
            'planning_mode' => PlanningMode::Budget->value,
            'target_budget_nn' => '500',
            'budget_elements' => [
                [
                    'client_id' => 'hamburg',
                    'inventory_id' => $catalog['hamburg']->id,
                    'spot_length_seconds' => 30,
                    'distribution_ranges' => [
                        [
                            'start_hour' => 8,
                            'end_hour_exclusive' => 12,
                            'day_group' => DayGroup::MoFr->value,
                        ],
                    ],
                    'position_discounts' => $discountByInventory[$catalog['hamburg']->id] ?? [],
                ],
                [
                    'client_id' => 'rock',
                    'inventory_id' => $catalog['rock']->id,
                    'spot_length_seconds' => 30,
                    'distribution_ranges' => [
                        [
                            'start_hour' => 8,
                            'end_hour_exclusive' => 12,
                            'day_group' => DayGroup::MoFr->value,
                        ],
                    ],
                    'position_discounts' => $discountByInventory[$catalog['rock']->id] ?? [],
                ],
            ],
            'order_discount_percent' => '0',
            'order_discounts' => $orderDiscounts,
            'ae_enabled' => $aeEnabled,
            'positions' => [],
        ];
    }

    private function appliedSavePayload(Calculation $calculation): array
    {
        $calculation->loadMissing(['positions.timeRanges', 'positions.discounts', 'orderDiscounts']);

        return [
            'planning_mode' => PlanningMode::Budget->value,
            'target_budget_nn' => (string) $calculation->target_budget_nn,
            'budget_strategy' => $calculation->budget_strategy?->value,
            'order_discount_percent' => (string) $calculation->order_discount_percent,
            'order_discounts' => $calculation->orderDiscounts->map(fn ($discount): array => [
                'type' => $discount->type->value,
                'custom_label' => $discount->custom_label,
                'percent' => (string) $discount->percent,
            ])->all(),
            'ae_enabled' => (bool) $calculation->ae_enabled,
            'budget_proposal_manual' => false,
            'lock_version' => $calculation->lock_version,
            'positions' => $calculation->positions->map(fn ($position): array => [
                'id' => $position->id,
                'client_key' => $position->client_key,
                'inventory_id' => $position->inventory_id,
                'advertising_medium_id' => $position->advertising_medium_id,
                'spot_method' => $position->spot_method->value,
                'length_seconds' => $position->length_seconds,
                'total_spot_count' => $position->total_spot_count,
                'needs_spot_redistribution' => (bool) $position->needs_spot_redistribution,
                'position_discount_percent' => (string) $position->position_discount_percent,
                'ae_percent' => (string) $position->ae_percent,
                'time_ranges' => $position->timeRanges->map(fn ($range): array => [
                    'start_hour' => $range->start_hour,
                    'end_hour_exclusive' => $range->end_hour_exclusive,
                    'day_group' => $range->day_group->value,
                    'spot_count' => $range->spot_count,
                ])->all(),
                'position_discounts' => $position->discounts->map(fn ($discount): array => [
                    'type' => $discount->type->value,
                    'custom_label' => $discount->custom_label,
                    'percent' => (string) $discount->percent,
                ])->all(),
                'plan_rows' => $position->timeRanges->flatMap(function ($range) {
                    $rows = [];
                    for ($hour = $range->start_hour; $hour < $range->end_hour_exclusive; $hour++) {
                        $rows[] = [
                            'hour' => $hour,
                            'day_group' => $range->day_group->value,
                        ];
                    }

                    return $rows;
                })->all(),
            ])->all(),
        ];
    }
}
