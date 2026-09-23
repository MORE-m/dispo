<?php

namespace Tests\Feature\DispoOrder;

use App\Enums\DispoOrderApprovalStatus;
use App\Enums\DispoOrderStatus;
use App\Enums\Role;
use App\Models\AuditEvent;
use App\Models\DispoOrder;
use App\Models\DispoOrderApprovalRequest;
use App\Models\User;
use App\Services\DispoOrder\DispoOrderApprovalService;
use App\Services\DispoOrder\DispoOrderWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesSavedCalculation;
use Tests\Concerns\CreatesSpotClassicCatalog;
use Tests\Concerns\EnsuresCustomerConfirmationException;
use Tests\TestCase;

class DispoOrderCustomerConfirmationExceptionTest extends TestCase
{
    use CreatesSavedCalculation;
    use CreatesSpotClassicCatalog;
    use EnsuresCustomerConfirmationException;
    use RefreshDatabase;

    /**
     * @return array{order: DispoOrder, creator: User}
     */
    private function draftWithoutConfirmation(?User $creator = null): array
    {
        $catalog = $this->createSpotClassicCatalog();
        $creator ??= User::factory()->role(Role::Sales)->create();
        $calculation = $this->createSavedCalculation($catalog, [
            ['inventory_id' => $catalog['hamburg']->id],
        ], $creator);

        $this->actingAs($creator)->post(route('dispo-orders.store', $calculation), [
            'position_ids' => [$calculation->positions()->first()->id],
        ])->assertRedirect();

        return [
            'order' => DispoOrder::query()->latest('id')->firstOrFail(),
            'creator' => $creator,
        ];
    }

    public function test_sales_admin_management_can_set_exception_disposition_and_pm_cannot(): void
    {
        ['order' => $order, 'creator' => $sales] = $this->draftWithoutConfirmation();

        foreach ([Role::Sales, Role::Admin, Role::Management] as $role) {
            $actor = $role === Role::Sales
                ? $sales
                : User::factory()->role($role)->create();
            $order = $order->fresh();
            $this->actingAs($actor)->putJson(route('dispo-orders.customer-confirmation.update', $order), [
                'lock_version' => $order->lock_version,
                'confirmation_without_upload' => true,
                'exception_reason' => 'Grund für '.$role->value,
            ])->assertOk();
            $order->refresh();
            $this->assertTrue($order->customer_confirmation_without_upload);
        }

        $order = $order->fresh();
        $this->actingAs(User::factory()->role(Role::Disposition)->create())
            ->putJson(route('dispo-orders.customer-confirmation.update', $order), [
                'lock_version' => $order->lock_version,
                'confirmation_without_upload' => true,
                'exception_reason' => 'Disposition darf nicht',
            ])->assertForbidden();

        $this->actingAs(User::factory()->role(Role::ProductManagement)->create())
            ->putJson(route('dispo-orders.customer-confirmation.update', $order), [
                'lock_version' => $order->lock_version,
                'confirmation_without_upload' => true,
                'exception_reason' => 'PM darf nicht',
            ])->assertForbidden();
    }

    public function test_exception_only_editable_in_draft_and_validates_reason(): void
    {
        ['order' => $order, 'creator' => $creator] = $this->draftWithoutConfirmation();

        $this->actingAs($creator)->putJson(route('dispo-orders.customer-confirmation.update', $order), [
            'lock_version' => $order->lock_version,
            'confirmation_without_upload' => true,
            'exception_reason' => '',
        ])->assertStatus(422)->assertJsonValidationErrors('exception_reason');

        $this->actingAs($creator)->putJson(route('dispo-orders.customer-confirmation.update', $order), [
            'lock_version' => $order->lock_version,
            'confirmation_without_upload' => true,
            'exception_reason' => '   ',
        ])->assertStatus(422);

        $this->actingAs($creator)->putJson(route('dispo-orders.customer-confirmation.update', $order), [
            'lock_version' => $order->lock_version,
            'confirmation_without_upload' => true,
            'exception_reason' => str_repeat('x', 2001),
        ])->assertStatus(422)->assertJsonValidationErrors('exception_reason');

        $before = $order->lock_version;
        $this->actingAs($creator)->putJson(route('dispo-orders.customer-confirmation.update', $order), [
            'lock_version' => $order->lock_version,
            'confirmation_without_upload' => true,
            'exception_reason' => 'Kundenfreigabe liegt per E-Mail vor; Upload wird nachgereicht.',
        ])->assertOk();

        $order->refresh();
        $this->assertSame($before + 1, $order->lock_version);
        $this->assertTrue($order->customer_confirmation_without_upload);
        $this->assertNotNull($order->customer_confirmation_exception_set_at);
        $this->assertSame($creator->id, $order->customer_confirmation_exception_set_by_id);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'dispo_order.customer_confirmation_exception_set',
        ]);

        $order = $this->seedCustomerConfirmationException($order->fresh(), $creator);
        $submitted = app(DispoOrderApprovalService::class)
            ->submit($order, $creator, $order->lock_version);

        $this->actingAs($creator)->putJson(route('dispo-orders.customer-confirmation.update', $submitted), [
            'lock_version' => $submitted->lock_version,
            'confirmation_without_upload' => false,
            'exception_reason' => null,
        ])->assertForbidden();
    }

    public function test_clear_exception_resets_fields_and_blocks_submit(): void
    {
        ['order' => $order, 'creator' => $creator] = $this->draftWithoutConfirmation();
        $order = $this->ensureCustomerConfirmationException($order, $creator);

        $this->actingAs($creator)->putJson(route('dispo-orders.customer-confirmation.update', $order), [
            'lock_version' => $order->lock_version,
            'confirmation_without_upload' => false,
            'exception_reason' => null,
        ])->assertOk();

        $order->refresh();
        $this->assertFalse($order->customer_confirmation_without_upload);
        $this->assertNull($order->customer_confirmation_exception_reason);
        $this->assertNull($order->customer_confirmation_exception_set_by_id);
        $this->assertNull($order->customer_confirmation_exception_set_by_name);
        $this->assertNull($order->customer_confirmation_exception_set_at);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'dispo_order.customer_confirmation_exception_cleared',
        ]);

        $this->actingAs($creator)->postJson(route('dispo-orders.submit', $order), [
            'lock_version' => $order->lock_version,
        ])->assertStatus(422)->assertJsonValidationErrors('customer_confirmation');
    }

    public function test_submit_without_exception_blocked_with_valid_snapshot_when_allowed(): void
    {
        ['order' => $order, 'creator' => $creator] = $this->draftWithoutConfirmation();

        $this->actingAs($creator)->postJson(route('dispo-orders.submit', $order), [
            'lock_version' => $order->lock_version,
        ])->assertStatus(422)->assertJsonValidationErrors('customer_confirmation');

        $order = $this->ensureCustomerConfirmationException($order->fresh(), $creator);
        $this->actingAs($creator)->postJson(route('dispo-orders.submit', $order), [
            'lock_version' => $order->lock_version,
        ])->assertOk();

        $request = DispoOrderApprovalRequest::query()
            ->where('dispo_order_id', $order->id)
            ->firstOrFail();
        $this->assertTrue($request->customer_confirmation_without_upload);
        $this->assertSame(
            'Kundenfreigabe liegt per E-Mail vor; Upload wird nachgereicht.',
            $request->customer_confirmation_exception_reason,
        );
        $this->assertSame($creator->id, $request->customer_confirmation_exception_set_by_id);

        // Spätere Model-Änderung darf Snapshot nicht verändern.
        $order->refresh()->forceFill([
            'customer_confirmation_exception_reason' => 'GEÄNDERT AM DRAFT-FELD',
        ])->save();
        $request->refresh();
        $this->assertSame(
            'Kundenfreigabe liegt per E-Mail vor; Upload wird nachgereicht.',
            $request->customer_confirmation_exception_reason,
        );
    }

    public function test_approve_requires_acknowledgement_reject_does_not(): void
    {
        ['order' => $order, 'creator' => $creator] = $this->draftWithoutConfirmation();
        $approver = User::factory()->role(Role::Sales)->create();
        $order = $this->ensureCustomerConfirmationException($order, $creator);
        $submitted = app(DispoOrderApprovalService::class)
            ->submit($order, $creator, $order->lock_version);

        $this->actingAs($approver)->postJson(route('dispo-orders.approve', $submitted), [
            'lock_version' => $submitted->lock_version,
        ])->assertStatus(422)->assertJsonValidationErrors('customer_confirmation_exception_acknowledged');

        $submitted->refresh();
        $this->assertSame(DispoOrderStatus::AwaitingSalesApproval, $submitted->status);
        $this->assertSame(DispoOrderApprovalStatus::Pending, $submitted->pendingApprovalRequest->status);
        $this->assertFalse((bool) $submitted->pendingApprovalRequest->customer_confirmation_exception_acknowledged);
        $this->assertSame(0, AuditEvent::query()->where('action', 'dispo_order.approved')->count());

        $this->actingAs($approver)->postJson(route('dispo-orders.approve', $submitted), [
            'lock_version' => $submitted->lock_version,
            'customer_confirmation_exception_acknowledged' => true,
        ])->assertOk();

        $submitted->refresh();
        $this->assertSame(DispoOrderStatus::AtDisposition, $submitted->status);
        $req = $submitted->latestApprovalRequest;
        $this->assertTrue($req->customer_confirmation_exception_acknowledged);
        $this->assertSame($approver->id, $req->customer_confirmation_exception_acknowledged_by_id);
        $this->assertNotNull($req->customer_confirmation_exception_acknowledged_at);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'dispo_order.approved',
        ]);
    }

    public function test_reject_without_acknowledgement_still_works(): void
    {
        ['order' => $order, 'creator' => $creator] = $this->draftWithoutConfirmation();
        $approver = User::factory()->role(Role::Sales)->create();
        $order = $this->ensureCustomerConfirmationException($order, $creator);
        $submitted = app(DispoOrderApprovalService::class)
            ->submit($order, $creator, $order->lock_version);

        $this->actingAs($approver)->postJson(route('dispo-orders.reject', $submitted), [
            'lock_version' => $submitted->lock_version,
            'reason' => 'Bestätigung unzureichend dokumentiert',
        ])->assertOk();

        $submitted->refresh();
        $this->assertSame(DispoOrderStatus::ApprovalRejected, $submitted->status);
        $this->assertFalse((bool) $submitted->latestApprovalRequest->customer_confirmation_exception_acknowledged);
    }

    public function test_special_approval_also_requires_acknowledgement_and_four_eyes_holds(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $creator = User::factory()->role(Role::Sales)->create([
            'discount_limit_percent' => '10',
        ]);
        $calculation = $this->createSavedCalculation(
            $catalog,
            [['inventory_id' => $catalog['hamburg']->id, 'position_discount_percent' => '20']],
            $creator,
            ['order_discount_percent' => '0', 'first_position_discount_percent' => '20'],
        );
        $this->actingAs($creator)->post(route('dispo-orders.store', $calculation), [
            'position_ids' => [$calculation->positions()->first()->id],
        ])->assertRedirect();
        $order = DispoOrder::query()->latest('id')->firstOrFail();
        $this->assertTrue($order->requiresSpecialApproval());

        $order = $this->ensureCustomerConfirmationException($order, $creator);
        $submitted = app(DispoOrderApprovalService::class)
            ->submit($order, $creator, $order->lock_version);

        $this->actingAs($creator)->postJson(route('dispo-orders.approve', $submitted), [
            'lock_version' => $submitted->lock_version,
            'customer_confirmation_exception_acknowledged' => true,
        ])->assertForbidden();

        $management = User::factory()->role(Role::Management)->create();
        $this->actingAs($management)->postJson(route('dispo-orders.approve', $submitted), [
            'lock_version' => $submitted->lock_version,
        ])->assertStatus(422);

        $this->actingAs($management)->postJson(route('dispo-orders.approve', $submitted), [
            'lock_version' => $submitted->lock_version,
            'customer_confirmation_exception_acknowledged' => true,
        ])->assertOk();
    }

    public function test_revision_draft_does_not_inherit_exception(): void
    {
        ['order' => $order, 'creator' => $creator] = $this->draftWithoutConfirmation();
        $approver = User::factory()->role(Role::Sales)->create();
        $order = $this->ensureCustomerConfirmationException(
            $order,
            $creator,
            'Alter Ausnahmegrund der Ablehnung',
        );
        $submitted = app(DispoOrderApprovalService::class)
            ->submit($order, $creator, $order->lock_version);
        app(DispoOrderApprovalService::class)->reject(
            $submitted,
            $approver,
            $submitted->lock_version,
            'Bitte nachbessern',
        );
        $rejected = $submitted->fresh();
        $this->assertTrue($rejected->customer_confirmation_without_upload);
        $this->assertSame('Alter Ausnahmegrund der Ablehnung', $rejected->customer_confirmation_exception_reason);

        $calculation = $rejected->calculation()->firstOrFail();
        $positionIds = $calculation->positions()->pluck('id')->all();
        $result = app(DispoOrderWriter::class)->createRevision(
            $rejected,
            $calculation,
            $positionIds,
            $creator,
        );
        $draft = $result->order;
        $this->assertFalse((bool) $draft->customer_confirmation_without_upload);
        $this->assertNull($draft->customer_confirmation_exception_reason);
        $this->assertNull($draft->customer_confirmation_exception_set_by_id);

        $rejected->refresh();
        $this->assertTrue($rejected->customer_confirmation_without_upload);
        $this->assertSame('Alter Ausnahmegrund der Ablehnung', $rejected->customer_confirmation_exception_reason);
    }

    public function test_stale_lock_on_exception_update_returns_409(): void
    {
        ['order' => $order, 'creator' => $creator] = $this->draftWithoutConfirmation();
        $other = User::factory()->role(Role::Sales)->create();

        $order = $this->ensureCustomerConfirmationException($order, $other);

        $this->actingAs($creator)->putJson(route('dispo-orders.customer-confirmation.update', $order), [
            'lock_version' => 1,
            'confirmation_without_upload' => true,
            'exception_reason' => 'Veralteter Versuch',
        ])->assertStatus(409);
    }

    public function test_show_exposes_confirmation_props(): void
    {
        ['order' => $order, 'creator' => $creator] = $this->draftWithoutConfirmation();
        $order = $this->ensureCustomerConfirmationException($order, $creator);

        $this->actingAs($creator)
            ->get(route('dispo-orders.show', $order))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('canUpdateCustomerConfirmation', true)
                ->where('order.customer_confirmation_without_upload', true)
                ->where(
                    'order.customer_confirmation_exception_reason',
                    'Kundenfreigabe liegt per E-Mail vor; Upload wird nachgereicht.',
                ));
    }

    public function test_historical_orders_without_fields_remain_valid_after_migration_defaults(): void
    {
        ['order' => $order] = $this->draftWithoutConfirmation();
        $this->assertFalse((bool) $order->customer_confirmation_without_upload);
        $this->assertNull($order->customer_confirmation_exception_reason);
    }
}
