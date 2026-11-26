<?php

namespace Tests\Feature\MasterDataTransfer;

use App\Models\Inventory;
use App\Services\MasterDataTransfer\Import\MasterDataImporterV2;
use App\Services\MasterDataTransfer\MasterDataTransferContractV2;
use App\Services\MasterDataTransfer\MasterDataTransferException;
use App\Services\MasterDataTransfer\Support\MasterDataApplyReceiptV2;
use App\Services\MasterDataTransfer\Support\MasterDataBackupProofV2;
use App\Services\MasterDataTransfer\Support\MasterDataProofSignerV2;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\MasterDataTransfer\Concerns\OperationalV2Fixtures;
use Tests\TestCase;

/**
 * EPIC-03-F Slice 7 (S7-PO-03): Apply-Receipt für erfolgreichen Inhalts-Apply.
 */
class MasterDataTransferApplyReceiptV2Test extends TestCase
{
    use OperationalV2Fixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootOperationalFixtures('receipt');
    }

    protected function tearDown(): void
    {
        $this->teardownOperationalFixtures();
        parent::tearDown();
    }

    #[Test]
    public function successful_content_apply_writes_signed_receipt_with_required_fields(): void
    {
        $package = $this->writePackage();
        $sha = $this->packageSha($package);

        $result = $this->applyContent($package);
        $this->assertSame(0, $result['exit'], $result['output']);
        $this->assertStringContainsString('receipt_id=cr-', $result['output']);
        $this->assertNotNull($result['receipt_path']);
        $this->assertCount(1, $this->receiptFiles());

        $raw = json_decode((string) File::get($result['receipt_path']), true, 32, JSON_THROW_ON_ERROR);
        $this->assertSame('content_apply', $raw['kind']);
        $this->assertSame($sha, $raw['package_sha256']);
        $this->assertSame('2.0.0', $raw['schema_version']);
        $this->assertSame(MasterDataTransferContractV2::PROCESSING_CONTRACT_VERSION, $raw['processing_contract_version']);
        $this->assertSame(MasterDataTransferContractV2::OPERATIONAL_DATABASE, $raw['database_name']);
        $this->assertSame('testing', $raw['target_environment']);
        $this->assertSame(['content'], $raw['scope']);
        $this->assertSame('PASS', $raw['result']);
        $this->assertStringStartsWith('run-', $raw['run_id']);
        $this->assertSame(64, strlen($raw['report_hash']));
        $this->assertSame(1, $raw['content_fingerprints_summary']['families']['spot']['count']);
        $this->assertSame(1, $raw['content_fingerprints_summary']['families']['production']['count']);
        $this->assertSame(1, $raw['content_fingerprints_summary']['families']['oa_tkp']['count']);
        $this->assertSame(['active' => 1], $raw['content_fingerprints_summary']['families']['spot']['by_source_status']);
        $this->assertSame('pre_content_apply', $raw['backup_proof_ref']['phase']);
        $this->assertStringStartsWith('bk-', $raw['backup_proof_ref']['backup_run_id']);
        $this->assertTrue((new MasterDataProofSignerV2)->verify($raw));
        $this->assertSame('0600', substr(sprintf('%o', fileperms($result['receipt_path'])), -4));

        $valid = app(MasterDataApplyReceiptV2::class)->assertValidContentReceipt(
            $result['receipt_path'],
            $sha,
            MasterDataTransferContractV2::OPERATIONAL_DATABASE,
        );
        $this->assertSame($raw['receipt_id'], $valid['receipt_id']);
    }

    #[Test]
    public function failed_gates_and_dry_run_produce_no_receipt(): void
    {
        $package = $this->writePackage();
        $sha = $this->packageSha($package);

        // falscher SHA
        $this->assertNotSame(0, Artisan::call('dispo:import-master-data', [
            'path' => $package, '--apply' => true,
            '--confirm-database' => MasterDataTransferContractV2::OPERATIONAL_DATABASE,
            '--require-package-sha256' => str_repeat('a', 64),
            '--require-backup-proof' => $this->proofFor($sha),
        ]));
        // altes Proof-Format
        $legacy = $this->tmp.'/legacy-proof';
        File::ensureDirectoryExists($legacy);
        File::put($legacy.'/dump.sql', "x\n");
        File::put($legacy.'/BACKUP_GATE.txt', "PASS\n");
        File::put($legacy.'/DUMP_META.json', json_encode([
            'dump_filename' => 'dump.sql', 'dump_sha256' => hash('sha256', "x\n"), 'dump_bytes' => 2, 'restore_path' => 'r',
        ], JSON_THROW_ON_ERROR));
        $this->assertNotSame(0, Artisan::call('dispo:import-master-data', [
            'path' => $package, '--apply' => true,
            '--confirm-database' => MasterDataTransferContractV2::OPERATIONAL_DATABASE,
            '--require-package-sha256' => $sha,
            '--require-backup-proof' => $legacy,
        ]));
        // Dry-Run
        $this->assertSame(0, Artisan::call('dispo:import-master-data', [
            'path' => $package, '--dry-run' => true,
            '--confirm-database' => MasterDataTransferContractV2::OPERATIONAL_DATABASE,
            '--require-package-sha256' => $sha,
        ]), Artisan::output());

        $this->assertSame([], $this->receiptFiles());
        $this->assertSame(0, Inventory::query()->count());
    }

    #[Test]
    public function rolled_back_apply_produces_no_success_receipt(): void
    {
        $package = $this->writePackage();
        $sha = $this->packageSha($package);
        $proof = $this->proofFor($sha);

        $threw = false;
        DB::listen(function ($query) use (&$threw): void {
            $sql = strtolower((string) $query->sql);
            if (! $threw && (str_contains($sql, 'update "field_definitions"') || str_contains($sql, 'update `field_definitions`'))) {
                $threw = true;
                throw new \RuntimeException('forced failure before commit');
            }
        });

        try {
            app(MasterDataImporterV2::class)->applyOperational(
                $package,
                MasterDataTransferContractV2::OPERATIONAL_DATABASE,
                $sha,
                $proof,
            );
            $this->fail('Abbruch erwartet');
        } catch (\RuntimeException $e) {
            $this->assertSame('forced failure before commit', $e->getMessage());
        }

        $this->assertTrue($threw);
        $this->assertSame([], $this->receiptFiles());
        $this->assertSame(0, Inventory::query()->count());
    }

    #[Test]
    public function repeated_apply_is_idempotent_and_issues_a_new_receipt(): void
    {
        $package = $this->writePackage();
        $first = $this->applyContent($package);
        $this->assertSame(0, $first['exit'], $first['output']);
        $second = $this->applyContent($package);
        $this->assertSame(0, $second['exit'], $second['output']);
        $this->assertStringContainsString('created=0', $second['output']);

        $this->assertCount(2, $this->receiptFiles());
        $this->assertNotSame($first['receipt_path'], $second['receipt_path']);
    }

    #[Test]
    public function tampered_or_foreign_receipts_are_rejected(): void
    {
        $package = $this->writePackage();
        $sha = $this->packageSha($package);
        $result = $this->applyContent($package);
        $path = $result['receipt_path'];
        $this->assertNotNull($path);
        $service = app(MasterDataApplyReceiptV2::class);
        $db = MasterDataTransferContractV2::OPERATIONAL_DATABASE;

        // anderes Paket
        $this->assertReceiptRejected(fn () => $service->assertValidContentReceipt($path, str_repeat('b', 64), $db), 'anderen Paket');
        // andere DB
        $this->assertReceiptRejected(fn () => $service->assertValidContentReceipt($path, $sha, 'dispo_mat_core'), 'gehört zu DB');
        // fehlende Datei
        $this->assertReceiptRejected(fn () => $service->assertValidContentReceipt($this->tmp.'/nope.json', $sha, $db), 'fehlt oder ist nicht lesbar');
        // leerer Pfad
        $this->assertReceiptRejected(fn () => $service->assertValidContentReceipt('', $sha, $db), '--require-content-apply-receipt');

        // manipuliertes Feld → Signatur
        $raw = json_decode((string) File::get($path), true, 32, JSON_THROW_ON_ERROR);
        $edited = $this->tmp.'/edited-receipt.json';
        File::put($edited, json_encode(array_merge($raw, ['package_sha256' => str_repeat('c', 64)]), JSON_THROW_ON_ERROR));
        $this->assertReceiptRejected(fn () => $service->assertValidContentReceipt($edited, str_repeat('c', 64), $db), 'Signatur');

        // frei gebautes Receipt ohne gültige Signatur ("Flag")
        $forged = $this->tmp.'/forged-receipt.json';
        File::put($forged, json_encode(['kind' => 'content_apply', 'result' => 'PASS', 'package_sha256' => $sha], JSON_THROW_ON_ERROR));
        $this->assertReceiptRejected(fn () => $service->assertValidContentReceipt($forged, $sha, $db), 'Signatur');

        // nicht-PASS-Receipt wird nie geschrieben
        $this->assertReceiptRejected(fn () => $service->writeContentReceipt([
            'run_id' => 'run-x', 'package_sha256' => $sha, 'database_name' => $db, 'result' => 'FAIL',
            'report_hash' => str_repeat('0', 64), 'content_fingerprints_summary' => [],
            'backup_proof' => ['backup_run_id' => 'bk', 'meta_sha256' => 'm', 'dump_sha256' => 'd', 'phase' => 'pre_content_apply'],
        ]), 'result=PASS');

        // gültiges Receipt aber falsche Art
        $kindEdited = array_merge($raw, ['kind' => 'price_status_apply']);
        $kindEdited[MasterDataProofSignerV2::SIGNATURE_FIELD] = (new MasterDataProofSignerV2)->sign($kindEdited);
        $kindFile = $this->tmp.'/kind-receipt.json';
        File::put($kindFile, json_encode($kindEdited, JSON_THROW_ON_ERROR));
        $this->assertReceiptRejected(fn () => $service->assertValidContentReceipt($kindFile, $sha, $db), 'Art "content_apply" erwartet');
    }

    #[Test]
    public function receipt_write_failure_after_commit_is_reported_clearly_and_reapply_is_idempotent(): void
    {
        $package = $this->writePackage();
        $sha = $this->packageSha($package);
        // Receipt-Verzeichnis ist eine Datei → mkdir/Schreiben scheitert erst NACH dem Commit.
        File::put($this->tmp.'/receipts', 'blocker');

        $output = '';
        $exit = Artisan::call('dispo:import-master-data', [
            'path' => $package, '--apply' => true,
            '--confirm-database' => MasterDataTransferContractV2::OPERATIONAL_DATABASE,
            '--require-package-sha256' => $sha,
            '--require-backup-proof' => $this->proofFor($sha),
        ]);
        $output = Artisan::output();
        $this->assertNotSame(0, $exit);
        $this->assertStringContainsString('committet, aber das Apply-Receipt konnte nicht geschrieben werden', $output);
        $this->assertSame(1, Inventory::query()->where('code', 'inv_v2_radio')->count());

        File::delete($this->tmp.'/receipts');
        $retry = $this->applyContent($package);
        $this->assertSame(0, $retry['exit'], $retry['output']);
        $this->assertStringContainsString('created=0', $retry['output']);
        $this->assertCount(1, $this->receiptFiles());
    }

    #[Test]
    public function artisan_helper_creates_backup_b_bound_to_the_content_receipt(): void
    {
        $package = $this->writePackage();
        $sha = $this->packageSha($package);
        $result = $this->applyContent($package);
        $this->assertSame(0, $result['exit'], $result['output']);

        $dump = $this->tmp.'/post-dump.sql';
        File::put($dump, "-- post apply dump\n");
        $out = $this->tmp.'/post-proof';
        $exit = Artisan::call('dispo:master-data-backup-proof', [
            'dump' => $dump,
            '--output-dir' => $out,
            '--phase' => 'post_content_apply',
            '--package-sha256' => $sha,
            '--confirm-database' => MasterDataTransferContractV2::OPERATIONAL_DATABASE,
            '--restore-path' => 'same_db_import_verified',
            '--content-receipt' => $result['receipt_path'],
        ]);
        $this->assertSame(0, $exit, Artisan::output());

        $receipt = app(MasterDataApplyReceiptV2::class)->assertValidContentReceipt(
            (string) $result['receipt_path'],
            $sha,
            MasterDataTransferContractV2::OPERATIONAL_DATABASE,
        );
        $proof = (new MasterDataBackupProofV2)->assertValid(
            $out,
            MasterDataTransferContractV2::OPERATIONAL_DATABASE,
            $sha,
            MasterDataBackupProofV2::PHASE_POST_CONTENT_APPLY,
            $receipt,
        );
        $this->assertSame($receipt['receipt_id'], $proof['content_receipt_id']);

        // ohne Receipt verweigert der Helper Backup B
        $refused = Artisan::call('dispo:master-data-backup-proof', [
            'dump' => $dump,
            '--output-dir' => $this->tmp.'/post-proof-2',
            '--phase' => 'post_content_apply',
            '--package-sha256' => $sha,
            '--confirm-database' => MasterDataTransferContractV2::OPERATIONAL_DATABASE,
            '--restore-path' => 'x',
        ]);
        $this->assertNotSame(0, $refused);
    }

    private function assertReceiptRejected(\Closure $call, string $needle): void
    {
        try {
            $call();
            $this->fail('Receipt hätte abgelehnt werden müssen: '.$needle);
        } catch (MasterDataTransferException $e) {
            $this->assertStringContainsString($needle, $e->getMessage());
        }
    }
}
