<?php

namespace App\Services\MasterDataTransfer\Support;

use App\Services\MasterDataTransfer\MasterDataTransferException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Slice 6b-H: erzeugt einen gültigen Backup-Proof (Version 2) aus einer bereits erstellten Dump-Datei.
 *
 * Wird vom Artisan-Helper `dispo:master-data-backup-proof` (Staging-Ops) UND von Tests verwendet —
 * Tests synthetisieren Proofs ausschließlich über diesen Builder (kein Handschreiben von Metadaten).
 *
 * Der Builder führt KEINEN Dump aus und schreibt keine Datenbank. Die Provenienzdaten
 * (database_name aus SELECT DATABASE(), target_environment) übergibt der Aufrufer; der Artisan-Helper
 * ermittelt sie über die operative DB-Guard-Kette.
 */
final class MasterDataBackupProofBuilderV2
{
    public function __construct(
        private readonly MasterDataProofSignerV2 $signer = new MasterDataProofSignerV2,
    ) {}

    /**
     * @param  array{
     *     dump_path: string,
     *     output_dir: string,
     *     phase: string,
     *     package_sha256: string,
     *     database_name: string,
     *     restore_path: string,
     *     target_environment?: string,
     *     instance_identity?: ?string,
     *     backup_run_id?: ?string,
     *     created_at?: ?string,
     *     content_receipt?: array{receipt_id: string, signature: string}|null,
     *     dump_filename?: string
     * }  $params
     * @return array{proof_dir: string, backup_run_id: string, meta_sha256: string, signature: string, dump_sha256: string, created_at: string}
     */
    public function build(array $params): array
    {
        $phase = $params['phase'];
        if (! in_array($phase, MasterDataBackupProofV2::PHASES, true)) {
            throw new MasterDataTransferException('Backup-Proof-Builder: unbekannte Phase: '.$phase);
        }

        $packageSha = strtolower(trim($params['package_sha256']));
        if (preg_match('/^[a-f0-9]{64}$/', $packageSha) !== 1) {
            throw new MasterDataTransferException('Backup-Proof-Builder: package_sha256 muss 64 Hex-Zeichen sein.');
        }

        $database = trim($params['database_name']);
        if ($database === '') {
            throw new MasterDataTransferException('Backup-Proof-Builder: database_name (SELECT DATABASE()) fehlt.');
        }

        $restorePath = trim($params['restore_path']);
        if ($restorePath === '') {
            throw new MasterDataTransferException('Backup-Proof-Builder: restore_path ist Pflicht.');
        }

        $receipt = $params['content_receipt'] ?? null;
        if ($phase === MasterDataBackupProofV2::PHASE_POST_CONTENT_APPLY && $receipt === null) {
            throw new MasterDataTransferException(
                'Backup-Proof-Builder: post_content_apply erfordert content_receipt (receipt_id + signature).',
            );
        }
        if ($phase === MasterDataBackupProofV2::PHASE_PRE_CONTENT_APPLY && $receipt !== null) {
            throw new MasterDataTransferException(
                'Backup-Proof-Builder: pre_content_apply darf keine Content-Receipt-Bindung tragen.',
            );
        }

        $dumpSource = realpath($params['dump_path']);
        if ($dumpSource === false || ! is_file($dumpSource) || ! is_readable($dumpSource)) {
            throw new MasterDataTransferException('Backup-Proof-Builder: Dump-Datei fehlt oder ist nicht lesbar.');
        }
        $dumpBytes = filesize($dumpSource);
        if ($dumpBytes === false || $dumpBytes <= 0) {
            throw new MasterDataTransferException('Backup-Proof-Builder: Dump-Datei ist leer.');
        }
        $dumpSha = hash_file('sha256', $dumpSource);
        if (! is_string($dumpSha)) {
            throw new MasterDataTransferException('Backup-Proof-Builder: Dump-SHA256 nicht berechenbar.');
        }

        $outputDir = $params['output_dir'];
        if (is_file($outputDir.DIRECTORY_SEPARATOR.MasterDataBackupProofV2::META_FILE)
            || is_file($outputDir.DIRECTORY_SEPARATOR.MasterDataBackupProofV2::GATE_FILE)) {
            throw new MasterDataTransferException(
                'Backup-Proof-Builder: Zielverzeichnis enthält bereits einen Proof (kein Überschreiben).',
            );
        }
        if (! is_dir($outputDir) && ! @mkdir($outputDir, 0700, true) && ! is_dir($outputDir)) {
            throw new MasterDataTransferException('Backup-Proof-Builder: Zielverzeichnis nicht anlegbar.');
        }
        $realOut = realpath($outputDir);
        if ($realOut === false) {
            throw new MasterDataTransferException('Backup-Proof-Builder: Zielverzeichnis nicht auflösbar.');
        }

        $dumpFilename = $params['dump_filename'] ?? basename($dumpSource);
        if ($dumpFilename === '' || str_contains($dumpFilename, '/') || str_contains($dumpFilename, '\\') || str_contains($dumpFilename, '..')) {
            throw new MasterDataTransferException('Backup-Proof-Builder: ungültiger dump_filename.');
        }
        $dumpTarget = $realOut.DIRECTORY_SEPARATOR.$dumpFilename;
        if ($dumpSource !== $dumpTarget && ! @copy($dumpSource, $dumpTarget)) {
            throw new MasterDataTransferException('Backup-Proof-Builder: Dump konnte nicht in das Proof-Verzeichnis kopiert werden.');
        }
        @chmod($dumpTarget, 0600);

        $createdAt = isset($params['created_at']) && $params['created_at'] !== null && $params['created_at'] !== ''
            ? CarbonImmutable::parse($params['created_at'])
            : CarbonImmutable::now();
        $runId = isset($params['backup_run_id']) && is_string($params['backup_run_id']) && trim($params['backup_run_id']) !== ''
            ? trim($params['backup_run_id'])
            : 'bk-'.Str::uuid()->toString();

        $instance = $params['instance_identity'] ?? null;
        if ($instance === null) {
            $configured = config('dispo.master_data_transfer_instance_id');
            $instance = is_string($configured) && trim($configured) !== '' ? trim($configured) : null;
        }

        $meta = [
            'proof_version' => MasterDataBackupProofV2::PROOF_VERSION,
            'backup_run_id' => $runId,
            'phase' => $phase,
            'created_at' => $createdAt->format('Y-m-d\TH:i:s.uP'),
            'database_name' => $database,
            'database_name_source' => 'select_database',
            'target_environment' => $params['target_environment'] ?? app()->environment(),
            'instance_identity' => $instance,
            'package_sha256' => $packageSha,
            'dump_filename' => $dumpFilename,
            'dump_sha256' => strtolower($dumpSha),
            'dump_bytes' => $dumpBytes,
            'restore_path' => $restorePath,
            'backup_process_result' => 'PASS',
            'content_receipt_id' => $receipt['receipt_id'] ?? null,
            'content_receipt_signature' => $receipt['signature'] ?? null,
        ];
        $meta[MasterDataProofSignerV2::SIGNATURE_FIELD] = $this->signer->sign($meta);

        $json = json_encode($meta, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $metaPath = $realOut.DIRECTORY_SEPARATOR.MasterDataBackupProofV2::META_FILE;
        $gatePath = $realOut.DIRECTORY_SEPARATOR.MasterDataBackupProofV2::GATE_FILE;
        if (@file_put_contents($metaPath, $json."\n") === false || @file_put_contents($gatePath, "PASS\n") === false) {
            throw new MasterDataTransferException('Backup-Proof-Builder: Metadaten/Gate konnten nicht geschrieben werden.');
        }
        @chmod($metaPath, 0600);
        @chmod($gatePath, 0600);

        return [
            'proof_dir' => $realOut,
            'backup_run_id' => $runId,
            'meta_sha256' => $this->signer->documentSha256($meta),
            'signature' => (string) $meta[MasterDataProofSignerV2::SIGNATURE_FIELD],
            'dump_sha256' => strtolower($dumpSha),
            'created_at' => $createdAt->format('Y-m-d\TH:i:s.uP'),
        ];
    }
}
