<?php

namespace Tests\Feature\DispoOrder;

use App\Enums\DispoOrderApprovalKind;
use App\Enums\DispoOrderApprovalStatus;
use App\Enums\DispoOrderStatus;
use App\Enums\Role;
use App\Exceptions\DispoOrderConflictException;
use App\Models\AuditEvent;
use App\Models\Calculation;
use App\Models\DispoOrder;
use App\Models\DispoOrderApprovalRequest;
use App\Models\User;
use App\Services\DispoOrder\DispoOrderWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesSavedCalculation;
use Tests\Concerns\CreatesSpotClassicCatalog;
use Tests\TestCase;

class DispoOrderRevisionTest extends TestCase
{
    use CreatesSavedCalculation;
    use CreatesSpotClassicCatalog;
    use RefreshDatabase;

    /**
     * @return array{
     *     calculation: Calculation,
     *     order: DispoOrder,
     *     creator: User,
     *     catalog: array,
     *     rejection_reason: string
     * }
     */
    private function rejectedOrder(?User $creator = null, array $options = [], ?array $catalog = null): array
    {
        $catalog ??= $this->createSpotClassicCatalog();
        $creator ??= User::factory()->role(Role::Sales)->create($options['creator'] ?? []);
        $calculation = $this->createSavedCalculation(
            $catalog,
            [
                [
                    'inventory_id' => $catalog['hamburg']->id,
                    'position_discount_percent' => $options['position_discount'] ?? '0',
                ],
                [
                    'inventory_id' => $catalog['rock']->id,
                    'total_spot_count' => 5,
                    'hour' => 10,
                ],
            ],
            $creator,
            [
                'order_discount_percent' => $options['order_discount'] ?? '0',
                'first_position_discount_percent' => $options['position_discount'] ?? '0',
            ],
        );

        $positionIds = $calculation->positions()->pluck('id')->all();
        if (! empty($options['only_first'])) {
            $positionIds = [$calculation->positions()->orderBy('sort')->first()->id];
        }

        $this->actingAs($creator)->post(route('dispo-orders.store', $calculation), [
            'position_ids' => $positionIds,
        ])->assertRedirect();

        $order = DispoOrder::query()->latest('id')->firstOrFail();

        $this->actingAs($creator)->postJson(route('dispo-orders.submit', $order), [
            'lock_version' => $order->lock_version,
        ])->assertOk();
        $order->refresh();

        $reason = $options['reason'] ?? 'Konditionen unklar';
        $decider = User::factory()->role(Role::Sales)->create();
        if (! empty($options['special'])) {
            $decider = User::factory()->role(Role::Management)->create();
        }

        $this->actingAs($decider)->postJson(route('dispo-orders.reject', $order), [
            'lock_version' => $order->lock_version,
            'reason' => $reason,
        ])->assertOk();
        $order->refresh();

        return [
            'calculation' => $calculation->fresh(),
            'order' => $order,
            'creator' => $creator,
            'catalog' => $catalog,
            'rejection_reason' => $reason,
        ];
    }

    public function test_creator_can_start_revision(): void
    {
        ['order' => $order, 'creator' => $creator, 'calculation' => $calculation, 'rejection_reason' => $reason] = $this->rejectedOrder();

        $this->actingAs($creator)
            ->get(route('dispo-orders.show', $order))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('canRevise', true)
                ->where('order.rejection_reason', $reason));

        $this->actingAs($creator)
            ->post(route('dispo-orders.revise', $order))
            ->assertRedirect(route('calculations.edit', $calculation));
    }

    public function test_other_sales_cannot_revise_foreign_rejected_order(): void
    {
        ['order' => $order] = $this->rejectedOrder();
        $other = User::factory()->role(Role::Sales)->create();

        $this->actingAs($other)->post(route('dispo-orders.revise', $order))->assertForbidden();
        $this->actingAs($other)
            ->get(route('dispo-orders.show', $order))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('canRevise', false));
    }

    public function test_disposition_and_product_management_cannot_revise(): void
    {
        ['order' => $order] = $this->rejectedOrder();

        $this->actingAs(User::factory()->role(Role::Disposition)->create())
            ->post(route('dispo-orders.revise', $order))
            ->assertForbidden();

        $this->actingAs(User::factory()->role(Role::ProductManagement)->create())
            ->post(route('dispo-orders.revise', $order))
            ->assertForbidden();
    }

    public function test_only_rejected_orders_can_be_revised(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $creator = User::factory()->role(Role::Sales)->create();
        $calculation = $this->createSavedCalculation($catalog, [
            ['inventory_id' => $catalog['hamburg']->id],
        ], $creator);

        $this->actingAs($creator)->post(route('dispo-orders.store', $calculation), [
            'position_ids' => [$calculation->positions()->first()->id],
        ]);
        $draft = DispoOrder::query()->latest('id')->firstOrFail();

        $this->actingAs($creator)->post(route('dispo-orders.revise', $draft))->assertForbidden();

        $this->actingAs($creator)->postJson(route('dispo-orders.submit', $draft), [
            'lock_version' => $draft->lock_version,
        ]);
        $draft->refresh();

        $this->actingAs($creator)->post(route('dispo-orders.revise', $draft))->assertForbidden();
    }

    public function test_revision_opens_calculation_with_context_and_rejection_reason(): void
    {
        ['order' => $order, 'creator' => $creator, 'calculation' => $calculation, 'rejection_reason' => $reason] = $this->rejectedOrder();

        $this->actingAs($creator)->post(route('dispo-orders.revise', $order))->assertRedirect();

        $this->actingAs($creator)
            ->get(route('calculations.edit', $calculation))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('calculations/wizard')
                ->where('canEdit', true)
                ->where('dispoOrderRevision.predecessor_id', $order->id)
                ->where('dispoOrderRevision.predecessor_number', $order->number)
                ->where('dispoOrderRevision.rejection_reason', $reason)
                ->where('dispoOrderRevision.return_url', route('dispo-orders.show', $order)));
    }

    public function test_calculation_can_be_updated_during_revision(): void
    {
        ['order' => $order, 'creator' => $creator, 'calculation' => $calculation] = $this->rejectedOrder();

        $this->actingAs($creator)->post(route('dispo-orders.revise', $order))->assertRedirect();

        $payload = $this->calculationUpdatePayload($calculation, [
            'customer_name' => 'Nachgebessert GmbH',
        ]);

        $this->actingAs($creator)
            ->put(route('calculations.update', $calculation), $payload)
            ->assertRedirect();

        $this->assertSame('Nachgebessert GmbH', $calculation->fresh()->customer_name);
        $this->assertSame('Testkunde GmbH', $order->fresh()->customer_name);
    }

    public function test_revised_order_gets_new_snapshot_number_draft_and_links(): void
    {
        ['order' => $order, 'creator' => $creator, 'calculation' => $calculation, 'rejection_reason' => $reason] = $this->rejectedOrder();
        $approvalCount = DispoOrderApprovalRequest::query()->where('dispo_order_id', $order->id)->count();
        $oldNumber = $order->number;
        $oldNn = (string) $order->nn_invest;

        $this->actingAs($creator)->post(route('dispo-orders.revise', $order))->assertRedirect();

        $payload = $this->calculationUpdatePayload($calculation, [
            'customer_name' => 'Korrektur AG',
            'first_position_discount_percent' => '5',
        ]);
        $this->actingAs($creator)->put(route('calculations.update', $calculation), $payload)->assertRedirect();
        $calculation->refresh()->load('positions');

        $positionIds = $calculation->positions()->pluck('id')->all();
        $this->actingAs($creator)->post(route('dispo-orders.store', $calculation), [
            'position_ids' => $positionIds,
            'revises_dispo_order_id' => $order->id,
        ])->assertRedirect();

        $revision = DispoOrder::query()->where('revises_dispo_order_id', $order->id)->firstOrFail();

        $this->assertSame(DispoOrderStatus::Draft, $revision->status);
        $this->assertSame(DispoOrderStatus::ApprovalRejected, $order->fresh()->status);
        $this->assertNotSame($oldNumber, $revision->number);
        $this->assertSame($order->number_year, $revision->number_year);
        $this->assertSame($order->number_org_seq, $revision->number_org_seq);
        $this->assertSame(2, $revision->number_calc_seq);
        $this->assertSame(
            sprintf('DA-%d-%06d-02', $order->number_year, $order->number_org_seq),
            $revision->number,
        );
        $this->assertSame('Korrektur AG', $revision->customer_name);
        $this->assertNotSame($oldNn, (string) $revision->nn_invest);
        $this->assertSame($order->id, $revision->revises_dispo_order_id);
        $this->assertSame(0, $revision->approvalRequests()->count());
        $this->assertSame(
            $approvalCount,
            DispoOrderApprovalRequest::query()->where('dispo_order_id', $order->id)->count(),
        );
        $this->assertSame($reason, $order->fresh()->latestApprovalRequest->rejection_reason);
        $this->assertSame(
            DispoOrderApprovalStatus::Rejected,
            $order->fresh()->latestApprovalRequest->status,
        );

        $this->actingAs($creator)
            ->get(route('dispo-orders.show', $order))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('canRevise', false)
                ->where('order.revision.id', $revision->id)
                ->where('order.revision.number', $revision->number)
                ->where('order.revision.status', DispoOrderStatus::Draft->value));

        $this->actingAs($creator)
            ->get(route('dispo-orders.show', $revision))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('order.revises.id', $order->id)
                ->where('order.revises.status', DispoOrderStatus::ApprovalRejected->value));
    }

    public function test_second_direct_successor_is_rejected(): void
    {
        ['order' => $order, 'creator' => $creator, 'calculation' => $calculation] = $this->rejectedOrder();
        $positionIds = $calculation->positions()->pluck('id')->all();

        $this->actingAs($creator)->post(route('dispo-orders.store', $calculation), [
            'position_ids' => $positionIds,
            'revises_dispo_order_id' => $order->id,
        ])->assertRedirect();

        $this->actingAs($creator)->post(route('dispo-orders.store', $calculation), [
            'position_ids' => $positionIds,
            'revises_dispo_order_id' => $order->id,
        ])->assertForbidden();

        $this->assertSame(1, DispoOrder::query()->where('revises_dispo_order_id', $order->id)->count());
    }

    public function test_manipulated_and_foreign_predecessor_ids_are_rejected(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        ['order' => $orderA, 'creator' => $creator, 'calculation' => $calcA] = $this->rejectedOrder(null, [], $catalog);
        ['order' => $orderB, 'calculation' => $calcB] = $this->rejectedOrder(
            User::factory()->role(Role::Sales)->create(),
            [],
            $catalog,
        );

        $positionIds = $calcA->positions()->pluck('id')->all();

        $this->actingAs($creator)->post(route('dispo-orders.store', $calcA), [
            'position_ids' => $positionIds,
            'revises_dispo_order_id' => 999999,
        ])->assertForbidden();

        $this->actingAs($creator)->post(route('dispo-orders.store', $calcA), [
            'position_ids' => $positionIds,
            'revises_dispo_order_id' => $orderB->id,
        ])->assertForbidden();

        $this->assertSame(0, DispoOrder::query()->whereNotNull('revises_dispo_order_id')->count());
        $this->assertSame(DispoOrderStatus::ApprovalRejected, $orderA->fresh()->status);
        $this->assertSame(DispoOrderStatus::ApprovalRejected, $orderB->fresh()->status);
    }

    public function test_special_approval_is_reassessed_for_new_snapshot(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $creator = User::factory()->role(Role::Sales)->create([
            'discount_limit_percent' => '10',
        ]);

        ['order' => $order, 'calculation' => $calculation] = $this->rejectedOrder(
            $creator,
            ['position_discount' => '20', 'special' => true],
            $catalog,
        );
        $this->assertTrue($order->requiresSpecialApproval());
        $this->assertSame(DispoOrderApprovalKind::Special, $order->approval_kind);

        $this->actingAs($creator)->post(route('dispo-orders.revise', $order))->assertRedirect();
        $payload = $this->calculationUpdatePayload($calculation, [
            'first_position_discount_percent' => '5',
        ]);
        $this->actingAs($creator)->put(route('calculations.update', $calculation), $payload)->assertRedirect();
        $calculation->refresh();

        $this->actingAs($creator)->post(route('dispo-orders.store', $calculation), [
            'position_ids' => $calculation->positions()->pluck('id')->all(),
            'revises_dispo_order_id' => $order->id,
        ])->assertRedirect();

        $revision = DispoOrder::query()->where('revises_dispo_order_id', $order->id)->firstOrFail();
        $this->assertFalse($revision->requiresSpecialApproval());
        $this->assertSame(DispoOrderApprovalKind::Regular, $revision->approval_kind);
        $this->assertTrue($order->fresh()->requiresSpecialApproval());
    }

    public function test_audit_events_contain_both_order_ids(): void
    {
        ['order' => $order, 'creator' => $creator, 'calculation' => $calculation] = $this->rejectedOrder();
        $positionIds = $calculation->positions()->pluck('id')->all();

        $this->actingAs($creator)->post(route('dispo-orders.store', $calculation), [
            'position_ids' => $positionIds,
            'revises_dispo_order_id' => $order->id,
        ])->assertRedirect();

        $revision = DispoOrder::query()->where('revises_dispo_order_id', $order->id)->firstOrFail();

        $onSuccessor = AuditEvent::query()
            ->where('action', 'dispo_order.revision_created')
            ->where('auditable_id', $revision->id)
            ->firstOrFail();
        $onPredecessor = AuditEvent::query()
            ->where('action', 'dispo_order.revision_created')
            ->where('auditable_id', $order->id)
            ->firstOrFail();

        $this->assertSame($order->id, $onSuccessor->new_values['predecessor_dispo_order_id']);
        $this->assertSame($revision->id, $onSuccessor->new_values['successor_dispo_order_id']);
        $this->assertSame($calculation->id, $onSuccessor->new_values['calculation_id']);
        $this->assertSame($positionIds, $onSuccessor->new_values['position_ids']);
        $this->assertSame($revision->id, $onPredecessor->new_values['revision_dispo_order_id']);
    }

    public function test_failed_revision_leaves_no_half_successor(): void
    {
        ['order' => $order, 'creator' => $creator, 'calculation' => $calculation] = $this->rejectedOrder();

        $this->actingAs($creator)->post(route('dispo-orders.store', $calculation), [
            'position_ids' => [999999],
            'revises_dispo_order_id' => $order->id,
        ])->assertSessionHasErrors('position_ids');

        $this->assertSame(0, DispoOrder::query()->where('revises_dispo_order_id', $order->id)->count());
        $this->assertFalse($order->fresh()->hasRevision());
    }

    public function test_preferred_position_ids_are_exposed_in_revision_context(): void
    {
        ['order' => $order, 'creator' => $creator, 'calculation' => $calculation] = $this->rejectedOrder(
            null,
            ['only_first' => true],
        );
        $preferredId = $order->positions()->first()->calculation_position_id;

        $this->actingAs($creator)->post(route('dispo-orders.revise', $order))->assertRedirect();

        $response = $this->actingAs($creator)->getJson(route('dispo-orders.positions', $calculation));
        $response->assertOk();
        $this->assertSame([$preferredId], $response->json('preferred_position_ids'));
        $this->assertSame($order->id, $response->json('revision.predecessor_id'));
    }

    public function test_admin_cannot_revise_foreign_rejected_order(): void
    {
        ['order' => $order] = $this->rejectedOrder();
        $admin = User::factory()->role(Role::Admin)->create();

        $this->actingAs($admin)->post(route('dispo-orders.revise', $order))->assertForbidden();
    }

    public function test_writer_conflict_when_successor_already_exists(): void
    {
        ['order' => $order, 'creator' => $creator, 'calculation' => $calculation] = $this->rejectedOrder();
        $positionIds = $calculation->positions()->pluck('id')->all();
        $writer = app(DispoOrderWriter::class);

        $writer->createRevision($order, $calculation, $positionIds, $creator);

        $this->expectException(DispoOrderConflictException::class);
        $writer->createRevision($order->fresh(), $calculation, $positionIds, $creator);
    }

    /**
     * @param  array{customer_name?: string, first_position_discount_percent?: string}  $overrides
     * @return array<string, mixed>
     */
    private function calculationUpdatePayload(Calculation $calculation, array $overrides = []): array
    {
        $calculation->load(['positions.planRows', 'positions.timeRanges', 'positions.discounts', 'orderDiscounts']);

        $firstDiscount = $overrides['first_position_discount_percent'] ?? null;

        return [
            'lock_version' => $calculation->lock_version,
            'planning_mode' => $calculation->planning_mode->value,
            'customer_name' => $overrides['customer_name'] ?? $calculation->customer_name,
            'agency_name' => $calculation->agency_name,
            'campaign' => $calculation->campaign,
            'product_title' => $calculation->product_title,
            'briefing' => $calculation->briefing,
            'order_discount_percent' => (string) $calculation->order_discount_percent,
            'ae_enabled' => (bool) $calculation->ae_enabled,
            'order_discounts' => $calculation->orderDiscounts->map(fn ($discount): array => [
                'type' => $discount->type->value,
                'custom_label' => $discount->custom_label,
                'percent' => (string) $discount->percent,
            ])->all(),
            'positions' => $calculation->positions->values()->map(function ($position, int $index) use ($firstDiscount): array {
                $discount = $firstDiscount !== null && $index === 0
                    ? $firstDiscount
                    : (string) $position->position_discount_percent;

                $positionDiscounts = $firstDiscount !== null && $index === 0
                    ? (
                        (float) $discount > 0
                            ? [['type' => 'quantity', 'custom_label' => null, 'percent' => $discount]]
                            : []
                    )
                    : $position->discounts->map(fn ($d): array => [
                        'type' => $d->type->value,
                        'custom_label' => $d->custom_label,
                        'percent' => (string) $d->percent,
                    ])->all();

                return [
                    'id' => $position->id,
                    'client_key' => $position->client_key,
                    'inventory_id' => $position->inventory_id,
                    'advertising_medium_id' => $position->advertising_medium_id,
                    'spot_method' => $position->spot_method->value,
                    'length_seconds' => $position->length_seconds,
                    'total_spot_count' => $position->total_spot_count,
                    'position_discount_percent' => $discount,
                    'ae_percent' => (string) $position->ae_percent,
                    'plan_rows' => $position->planRows->map(fn ($row): array => [
                        'hour' => $row->hour,
                        'day_group' => $row->day_group->value,
                    ])->all(),
                    'time_ranges' => $position->timeRanges->map(fn ($range): array => [
                        'start_hour' => $range->start_hour,
                        'end_hour_exclusive' => $range->end_hour_exclusive,
                        'day_group' => $range->day_group->value,
                        'spot_count' => $range->spot_count,
                    ])->all(),
                    'position_discounts' => $positionDiscounts,
                ];
            })->all(),
        ];
    }
}
