<?php

namespace Tests\Feature\MasterDataTransfer;

use App\Models\PriceList;
use App\Services\MasterDataTransfer\MasterDataTransferContractV2;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\MasterDataTransfer\Concerns\OperationalV2Fixtures;
use Tests\TestCase;

/**
 * EPIC-03-F Slice 7: Preisstatus-Dry-Run (--dry-run-price-status) — schreibfrei, Plan-Hash, Gates.
 */
class MasterDataTransferPriceStatusDryRunV2Test extends TestCase
{
    use OperationalV2Fixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootOperationalFixtures('status-dry');
    }

    protected function tearDown(): void
    {
        $this->teardownOperationalFixtures();
        parent::tearDown();
    }

    #[Test]
    public function dry_run_plans_all_three_families_without_any_write_and_without_receipt(): void
    {
        $ctx = $this->contentAppliedPackage();
        $digestBefore = $this->whitelistDigest();
        $receiptsBefore = $this->receiptFiles();
        $statusesBefore = $this->listStatuses();

        $writes = [];
        DB::listen(function ($query) use (&$writes): void {
            $sql = strtolower(trim((string) $query->sql));
            if (preg_match('/^(insert|update|delete|replace|alter|drop|create|truncate)\b/', $sql) === 1) {
                $writes[] = $sql;
            }
        });

        $result = $this->runStatus('dry-run-price-status', $ctx);

        $this->assertSame(0, $result['exit'], $result['output']);
        $this->assertStringContainsString('Preisstatus Dry-Run OK', $result['output']);
        $this->assertStringContainsString('price_status.activate=3', $result['output']);
        $this->assertNotNull($result['plan_hash']);
        $this->assertSame([], $writes, 'Dry-Run darf keine Schreibzugriffe auslösen');
        $this->assertSame($digestBefore, $this->whitelistDigest());
        $this->assertSame($statusesBefore, $this->listStatuses());
        $this->assertSame($receiptsBefore, $this->receiptFiles());
        $this->assertSame(['spot:2026r1' => 'draft', 'production:2026r1' => 'draft', 'oa_tkp:2026r1' => 'draft'], $this->listStatuses());
    }

    #[Test]
    public function plan_hash_is_deterministic_and_changes_with_staging_state(): void
    {
        $ctx = $this->contentAppliedPackage();

        $first = $this->dryRunHash($ctx);
        $second = $this->dryRunHash($ctx);
        $this->assertSame($first, $second);

        // anderer Staging-Zustand → anderer Hash (lock_version/Status)
        DB::table('production_price_lists')->update(['lock_version' => 5]);
        $third = $this->dryRunHash($ctx);
        $this->assertNotSame($first, $third);
    }

    #[Test]
    public function plan_reports_every_action_kind(): void
    {
        $files = [
            'data/price_lists.json' => [
                $this->spotList('inv_v2_radio', 2026, 1, 'active'),   // activate
                $this->spotList('inv_v2_radio', 2027, 1, 'archived'), // archive
                $this->spotList('inv_v2_radio', 2028, 1, 'draft'),    // keep_draft
                $this->spotList('inv_v2_other', 2026, 1, 'active'),   // unchanged (Staging bereits active)
            ],
        ];
        $ctx = $this->contentAppliedPackage($files);
        $this->setListStatus('price_lists', 2026, 1, 'draft');
        // inv_v2_other 2026 auf active setzen → unchanged
        DB::table('price_lists')
            ->where('year', 2026)
            ->where('inventory_id', DB::table('inventories')->where('code', 'inv_v2_other')->value('id'))
            ->update(['status' => 'active']);

        $result = $this->runStatus('dry-run-price-status', $ctx);
        $this->assertSame(0, $result['exit'], $result['output']);
        $this->assertStringContainsString('[price_status.spot] inv_v2_radio|2026|r1: activate', $result['output']);
        $this->assertStringContainsString('[price_status.spot] inv_v2_radio|2027|r1: archive', $result['output']);
        $this->assertStringContainsString('[price_status.spot] inv_v2_radio|2028|r1: keep_draft', $result['output']);
        $this->assertStringContainsString('[price_status.spot] inv_v2_other|2026|r1: unchanged', $result['output']);
    }

    #[Test]
    public function blocked_cases_are_reported_and_fail_the_dry_run(): void
    {
        $files = [
            'data/price_lists.json' => [
                $this->spotList('inv_v2_radio', 2026, 1, 'archived'), // Staging active → blocked (nur via Nachfolger)
                $this->spotList('inv_v2_radio', 2027, 1, 'active'),   // Staging archived → blocked (Reaktivierung)
                $this->spotList('inv_v2_radio', 2028, 1, 'draft'),    // Staging active → blocked (active→draft)
                $this->spotList('inv_v2_radio', 2029, 1, 'active'),   // Fingerabdruck verändert → blocked
            ],
        ];
        $ctx = $this->contentAppliedPackage($files);
        $this->setListStatus('price_lists', 2026, 1, 'active');
        $this->setListStatus('price_lists', 2027, 1, 'archived');
        $this->setListStatus('price_lists', 2028, 1, 'active');
        DB::table('price_list_items')
            ->whereIn('price_list_id', PriceList::query()->where('year', 2029)->pluck('id'))
            ->update(['second_price' => '99.0000']);

        $result = $this->runStatus('dry-run-price-status', $ctx);
        $this->assertNotSame(0, $result['exit']);
        $this->assertStringContainsString('security.result=STOP', $result['output']);
        $this->assertStringContainsString('[price_status.spot] inv_v2_radio|2026|r1: blocked', $result['output']);
        $this->assertStringContainsString('[price_status.spot] inv_v2_radio|2027|r1: blocked', $result['output']);
        $this->assertStringContainsString('[price_status.spot] inv_v2_radio|2028|r1: blocked', $result['output']);
        $this->assertStringContainsString('[price_status.spot] inv_v2_radio|2029|r1: blocked', $result['output']);
        $this->assertNotNull($result['plan_hash']);
        $this->assertSame(
            ['spot:2026r1' => 'active', 'spot:2027r1' => 'archived', 'spot:2028r1' => 'active', 'spot:2029r1' => 'draft'],
            array_filter($this->listStatuses(), fn (string $k): bool => str_starts_with($k, 'spot:'), ARRAY_FILTER_USE_KEY),
        );
    }

    #[Test]
    public function missing_staging_list_and_duplicate_source_active_are_blocked(): void
    {
        $files = [
            'data/price_lists.json' => [
                $this->spotList('inv_v2_radio', 2026, 1, 'active'),
                $this->spotList('inv_v2_radio', 2026, 2, 'active'), // zwei aktive Quellen im selben Slot
                $this->spotList('inv_v2_radio', 2027, 1, 'active'),
            ],
        ];
        $ctx = $this->contentAppliedPackage($files);
        DB::table('price_list_items')->whereIn('price_list_id', PriceList::query()->where('year', 2027)->pluck('id'))->delete();
        PriceList::query()->where('year', 2027)->delete();

        $result = $this->runStatus('dry-run-price-status', $ctx);
        $this->assertNotSame(0, $result['exit']);
        $this->assertStringContainsString('inv_v2_radio|2026|r1: blocked', $result['output']);
        $this->assertStringContainsString('inv_v2_radio|2026|r2: blocked', $result['output']);
        $this->assertStringContainsString('inv_v2_radio|2027|r1: blocked', $result['output']);
        $this->assertStringContainsString('Mehrere Quelllisten mit source_status=active', $result['output']);
        $this->assertStringContainsString('Staging-Liste fehlt', $result['output']);
    }

    #[Test]
    public function incomplete_oa_list_is_blocked_fail_closed(): void
    {
        // Zusätzlich aktive OA-Regel (inv_v2_other × pre_stream), die die OA-Liste NICHT abdeckt.
        $base = $this->basePayload();
        $rules = $base['data/inventory_medium_rules.json'];
        $rules[] = array_merge($rules[1], ['inventory_code' => 'inv_v2_other', 'booking_code' => 'BK3', 'sort' => 3]);
        $ctx = $this->contentAppliedPackage(['data/inventory_medium_rules.json' => $rules]);

        $result = $this->runStatus('dry-run-price-status', $ctx);
        $this->assertNotSame(0, $result['exit']);
        $this->assertStringContainsString('[price_status.oa_tkp] 2026|r1: blocked', $result['output']);
        $this->assertStringContainsString('Unvollständige Liste', $result['output']);
        $this->assertStringContainsString('fehlendes Paar', $result['output']);
        // Spot und Produktion bleiben aktivierbar, die Gesamtfreigabe ist aber STOP.
        $this->assertStringContainsString('[price_status.spot] inv_v2_radio|2026|r1: activate', $result['output']);
        $this->assertSame('draft', $this->listStatuses()['oa_tkp:2026r1']);
    }

    #[Test]
    public function incomplete_spot_and_production_lists_are_blocked(): void
    {
        $files = [
            'data/price_lists.json' => [array_merge($this->spotList('inv_v2_radio', 2026, 1, 'active'), ['items' => []])],
        ];
        $ctx = $this->contentAppliedPackage($files);

        $result = $this->runStatus('dry-run-price-status', $ctx);
        $this->assertNotSame(0, $result['exit']);
        $this->assertStringContainsString('[price_status.spot] inv_v2_radio|2026|r1: blocked', $result['output']);
        $this->assertStringContainsString('mindestens ein gültiger Basispreis', $result['output']);
    }

    #[Test]
    public function pinned_list_is_blocked(): void
    {
        $ctx = $this->contentAppliedPackage();
        $this->pinSpotList(PriceList::query()->firstOrFail());

        $result = $this->runStatus('dry-run-price-status', $ctx);
        $this->assertNotSame(0, $result['exit']);
        $this->assertStringContainsString('[price_status.spot] inv_v2_radio|2026|r1: blocked', $result['output']);
        $this->assertStringContainsString('gepinnt', $result['output']);
    }

    #[Test]
    public function activate_with_archive_predecessor_is_planned_for_same_slot_and_covers_predecessor_row(): void
    {
        $first = $this->contentAppliedPackage();
        $this->setListStatus('price_lists', 2026, 1, 'active');
        unset($first);

        $files = [
            'data/price_lists.json' => [
                $this->spotList('inv_v2_radio', 2026, 1, 'archived'),
                $this->spotList('inv_v2_radio', 2026, 2, 'active', '13.0000'),
            ],
        ];
        $ctx = $this->contentAppliedPackage($files);
        $this->assertSame('active', $this->listStatuses()['spot:2026r1']);
        $this->assertSame('draft', $this->listStatuses()['spot:2026r2']);

        $result = $this->runStatus('dry-run-price-status', $ctx);
        $this->assertSame(0, $result['exit'], $result['output']);
        $this->assertStringContainsString('inv_v2_radio|2026|r2: activate_with_archive_predecessor', $result['output']);
        $this->assertStringContainsString('inv_v2_radio|2026|r1: unchanged', $result['output']);
        $this->assertStringContainsString('price_status.activate_with_archive_predecessor=1', $result['output']);
    }

    #[Test]
    public function pinned_predecessor_blocks_activate_with_archive_predecessor(): void
    {
        $this->contentAppliedPackage();
        $this->setListStatus('price_lists', 2026, 1, 'active');
        $this->pinSpotList(PriceList::query()->where('revision_number', 1)->firstOrFail());

        $files = [
            'data/price_lists.json' => [
                $this->spotList('inv_v2_radio', 2026, 1, 'archived'),
                $this->spotList('inv_v2_radio', 2026, 2, 'active', '13.0000'),
            ],
        ];
        $ctx = $this->contentAppliedPackage($files);

        $result = $this->runStatus('dry-run-price-status', $ctx);
        $this->assertNotSame(0, $result['exit']);
        $this->assertStringContainsString('Aktiver Vorgänger inv_v2_radio|2026|r1 ist gepinnt', $result['output']);
        $this->assertStringContainsString('inv_v2_radio|2026|r1: blocked', $result['output']);
    }

    #[Test]
    public function gates_reject_wrong_hash_database_receipt_and_backup(): void
    {
        $ctx = $this->contentAppliedPackage();

        // falscher Paket-SHA
        $r = $this->runStatus('dry-run-price-status', $ctx, null, ['--require-package-sha256' => str_repeat('a', 64)]);
        $this->assertNotSame(0, $r['exit']);
        $this->assertStringContainsString('Paket-SHA256 stimmt nicht', $r['output']);

        // falsche DB
        $r = $this->runStatus('dry-run-price-status', $ctx, null, ['--confirm-database' => 'dispo_mat_core']);
        $this->assertNotSame(0, $r['exit']);

        // fehlendes Receipt
        $r = $this->runStatus('dry-run-price-status', $ctx, null, ['--require-content-apply-receipt' => '']);
        $this->assertNotSame(0, $r['exit']);
        $this->assertStringContainsString('--require-content-apply-receipt', $r['output']);

        // Receipt eines anderen Pakets
        $other = $this->writePackage(['data/price_lists.json' => [$this->spotList('inv_v2_radio', 2031, 1, 'active')]]);
        $otherApplied = $this->applyContent($other);
        $this->assertSame(0, $otherApplied['exit'], $otherApplied['output']);
        $r = $this->runStatus('dry-run-price-status', $ctx, null, ['--require-content-apply-receipt' => $otherApplied['receipt_path']]);
        $this->assertNotSame(0, $r['exit']);
        $this->assertStringContainsString('anderen Paket', $r['output']);

        // Backup A statt Backup B
        $r = $this->runStatus('dry-run-price-status', $ctx, null, ['--require-backup-proof' => $this->proofFor($ctx['sha'])]);
        $this->assertNotSame(0, $r['exit']);
        $this->assertStringContainsString('Phase "pre_content_apply" passt nicht', $r['output']);

        // fehlendes Backup
        $r = $this->runStatus('dry-run-price-status', $ctx, null, ['--require-backup-proof' => '']);
        $this->assertNotSame(0, $r['exit']);
        $this->assertStringContainsString('--require-backup-proof', $r['output']);

        // manipuliertes Receipt
        $raw = json_decode((string) File::get($ctx['receipt']), true, 32, JSON_THROW_ON_ERROR);
        $raw['scope'] = ['content', 'everything'];
        $forged = $this->tmp.'/forged.json';
        File::put($forged, json_encode($raw, JSON_THROW_ON_ERROR));
        $r = $this->runStatus('dry-run-price-status', $ctx, null, ['--require-content-apply-receipt' => $forged]);
        $this->assertNotSame(0, $r['exit']);
        $this->assertStringContainsString('Signatur', $r['output']);

        $this->assertSame('draft', $this->listStatuses()['spot:2026r1']);
        $this->assertSame('draft', $this->listStatuses()['production:2026r1']);
        $this->assertSame('draft', $this->listStatuses()['oa_tkp:2026r1']);
    }

    #[Test]
    public function backup_created_before_content_apply_is_rejected(): void
    {
        $package = $this->writePackage();
        $sha = $this->packageSha($package);
        $applied = $this->applyContent($package);
        $this->assertSame(0, $applied['exit'], $applied['output']);

        $early = $this->backupBFor($sha, (string) $applied['receipt_path'], now()->subHours(2)->toIso8601String());
        $ctx = ['package' => $package, 'sha' => $sha, 'receipt' => (string) $applied['receipt_path'], 'proof_b' => $early];

        $r = $this->runStatus('dry-run-price-status', $ctx);
        $this->assertNotSame(0, $r['exit']);
        $this->assertStringContainsString('VOR dem Inhalts-Apply', $r['output']);
    }

    #[Test]
    public function mode_exclusivity_and_option_scoping_are_enforced(): void
    {
        $ctx = $this->contentAppliedPackage();

        $both = Artisan::call('dispo:import-master-data', [
            'path' => $ctx['package'], '--dry-run' => true, '--dry-run-price-status' => true,
            '--confirm-database' => MasterDataTransferContractV2::OPERATIONAL_DATABASE,
            '--require-package-sha256' => $ctx['sha'],
        ]);
        $this->assertNotSame(0, $both);
        $this->assertStringContainsString('Genau eine Option erforderlich', Artisan::output());

        $none = Artisan::call('dispo:import-master-data', ['path' => $ctx['package']]);
        $this->assertNotSame(0, $none);

        $dryWithHash = $this->runStatus('dry-run-price-status', $ctx, str_repeat('a', 64));
        $this->assertNotSame(0, $dryWithHash['exit']);
        $this->assertStringContainsString('gilt nur für --apply-price-status', $dryWithHash['output']);

        $contentWithReceipt = Artisan::call('dispo:import-master-data', [
            'path' => $ctx['package'], '--dry-run' => true,
            '--confirm-database' => MasterDataTransferContractV2::OPERATIONAL_DATABASE,
            '--require-package-sha256' => $ctx['sha'],
            '--require-content-apply-receipt' => $ctx['receipt'],
        ]);
        $this->assertNotSame(0, $contentWithReceipt);
        $this->assertStringContainsString('gelten nur für die Preisstatus-Modi', Artisan::output());
    }

    #[Test]
    public function non_staging_database_is_rejected_without_testing_override(): void
    {
        $ctx = $this->contentAppliedPackage();
        config(['dispo.master_data_transfer_testing_operational_database' => null]);

        $r = $this->runStatus('dry-run-price-status', $ctx);
        $this->assertNotSame(0, $r['exit']);
        // SQLite: Treiber-Gate. MySQL/dispo_test: Confirm-Mismatch oder Allowlist („nur gegen“).
        $this->assertTrue(
            str_contains($r['output'], 'nur mit MySQL/MariaDB')
            || str_contains($r['output'], 'nur gegen')
            || str_contains($r['output'], '--confirm-database muss exakt'),
            $r['output'],
        );
    }
}
