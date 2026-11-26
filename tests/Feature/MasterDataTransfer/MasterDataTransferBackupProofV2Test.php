<?php

namespace Tests\Feature\MasterDataTransfer;

use App\Services\MasterDataTransfer\MasterDataTransferContractV2;
use App\Services\MasterDataTransfer\MasterDataTransferException;
use App\Services\MasterDataTransfer\Support\MasterDataBackupProofBuilderV2;
use App\Services\MasterDataTransfer\Support\MasterDataBackupProofV2;
use App\Services\MasterDataTransfer\Support\MasterDataProofSignerV2;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * EPIC-03-F Slice 6b-H: Backup-Proof-Härtung (Integrität A + Provenienz B; Restore C ist nicht Testgegenstand).
 */
class MasterDataTransferBackupProofV2Test extends TestCase
{
    use RefreshDatabase;

    private const PKG = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir().'/mdt-v2-backup-proof-'.uniqid('', true);
        File::ensureDirectoryExists($this->tmp);
        config([
            'dispo.master_data_transfer_testing_operational_database' => MasterDataTransferContractV2::OPERATIONAL_DATABASE,
            'dispo.master_data_transfer_instance_id' => null,
        ]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        File::deleteDirectory($this->tmp);
        config(['dispo.master_data_transfer_testing_operational_database' => null]);
        parent::tearDown();
    }

    #[Test]
    public function builder_proof_passes_with_full_provenance(): void
    {
        $built = $this->buildProof();
        $proof = (new MasterDataBackupProofV2)->assertValid(
            $built['proof_dir'],
            MasterDataTransferContractV2::OPERATIONAL_DATABASE,
            self::PKG,
        );

        $this->assertSame(2, $proof['proof_version']);
        $this->assertSame(MasterDataTransferContractV2::OPERATIONAL_DATABASE, $proof['database_name']);
        $this->assertSame('testing', $proof['target_environment']);
        $this->assertSame(MasterDataBackupProofV2::PHASE_PRE_CONTENT_APPLY, $proof['phase']);
        $this->assertSame(self::PKG, $proof['package_sha256']);
        $this->assertSame('PASS', $proof['backup_process_result']);
        $this->assertStringStartsWith('bk-', $proof['backup_run_id']);
        $this->assertSame($built['meta_sha256'], $proof['meta_sha256']);
        $this->assertSame(64, strlen($proof['signature']));
    }

    #[Test]
    public function legacy_proof_without_provenance_is_rejected(): void
    {
        $dir = $this->tmp.'/legacy';
        File::ensureDirectoryExists($dir);
        File::put($dir.'/dump.sql', "-- legacy\n");
        File::put($dir.'/'.MasterDataBackupProofV2::GATE_FILE, "PASS\n");
        File::put($dir.'/'.MasterDataBackupProofV2::META_FILE, json_encode([
            'dump_filename' => 'dump.sql',
            'dump_sha256' => hash_file('sha256', $dir.'/dump.sql'),
            'dump_bytes' => filesize($dir.'/dump.sql'),
            'restore_path' => 'same_db_import_verified',
        ], JSON_THROW_ON_ERROR));

        $this->assertProofRejected($dir, 'proof_version=2 erforderlich');
    }

    #[Test]
    public function freely_edited_database_name_is_rejected_by_signature(): void
    {
        $built = $this->buildProof(database: 'other_db');
        $this->editMeta($built['proof_dir'], ['database_name' => MasterDataTransferContractV2::OPERATIONAL_DATABASE]);

        $this->assertProofRejected($built['proof_dir'], 'Signatur');
    }

    #[Test]
    public function proof_signed_with_foreign_secret_is_rejected(): void
    {
        config(['dispo.master_data_transfer_proof_secret' => 'attacker-secret']);
        $built = $this->buildProof();
        config(['dispo.master_data_transfer_proof_secret' => null]);

        $this->assertProofRejected($built['proof_dir'], 'Signatur');
    }

    #[Test]
    public function properly_signed_proof_for_other_database_is_rejected(): void
    {
        $built = $this->buildProof(database: 'dispo_mat_core');

        $this->assertProofRejected($built['proof_dir'], 'gehört zu DB "dispo_mat_core"');
    }

    #[Test]
    public function wrong_environment_package_phase_and_instance_are_rejected(): void
    {
        $envProof = $this->buildProof(extra: ['target_environment' => 'production']);
        $this->assertProofRejected($envProof['proof_dir'], 'Zielumgebung');

        $pkgProof = $this->buildProof();
        $this->assertProofRejected($pkgProof['proof_dir'], 'anderes Paket', packageSha: str_repeat('b', 64));

        $phaseProof = $this->buildProof();
        $this->assertProofRejected(
            $phaseProof['proof_dir'],
            'Phase "pre_content_apply" passt nicht',
            phase: MasterDataBackupProofV2::PHASE_POST_CONTENT_APPLY,
        );

        $instanceProof = $this->buildProof(extra: ['instance_identity' => 'staging-host-a']);
        config(['dispo.master_data_transfer_instance_id' => 'staging-host-b']);
        $this->assertProofRejected($instanceProof['proof_dir'], 'Instanzidentität');
    }

    #[Test]
    public function stale_and_future_proofs_are_rejected(): void
    {
        $old = $this->buildProof(createdAt: CarbonImmutable::now()->subDays(3)->toIso8601String());
        $this->assertProofRejected($old['proof_dir'], 'älter als');

        $future = $this->buildProof(createdAt: CarbonImmutable::now()->addHours(2)->toIso8601String());
        $this->assertProofRejected($future['proof_dir'], 'Zukunft');
    }

    #[Test]
    public function failed_backup_process_result_and_wrong_name_source_are_rejected(): void
    {
        $built = $this->buildProof();
        $this->resignMeta($built['proof_dir'], ['backup_process_result' => 'FAIL']);
        $this->assertProofRejected($built['proof_dir'], 'backup_process_result');

        $second = $this->buildProof();
        $this->resignMeta($second['proof_dir'], ['database_name_source' => 'config']);
        $this->assertProofRejected($second['proof_dir'], 'select_database');
    }

    #[Test]
    public function integrity_failures_are_reported_before_provenance(): void
    {
        $built = $this->buildProof();
        File::append($built['proof_dir'].'/pre-apply-dump.sql', 'tamper');
        $this->assertProofRejected($built['proof_dir'], 'Dump-Größe weicht ab');

        $second = $this->buildProof();
        File::delete($second['proof_dir'].'/'.MasterDataBackupProofV2::GATE_FILE);
        $this->assertProofRejected($second['proof_dir'], 'BACKUP_GATE.txt fehlt');
    }

    #[Test]
    public function post_content_phase_requires_receipt_linkage_and_time_after_content_apply(): void
    {
        $completedAt = CarbonImmutable::now()->subMinutes(10);
        $receipt = [
            'receipt_id' => 'cr-test-1',
            'signature' => str_repeat('c', 64),
            'completed_at' => $completedAt->toIso8601String(),
        ];

        $good = $this->buildProof(
            phase: MasterDataBackupProofV2::PHASE_POST_CONTENT_APPLY,
            receipt: $receipt,
            createdAt: $completedAt->addMinutes(2)->toIso8601String(),
        );
        $proof = (new MasterDataBackupProofV2)->assertValid(
            $good['proof_dir'],
            MasterDataTransferContractV2::OPERATIONAL_DATABASE,
            self::PKG,
            MasterDataBackupProofV2::PHASE_POST_CONTENT_APPLY,
            $receipt,
        );
        $this->assertSame('cr-test-1', $proof['content_receipt_id']);

        // Backup B vor dem Inhalts-Apply → STOP.
        $before = $this->buildProof(
            phase: MasterDataBackupProofV2::PHASE_POST_CONTENT_APPLY,
            receipt: $receipt,
            createdAt: $completedAt->subMinutes(5)->toIso8601String(),
        );
        $this->assertProofRejected(
            $before['proof_dir'],
            'VOR dem Inhalts-Apply',
            phase: MasterDataBackupProofV2::PHASE_POST_CONTENT_APPLY,
            receipt: $receipt,
        );

        // Falsches Receipt.
        $this->assertProofRejected(
            $good['proof_dir'],
            'nicht an dieses Content-Apply-Receipt gebunden',
            phase: MasterDataBackupProofV2::PHASE_POST_CONTENT_APPLY,
            receipt: ['receipt_id' => 'cr-other', 'signature' => $receipt['signature'], 'completed_at' => $receipt['completed_at']],
        );

        // Receipt-Signatur weicht ab.
        $this->assertProofRejected(
            $good['proof_dir'],
            'Receipt-Signatur',
            phase: MasterDataBackupProofV2::PHASE_POST_CONTENT_APPLY,
            receipt: ['receipt_id' => 'cr-test-1', 'signature' => str_repeat('d', 64), 'completed_at' => $receipt['completed_at']],
        );

        // Ohne Receipt kein Backup B.
        $this->assertProofRejected(
            $good['proof_dir'],
            'erfordert ein verifiziertes Content-Apply-Receipt',
            phase: MasterDataBackupProofV2::PHASE_POST_CONTENT_APPLY,
        );
    }

    #[Test]
    public function builder_refuses_overwrite_missing_receipt_and_invalid_input(): void
    {
        $built = $this->buildProof();
        try {
            (new MasterDataBackupProofBuilderV2)->build([
                'dump_path' => $this->tmp.'/dump.sql',
                'output_dir' => $built['proof_dir'],
                'phase' => MasterDataBackupProofV2::PHASE_PRE_CONTENT_APPLY,
                'package_sha256' => self::PKG,
                'database_name' => MasterDataTransferContractV2::OPERATIONAL_DATABASE,
                'restore_path' => 'x',
            ]);
            $this->fail('Überschreiben muss verweigert werden');
        } catch (MasterDataTransferException $e) {
            $this->assertStringContainsString('kein Überschreiben', $e->getMessage());
        }

        foreach ([
            ['phase' => MasterDataBackupProofV2::PHASE_POST_CONTENT_APPLY, 'content_receipt' => null, 'needle' => 'content_receipt'],
            ['phase' => 'bogus', 'content_receipt' => null, 'needle' => 'unbekannte Phase'],
        ] as $case) {
            try {
                (new MasterDataBackupProofBuilderV2)->build([
                    'dump_path' => $this->tmp.'/dump.sql',
                    'output_dir' => $this->tmp.'/out-'.uniqid('', true),
                    'phase' => $case['phase'],
                    'package_sha256' => self::PKG,
                    'database_name' => MasterDataTransferContractV2::OPERATIONAL_DATABASE,
                    'restore_path' => 'x',
                    'content_receipt' => $case['content_receipt'],
                ]);
                $this->fail('Ungültige Eingabe muss verweigert werden');
            } catch (MasterDataTransferException $e) {
                $this->assertStringContainsString($case['needle'], $e->getMessage());
            }
        }
    }

    #[Test]
    public function artisan_helper_creates_valid_proof_and_refuses_wrong_database_or_header(): void
    {
        $dump = $this->tmp.'/helper-dump.sql';
        File::put($dump, "-- MySQL dump\n-- Host: localhost    Database: ".MasterDataTransferContractV2::OPERATIONAL_DATABASE."\nSELECT 1;\n");
        $out = $this->tmp.'/helper-out';

        $exit = Artisan::call('dispo:master-data-backup-proof', [
            'dump' => $dump,
            '--output-dir' => $out,
            '--package-sha256' => self::PKG,
            '--confirm-database' => MasterDataTransferContractV2::OPERATIONAL_DATABASE,
            '--restore-path' => 'same_db_import_verified',
        ]);
        $helperOutput = Artisan::output();
        $this->assertSame(0, $exit, $helperOutput);
        $this->assertStringContainsString('Backup-Proof erzeugt', $helperOutput);

        $proof = (new MasterDataBackupProofV2)->assertValid(
            $out,
            MasterDataTransferContractV2::OPERATIONAL_DATABASE,
            self::PKG,
        );
        $this->assertSame(MasterDataTransferContractV2::OPERATIONAL_DATABASE, $proof['database_name']);

        $wrongConfirm = Artisan::call('dispo:master-data-backup-proof', [
            'dump' => $dump,
            '--output-dir' => $this->tmp.'/helper-out-2',
            '--package-sha256' => self::PKG,
            '--confirm-database' => 'dispo_mat_core',
            '--restore-path' => 'x',
        ]);
        $this->assertNotSame(0, $wrongConfirm);
        $this->assertFalse(is_file($this->tmp.'/helper-out-2/'.MasterDataBackupProofV2::META_FILE));

        $foreignDump = $this->tmp.'/foreign-dump.sql';
        File::put($foreignDump, "-- MySQL dump\n-- Host: localhost    Database: dispo_mat_core\n");
        $headerExit = Artisan::call('dispo:master-data-backup-proof', [
            'dump' => $foreignDump,
            '--output-dir' => $this->tmp.'/helper-out-3',
            '--package-sha256' => self::PKG,
            '--confirm-database' => MasterDataTransferContractV2::OPERATIONAL_DATABASE,
            '--restore-path' => 'x',
        ]);
        $this->assertNotSame(0, $headerExit);
        $this->assertStringContainsString('Dump-Header nennt DB', Artisan::output());
        $this->assertFalse(is_file($this->tmp.'/helper-out-3/'.MasterDataBackupProofV2::META_FILE));
    }

    /**
     * @param  array<string, mixed>  $extra
     * @param  array{receipt_id: string, signature: string, completed_at: string}|null  $receipt
     * @return array{proof_dir: string, backup_run_id: string, meta_sha256: string, signature: string, dump_sha256: string, created_at: string}
     */
    private function buildProof(
        string $database = MasterDataTransferContractV2::OPERATIONAL_DATABASE,
        string $phase = MasterDataBackupProofV2::PHASE_PRE_CONTENT_APPLY,
        ?array $receipt = null,
        ?string $createdAt = null,
        array $extra = [],
    ): array {
        $dump = $this->tmp.'/dump.sql';
        if (! is_file($dump)) {
            File::put($dump, "-- synthetic dump\n".bin2hex(random_bytes(8))."\n");
        }

        return (new MasterDataBackupProofBuilderV2)->build(array_merge([
            'dump_path' => $dump,
            'output_dir' => $this->tmp.'/proof-'.uniqid('', true),
            'phase' => $phase,
            'package_sha256' => self::PKG,
            'database_name' => $database,
            'restore_path' => 'same_db_import_verified',
            'dump_filename' => 'pre-apply-dump.sql',
            'content_receipt' => $receipt === null ? null : ['receipt_id' => $receipt['receipt_id'], 'signature' => $receipt['signature']],
            'created_at' => $createdAt,
        ], $extra));
    }

    /**
     * @param  array{receipt_id: string, signature: string, completed_at: string}|null  $receipt
     */
    private function assertProofRejected(
        string $dir,
        string $needle,
        ?string $packageSha = null,
        string $phase = MasterDataBackupProofV2::PHASE_PRE_CONTENT_APPLY,
        ?array $receipt = null,
    ): void {
        try {
            (new MasterDataBackupProofV2)->assertValid(
                $dir,
                MasterDataTransferContractV2::OPERATIONAL_DATABASE,
                $packageSha ?? self::PKG,
                $phase,
                $receipt,
            );
            $this->fail('Proof hätte abgelehnt werden müssen: '.$needle);
        } catch (MasterDataTransferException $e) {
            $this->assertStringContainsString($needle, $e->getMessage());
        }
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private function editMeta(string $dir, array $changes): void
    {
        $path = $dir.'/'.MasterDataBackupProofV2::META_FILE;
        $meta = json_decode((string) File::get($path), true, 32, JSON_THROW_ON_ERROR);
        File::put($path, json_encode(array_merge($meta, $changes), JSON_THROW_ON_ERROR));
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private function resignMeta(string $dir, array $changes): void
    {
        $path = $dir.'/'.MasterDataBackupProofV2::META_FILE;
        $meta = json_decode((string) File::get($path), true, 32, JSON_THROW_ON_ERROR);
        $meta = array_merge($meta, $changes);
        $meta[MasterDataProofSignerV2::SIGNATURE_FIELD] = (new MasterDataProofSignerV2)->sign($meta);
        File::put($path, json_encode($meta, JSON_THROW_ON_ERROR));
    }
}
