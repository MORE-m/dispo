<?php

namespace Tests\Feature\MasterDataTransfer;

use App\Services\MasterDataTransfer\Support\MasterDataApplyReceiptV2;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\MasterDataTransfer\Concerns\OperationalV2Fixtures;
use Tests\TestCase;

/**
 * EPIC-03-F Slice 7: Preisstatus-Apply (--apply-price-status) — Erfolgspfade und Gates.
 */
class MasterDataTransferPriceStatusApplyV2Test extends TestCase
{
    use OperationalV2Fixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootOperationalFixtures('status-apply');
    }

    protected function tearDown(): void
    {
        $this->teardownOperationalFixtures();
        parent::tearDown();
    }

    #[Test]
    public function apply_activates_all_three_families_changes_only_status_and_writes_status_receipt(): void
    {
        $ctx = $this->contentAppliedPackage();
        $hash = $this->dryRunHash($ctx);
        $contentBefore = $this->contentDigestWithoutStatus();
        $usersBefore = DB::table('users')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
        $lockBefore = (int) DB::table('price_lists')->value('lock_version');
        $receiptsBefore = count($this->receiptFiles());

        $result = $this->runStatus('apply-price-status', $ctx, $hash);

        $this->assertSame(0, $result['exit'], $result['output']);
        $this->assertStringContainsString('Preisstatus Apply OK', $result['output']);
        $this->assertStringContainsString('receipt_id=ps-', $result['output']);
        $this->assertSame(
            ['spot:2026r1' => 'active', 'production:2026r1' => 'active', 'oa_tkp:2026r1' => 'active'],
            $this->listStatuses(),
        );
        // Inhalte, Items, Fingerabdrücke und alle anderen Tabellen bleiben unverändert.
        $this->assertSame($contentBefore, $this->contentDigestWithoutStatus());
        $this->assertSame($usersBefore, DB::table('users')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all());
        // Status-Metadaten wie im Admin-Lifecycle gesetzt.
        $this->assertSame($lockBefore + 1, (int) DB::table('price_lists')->value('lock_version'));
        $this->assertNotNull(DB::table('price_lists')->value('published_at'));
        $this->assertNull(DB::table('price_lists')->value('archived_at'));

        // Status-Receipt nach Commit, signiert, an Content-Receipt + Plan-Hash gebunden.
        $files = $this->receiptFiles();
        $this->assertCount($receiptsBefore + 1, $files);
        $statusReceipt = null;
        foreach ($files as $file) {
            $raw = json_decode((string) File::get($file), true, 32, JSON_THROW_ON_ERROR);
            if ($raw['kind'] === 'price_status_apply') {
                $statusReceipt = $raw;
            }
        }
        $this->assertNotNull($statusReceipt);
        $this->assertSame($hash, $statusReceipt['plan_hash']);
        $this->assertSame($ctx['sha'], $statusReceipt['package_sha256']);
        $this->assertSame('post_content_apply', $statusReceipt['backup_proof_ref']['phase']);
        $this->assertSame($this->receiptRef($ctx['receipt'])['receipt_id'], $statusReceipt['content_receipt_ref']['receipt_id']);
        $this->assertSame(['price_status'], $statusReceipt['scope']);

        // Ein Status-Receipt ist KEIN Content-Receipt.
        $this->assertNotSame(0, count($files));
        $this->expectExceptionMessage('Art "content_apply" erwartet');
        app(MasterDataApplyReceiptV2::class)->assertValidContentReceipt(
            (string) array_values(array_filter($files, fn (string $f) => str_contains($f, 'price-status-apply')))[0],
            $ctx['sha'],
            'spn26jz7_dispo_staging',
        );
    }

    #[Test]
    public function apply_handles_archive_keep_draft_and_activate_with_archive_predecessor_atomically(): void
    {
        $this->contentAppliedPackage();
        $this->setListStatus('price_lists', 2026, 1, 'active');

        $files = [
            'data/price_lists.json' => [
                $this->spotList('inv_v2_radio', 2026, 1, 'archived'),
                $this->spotList('inv_v2_radio', 2026, 2, 'active', '13.0000'),
                $this->spotList('inv_v2_radio', 2027, 1, 'archived'),
                $this->spotList('inv_v2_radio', 2028, 1, 'draft'),
            ],
        ];
        $ctx = $this->contentAppliedPackage($files);
        $hash = $this->dryRunHash($ctx);

        $result = $this->runStatus('apply-price-status', $ctx, $hash);
        $this->assertSame(0, $result['exit'], $result['output']);

        $statuses = $this->listStatuses();
        $this->assertSame('archived', $statuses['spot:2026r1']);
        $this->assertSame('active', $statuses['spot:2026r2']);
        $this->assertSame('archived', $statuses['spot:2027r1']);
        $this->assertSame('draft', $statuses['spot:2028r1']);
        // One-Active je Inventar/Jahr
        $this->assertSame(1, (int) DB::table('price_lists')->where('year', 2026)->where('status', 'active')->count());
        $this->assertNotNull(DB::table('price_lists')->where('year', 2026)->where('revision_number', 1)->value('archived_at'));
    }

    #[Test]
    public function repeated_apply_is_idempotent_after_a_fresh_dry_run(): void
    {
        $ctx = $this->contentAppliedPackage();
        $first = $this->runStatus('apply-price-status', $ctx, $this->dryRunHash($ctx));
        $this->assertSame(0, $first['exit'], $first['output']);
        $afterFirst = $this->whitelistDigest();

        // Alter Plan-Hash ist nach dem Apply veraltet → STOP (kein Doppel-Apply).
        $stale = $this->runStatus('apply-price-status', $ctx, $first['plan_hash'] ?? str_repeat('0', 64));
        $this->assertNotSame(0, $stale['exit']);
        $this->assertSame($afterFirst, $this->whitelistDigest());

        // Frischer Dry-Run: alles unchanged → Apply ist ein No-op ohne Statusänderung.
        $secondHash = $this->dryRunHash($ctx);
        $dry = $this->runStatus('dry-run-price-status', $ctx);
        $this->assertStringContainsString('price_status.unchanged=3', $dry['output']);
        $second = $this->runStatus('apply-price-status', $ctx, $secondHash);
        $this->assertSame(0, $second['exit'], $second['output']);
        $this->assertSame($afterFirst, $this->whitelistDigest());
    }

    #[Test]
    public function apply_requires_plan_hash_and_rejects_wrong_or_drifted_hash(): void
    {
        $ctx = $this->contentAppliedPackage();
        $digest = $this->whitelistDigest();

        $missing = $this->runStatus('apply-price-status', $ctx, null);
        $this->assertNotSame(0, $missing['exit']);
        $this->assertStringContainsString('--require-price-status-plan-hash', $missing['output']);

        $wrong = $this->runStatus('apply-price-status', $ctx, str_repeat('a', 64));
        $this->assertNotSame(0, $wrong['exit']);
        $this->assertStringContainsString('Plan-Drift', $wrong['output']);

        $malformed = $this->runStatus('apply-price-status', $ctx, 'nicht-hex');
        $this->assertNotSame(0, $malformed['exit']);

        // Drift zwischen Dry-Run und Apply (Staging verändert) → STOP
        $hash = $this->dryRunHash($ctx);
        DB::table('oa_tkp_price_lists')->update(['lock_version' => 9]);
        $drifted = $this->runStatus('apply-price-status', $ctx, $hash);
        $this->assertNotSame(0, $drifted['exit']);
        $this->assertStringContainsString('Plan-Drift', $drifted['output']);

        $this->assertSame(['spot:2026r1' => 'draft', 'production:2026r1' => 'draft', 'oa_tkp:2026r1' => 'draft'], $this->listStatuses());
        $this->assertNotSame($digest, $this->whitelistDigest(), 'nur der absichtliche lock_version-Eingriff darf den Digest ändern');
    }

    #[Test]
    public function apply_with_blocked_plan_is_refused_entirely(): void
    {
        $base = $this->basePayload();
        $rules = $base['data/inventory_medium_rules.json'];
        $rules[] = array_merge($rules[1], ['inventory_code' => 'inv_v2_other', 'booking_code' => 'BK3', 'sort' => 3]);
        $ctx = $this->contentAppliedPackage(['data/inventory_medium_rules.json' => $rules]);

        // Hash eines Plans mit blockierter OA-Liste (Dry-Run STOP liefert dennoch Hash)
        $dry = $this->runStatus('dry-run-price-status', $ctx);
        $this->assertNotSame(0, $dry['exit']);
        $this->assertNotNull($dry['plan_hash']);

        $apply = $this->runStatus('apply-price-status', $ctx, $dry['plan_hash']);
        $this->assertNotSame(0, $apply['exit']);
        $this->assertStringContainsString('Plan enthält blockierte Listen', $apply['output']);
        $this->assertSame(['spot:2026r1' => 'draft', 'production:2026r1' => 'draft', 'oa_tkp:2026r1' => 'draft'], $this->listStatuses());
        $this->assertCount(1, $this->receiptFiles(), 'nur das Content-Receipt, kein Status-Receipt');
    }
}
