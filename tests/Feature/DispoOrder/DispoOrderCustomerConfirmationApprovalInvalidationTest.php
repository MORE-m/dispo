<?php

namespace Tests\Feature\DispoOrder;

use App\Enums\DispoOrderApprovalStatus;
use App\Enums\DispoOrderStatus;
use App\Enums\DispoOrderUploadCategory;
use App\Enums\Role;
use App\Models\AuditEvent;
use App\Models\DispoOrder;
use App\Models\DispoOrderApprovalRequest;
use App\Models\DispoOrderUpload;
use App\Models\User;
use App\Services\DispoOrder\DispoOrderApprovalInvalidationService;
use App\Services\DispoOrder\DispoOrderApprovalService;
use App\Services\DispoOrder\DispoOrderOperationalStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesSavedCalculation;
use Tests\Concerns\CreatesSpotClassicCatalog;
use Tests\TestCase;

/**
 * BL-P7-02a / PO-AT13-CC-1: CC-Archiv bei at_disposition → draft + erneuter Zyklus.
 */
class DispoOrderCustomerConfirmationApprovalInvalidationTest extends TestCase
{
    use CreatesSavedCalculation;
    use CreatesSpotClassicCatalog;
    use RefreshDatabase;

    /**
     * @return array{
     *     order: DispoOrder,
     *     creator: User,
     *     admin: User,
     *     disposition: User,
     *     approval: DispoOrderApprovalRequest,
     *     upload: DispoOrderUpload
     * }
     */
    private function approvedAtDispositionWithActiveCc(): array
    {
        Storage::fake((string) config('dispo.files_disk'));

        $catalog = $this->createSpotClassicCatalog();
        $creator = User::factory()->role(Role::Sales)->create();
        $approver = User::factory()->role(Role::Sales)->create();
        $admin = User::factory()->role(Role::Admin)->create();
        $disposition = User::factory()->role(Role::Disposition)->create();

        $calculation = $this->createSavedCalculation($catalog, [
            ['inventory_id' => $catalog['hamburg']->id],
        ], $creator);

        $this->actingAs($creator)->post(route('dispo-orders.store', $calculation), [
            'position_ids' => [$calculation->positions()->first()->id],
        ])->assertRedirect();

        $order = DispoOrder::query()->latest('id')->firstOrFail();

        $this->actingAs($creator)
            ->withHeader('Accept', 'application/json')
            ->post(route('dispo-orders.uploads.customer-confirmation', $order), [
                'lock_version' => $order->lock_version,
                'file' => $this->sampleUpload(),
            ])
            ->assertOk();

        $order->refresh();
        $upload = DispoOrderUpload::query()
            ->where('dispo_order_id', $order->id)
            ->where('category', DispoOrderUploadCategory::CustomerConfirmation->value)
            ->whereNull('archived_at')
            ->firstOrFail();

        $approvals = app(DispoOrderApprovalService::class);
        $submitted = $approvals->submit($order, $creator, $order->lock_version);
        $approved = $approvals->approve($submitted, $approver, $submitted->lock_version, null, true);

        $approval = $approved->latestApprovalRequest;
        $this->assertNotNull($approval);
        $this->assertSame(DispoOrderApprovalStatus::Approved, $approval->status);
        $this->assertSame(DispoOrderStatus::AtDisposition, $approved->status);

        return [
            'order' => $approved->fresh(['approvalRequests', 'uploads']),
            'creator' => $creator,
            'admin' => $admin,
            'disposition' => $disposition,
            'approval' => $approval,
            'upload' => $upload->fresh(),
        ];
    }

    private function sampleUpload(string $name = 'bestaetigung.pdf'): UploadedFile
    {
        $path = base_path('tests/fixtures/customer-confirmation-sample.pdf');

        return new UploadedFile($path, $name, 'application/pdf', null, true);
    }

    public function test_archive_active_cc_at_disposition_invalidates_to_draft_with_history_and_audit(): void
    {
        $ctx = $this->approvedAtDispositionWithActiveCc();
        $order = $ctx['order'];
        $admin = $ctx['admin'];
        $approval = $ctx['approval'];
        $upload = $ctx['upload'];
        $lockBefore = $order->lock_version;
        $approvalCountBefore = $order->approvalRequests()->count();

        $this->actingAs($admin)
            ->withHeader('Accept', 'application/json')
            ->postJson(route('dispo-orders.uploads.archive', [$order, $upload]), [
                'lock_version' => $order->lock_version,
            ])
            ->assertOk();

        $order->refresh();
        $upload->refresh();
        $approval->refresh();

        $this->assertSame(DispoOrderStatus::Draft, $order->status);
        $this->assertSame($lockBefore + 1, $order->lock_version);
        $this->assertNotNull($upload->archived_at);
        $this->assertSame(DispoOrderApprovalStatus::Approved, $approval->status);
        $this->assertSame($approvalCountBefore, $order->approvalRequests()->count());
        $this->assertSame(0, DispoOrderApprovalRequest::query()
            ->where('dispo_order_id', $order->id)
            ->where('status', DispoOrderApprovalStatus::Pending->value)
            ->count());

        $this->assertDatabaseHas('dispo_order_status_events', [
            'dispo_order_id' => $order->id,
            'from_status' => DispoOrderStatus::AtDisposition->value,
            'to_status' => DispoOrderStatus::Draft->value,
            'reason' => DispoOrderApprovalInvalidationService::STATUS_REASON,
        ]);

        $this->assertDatabaseHas('audit_events', [
            'action' => DispoOrderApprovalInvalidationService::AUDIT_ACTION,
            'auditable_type' => DispoOrder::class,
            'auditable_id' => $order->id,
        ]);

        $audit = AuditEvent::query()
            ->where('action', DispoOrderApprovalInvalidationService::AUDIT_ACTION)
            ->where('auditable_id', $order->id)
            ->latest('id')
            ->firstOrFail();
        $payload = $audit->new_values ?? [];
        $this->assertSame($approval->id, $payload['approval_request_id'] ?? null);
        $this->assertSame('customer_confirmation_archived', $payload['cause'] ?? null);
        $this->assertSame($upload->id, $payload['upload_id'] ?? null);
        $this->assertSame(DispoOrderStatus::Draft->value, $payload['status'] ?? null);
    }

    public function test_full_reapproval_cycle_after_invalidation(): void
    {
        $ctx = $this->approvedAtDispositionWithActiveCc();
        $order = $ctx['order'];
        $creator = $ctx['creator'];
        $admin = $ctx['admin'];
        $upload = $ctx['upload'];
        $firstApprovalId = $ctx['approval']->id;

        $this->actingAs($admin)
            ->withHeader('Accept', 'application/json')
            ->postJson(route('dispo-orders.uploads.archive', [$order, $upload]), [
                'lock_version' => $order->lock_version,
            ])
            ->assertOk();

        $order->refresh();
        $this->assertSame(DispoOrderStatus::Draft, $order->status);

        $this->actingAs($creator)
            ->withHeader('Accept', 'application/json')
            ->post(route('dispo-orders.uploads.customer-confirmation', $order), [
                'lock_version' => $order->lock_version,
                'file' => $this->sampleUpload('erneut.pdf'),
            ])
            ->assertOk();

        $order->refresh();
        $approvals = app(DispoOrderApprovalService::class);
        $submitted = $approvals->submit($order, $creator, $order->lock_version);
        $this->assertSame(DispoOrderStatus::AwaitingSalesApproval, $submitted->status);

        $approver = User::factory()->role(Role::Sales)->create();
        $again = $approvals->approve($submitted, $approver, $submitted->lock_version, null, true);
        $this->assertSame(DispoOrderStatus::AtDisposition, $again->status);

        $this->assertSame(2, DispoOrderApprovalRequest::query()
            ->where('dispo_order_id', $order->id)
            ->where('status', DispoOrderApprovalStatus::Approved->value)
            ->count());
        $this->assertDatabaseHas('dispo_order_approval_requests', [
            'id' => $firstApprovalId,
            'status' => DispoOrderApprovalStatus::Approved->value,
        ]);
        $latest = $again->latestApprovalRequest;
        $this->assertNotNull($latest);
        $this->assertNotSame($firstApprovalId, $latest->id);
        $this->assertSame(2, $latest->cycle_number);
    }

    public function test_archive_cc_in_progress_fails_closed(): void
    {
        $ctx = $this->approvedAtDispositionWithActiveCc();
        $order = $ctx['order'];
        $admin = $ctx['admin'];
        $disposition = $ctx['disposition'];
        $upload = $ctx['upload'];
        $approval = $ctx['approval'];

        $ops = app(DispoOrderOperationalStatusService::class);
        $order = $ops->transition(
            $order,
            $disposition,
            $order->lock_version,
            DispoOrderStatus::InProgress,
        );
        $lockBefore = $order->lock_version;

        $this->actingAs($admin)
            ->withHeader('Accept', 'application/json')
            ->postJson(route('dispo-orders.uploads.archive', [$order, $upload]), [
                'lock_version' => $order->lock_version,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('upload');

        $order->refresh();
        $upload->refresh();
        $approval->refresh();
        $this->assertSame(DispoOrderStatus::InProgress, $order->status);
        $this->assertSame($lockBefore, $order->lock_version);
        $this->assertNull($upload->archived_at);
        $this->assertSame(DispoOrderApprovalStatus::Approved, $approval->status);
        $this->assertSame(0, AuditEvent::query()
            ->where('action', DispoOrderApprovalInvalidationService::AUDIT_ACTION)
            ->where('auditable_id', $order->id)
            ->count());
    }

    public function test_archive_without_active_cc_fails_closed_on_material(): void
    {
        $ctx = $this->approvedAtDispositionWithActiveCc();
        $order = $ctx['order'];
        $admin = $ctx['admin'];
        $creator = $ctx['creator'];

        $this->actingAs($creator)
            ->withHeader('Accept', 'application/json')
            ->post(route('dispo-orders.uploads.store', $order), [
                'lock_version' => $order->lock_version,
                'category' => DispoOrderUploadCategory::Briefing->value,
                'file' => $this->sampleUpload('briefing.pdf'),
            ])
            ->assertOk();

        $order->refresh();
        $material = DispoOrderUpload::query()
            ->where('dispo_order_id', $order->id)
            ->where('category', DispoOrderUploadCategory::Briefing->value)
            ->firstOrFail();

        $lockBefore = $order->lock_version;
        $this->actingAs($admin)
            ->withHeader('Accept', 'application/json')
            ->postJson(route('dispo-orders.uploads.archive', [$order, $material]), [
                'lock_version' => $order->lock_version,
            ])
            ->assertOk();

        $order->refresh();
        $this->assertSame(DispoOrderStatus::AtDisposition, $order->status);
        $this->assertSame($lockBefore + 1, $order->lock_version);
        $this->assertSame(0, AuditEvent::query()
            ->where('action', DispoOrderApprovalInvalidationService::AUDIT_ACTION)
            ->where('auditable_id', $order->id)
            ->count());
    }

    public function test_comment_on_approved_order_does_not_invalidate(): void
    {
        $ctx = $this->approvedAtDispositionWithActiveCc();
        $order = $ctx['order'];
        $disposition = $ctx['disposition'];
        $approval = $ctx['approval'];
        $lockBefore = $order->lock_version;

        $this->actingAs($disposition)
            ->withHeader('Accept', 'application/json')
            ->postJson(route('dispo-orders.comments.store', $order), [
                'body' => 'Nur Kommentar, keine Invalidierung',
            ])
            ->assertOk();

        $order->refresh();
        $approval->refresh();
        $this->assertSame(DispoOrderStatus::AtDisposition, $order->status);
        $this->assertSame($lockBefore, $order->lock_version);
        $this->assertSame(DispoOrderApprovalStatus::Approved, $approval->status);
        $this->assertSame(0, AuditEvent::query()
            ->where('action', DispoOrderApprovalInvalidationService::AUDIT_ACTION)
            ->where('auditable_id', $order->id)
            ->count());
    }

    public function test_multiple_active_ccs_use_uploaded_at_then_id_and_require_approval_upload_match(): void
    {
        $ctx = $this->approvedAtDispositionWithActiveCc();
        $order = $ctx['order'];
        $admin = $ctx['admin'];
        $approval = $ctx['approval'];
        $basisUpload = $ctx['upload'];

        $this->assertSame($basisUpload->id, $approval->customer_confirmation_upload_id);

        // Höhere id, aber älteres uploaded_at → aktiv nach Submit/UI bleibt $basisUpload.
        $staleHigherId = DispoOrderUpload::query()->create([
            'dispo_order_id' => $order->id,
            'category' => DispoOrderUploadCategory::CustomerConfirmation,
            'original_filename' => 'spaeter-id-aelter.pdf',
            'storage_path' => 'dispo/'.$order->id.'/uploads/stale-higher-id',
            'mime_type' => 'application/pdf',
            'size_bytes' => 100,
            'sha256' => hash('sha256', 'stale-higher-id'),
            'uploaded_by_user_id' => $admin->id,
            'uploaded_by_name_snapshot' => $admin->name,
            'uploaded_at' => $basisUpload->uploaded_at->copy()->subMinute(),
        ]);

        $this->assertGreaterThan($basisUpload->id, $staleHigherId->id);

        $lockBefore = $order->fresh()->lock_version;

        // Archiv der id-neueren, zeitlich älteren Datei: nicht aktiv → fail-closed.
        $this->actingAs($admin)
            ->withHeader('Accept', 'application/json')
            ->postJson(route('dispo-orders.uploads.archive', [$order, $staleHigherId]), [
                'lock_version' => $lockBefore,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('upload');

        $order->refresh();
        $staleHigherId->refresh();
        $basisUpload->refresh();
        $approval->refresh();
        $this->assertSame(DispoOrderStatus::AtDisposition, $order->status);
        $this->assertSame($lockBefore, $order->lock_version);
        $this->assertNull($staleHigherId->archived_at);
        $this->assertNull($basisUpload->archived_at);
        $this->assertSame(DispoOrderApprovalStatus::Approved, $approval->status);

        // Zeitlich neuere zweite Datei wird aktiv, gehört aber nicht zur Freigabe.
        $staleHigherId->uploaded_at = $basisUpload->uploaded_at->copy()->addMinute();
        $staleHigherId->save();

        $this->actingAs($admin)
            ->withHeader('Accept', 'application/json')
            ->postJson(route('dispo-orders.uploads.archive', [$order, $staleHigherId]), [
                'lock_version' => $order->lock_version,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('upload');

        $order->refresh();
        $staleHigherId->refresh();
        $this->assertSame(DispoOrderStatus::AtDisposition, $order->status);
        $this->assertSame($lockBefore, $order->lock_version);
        $this->assertNull($staleHigherId->archived_at);

        // Freigabegrundlage ist nicht mehr aktiv → ebenfalls fail-closed.
        $this->actingAs($admin)
            ->withHeader('Accept', 'application/json')
            ->postJson(route('dispo-orders.uploads.archive', [$order, $basisUpload]), [
                'lock_version' => $order->lock_version,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('upload');

        $order->refresh();
        $basisUpload->refresh();
        $this->assertSame(DispoOrderStatus::AtDisposition, $order->status);
        $this->assertSame($lockBefore, $order->lock_version);
        $this->assertNull($basisUpload->archived_at);
        $this->assertSame(0, AuditEvent::query()
            ->where('action', DispoOrderApprovalInvalidationService::AUDIT_ACTION)
            ->where('auditable_id', $order->id)
            ->count());

        // Wieder: Freigabegrundlage = aktive Datei → Invalidierung.
        $staleHigherId->uploaded_at = $basisUpload->uploaded_at->copy()->subMinute();
        $staleHigherId->save();

        $this->actingAs($admin)
            ->withHeader('Accept', 'application/json')
            ->postJson(route('dispo-orders.uploads.archive', [$order, $basisUpload]), [
                'lock_version' => $order->lock_version,
            ])
            ->assertOk();

        $order->refresh();
        $basisUpload->refresh();
        $this->assertSame(DispoOrderStatus::Draft, $order->status);
        $this->assertSame($lockBefore + 1, $order->lock_version);
        $this->assertNotNull($basisUpload->archived_at);
        $this->assertDatabaseHas('audit_events', [
            'action' => DispoOrderApprovalInvalidationService::AUDIT_ACTION,
            'auditable_id' => $order->id,
        ]);
    }
}
