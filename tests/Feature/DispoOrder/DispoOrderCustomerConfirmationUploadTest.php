<?php

namespace Tests\Feature\DispoOrder;

use App\Enums\DispoOrderApprovalStatus;
use App\Enums\DispoOrderStatus;
use App\Enums\DispoOrderUploadCategory;
use App\Enums\Role;
use App\Models\DispoOrder;
use App\Models\DispoOrderUpload;
use App\Models\User;
use App\Services\DispoOrder\DispoOrderApprovalService;
use App\Services\DispoOrder\DispoOrderCompletionReadiness;
use App\Services\DispoOrder\DispoOrderUploadService;
use App\Services\DispoOrder\DispoOrderWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreatesSavedCalculation;
use Tests\Concerns\CreatesSpotClassicCatalog;
use Tests\Concerns\EnsuresCustomerConfirmationException;
use Tests\TestCase;

class DispoOrderCustomerConfirmationUploadTest extends TestCase
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

    private function sampleUpload(string $name = 'bestaetigung.pdf'): UploadedFile
    {
        $path = base_path('tests/fixtures/customer-confirmation-sample.pdf');

        return new UploadedFile($path, $name, 'application/pdf', null, true);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function postUpload(User $actor, DispoOrder $order, array $payload)
    {
        return $this->actingAs($actor)
            ->withHeader('Accept', 'application/json')
            ->post(route('dispo-orders.uploads.customer-confirmation', $order), $payload);
    }

    public function test_upload_success_stores_private_file_metadata_and_audit(): void
    {
        Storage::fake((string) config('dispo.files_disk'));
        ['order' => $order, 'creator' => $creator] = $this->draftWithoutConfirmation();
        $before = $order->lock_version;

        $response = $this->postUpload($creator, $order, [
            'lock_version' => $order->lock_version,
            'file' => $this->sampleUpload(),
        ]);

        $response->assertOk();
        $order->refresh();
        $this->assertSame($before + 1, $order->lock_version);

        $upload = DispoOrderUpload::query()->where('dispo_order_id', $order->id)->firstOrFail();
        $this->assertSame(DispoOrderUploadCategory::CustomerConfirmation, $upload->category);
        $this->assertSame('bestaetigung.pdf', $upload->original_filename);
        $this->assertSame(64, strlen($upload->sha256));
        $this->assertTrue($upload->size_bytes > 0);
        $this->assertNull($upload->archived_at);
        Storage::disk((string) config('dispo.files_disk'))->assertExists($upload->storage_path);
        $this->assertStringStartsWith('dispo-orders/'.$order->id.'/uploads/', $upload->storage_path);

        $this->assertDatabaseHas('audit_events', [
            'action' => 'dispo_order.upload.created',
            'auditable_type' => DispoOrder::class,
            'auditable_id' => $order->id,
        ]);
    }

    public function test_upload_rejects_over_50mb_and_missing_file(): void
    {
        Storage::fake((string) config('dispo.files_disk'));
        ['order' => $order, 'creator' => $creator] = $this->draftWithoutConfirmation();

        $this->postUpload($creator, $order,
            ['lock_version' => $order->lock_version],
        )->assertStatus(422)->assertJsonValidationErrors('file');

        $tooBig = UploadedFile::fake()->create(
            'huge.bin',
            (int) ((DispoOrderUploadService::MAX_BYTES + 1) / 1024) + 1,
        );
        $this->postUpload($creator, $order,
            [
                'lock_version' => $order->lock_version,
                'file' => $tooBig,
            ],
        )->assertStatus(422)->assertJsonValidationErrors('file');
    }

    public function test_upload_service_accepts_exact_max_bytes_and_rejects_one_over(): void
    {
        Storage::fake((string) config('dispo.files_disk'));
        ['order' => $order, 'creator' => $creator] = $this->draftWithoutConfirmation();
        $service = app(DispoOrderUploadService::class);

        $exactPath = $this->createTemporarySizedFile(DispoOrderUploadService::MAX_BYTES);
        $overPath = $this->createTemporarySizedFile(DispoOrderUploadService::MAX_BYTES + 1);

        try {
            $upload = $service->uploadCustomerConfirmation(
                $order,
                $creator,
                $order->lock_version,
                new UploadedFile($exactPath, 'exact-50mb.bin', 'application/octet-stream', null, true),
            );

            $this->assertSame(DispoOrderUploadService::MAX_BYTES, $upload->size_bytes);
            $this->assertSame(64, strlen($upload->sha256));
            Storage::disk((string) config('dispo.files_disk'))->assertExists($upload->storage_path);

            $this->expectException(ValidationException::class);
            $service->uploadCustomerConfirmation(
                $order->fresh(),
                $creator,
                $order->fresh()->lock_version,
                new UploadedFile($overPath, 'over-50mb.bin', 'application/octet-stream', null, true),
            );
        } finally {
            @unlink($exactPath);
            @unlink($overPath);
        }
    }

    private function createTemporarySizedFile(int $bytes): string
    {
        $path = tempnam(sys_get_temp_dir(), 'dispo-cc-size-');
        $this->assertNotFalse($path);

        $handle = fopen($path, 'wb');
        $this->assertNotFalse($handle);
        $this->assertTrue(ftruncate($handle, $bytes));
        fclose($handle);
        clearstatcache(true, $path);
        $this->assertSame($bytes, filesize($path));

        return $path;
    }

    public function test_upload_policy_roles_and_draft_only(): void
    {
        Storage::fake((string) config('dispo.files_disk'));
        ['order' => $order, 'creator' => $sales] = $this->draftWithoutConfirmation();

        $this->actingAs(User::factory()->role(Role::Disposition)->create())
            ->withHeader('Accept', 'application/json')->post(route('dispo-orders.uploads.customer-confirmation', $order), [
                'lock_version' => $order->lock_version,
                'file' => $this->sampleUpload(),
            ])->assertForbidden();

        $this->actingAs(User::factory()->role(Role::ProductManagement)->create())
            ->withHeader('Accept', 'application/json')->post(route('dispo-orders.uploads.customer-confirmation', $order), [
                'lock_version' => $order->lock_version,
                'file' => $this->sampleUpload(),
            ])->assertForbidden();

        $this->ensureCustomerConfirmationException($order, $sales);
        $submitted = app(DispoOrderApprovalService::class)
            ->submit($order->fresh(), $sales, $order->fresh()->lock_version);

        $this->actingAs($sales)
            ->withHeader('Accept', 'application/json')
            ->post(
                route('dispo-orders.uploads.customer-confirmation', $submitted),
                [
                    'lock_version' => $submitted->lock_version,
                    'file' => $this->sampleUpload(),
                ],
            )->assertForbidden();
    }

    public function test_submit_with_upload_freezes_snapshot_and_approve_without_exception_ack(): void
    {
        Storage::fake((string) config('dispo.files_disk'));
        ['order' => $order, 'creator' => $creator] = $this->draftWithoutConfirmation();

        // Exception may exist on draft; upload takes precedence.
        $this->actingAs($creator)->putJson(route('dispo-orders.customer-confirmation.update', $order), [
            'lock_version' => $order->lock_version,
            'confirmation_without_upload' => true,
            'exception_reason' => 'Sollte nicht greifen wenn Upload da ist',
        ])->assertOk();
        $order->refresh();

        $this->postUpload($creator, $order,
            [
                'lock_version' => $order->lock_version,
                'file' => $this->sampleUpload('upload-win.pdf'),
            ],
        )->assertOk();
        $order->refresh();

        $this->actingAs($creator)->postJson(route('dispo-orders.submit', $order), [
            'lock_version' => $order->lock_version,
        ])->assertOk();

        $order->refresh();
        $request = $order->pendingApprovalRequest;
        $this->assertNotNull($request);
        $this->assertSame('upload', $request->customer_confirmation_mode);
        $this->assertFalse((bool) $request->customer_confirmation_without_upload);
        $this->assertNotNull($request->customer_confirmation_upload_id);
        $this->assertSame('upload-win.pdf', $request->customer_confirmation_upload_original_filename);
        $this->assertNotEmpty($request->customer_confirmation_upload_sha256);

        $approver = User::factory()->role(Role::Management)->create();
        $this->actingAs($approver)->postJson(route('dispo-orders.approve', $order), [
            'lock_version' => $order->lock_version,
            // no exception ack
        ])->assertOk();

        $order->refresh();
        $this->assertSame(DispoOrderStatus::AtDisposition, $order->status);
        $approved = $order->latestApprovalRequest;
        $this->assertSame(DispoOrderApprovalStatus::Approved, $approved->status);
        $this->assertFalse((bool) $approved->customer_confirmation_exception_acknowledged);
    }

    public function test_submit_blocked_without_upload_or_exception(): void
    {
        ['order' => $order, 'creator' => $creator] = $this->draftWithoutConfirmation();

        $this->actingAs($creator)->postJson(route('dispo-orders.submit', $order), [
            'lock_version' => $order->lock_version,
        ])->assertStatus(422)->assertJsonValidationErrors('customer_confirmation');
    }

    public function test_archive_in_draft_blocks_submit_and_download_remains(): void
    {
        Storage::fake((string) config('dispo.files_disk'));
        ['order' => $order, 'creator' => $creator] = $this->draftWithoutConfirmation();
        $admin = User::factory()->role(Role::Admin)->create();

        $this->postUpload($creator, $order, [
            'lock_version' => $order->lock_version,
            'file' => $this->sampleUpload(),
        ])->assertOk();
        $order->refresh();
        $upload = DispoOrderUpload::query()->where('dispo_order_id', $order->id)->firstOrFail();

        $this->actingAs($creator)->postJson(
            route('dispo-orders.uploads.archive', [$order, $upload]),
            ['lock_version' => $order->lock_version],
        )->assertForbidden();

        $this->actingAs($admin)->postJson(
            route('dispo-orders.uploads.archive', [$order, $upload]),
            ['lock_version' => $order->lock_version],
        )->assertOk();

        $upload->refresh();
        $this->assertNotNull($upload->archived_at);
        Storage::disk((string) config('dispo.files_disk'))->assertExists($upload->storage_path);

        $order->refresh();
        $this->actingAs($creator)->postJson(route('dispo-orders.submit', $order), [
            'lock_version' => $order->lock_version,
        ])->assertStatus(422);

        $this->actingAs($creator)->get(
            route('dispo-orders.uploads.download', [$order, $upload]),
        )->assertOk();

        $this->assertDatabaseHas('audit_events', [
            'action' => 'dispo_order.upload.archived',
        ]);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'dispo_order.upload.downloaded',
        ]);
    }

    public function test_download_forbidden_for_pm_and_wrong_order(): void
    {
        Storage::fake((string) config('dispo.files_disk'));
        $catalog = $this->createSpotClassicCatalog();
        $creator = User::factory()->role(Role::Sales)->create();
        $otherSales = User::factory()->role(Role::Sales)->create();

        $calcA = $this->createSavedCalculation($catalog, [
            ['inventory_id' => $catalog['hamburg']->id],
        ], $creator);
        $this->actingAs($creator)->post(route('dispo-orders.store', $calcA), [
            'position_ids' => [$calcA->positions()->first()->id],
        ])->assertRedirect();
        $order = DispoOrder::query()->latest('id')->firstOrFail();

        $this->postUpload($creator, $order, [
            'lock_version' => $order->lock_version,
            'file' => $this->sampleUpload(),
        ])->assertOk();
        $upload = DispoOrderUpload::query()->where('dispo_order_id', $order->id)->firstOrFail();

        $this->actingAs(User::factory()->role(Role::ProductManagement)->create())
            ->get(route('dispo-orders.uploads.download', [$order, $upload]))
            ->assertForbidden();

        $calcB = $this->createSavedCalculation($catalog, [
            ['inventory_id' => $catalog['hamburg']->id],
        ], $otherSales);
        $this->actingAs($otherSales)->post(route('dispo-orders.store', $calcB), [
            'position_ids' => [$calcB->positions()->first()->id],
        ])->assertRedirect();
        $other = DispoOrder::query()->latest('id')->firstOrFail();

        $this->actingAs($creator)
            ->get(route('dispo-orders.uploads.download', [$other, $upload]))
            ->assertNotFound();
    }

    public function test_stale_lock_returns_409_on_upload(): void
    {
        Storage::fake((string) config('dispo.files_disk'));
        ['order' => $order, 'creator' => $creator] = $this->draftWithoutConfirmation();

        $this->postUpload($creator, $order,
            [
                'lock_version' => $order->lock_version + 5,
                'file' => $this->sampleUpload(),
            ],
        )->assertStatus(409);
    }

    public function test_completion_passes_with_approved_upload_snapshot(): void
    {
        Storage::fake((string) config('dispo.files_disk'));
        ['order' => $order, 'creator' => $creator] = $this->draftWithoutConfirmation();
        $this->postUpload($creator, $order, [
            'lock_version' => $order->lock_version,
            'file' => $this->sampleUpload(),
        ])->assertOk();
        $order->refresh();

        $approvals = app(DispoOrderApprovalService::class);
        $submitted = $approvals->submit($order, $creator, $order->lock_version);
        $approver = User::factory()->role(Role::Management)->create();
        $approved = $approvals->approve($submitted, $approver, $submitted->lock_version);

        $approved->status = DispoOrderStatus::Disposed;
        $approved->save();
        $approved->load('approvalRequests');

        $check = collect(app(DispoOrderCompletionReadiness::class)->evaluate($approved)['checks'])
            ->firstWhere('key', 'customer_confirmation');
        $this->assertTrue($check['passed']);
    }

    public function test_archive_after_approval_keeps_completion_from_snapshot(): void
    {
        Storage::fake((string) config('dispo.files_disk'));
        ['order' => $order, 'creator' => $creator] = $this->draftWithoutConfirmation();
        $admin = User::factory()->role(Role::Admin)->create();

        $this->postUpload($creator, $order, [
            'lock_version' => $order->lock_version,
            'file' => $this->sampleUpload(),
        ])->assertOk();
        $order->refresh();
        $upload = DispoOrderUpload::query()->where('dispo_order_id', $order->id)->firstOrFail();

        $approvals = app(DispoOrderApprovalService::class);
        $submitted = $approvals->submit($order, $creator, $order->lock_version);
        $approver = User::factory()->role(Role::Management)->create();
        $approved = $approvals->approve($submitted, $approver, $submitted->lock_version);

        $this->actingAs($admin)->postJson(
            route('dispo-orders.uploads.archive', [$approved, $upload]),
            ['lock_version' => $approved->lock_version],
        )->assertOk();

        $approved->refresh();
        $approved->status = DispoOrderStatus::Disposed;
        $approved->save();
        $approved->load('approvalRequests');

        $frozen = $approved->latestApprovalRequest;
        $this->assertSame('upload', $frozen->customer_confirmation_mode);
        $this->assertNotEmpty($frozen->customer_confirmation_upload_sha256);

        $check = collect(app(DispoOrderCompletionReadiness::class)->evaluate($approved)['checks'])
            ->firstWhere('key', 'customer_confirmation');
        $this->assertTrue($check['passed']);
    }

    public function test_revision_does_not_inherit_active_upload_evidence(): void
    {
        Storage::fake((string) config('dispo.files_disk'));
        ['order' => $order, 'creator' => $creator] = $this->draftWithoutConfirmation();
        $this->postUpload($creator, $order, [
            'lock_version' => $order->lock_version,
            'file' => $this->sampleUpload(),
        ])->assertOk();
        $order->refresh();

        $approvals = app(DispoOrderApprovalService::class);
        $submitted = $approvals->submit($order, $creator, $order->lock_version);
        $approver = User::factory()->role(Role::Sales)->create();
        $approvals->reject($submitted, $approver, $submitted->lock_version, 'Bitte nachbessern');
        $rejected = $submitted->fresh();

        $calculation = $rejected->calculation()->firstOrFail();
        $positionIds = $calculation->positions()->pluck('id')->all();
        $draft = app(DispoOrderWriter::class)
            ->createRevision($rejected, $calculation, $positionIds, $creator)
            ->order;

        $this->assertSame(DispoOrderStatus::Draft, $draft->status);
        $this->assertSame(0, DispoOrderUpload::query()->where('dispo_order_id', $draft->id)->count());
        $this->assertFalse(app(DispoOrderUploadService::class)->hasActiveCustomerConfirmation($draft));
        $this->assertSame(
            1,
            DispoOrderUpload::query()->where('dispo_order_id', $rejected->id)->count(),
        );
    }

    public function test_newest_active_upload_is_selected_for_submit(): void
    {
        Storage::fake((string) config('dispo.files_disk'));
        ['order' => $order, 'creator' => $creator] = $this->draftWithoutConfirmation();

        $this->postUpload($creator, $order,
            [
                'lock_version' => $order->lock_version,
                'file' => $this->sampleUpload('first.pdf'),
            ],
        )->assertOk();
        $order->refresh();

        $this->postUpload($creator, $order,
            [
                'lock_version' => $order->lock_version,
                'file' => $this->sampleUpload('second.pdf'),
            ],
        )->assertOk();
        $order->refresh();

        $active = app(DispoOrderUploadService::class)->activeCustomerConfirmation($order);
        $this->assertSame('second.pdf', $active?->original_filename);

        $this->actingAs($creator)->postJson(route('dispo-orders.submit', $order), [
            'lock_version' => $order->lock_version,
        ])->assertOk();

        $this->assertSame(
            'second.pdf',
            $order->fresh()->pendingApprovalRequest->customer_confirmation_upload_original_filename,
        );
        $this->assertSame(2, DispoOrderUpload::query()->where('dispo_order_id', $order->id)->count());
    }
}
