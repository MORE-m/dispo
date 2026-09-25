<?php

namespace Tests\Feature\DispoOrder;

use App\Enums\DispoOrderUploadCategory;
use App\Enums\Role;
use App\Models\AuditEvent;
use App\Models\DispoOrder;
use App\Models\DispoOrderUpload;
use App\Models\User;
use App\Support\PrivateFileStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\Concerns\CreatesSavedCalculation;
use Tests\Concerns\CreatesSpotClassicCatalog;
use Tests\TestCase;

class DispoOrderAudioPlaybackTest extends TestCase
{
    use CreatesSavedCalculation;
    use CreatesSpotClassicCatalog;
    use RefreshDatabase;

    /**
     * @return array{order: DispoOrder, creator: User, upload: DispoOrderUpload}
     */
    private function orderWithAudio(): array
    {
        $catalog = $this->createSpotClassicCatalog();
        $creator = User::factory()->role(Role::Sales)->create();
        $calculation = $this->createSavedCalculation($catalog, [
            ['inventory_id' => $catalog['hamburg']->id],
        ], $creator);

        $this->actingAs($creator)->post(route('dispo-orders.store', $calculation), [
            'position_ids' => [$calculation->positions()->first()->id],
        ])->assertRedirect();

        $order = DispoOrder::query()->latest('id')->firstOrFail();
        app(PrivateFileStorage::class)->disk()->deleteDirectory('dispo-orders/'.$order->id);

        $mp3 = new UploadedFile(
            base_path('tests/fixtures/audio-motif-sample.mp3'),
            'motif.mp3',
            'audio/mpeg',
            null,
            true,
        );

        $this->actingAs($creator)
            ->withHeader('Accept', 'application/json')
            ->post(route('dispo-orders.uploads.store', $order), [
                'lock_version' => $order->lock_version,
                'category' => DispoOrderUploadCategory::AudioMotif->value,
                'file' => $mp3,
            ])->assertOk();

        $order->refresh();
        $upload = DispoOrderUpload::query()
            ->where('dispo_order_id', $order->id)
            ->where('category', DispoOrderUploadCategory::AudioMotif->value)
            ->firstOrFail();

        return compact('order', 'creator', 'upload');
    }

    public function test_stream_returns_inline_audio_with_security_headers(): void
    {
        ['order' => $order, 'creator' => $creator, 'upload' => $upload] = $this->orderWithAudio();

        $response = $this->actingAs($creator)
            ->get(route('dispo-orders.uploads.stream', [$order, $upload]));

        $response->assertOk();
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('audio/', (string) $response->headers->get('Content-Type'));
        $disposition = (string) $response->headers->get('Content-Disposition');
        $this->assertStringContainsString('inline', $disposition);
        $this->assertStringNotContainsString('attachment', $disposition);
        $cache = (string) $response->headers->get('Cache-Control');
        $this->assertTrue(
            str_contains($cache, 'private') || str_contains($cache, 'no-store'),
            'Expected private/no-store cache control, got: '.$cache,
        );
    }

    public function test_range_request_returns_206_partial_content(): void
    {
        ['order' => $order, 'creator' => $creator, 'upload' => $upload] = $this->orderWithAudio();

        $response = $this->actingAs($creator)
            ->withHeaders(['Range' => 'bytes=0-10'])
            ->get(route('dispo-orders.uploads.stream', [$order, $upload]));

        $response->assertStatus(206);
        $this->assertNotNull($response->headers->get('Content-Range'));
        $this->assertStringStartsWith('bytes 0-10/', (string) $response->headers->get('Content-Range'));
    }

    public function test_stream_forbidden_for_pm_and_404_for_wrong_order(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $creator = User::factory()->role(Role::Sales)->create();
        $calculation = $this->createSavedCalculation($catalog, [
            ['inventory_id' => $catalog['hamburg']->id],
        ], $creator);

        $this->actingAs($creator)->post(route('dispo-orders.store', $calculation), [
            'position_ids' => [$calculation->positions()->first()->id],
        ])->assertRedirect();

        $orderA = DispoOrder::query()->latest('id')->firstOrFail();
        app(PrivateFileStorage::class)->disk()->deleteDirectory('dispo-orders/'.$orderA->id);

        $mp3 = new UploadedFile(
            base_path('tests/fixtures/audio-motif-sample.mp3'),
            'motif.mp3',
            'audio/mpeg',
            null,
            true,
        );

        $this->actingAs($creator)
            ->withHeader('Accept', 'application/json')
            ->post(route('dispo-orders.uploads.store', $orderA), [
                'lock_version' => $orderA->lock_version,
                'category' => DispoOrderUploadCategory::AudioMotif->value,
                'file' => $mp3,
            ])->assertOk();

        $upload = DispoOrderUpload::query()
            ->where('dispo_order_id', $orderA->id)
            ->firstOrFail();

        $this->actingAs(User::factory()->role(Role::ProductManagement)->create())
            ->get(route('dispo-orders.uploads.stream', [$orderA, $upload]))
            ->assertForbidden();

        $other = User::factory()->role(Role::Sales)->create();
        $calculationB = $this->createSavedCalculation($catalog, [
            ['inventory_id' => $catalog['hamburg']->id],
        ], $other);
        $this->actingAs($other)->post(route('dispo-orders.store', $calculationB), [
            'position_ids' => [$calculationB->positions()->first()->id],
        ])->assertRedirect();
        $orderB = DispoOrder::query()->latest('id')->firstOrFail();

        $this->actingAs($creator)
            ->get(route('dispo-orders.uploads.stream', [$orderB, $upload]))
            ->assertNotFound();
    }

    public function test_stream_only_for_audio_motif_and_no_playback_audit(): void
    {
        ['order' => $order, 'creator' => $creator] = $this->orderWithAudio();

        $briefing = new UploadedFile(
            base_path('tests/fixtures/customer-confirmation-sample.pdf'),
            'briefing.pdf',
            'application/pdf',
            null,
            true,
        );

        $this->actingAs($creator)
            ->withHeader('Accept', 'application/json')
            ->post(route('dispo-orders.uploads.store', $order), [
                'lock_version' => $order->lock_version,
                'category' => DispoOrderUploadCategory::Briefing->value,
                'file' => $briefing,
            ])->assertOk();

        $nonAudio = DispoOrderUpload::query()
            ->where('dispo_order_id', $order->id)
            ->where('category', DispoOrderUploadCategory::Briefing->value)
            ->firstOrFail();

        $this->actingAs($creator)
            ->get(route('dispo-orders.uploads.stream', [$order->fresh(), $nonAudio]))
            ->assertNotFound();

        $audio = DispoOrderUpload::query()
            ->where('dispo_order_id', $order->id)
            ->where('category', DispoOrderUploadCategory::AudioMotif->value)
            ->firstOrFail();

        $auditBefore = AuditEvent::query()
            ->where('auditable_type', DispoOrder::class)
            ->where('auditable_id', $order->id)
            ->count();

        $this->actingAs($creator)
            ->get(route('dispo-orders.uploads.stream', [$order, $audio]))
            ->assertOk();

        $this->assertSame(
            $auditBefore,
            AuditEvent::query()
                ->where('auditable_type', DispoOrder::class)
                ->where('auditable_id', $order->id)
                ->count(),
        );
        $this->assertSame(
            0,
            AuditEvent::query()
                ->where('action', 'like', '%played%')
                ->count(),
        );
    }

    public function test_archived_audio_remains_streamable(): void
    {
        ['order' => $order, 'creator' => $creator, 'upload' => $upload] = $this->orderWithAudio();
        $admin = User::factory()->role(Role::Admin)->create();

        $this->actingAs($admin)->postJson(
            route('dispo-orders.uploads.archive', [$order, $upload]),
            ['lock_version' => $order->lock_version],
        )->assertOk();

        $this->actingAs($creator)
            ->get(route('dispo-orders.uploads.stream', [$order->fresh(), $upload->fresh()]))
            ->assertOk();
    }
}
