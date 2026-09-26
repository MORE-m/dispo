<?php

namespace Tests\Feature\DispoOrder;

use App\Enums\DispoOrderCommentType;
use App\Enums\DispoOrderStatus;
use App\Enums\Role;
use App\Models\DispoOrder;
use App\Models\DispoOrderComment;
use App\Models\User;
use App\Services\DispoOrder\DispoOrderApprovalService;
use App\Services\DispoOrder\DispoOrderCancellationService;
use App\Services\DispoOrder\DispoOrderCompletionService;
use App\Services\DispoOrder\DispoOrderInvoiceEndService;
use App\Services\DispoOrder\DispoOrderOperationalStatusService;
use App\Services\DispoOrder\DispoOrderSalesInquiryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesSavedCalculation;
use Tests\Concerns\CreatesSpotClassicCatalog;
use Tests\Concerns\EnsuresCustomerConfirmationException;
use Tests\TestCase;

class DispoOrderGeneralCommentTest extends TestCase
{
    use CreatesSavedCalculation;
    use CreatesSpotClassicCatalog;
    use EnsuresCustomerConfirmationException;
    use RefreshDatabase;

    /**
     * @return array{order: DispoOrder, creator: User, disposition: User, sales: User, admin: User, management: User, pm: User}
     */
    private function orderInProgress(): array
    {
        $catalog = $this->createSpotClassicCatalog();
        $creator = User::factory()->role(Role::Sales)->create();
        $approver = User::factory()->role(Role::Sales)->create();
        $disposition = User::factory()->role(Role::Disposition)->create();
        $admin = User::factory()->role(Role::Admin)->create();
        $management = User::factory()->role(Role::Management)->create();
        $pm = User::factory()->role(Role::ProductManagement)->create();

        $calculation = $this->createSavedCalculation($catalog, [
            ['inventory_id' => $catalog['hamburg']->id],
        ], $creator);

        $this->actingAs($creator)->post(route('dispo-orders.store', $calculation), [
            'position_ids' => [$calculation->positions()->first()->id],
        ])->assertRedirect();

        $order = DispoOrder::query()->latest('id')->firstOrFail();
        $approvals = app(DispoOrderApprovalService::class);
        $order = $this->seedCustomerConfirmationException($order, $creator);
        $submitted = $approvals->submit($order, $creator, $order->lock_version);
        $approved = $approvals->approve($submitted, $approver, $submitted->lock_version, null, true);
        $inProgress = app(DispoOrderOperationalStatusService::class)->transition(
            $approved,
            $disposition,
            $approved->lock_version,
            DispoOrderStatus::InProgress,
        );

        return [
            'order' => $inProgress,
            'creator' => $creator,
            'disposition' => $disposition,
            'sales' => $creator,
            'admin' => $admin,
            'management' => $management,
            'pm' => $pm,
        ];
    }

    public static function coreRoleProvider(): array
    {
        return [
            'sales' => ['sales'],
            'disposition' => ['disposition'],
            'admin' => ['admin'],
            'management' => ['management'],
        ];
    }

    #[DataProvider('coreRoleProvider')]
    public function test_core_roles_can_add_and_see_comment(string $roleKey): void
    {
        $ctx = $this->orderInProgress();
        $order = $ctx['order'];
        /** @var User $actor */
        $actor = $ctx[$roleKey];

        $this->actingAs($actor)->postJson(route('dispo-orders.comments.store', $order), [
            'body' => 'Hinweis von '.$roleKey,
        ])->assertOk();

        $this->assertDatabaseHas('dispo_order_comments', [
            'dispo_order_id' => $order->id,
            'type' => DispoOrderCommentType::General->value,
            'body' => 'Hinweis von '.$roleKey,
            'created_by_id' => $actor->id,
        ]);

        $order->refresh();
        $this->assertSame(DispoOrderStatus::InProgress, $order->status);
        $this->assertSame($ctx['order']->lock_version, $order->lock_version);

        $this->actingAs($actor)->get(route('dispo-orders.show', $order))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('canAddComment', true)
                ->has('order.communication', 1)
                ->where('order.communication.0.type', 'general')
                ->where('order.communication.0.body', 'Hinweis von '.$roleKey));
    }

    public function test_pm_without_extra_right_forbidden_pm_with_extra_allowed(): void
    {
        $ctx = $this->orderInProgress();
        $order = $ctx['order'];
        $pm = $ctx['pm'];

        $this->actingAs($pm)->get(route('dispo-orders.show', $order))->assertForbidden();
        $this->actingAs($pm)->postJson(route('dispo-orders.comments.store', $order), [
            'body' => 'ohne Recht',
        ])->assertForbidden();

        $pm->forceFill(['can_view_dispo_orders' => true])->save();

        $this->actingAs($pm)->get(route('dispo-orders.show', $order))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('canAddComment', true));

        $this->actingAs($pm)->postJson(route('dispo-orders.comments.store', $order), [
            'body' => 'PM mit Extra-Recht',
        ])->assertOk();

        $this->assertDatabaseHas('dispo_order_comments', [
            'dispo_order_id' => $order->id,
            'body' => 'PM mit Extra-Recht',
            'type' => DispoOrderCommentType::General->value,
        ]);

        $this->assertFalse($pm->fresh()->canAccessCalculations());
    }

    public function test_guest_cannot_comment(): void
    {
        $ctx = $this->orderInProgress();

        auth()->logout();

        $this->postJson(route('dispo-orders.comments.store', $ctx['order']), [
            'body' => 'Gast',
        ])->assertUnauthorized();
    }

    public static function allStatusesProvider(): array
    {
        return array_map(
            fn (DispoOrderStatus $status): array => [$status->value],
            DispoOrderStatus::cases(),
        );
    }

    #[DataProvider('allStatusesProvider')]
    public function test_comment_allowed_in_every_status(string $statusValue): void
    {
        $status = DispoOrderStatus::from($statusValue);
        $ctx = $this->orderInProgress();
        $order = $this->forceStatus($ctx['order'], $status);
        $actor = $ctx['admin'];
        $lockBefore = $order->lock_version;

        $this->actingAs($actor)->postJson(route('dispo-orders.comments.store', $order), [
            'body' => 'Kommentar in '.$statusValue,
        ])->assertOk();

        $order->refresh();
        $this->assertSame($status, $order->status);
        $this->assertSame($lockBefore, $order->lock_version);
        $this->assertDatabaseHas('dispo_order_comments', [
            'dispo_order_id' => $order->id,
            'body' => 'Kommentar in '.$statusValue,
            'type' => DispoOrderCommentType::General->value,
        ]);
    }

    public function test_comment_does_not_invalidate_approval_or_change_status(): void
    {
        $ctx = $this->orderInProgress();
        $order = $ctx['order'];
        $approvedRequest = $order->latestApprovalRequest;
        $this->assertNotNull($approvedRequest);
        $statusBeforeApproval = $approvedRequest->status;
        $lockBefore = $order->lock_version;
        $statusBefore = $order->status;

        $this->actingAs($ctx['disposition'])->postJson(route('dispo-orders.comments.store', $order), [
            'body' => 'ohne Freigabe-Effekt',
        ])->assertOk();

        $order->refresh();
        $approvedRequest->refresh();
        $this->assertSame($statusBefore, $order->status);
        $this->assertSame($lockBefore, $order->lock_version);
        $this->assertSame($statusBeforeApproval, $approvedRequest->status);
    }

    public function test_mixed_history_with_sales_inquiry_and_audit_and_immutability(): void
    {
        $ctx = $this->orderInProgress();
        $order = $ctx['order'];
        $disposition = $ctx['disposition'];
        $sales = $ctx['sales'];

        $this->actingAs($disposition)->postJson(route('dispo-orders.comments.store', $order), [
            'body' => 'Allgemeiner Hinweis zuerst',
        ])->assertOk();

        $order->refresh();
        $asked = app(DispoOrderSalesInquiryService::class)->ask(
            $order,
            $disposition,
            $order->lock_version,
            'Strukturierte Rückfrage',
        );
        $inquiry = DispoOrderComment::query()
            ->where('dispo_order_id', $asked->id)
            ->where('type', DispoOrderCommentType::SalesInquiry)
            ->firstOrFail();

        app(DispoOrderSalesInquiryService::class)->answer(
            $asked,
            $inquiry,
            $sales,
            $asked->lock_version,
            'Strukturierte Antwort',
        );

        $asked->refresh();
        $this->actingAs($disposition)->postJson(route('dispo-orders.comments.store', $asked), [
            'body' => 'Nach der Antwort',
        ])->assertOk();

        $this->actingAs($disposition)->get(route('dispo-orders.show', $asked))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('order.communication', 4)
                ->where('order.communication.0.type', 'general')
                ->where('order.communication.1.type', 'sales_inquiry')
                ->where('order.communication.2.type', 'sales_inquiry_response')
                ->where('order.communication.3.type', 'general'));

        $this->assertDatabaseHas('audit_events', [
            'action' => 'dispo_order.comment.created',
            'auditable_id' => $asked->id,
        ]);

        $general = DispoOrderComment::query()
            ->where('dispo_order_id', $asked->id)
            ->where('type', DispoOrderCommentType::General)
            ->orderBy('id')
            ->firstOrFail();

        $this->expectException(LogicException::class);
        $general->body = 'geändert';
        $general->save();
    }

    public function test_comment_cannot_be_deleted(): void
    {
        $ctx = $this->orderInProgress();
        $this->actingAs($ctx['admin'])->postJson(route('dispo-orders.comments.store', $ctx['order']), [
            'body' => 'Löschschutz',
        ])->assertOk();

        $comment = DispoOrderComment::query()
            ->where('dispo_order_id', $ctx['order']->id)
            ->firstOrFail();

        $this->expectException(LogicException::class);
        $comment->delete();
    }

    public function test_empty_and_too_long_body_rejected(): void
    {
        $ctx = $this->orderInProgress();
        $order = $ctx['order'];

        $this->actingAs($ctx['admin'])->postJson(route('dispo-orders.comments.store', $order), [
            'body' => '   ',
        ])->assertUnprocessable();

        $this->actingAs($ctx['admin'])->postJson(route('dispo-orders.comments.store', $order), [
            'body' => str_repeat('x', 2001),
        ])->assertUnprocessable();
    }

    public function test_completed_and_cancelled_remain_terminal_after_comment(): void
    {
        $ctx = $this->orderInProgress();
        $order = $ctx['order'];
        $disposition = $ctx['disposition'];

        foreach ($order->positions as $position) {
            $order = app(DispoOrderInvoiceEndService::class)->update(
                $order,
                $position,
                $disposition,
                $order->lock_version,
                [1],
            );
        }

        $material = app(DispoOrderOperationalStatusService::class)->transition(
            $order,
            $disposition,
            $order->lock_version,
            DispoOrderStatus::MaterialReceived,
        );
        $disposed = app(DispoOrderOperationalStatusService::class)->transition(
            $material,
            $disposition,
            $material->lock_version,
            DispoOrderStatus::Disposed,
        );
        $completed = app(DispoOrderCompletionService::class)->complete(
            $disposed,
            $disposition,
            $disposed->lock_version,
        );
        $this->assertSame(DispoOrderStatus::Completed, $completed->status);

        $this->actingAs($disposition)->postJson(route('dispo-orders.comments.store', $completed), [
            'body' => 'Nach Abschluss',
        ])->assertOk();
        $completed->refresh();
        $this->assertSame(DispoOrderStatus::Completed, $completed->status);

        $cancelled = app(DispoOrderCancellationService::class)->cancel(
            $completed,
            $disposition,
            $completed->lock_version,
            'Storno mit Kommentar danach',
        );
        $this->assertSame(DispoOrderStatus::Cancelled, $cancelled->status);

        $this->actingAs($disposition)->postJson(route('dispo-orders.comments.store', $cancelled), [
            'body' => 'Nach Storno',
        ])->assertOk();
        $cancelled->refresh();
        $this->assertSame(DispoOrderStatus::Cancelled, $cancelled->status);
    }

    private function forceStatus(DispoOrder $order, DispoOrderStatus $status): DispoOrder
    {
        $order->forceFill(['status' => $status])->save();

        return $order->fresh();
    }
}
