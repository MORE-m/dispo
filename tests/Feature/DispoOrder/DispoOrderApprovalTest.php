<?php

namespace Tests\Feature\DispoOrder;

use App\Enums\DispoOrderApprovalKind;
use App\Enums\DispoOrderApprovalStatus;
use App\Enums\DispoOrderStatus;
use App\Enums\Role;
use App\Models\AuditEvent;
use App\Models\Calculation;
use App\Models\DispoOrder;
use App\Models\DispoOrderApprovalRequest;
use App\Models\User;
use App\Services\DispoOrder\DispoOrderApprovalService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesSavedCalculation;
use Tests\Concerns\CreatesSpotClassicCatalog;
use Tests\TestCase;

class DispoOrderApprovalTest extends TestCase
{
    use CreatesSavedCalculation;
    use CreatesSpotClassicCatalog;
    use RefreshDatabase;

    /**
     * @param  array{hamburg?: mixed, rock?: mixed, medium?: mixed}|null  $catalog
     * @return array{calculation: Calculation, order: DispoOrder, creator: User, catalog: array}
     */
    private function draftOrder(?User $creator = null, array $options = [], ?array $catalog = null): array
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

        $positionIds = isset($options['position_ids'])
            ? $options['position_ids']
            : $calculation->positions()->pluck('id')->all();

        if (isset($options['only_first']) && $options['only_first']) {
            $positionIds = [$calculation->positions()->orderBy('sort')->first()->id];
        }

        if (isset($options['only_second']) && $options['only_second']) {
            $positionIds = [$calculation->positions()->orderBy('sort')->skip(1)->first()->id];
        }

        $this->actingAs($creator)->post(route('dispo-orders.store', $calculation), [
            'position_ids' => $positionIds,
        ])->assertRedirect();

        return [
            'calculation' => $calculation->fresh(),
            'order' => DispoOrder::query()->latest('id')->firstOrFail(),
            'creator' => $creator,
            'catalog' => $catalog,
        ];
    }

    public function test_sales_can_submit_own_draft(): void
    {
        ['order' => $order, 'creator' => $creator] = $this->draftOrder();

        $this->actingAs($creator)->postJson(route('dispo-orders.submit', $order), [
            'lock_version' => $order->lock_version,
        ])->assertOk();

        $order->refresh();
        $this->assertSame(DispoOrderStatus::AwaitingSalesApproval, $order->status);
        $this->assertSame(2, $order->lock_version);
        $this->assertDatabaseHas('dispo_order_approval_requests', [
            'dispo_order_id' => $order->id,
            'status' => DispoOrderApprovalStatus::Pending->value,
            'open_guard' => 1,
        ]);
    }

    public function test_admin_and_management_can_submit(): void
    {
        $catalog = $this->createSpotClassicCatalog();

        foreach ([Role::Admin, Role::Management] as $role) {
            $user = User::factory()->role($role)->create();
            ['order' => $order] = $this->draftOrder($user, [], $catalog);

            $this->actingAs($user)->postJson(route('dispo-orders.submit', $order), [
                'lock_version' => $order->lock_version,
            ])->assertOk();
        }
    }

    public function test_disposition_and_product_management_cannot_submit(): void
    {
        ['order' => $order] = $this->draftOrder();

        $this->actingAs(User::factory()->role(Role::Disposition)->create())
            ->postJson(route('dispo-orders.submit', $order), [
                'lock_version' => $order->lock_version,
            ])->assertForbidden();

        $this->actingAs(User::factory()->role(Role::ProductManagement)->create())
            ->postJson(route('dispo-orders.submit', $order), [
                'lock_version' => $order->lock_version,
            ])->assertForbidden();
    }

    public function test_every_order_needs_approval_no_direct_disposition(): void
    {
        ['order' => $order, 'creator' => $creator] = $this->draftOrder();
        $this->assertSame(DispoOrderStatus::Draft, $order->status);

        $this->actingAs($creator)->postJson(route('dispo-orders.submit', $order), [
            'lock_version' => $order->lock_version,
        ])->assertOk();

        $order->refresh();
        $this->assertNotSame(DispoOrderStatus::AtDisposition, $order->status);
        $this->assertSame(DispoOrderStatus::AwaitingSalesApproval, $order->status);
    }

    public function test_other_sales_can_approve_regular_order(): void
    {
        ['order' => $order, 'creator' => $creator] = $this->draftOrder();
        $other = User::factory()->role(Role::Sales)->create();

        $this->actingAs($creator)->postJson(route('dispo-orders.submit', $order), [
            'lock_version' => $order->lock_version,
        ])->assertOk();
        $order->refresh();

        $this->actingAs($other)->postJson(route('dispo-orders.approve', $order), [
            'lock_version' => $order->lock_version,
            'note' => 'passt',
        ])->assertOk();

        $order->refresh();
        $this->assertSame(DispoOrderStatus::AtDisposition, $order->status);
        $this->assertSame(DispoOrderApprovalStatus::Approved, $order->latestApprovalRequest->status);
        $this->assertSame('passt', $order->latestApprovalRequest->decision_note);
    }

    public function test_other_sales_can_reject_regular_order(): void
    {
        ['order' => $order, 'creator' => $creator] = $this->draftOrder();
        $other = User::factory()->role(Role::Sales)->create();

        $this->actingAs($creator)->postJson(route('dispo-orders.submit', $order), [
            'lock_version' => $order->lock_version,
        ])->assertOk();
        $order->refresh();

        $this->actingAs($other)->postJson(route('dispo-orders.reject', $order), [
            'lock_version' => $order->lock_version,
            'reason' => 'Konditionen unklar',
        ])->assertOk();

        $order->refresh();
        $this->assertSame(DispoOrderStatus::ApprovalRejected, $order->status);
        $this->assertSame('Konditionen unklar', $order->latestApprovalRequest->rejection_reason);
    }

    public function test_sales_cannot_decide_special_but_admin_and_management_can(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $creator = User::factory()->role(Role::Sales)->create([
            'discount_limit_percent' => '10',
        ]);
        ['order' => $order] = $this->draftOrder($creator, ['position_discount' => '20'], $catalog);
        $this->assertTrue($order->requiresSpecialApproval());
        $this->assertSame(DispoOrderApprovalKind::Special, $order->approval_kind);

        $this->actingAs($creator)->postJson(route('dispo-orders.submit', $order), [
            'lock_version' => $order->lock_version,
        ])->assertOk();
        $order->refresh();

        $otherSales = User::factory()->role(Role::Sales)->create();
        $this->actingAs($otherSales)->postJson(route('dispo-orders.approve', $order), [
            'lock_version' => $order->lock_version,
        ])->assertForbidden();
        $this->actingAs($otherSales)->postJson(route('dispo-orders.reject', $order), [
            'lock_version' => $order->lock_version,
            'reason' => 'test',
        ])->assertForbidden();

        $admin = User::factory()->role(Role::Admin)->create();
        $this->actingAs($admin)->postJson(route('dispo-orders.approve', $order), [
            'lock_version' => $order->lock_version,
        ])->assertOk();
        $this->assertSame(DispoOrderStatus::AtDisposition, $order->fresh()->status);

        $creator2 = User::factory()->role(Role::Sales)->create([
            'discount_limit_percent' => '10',
        ]);
        ['order' => $special2] = $this->draftOrder($creator2, ['position_discount' => '25'], $catalog);
        $this->actingAs($creator2)->postJson(route('dispo-orders.submit', $special2), [
            'lock_version' => $special2->lock_version,
        ]);
        $special2->refresh();

        $management = User::factory()->role(Role::Management)->create();
        $this->actingAs($management)->postJson(route('dispo-orders.approve', $special2), [
            'lock_version' => $special2->lock_version,
        ])->assertOk();
    }

    public function test_creator_cannot_decide_even_as_admin(): void
    {
        $creator = User::factory()->role(Role::Admin)->create();
        ['order' => $order] = $this->draftOrder($creator);

        $this->actingAs($creator)->postJson(route('dispo-orders.submit', $order), [
            'lock_version' => $order->lock_version,
        ])->assertOk();
        $order->refresh();

        $this->actingAs($creator)->postJson(route('dispo-orders.approve', $order), [
            'lock_version' => $order->lock_version,
        ])->assertForbidden();
        $this->actingAs($creator)->postJson(route('dispo-orders.reject', $order), [
            'lock_version' => $order->lock_version,
            'reason' => 'selbst',
        ])->assertForbidden();
    }

    public function test_submitted_at_is_preserved_when_deciding(): void
    {
        ['order' => $order, 'creator' => $creator] = $this->draftOrder();
        $other = User::factory()->role(Role::Sales)->create();

        $this->travelTo(CarbonImmutable::parse('2026-09-03 19:17:00', 'UTC'));

        $this->actingAs($creator)->postJson(route('dispo-orders.submit', $order), [
            'lock_version' => $order->lock_version,
        ])->assertOk();

        $request = $order->fresh()->pendingApprovalRequest;
        $this->assertNotNull($request);
        $submittedAt = $request->submitted_at->toIso8601String();

        $this->travelTo(CarbonImmutable::parse('2026-09-03 19:20:00', 'UTC'));

        $this->actingAs($other)->postJson(route('dispo-orders.approve', $order->fresh()), [
            'lock_version' => $order->fresh()->lock_version,
        ])->assertOk();

        $request->refresh();
        $this->assertSame($submittedAt, $request->submitted_at->toIso8601String());
        $this->assertTrue($request->decided_at->greaterThan($request->submitted_at));
        $this->assertTrue(
            $request->submitted_at->diffInMinutes($request->decided_at) < 10,
        );
    }

    public function test_rejection_requires_reason(): void
    {
        ['order' => $order, 'creator' => $creator] = $this->draftOrder();
        $other = User::factory()->role(Role::Sales)->create();
        $this->actingAs($creator)->postJson(route('dispo-orders.submit', $order), [
            'lock_version' => $order->lock_version,
        ]);
        $order->refresh();

        $this->actingAs($other)->postJson(route('dispo-orders.reject', $order), [
            'lock_version' => $order->lock_version,
            'reason' => '   ',
        ])->assertStatus(422)->assertJsonValidationErrors('reason');
    }

    public function test_decision_is_atomic_and_history_persists(): void
    {
        ['order' => $order, 'creator' => $creator] = $this->draftOrder();
        $other = User::factory()->role(Role::Sales)->create();
        $this->actingAs($creator)->postJson(route('dispo-orders.submit', $order), [
            'lock_version' => $order->lock_version,
        ]);
        $order->refresh();
        $requestId = $order->pendingApprovalRequest->id;

        $this->actingAs($other)->postJson(route('dispo-orders.reject', $order), [
            'lock_version' => $order->lock_version,
            'reason' => 'Historarische Ablehnung',
        ])->assertOk();

        $request = DispoOrderApprovalRequest::query()->findOrFail($requestId);
        $this->assertSame(DispoOrderApprovalStatus::Rejected, $request->status);
        $this->assertNull($request->open_guard);
        $this->assertSame(DispoOrderStatus::ApprovalRejected, $order->fresh()->status);

        $this->actingAs($other)->postJson(route('dispo-orders.approve', $order->fresh()), [
            'lock_version' => $order->fresh()->lock_version,
        ])->assertStatus(409);

        $this->actingAs($creator)->postJson(route('dispo-orders.submit', $order->fresh()), [
            'lock_version' => $order->fresh()->lock_version,
        ])->assertStatus(409);
    }

    public function test_stale_lock_version_and_double_submit_conflict(): void
    {
        ['order' => $order, 'creator' => $creator] = $this->draftOrder();

        $this->actingAs($creator)->postJson(route('dispo-orders.submit', $order), [
            'lock_version' => 999,
        ])->assertStatus(409);

        $this->actingAs($creator)->postJson(route('dispo-orders.submit', $order), [
            'lock_version' => $order->lock_version,
        ])->assertOk();

        $this->actingAs($creator)->postJson(route('dispo-orders.submit', $order->fresh()), [
            'lock_version' => $order->fresh()->lock_version,
        ])->assertStatus(409);
    }

    public function test_partial_adoption_does_not_bypass_special_approval(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $creator = User::factory()->role(Role::Sales)->create([
            'discount_limit_percent' => '10',
        ]);
        ['order' => $withSpecial] = $this->draftOrder($creator, [
            'position_discount' => '20',
            'only_first' => true,
        ], $catalog);
        $this->assertTrue($withSpecial->requiresSpecialApproval());

        ['order' => $withoutSpecial] = $this->draftOrder($creator, [
            'position_discount' => '20',
            'only_second' => true,
        ], $catalog);
        $this->assertFalse($withoutSpecial->requiresSpecialApproval());
    }

    public function test_later_user_limit_change_does_not_alter_stored_kind(): void
    {
        $creator = User::factory()->role(Role::Sales)->create([
            'discount_limit_percent' => '10',
        ]);
        ['order' => $order] = $this->draftOrder($creator, ['position_discount' => '20']);
        $this->assertSame(DispoOrderApprovalKind::Special, $order->approval_kind);

        $creator->update(['discount_limit_percent' => '50']);

        $this->actingAs($creator)->postJson(route('dispo-orders.submit', $order), [
            'lock_version' => $order->lock_version,
        ])->assertOk();

        $order->refresh();
        $this->assertSame(DispoOrderApprovalKind::Special, $order->approval_kind);
        $this->assertSame(DispoOrderApprovalKind::Special, $order->pendingApprovalRequest->kind);
    }

    public function test_audit_events_and_inertia_props(): void
    {
        ['order' => $order, 'creator' => $creator] = $this->draftOrder();
        $other = User::factory()->role(Role::Sales)->create();

        $this->actingAs($creator)->postJson(route('dispo-orders.submit', $order), [
            'lock_version' => $order->lock_version,
        ]);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'dispo_order.submitted_for_approval',
            'auditable_id' => $order->id,
            'user_id' => $creator->id,
        ]);

        $order->refresh();
        $this->actingAs($creator)
            ->get(route('dispo-orders.show', $order))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('canSubmit', false)
                ->where('canApprove', false)
                ->where('canReject', false)
                ->where('isCreator', true));

        $this->actingAs($other)
            ->get(route('dispo-orders.show', $order))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('canApprove', true)
                ->where('canReject', true)
                ->where('isCreator', false));

        $this->actingAs($other)->postJson(route('dispo-orders.approve', $order), [
            'lock_version' => $order->lock_version,
        ]);

        $event = AuditEvent::query()->where('action', 'dispo_order.approved')->firstOrFail();
        $this->assertSame($other->id, $event->user_id);
        $this->assertSame(DispoOrderStatus::AtDisposition->value, $event->new_values['status']);

        $this->actingAs($other)
            ->get(route('dispo-orders.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('orders.0.nn_invest', (string) $order->fresh()->nn_invest)
                ->where('orders.0.requires_special_approval', false));
    }

    public function test_disposition_can_view_at_disposition_but_not_operate(): void
    {
        ['order' => $order, 'creator' => $creator] = $this->draftOrder();
        $other = User::factory()->role(Role::Sales)->create();
        $this->actingAs($creator)->postJson(route('dispo-orders.submit', $order), [
            'lock_version' => $order->lock_version,
        ]);
        $order->refresh();
        $this->actingAs($other)->postJson(route('dispo-orders.approve', $order), [
            'lock_version' => $order->lock_version,
        ]);

        $disposition = User::factory()->role(Role::Disposition)->create();
        $this->actingAs($disposition)->get(route('dispo-orders.show', $order->fresh()))->assertOk();
        $this->actingAs($disposition)->postJson(route('dispo-orders.submit', $order->fresh()), [
            'lock_version' => $order->fresh()->lock_version,
        ])->assertForbidden();
    }

    public function test_service_direct_approve_increments_lock_version(): void
    {
        ['order' => $order, 'creator' => $creator] = $this->draftOrder();
        $other = User::factory()->role(Role::Sales)->create();
        $service = app(DispoOrderApprovalService::class);

        $submitted = $service->submit($order, $creator, $order->lock_version);
        $approved = $service->approve($submitted, $other, $submitted->lock_version, null);

        $this->assertSame(3, $approved->lock_version);
        $this->assertSame(DispoOrderStatus::AtDisposition, $approved->status);
    }
}
