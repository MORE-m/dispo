<?php

namespace App\Console\Commands\MasterDataTransfer;

use App\Services\MasterDataTransfer\MasterDataTransferException;
use App\Services\MasterDataTransfer\Support\MasterDataApplyReceiptV2;
use App\Services\MasterDataTransfer\Support\MasterDataBackupProofBuilderV2;
use App\Services\MasterDataTransfer\Support\MasterDataBackupProofV2;
use App\Services\MasterDataTransfer\Support\MasterDataOperationalDatabaseGuardV2;
use Illuminate\Console\Command;

/**
 * EPIC-03-F Slice 6b-H: Staging-Ops-Helper — erzeugt einen signierten Backup-Proof (Version 2)
 * aus einer bereits erstellten Dump-Datei. Führt KEINEN Dump aus und schreibt keine Datenbank.
 *
 * Die DB-Identität wird über dieselbe operative DB-Guard-Kette wie Apply ermittelt
 * (--confirm-database, Allowlist spn26jz7_dispo_staging, SELECT DATABASE()).
 */
class CreateMasterDataBackupProofCommand extends Command
{
    protected $signature = 'dispo:master-data-backup-proof
        {dump : Pfad zur bereits erstellten Dump-Datei}
        {--output-dir= : Neues Proof-Verzeichnis (darf noch keinen Proof enthalten)}
        {--phase=pre_content_apply : pre_content_apply (Backup A) oder post_content_apply (Backup B)}
        {--package-sha256= : Paket-SHA256 (64 Hex), an das der Proof gebunden wird}
        {--confirm-database= : Muss exakt der aktiven Staging-DB entsprechen}
        {--restore-path= : Dokumentierter Restore-Weg (z. B. same_db_import_verified)}
        {--content-receipt= : Pfad zum Content-Apply-Receipt (Pflicht bei post_content_apply)}
        {--backup-run-id= : Optionale Lauf-ID (Default: generiert)}';

    protected $description = 'Signierten Backup-Proof (v2) für Master-Data-Transfer erzeugen (Staging-Ops-Helper, schreibt keine DB)';

    public function handle(
        MasterDataOperationalDatabaseGuardV2 $guard,
        MasterDataBackupProofBuilderV2 $builder,
        MasterDataApplyReceiptV2 $receipts,
    ): int {
        try {
            $dump = $this->argument('dump');
            $outputDir = $this->stringOption('output-dir');
            $phase = $this->stringOption('phase');
            $packageSha = strtolower($this->stringOption('package-sha256'));
            $confirm = $this->stringOption('confirm-database');
            $restorePath = $this->stringOption('restore-path');
            $receiptPath = $this->stringOption('content-receipt');

            if (! is_string($dump) || $dump === '') {
                throw new MasterDataTransferException('Pfad zur Dump-Datei fehlt.');
            }
            if ($outputDir === '') {
                throw new MasterDataTransferException('--output-dir ist Pflicht.');
            }
            if (preg_match('/^[a-f0-9]{64}$/', $packageSha) !== 1) {
                throw new MasterDataTransferException('--package-sha256 ist Pflicht und muss 64 Hex-Zeichen haben.');
            }

            $database = $guard->assertApplyAllowed($confirm);
            $this->assertDumpHeaderMatchesDatabase($dump, $database);

            $receiptRef = null;
            if ($phase === MasterDataBackupProofV2::PHASE_POST_CONTENT_APPLY) {
                if ($receiptPath === '') {
                    throw new MasterDataTransferException('--content-receipt ist bei post_content_apply Pflicht.');
                }
                $receipt = $receipts->assertValidContentReceipt($receiptPath, $packageSha, $database);
                $receiptRef = ['receipt_id' => $receipt['receipt_id'], 'signature' => $receipt['signature']];
            } elseif ($receiptPath !== '') {
                throw new MasterDataTransferException('--content-receipt gilt nur für post_content_apply.');
            }

            $result = $builder->build([
                'dump_path' => $dump,
                'output_dir' => $outputDir,
                'phase' => $phase,
                'package_sha256' => $packageSha,
                'database_name' => $database,
                'restore_path' => $restorePath,
                'backup_run_id' => $this->stringOption('backup-run-id') ?: null,
                'content_receipt' => $receiptRef,
            ]);
        } catch (MasterDataTransferException $e) {
            $this->error($e->getMessage());
            foreach ($e->errors as $error) {
                $this->line(' - '.mb_substr($error, 0, 300));
            }

            return self::FAILURE;
        }

        $this->info('Backup-Proof erzeugt');
        $this->line('proof_dir='.$result['proof_dir']);
        $this->line('backup_run_id='.$result['backup_run_id']);
        $this->line('phase='.$phase);
        $this->line('created_at='.$result['created_at']);
        $this->line('dump_sha256='.$result['dump_sha256']);
        $this->line('meta_sha256='.$result['meta_sha256']);

        return self::SUCCESS;
    }

    private function stringOption(string $name): string
    {
        $value = $this->option($name);

        return is_string($value) ? trim($value) : '';
    }

    /**
     * Weiche Plausibilitätsprüfung: trägt der Dump einen mysqldump-Header mit DB-Namen, muss er passen.
     * Fehlt ein Header (z. B. synthetischer Dump), wird nicht geraten — die Provenienz stützt sich dann
     * ausschließlich auf SELECT DATABASE() der aktiven Verbindung zum Proof-Zeitpunkt.
     */
    private function assertDumpHeaderMatchesDatabase(string $dumpPath, string $database): void
    {
        $handle = @fopen($dumpPath, 'rb');
        if ($handle === false) {
            throw new MasterDataTransferException('Dump-Datei nicht lesbar.');
        }
        $head = (string) fread($handle, 8192);
        fclose($handle);

        $found = [];
        if (preg_match_all('/^--\s+Host:.*?\bDatabase:\s*(\S+)/m', $head, $m) > 0) {
            $found = array_merge($found, $m[1]);
        }
        if (preg_match_all('/^--\s+Current Database:\s*`([^`]+)`/m', $head, $m2) > 0) {
            $found = array_merge($found, $m2[1]);
        }
        foreach ($found as $name) {
            if ($name !== $database) {
                throw new MasterDataTransferException(sprintf(
                    'Dump-Header nennt DB "%s", aktiv ist "%s" — Proof verweigert.',
                    $name,
                    $database,
                ));
            }
        }
    }
}
