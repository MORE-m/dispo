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
use App\Services\DispoOrder\DispoOrderApprovalService;
use App\Services\DispoOrder\DispoOrderOperationalStatusService;
use App\Services\DispoOrder\DispoOrderUploadService;
use App\Support\PrivateFileStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesSavedCalculation;
use Tests\Concerns\CreatesSpotClassicCatalog;
use Tests\Concerns\EnsuresCustomerConfirmationException;
use Tests\TestCase;

class DispoOrderMaterialUploadTest extends TestCase
{
    use CreatesSavedCalculation;
    use CreatesSpotClassicCatalog;
    use EnsuresCustomerConfirmationException;
    use RefreshDatabase;

    /**
     * @return array{order: DispoOrder, creator: User}
     */
    private function draftOrder(?User $creator = null): array
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

    /**
     * @return array{order: DispoOrder, creator: User, disposition: User, approval: DispoOrderApprovalRequest}
     */
    private function approvedOrder(): array
    {
        ['order' => $order, 'creator' => $creator] = $this->draftOrder();
        $approver = User::factory()->role(Role::Sales)->create();
        $disposition = User::factory()->role(Role::Disposition)->create();

        $service = app(DispoOrderApprovalService::class);
        $order = $this->seedCustomerConfirmationException($order, $creator);
        $submitted = $service->submit($order, $creator, $order->lock_version);
        $approved = $service->approve($submitted, $approver, $submitted->lock_version, null, true);

        $approval = $approved->latestApprovalRequest;
        $this->assertNotNull($approval);

        return [
            'order' => $approved,
            'creator' => $creator,
            'disposition' => $disposition,
            'approval' => $approval,
        ];
    }

    private function briefingFile(string $name = 'briefing.pdf'): UploadedFile
    {
        $path = base_path('tests/fixtures/customer-confirmation-sample.pdf');

        return new UploadedFile($path, $name, 'application/pdf', null, true);
    }

    private function mp3File(string $name = 'motif.mp3'): UploadedFile
    {
        $path = base_path('tests/fixtures/audio-motif-sample.mp3');

        return new UploadedFile($path, $name, 'audio/mpeg', null, true);
    }

    private function wavFile(string $name = 'motif.wav'): UploadedFile
    {
        $path = base_path('tests/fixtures/audio-motif-sample.wav');

        return new UploadedFile($path, $name, 'audio/x-wav', null, true);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function postMaterial(User $actor, DispoOrder $order, array $payload)
    {
        return $this->actingAs($actor)
            ->withHeader('Accept', 'application/json')
            ->post(route('dispo-orders.uploads.store', $order), $payload);
    }

    public function test_each_material_category_uploads_successfully(): void
    {
        Storage::fake((string) config('dispo.files_disk'));
        ['order' => $order, 'creator' => $creator] = $this->draftOrder();

        foreach (DispoOrderUploadCategory::materialCategories() as $category) {
            $order->refresh();
            $file = $category->isAudioMotif()
                ? $this->mp3File($category->value.'.mp3')
                : $this->briefingFile($category->value.'.pdf');

            $this->postMaterial($creator, $order, [
                'lock_version' => $order->lock_version,
                'category' => $category->value,
                'file' => $file,
            ])->assertOk();
        }

        $this->assertSame(
            count(DispoOrderUploadCategory::materialCategories()),
            DispoOrderUpload::query()->where('dispo_order_id', $order->id)->count(),
        );
    }

    public function test_customer_confirmation_via_generic_endpoint_is_rejected(): void
    {
        Storage::fake((string) config('dispo.files_disk'));
        ['order' => $order, 'creator' => $creator] = $this->draftOrder();

        $this->postMaterial($creator, $order, [
            'lock_version' => $order->lock_version,
            'category' => DispoOrderUploadCategory::CustomerConfirmation->value,
            'file' => $this->briefingFile(),
        ])->assertStatus(422)->assertJsonValidationErrors('category');

        $this->assertSame(0, DispoOrderUpload::query()->where('dispo_order_id', $order->id)->count());
    }

    public function test_role_matrix_for_material_upload(): void
    {
        Storage::fake((string) config('dispo.files_disk'));
        ['order' => $order] = $this->approvedOrder();

        $allowed = [
            User::factory()->role(Role::Sales)->create(),
            User::factory()->role(Role::Disposition)->create(),
            User::factory()->role(Role::Admin)->create(),
            User::factory()->role(Role::Management)->create(),
        ];

        foreach ($allowed as $actor) {
            $order->refresh();
            $this->postMaterial($actor, $order, [
                'lock_version' => $order->lock_version,
                'category' => DispoOrderUploadCategory::Briefing->value,
                'file' => $this->briefingFile($actor->role->value.'.pdf'),
            ])->assertOk();
        }

        $this->actingAs(User::factory()->role(Role::ProductManagement)->create())
            ->withHeader('Accept', 'application/json')
            ->post(route('dispo-orders.uploads.store', $order->fresh()), [
                'lock_version' => $order->fresh()->lock_version,
                'category' => DispoOrderUploadCategory::Briefing->value,
                'file' => $this->briefingFile('pm.pdf'),
            ])->assertForbidden();
    }

    public function test_status_matrix_allows_and_blocks_material_upload(): void
    {
        Storage::fake((string) config('dispo.files_disk'));
        ['order' => $order, 'creator' => $creator, 'disposition' => $disposition] = $this->approvedOrder();
        $ops = app(DispoOrderOperationalStatusService::class);

        $allowed = [
            DispoOrderStatus::AtDisposition,
            DispoOrderStatus::InProgress,
            DispoOrderStatus::MaterialMissing,
            DispoOrderStatus::MaterialReceived,
        ];

        foreach ($allowed as $status) {
            if ($status === DispoOrderStatus::AtDisposition) {
                $current = $order->fresh();
            } elseif ($status === DispoOrderStatus::InProgress) {
                $current = $ops->transition($order->fresh(), $disposition, $order->fresh()->lock_version, DispoOrderStatus::InProgress);
            } elseif ($status === DispoOrderStatus::MaterialMissing) {
                $current = $ops->transition($order->fresh(), $disposition, $order->fresh()->lock_version, DispoOrderStatus::MaterialMissing);
            } else {
                $current = $ops->transition($order->fresh(), $disposition, $order->fresh()->lock_version, DispoOrderStatus::MaterialReceived);
            }

            $this->postMaterial($creator, $current, [
                'lock_version' => $current->lock_version,
                'category' => DispoOrderUploadCategory::Other->value,
                'file' => $this->briefingFile($status->value.'.pdf'),
            ])->assertOk();
        }

        $blocked = [
            DispoOrderStatus::AwaitingSalesApproval,
            DispoOrderStatus::ApprovalRejected,
            DispoOrderStatus::Disposed,
            DispoOrderStatus::Completed,
            DispoOrderStatus::Cancelled,
        ];

        foreach ($blocked as $status) {
            $order->forceFill(['status' => $status])->save();
            $fresh = $order->fresh();
            $this->postMaterial($creator, $fresh, [
                'lock_version' => $fresh->lock_version,
                'category' => DispoOrderUploadCategory::Other->value,
                'file' => $this->briefingFile('blocked-'.$status->value.'.pdf'),
            ])->assertStatus(422)->assertJsonValidationErrors('order');
        }
    }

    public function test_sales_inquiry_allows_material_upload(): void
    {
        Storage::fake((string) config('dispo.files_disk'));
        ['order' => $order, 'creator' => $creator] = $this->approvedOrder();
        $order->forceFill(['status' => DispoOrderStatus::SalesInquiry])->save();

        $this->postMaterial($creator, $order->fresh(), [
            'lock_version' => $order->fresh()->lock_version,
            'category' => DispoOrderUploadCategory::Briefing->value,
            'file' => $this->briefingFile('inquiry.pdf'),
        ])->assertOk();

        $this->assertSame(DispoOrderStatus::SalesInquiry, $order->fresh()->status);
    }

    public function test_rejects_over_50mb_and_dangerous_mime(): void
    {
        Storage::fake((string) config('dispo.files_disk'));
        ['order' => $order, 'creator' => $creator] = $this->draftOrder();

        $tooBig = UploadedFile::fake()->create(
            'big.bin',
            (int) (DispoOrderUploadService::MAX_BYTES / 1024) + 1,
        );

        $this->postMaterial($creator, $order, [
            'lock_version' => $order->lock_version,
            'category' => DispoOrderUploadCategory::Other->value,
            'file' => $tooBig,
        ])->assertStatus(422)->assertJsonValidationErrors('file');

        $exe = UploadedFile::fake()->create('evil.exe', 10, 'application/x-msdownload');
        $this->postMaterial($creator, $order->fresh(), [
            'lock_version' => $order->fresh()->lock_version,
            'category' => DispoOrderUploadCategory::Other->value,
            'file' => $exe,
        ])->assertStatus(422)->assertJsonValidationErrors('file');
    }

    public function test_audio_accepts_mp3_and_wav_rejects_spoofed_mp3(): void
    {
        Storage::fake((string) config('dispo.files_disk'));
        ['order' => $order, 'creator' => $creator] = $this->draftOrder();

        $this->postMaterial($creator, $order, [
            'lock_version' => $order->lock_version,
            'category' => DispoOrderUploadCategory::AudioMotif->value,
            'file' => $this->mp3File('a.mp3'),
        ])->assertOk();

        $order->refresh();
        $this->postMaterial($creator, $order, [
            'lock_version' => $order->lock_version,
            'category' => DispoOrderUploadCategory::AudioMotif->value,
            'file' => $this->wavFile('b.wav'),
        ])->assertOk();

        $this->assertSame(
            2,
            DispoOrderUpload::query()
                ->where('dispo_order_id', $order->id)
                ->where('category', DispoOrderUploadCategory::AudioMotif->value)
                ->count(),
        );

        $fakePath = tempnam(sys_get_temp_dir(), 'fake-mp3-');
        $this->assertNotFalse($fakePath);
        file_put_contents($fakePath, "%PDF-1.4\nfake audio\n");
        $fake = new UploadedFile($fakePath, 'spoof.mp3', 'application/pdf', null, true);

        $order->refresh();
        $before = DispoOrderUpload::query()->where('dispo_order_id', $order->id)->count();
        $this->postMaterial($creator, $order, [
            'lock_version' => $order->lock_version,
            'category' => DispoOrderUploadCategory::AudioMotif->value,
            'file' => $fake,
        ])->assertStatus(422)->assertJsonValidationErrors('file');

        $this->assertSame($before, DispoOrderUpload::query()->where('dispo_order_id', $order->id)->count());
        @unlink($fakePath);
    }

    public function test_download_and_archive_without_hard_delete(): void
    {
        Storage::fake((string) config('dispo.files_disk'));
        ['order' => $order, 'creator' => $creator] = $this->draftOrder();
        $admin = User::factory()->role(Role::Admin)->create();

        $this->postMaterial($creator, $order, [
            'lock_version' => $order->lock_version,
            'category' => DispoOrderUploadCategory::Briefing->value,
            'file' => $this->briefingFile(),
        ])->assertOk();

        $order->refresh();
        $upload = DispoOrderUpload::query()->where('dispo_order_id', $order->id)->firstOrFail();

        $this->actingAs($creator)->get(route('dispo-orders.uploads.download', [$order, $upload]))
            ->assertOk();

        $this->actingAs($creator)->postJson(
            route('dispo-orders.uploads.archive', [$order, $upload]),
            ['lock_version' => $order->lock_version],
        )->assertForbidden();

        $this->actingAs($admin)->postJson(
            route('dispo-orders.uploads.archive', [$order, $upload]),
            ['lock_version' => $order->fresh()->lock_version],
        )->assertOk();

        $upload->refresh();
        $this->assertNotNull($upload->archived_at);
        Storage::disk((string) config('dispo.files_disk'))->assertExists($upload->storage_path);

        $this->actingAs($creator)->get(route('dispo-orders.uploads.download', [$order, $upload]))
            ->assertOk();
    }

    public function test_material_upload_does_not_change_status_or_approval(): void
    {
        Storage::fake((string) config('dispo.files_disk'));
        ['order' => $order, 'creator' => $creator, 'disposition' => $disposition, 'approval' => $approval] = $this->approvedOrder();
        $ops = app(DispoOrderOperationalStatusService::class);
        $order = $ops->transition($order, $disposition, $order->lock_version, DispoOrderStatus::InProgress);
        $order = $ops->transition($order, $disposition, $order->lock_version, DispoOrderStatus::MaterialMissing);

        $approvalId = $approval->id;
        $approvalSha = $approval->customer_confirmation_upload_sha256;
        $approvalStatus = $approval->status;
        $statusBefore = $order->status;
        $pendingBefore = DispoOrderApprovalRequest::query()
            ->where('dispo_order_id', $order->id)
            ->where('status', DispoOrderApprovalStatus::Pending->value)
            ->count();

        $this->postMaterial($creator, $order, [
            'lock_version' => $order->lock_version,
            'category' => DispoOrderUploadCategory::AudioMotif->value,
            'file' => $this->mp3File(),
        ])->assertOk();

        $order->refresh();
        $approval->refresh();

        $this->assertSame($statusBefore, $order->status);
        $this->assertSame($approvalId, $approval->id);
        $this->assertSame($approvalStatus, $approval->status);
        $this->assertSame($approvalSha, $approval->customer_confirmation_upload_sha256);
        $this->assertSame(
            $pendingBefore,
            DispoOrderApprovalRequest::query()
                ->where('dispo_order_id', $order->id)
                ->where('status', DispoOrderApprovalStatus::Pending->value)
                ->count(),
        );
    }

    public function test_stale_lock_returns_409(): void
    {
        Storage::fake((string) config('dispo.files_disk'));
        ['order' => $order, 'creator' => $creator] = $this->draftOrder();
        $stale = $order->lock_version;

        $this->postMaterial($creator, $order, [
            'lock_version' => $stale,
            'category' => DispoOrderUploadCategory::Briefing->value,
            'file' => $this->briefingFile('a.pdf'),
        ])->assertOk();

        $this->postMaterial($creator, $order->fresh(), [
            'lock_version' => $stale,
            'category' => DispoOrderUploadCategory::Briefing->value,
            'file' => $this->briefingFile('b.pdf'),
        ])->assertStatus(409);
    }

    public function test_show_props_expose_material_upload_cta_and_categories(): void
    {
        ['order' => $order, 'creator' => $creator] = $this->draftOrder();

        $this->actingAs($creator)
            ->get(route('dispo-orders.show', $order))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('canUploadMaterial', true)
                ->has('materialUploadCategories', count(DispoOrderUploadCategory::materialCategories()))
                ->where('materialUploadCategories.0.value', DispoOrderUploadCategory::AudioMotif->value));

        $order->forceFill(['status' => DispoOrderStatus::Disposed])->save();

        $this->actingAs($creator)
            ->get(route('dispo-orders.show', $order->fresh()))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('canUploadMaterial', false)
                ->where('materialUploadCategories', []));
    }

    public function test_loser_orphan_cleanup_on_stale_lock_conflict(): void
    {
        ['order' => $order, 'creator' => $creator] = $this->draftOrder();
        $disk = app(PrivateFileStorage::class);
        $prefix = 'dispo-orders/'.$order->id.'/uploads';
        $disk->disk()->deleteDirectory('dispo-orders/'.$order->id);

        $this->postMaterial($creator, $order, [
            'lock_version' => $order->lock_version,
            'category' => DispoOrderUploadCategory::Briefing->value,
            'file' => $this->briefingFile('winner.pdf'),
        ])->assertOk();

        $pathsAfterWinner = $disk->disk()->allFiles($prefix);
        $this->assertCount(1, $pathsAfterWinner);

        $this->postMaterial($creator, $order->fresh(), [
            'lock_version' => $order->lock_version,
            'category' => DispoOrderUploadCategory::Briefing->value,
            'file' => $this->briefingFile('loser.pdf'),
        ])->assertStatus(409);

        $pathsAfterLoser = $disk->disk()->allFiles($prefix);
        sort($pathsAfterWinner);
        sort($pathsAfterLoser);
        $this->assertSame($pathsAfterWinner, $pathsAfterLoser);
        $this->assertSame(
            1,
            AuditEvent::query()
                ->where('auditable_type', DispoOrder::class)
                ->where('auditable_id', $order->id)
                ->where('action', 'dispo_order.upload.created')
                ->count(),
        );
    }
}
