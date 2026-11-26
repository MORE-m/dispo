<?php

namespace App\Services\MasterDataTransfer\Support;

use App\Services\MasterDataTransfer\MasterDataTransferException;
use Carbon\CarbonImmutable;

/**
 * Slice 6b-H: Verifizierter Backup-Nachweis mit Provenienz (Proof-Version 2).
 *
 * Verbindliches Verzeichnisformat (Runbook §4):
 *
 *   <proof-dir>/
 *     BACKUP_GATE.txt   → erste nicht-leere Zeile exakt "PASS"
 *     DUMP_META.json    → signierte Metadaten (Proof-Version 2)
 *     <dump_filename>   → Dump-Datei, SHA256 + Größe müssen passen
 *
 * Drei getrennte Nachweisebenen (siehe Runbook "Backup A/B/C"):
 *  A) Integrität  — Dump existiert, Größe + SHA256 passen, Gate PASS.
 *  B) Provenienz  — signierte Metadaten: tatsächlicher DB-Name (SELECT DATABASE() zum
 *                   Erstellzeitpunkt), Zielumgebung, Instanz, Zeitpunkt, backup_run_id,
 *                   Prozessergebnis, Bindung an Paket-SHA256 und Phase.
 *  C) Restore     — NICHT Gegenstand dieser Klasse (getrennte, manuelle Verifikation).
 *
 * Alte Proofs ohne proof_version=2 + gültige HMAC-Signatur werden abgelehnt.
 * Phase:
 *  - pre_content_apply  (Backup A): vor Inhalts-Apply, an Paket gebunden.
 *  - post_content_apply (Backup B): nach Inhalts-Apply, an Content-Receipt gebunden und
 *    erst NACH dessen completed_at erstellt (Slice 7, S7-PO-02).
 */
final class MasterDataBackupProofV2
{
    public const GATE_FILE = 'BACKUP_GATE.txt';

    public const META_FILE = 'DUMP_META.json';

    public const PROOF_VERSION = 2;

    public const PHASE_PRE_CONTENT_APPLY = 'pre_content_apply';

    public const PHASE_POST_CONTENT_APPLY = 'post_content_apply';

    public const PHASES = [self::PHASE_PRE_CONTENT_APPLY, self::PHASE_POST_CONTENT_APPLY];

    /** Zulässige Uhrenabweichung (Sekunden) für created_at in der Zukunft. */
    private const CLOCK_SKEW_SECONDS = 300;

    public function __construct(
        private readonly MasterDataProofSignerV2 $signer = new MasterDataProofSignerV2,
    ) {}

    /**
     * @param  array{receipt_id: string, signature: string, completed_at: string}|null  $contentReceipt  Pflicht bei post_content_apply
     * @return array{
     *     proof_dir: string,
     *     gate: string,
     *     proof_version: int,
     *     backup_run_id: string,
     *     phase: string,
     *     created_at: string,
     *     database_name: string,
     *     target_environment: string,
     *     instance_identity: ?string,
     *     package_sha256: string,
     *     dump_filename: string,
     *     dump_sha256: string,
     *     dump_bytes: int,
     *     restore_path: string,
     *     backup_process_result: string,
     *     content_receipt_id: ?string,
     *     meta_sha256: string,
     *     signature: string
     * }
     */
    public function assertValid(
        string $requireBackupProof,
        string $expectedDatabase,
        string $expectedPackageSha256,
        string $expectedPhase = self::PHASE_PRE_CONTENT_APPLY,
        ?array $contentReceipt = null,
    ): array {
        if (! in_array($expectedPhase, self::PHASES, true)) {
            throw new MasterDataTransferException('Backup-Proof: unbekannte erwartete Phase: '.$expectedPhase);
        }

        $path = trim($requireBackupProof);
        if ($path === '') {
            throw new MasterDataTransferException(
                '--require-backup-proof ist Pflicht und muss auf ein Proof-Verzeichnis zeigen.',
            );
        }

        $realDir = realpath($path);
        if ($realDir === false || ! is_dir($realDir)) {
            throw new MasterDataTransferException(
                'Backup-Proof ungültig: Verzeichnis fehlt oder ist nicht lesbar.',
            );
        }

        $this->assertGate($realDir);
        $meta = $this->readMeta($realDir);

        // Integrität (A)
        $dumpFilename = $meta['dump_filename'] ?? null;
        $dumpSha = $meta['dump_sha256'] ?? null;
        $dumpBytes = $meta['dump_bytes'] ?? null;
        $restorePath = $meta['restore_path'] ?? null;

        if (! is_string($dumpFilename) || trim($dumpFilename) === '') {
            throw new MasterDataTransferException(
                'Backup-Proof ungültig: dump_filename fehlt oder ist leer.',
            );
        }
        $dumpFilename = trim($dumpFilename);
        if (
            str_contains($dumpFilename, '..')
            || str_contains($dumpFilename, '/')
            || str_contains($dumpFilename, '\\')
        ) {
            throw new MasterDataTransferException(
                'Backup-Proof ungültig: dump_filename darf keinen Pfadanteil enthalten.',
            );
        }

        if (! is_string($dumpSha)) {
            throw new MasterDataTransferException('Backup-Proof ungültig: dump_sha256 fehlt.');
        }
        $dumpSha = strtolower(trim($dumpSha));
        if (preg_match('/^[a-f0-9]{64}$/', $dumpSha) !== 1) {
            throw new MasterDataTransferException(
                'Backup-Proof ungültig: dump_sha256 muss 64 Hex-Zeichen sein.',
            );
        }

        if (is_string($dumpBytes) && ctype_digit($dumpBytes)) {
            $dumpBytes = (int) $dumpBytes;
        }
        if (! is_int($dumpBytes) || $dumpBytes <= 0) {
            throw new MasterDataTransferException(
                'Backup-Proof ungültig: dump_bytes muss eine positive Ganzzahl sein.',
            );
        }

        if (! is_string($restorePath) || trim($restorePath) === '') {
            throw new MasterDataTransferException(
                'Backup-Proof ungültig: restore_path fehlt oder ist leer.',
            );
        }

        $dumpPath = $realDir.DIRECTORY_SEPARATOR.$dumpFilename;
        $realDump = realpath($dumpPath);
        if ($realDump === false || ! is_file($realDump) || ! is_readable($realDump)) {
            throw new MasterDataTransferException(
                'Backup-Proof ungültig: Dump-Datei fehlt oder ist nicht lesbar.',
            );
        }
        if (! str_starts_with($realDump, $realDir.DIRECTORY_SEPARATOR)) {
            throw new MasterDataTransferException(
                'Backup-Proof ungültig: Dump-Datei liegt außerhalb des Proof-Verzeichnisses.',
            );
        }

        $actualBytes = filesize($realDump);
        if ($actualBytes === false || $actualBytes !== $dumpBytes) {
            throw new MasterDataTransferException(sprintf(
                'Backup-Proof ungültig: Dump-Größe weicht ab (meta=%d, actual=%s).',
                $dumpBytes,
                $actualBytes === false ? '(unbekannt)' : (string) $actualBytes,
            ));
        }

        $actualSha = hash_file('sha256', $realDump);
        if (! is_string($actualSha) || ! hash_equals($dumpSha, strtolower($actualSha))) {
            throw new MasterDataTransferException(
                'Backup-Proof ungültig: Dump-SHA256 stimmt nicht mit DUMP_META.json überein.',
            );
        }

        // Provenienz (B)
        $this->assertProvenance($meta, $expectedDatabase, $expectedPackageSha256, $expectedPhase, $contentReceipt);

        return [
            'proof_dir' => $realDir,
            'gate' => 'PASS',
            'proof_version' => self::PROOF_VERSION,
            'backup_run_id' => (string) $meta['backup_run_id'],
            'phase' => (string) $meta['phase'],
            'created_at' => (string) $meta['created_at'],
            'database_name' => (string) $meta['database_name'],
            'target_environment' => (string) $meta['target_environment'],
            'instance_identity' => is_string($meta['instance_identity'] ?? null) ? $meta['instance_identity'] : null,
            'package_sha256' => (string) $meta['package_sha256'],
            'dump_filename' => $dumpFilename,
            'dump_sha256' => $dumpSha,
            'dump_bytes' => $dumpBytes,
            'restore_path' => trim($restorePath),
            'backup_process_result' => (string) $meta['backup_process_result'],
            'content_receipt_id' => is_string($meta['content_receipt_id'] ?? null) ? $meta['content_receipt_id'] : null,
            'meta_sha256' => $this->signer->documentSha256($meta),
            'signature' => (string) $meta[MasterDataProofSignerV2::SIGNATURE_FIELD],
        ];
    }

    private function assertGate(string $realDir): void
    {
        $gatePath = $realDir.DIRECTORY_SEPARATOR.self::GATE_FILE;
        if (! is_file($gatePath) || ! is_readable($gatePath)) {
            throw new MasterDataTransferException(
                'Backup-Proof ungültig: '.self::GATE_FILE.' fehlt oder ist nicht lesbar.',
            );
        }

        $gateRaw = @file_get_contents($gatePath);
        if ($gateRaw === false) {
            throw new MasterDataTransferException(
                'Backup-Proof ungültig: '.self::GATE_FILE.' konnte nicht gelesen werden.',
            );
        }

        $gateLine = '';
        foreach (preg_split("/\R/u", $gateRaw) ?: [] as $line) {
            $trimmed = trim((string) $line);
            if ($trimmed !== '') {
                $gateLine = $trimmed;
                break;
            }
        }
        if ($gateLine !== 'PASS') {
            throw new MasterDataTransferException(
                'Backup-Proof ungültig: '.self::GATE_FILE.' muss PASS zeigen (gefunden: '
                .($gateLine === '' ? '(leer)' : mb_substr($gateLine, 0, 64)).').',
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function readMeta(string $realDir): array
    {
        $metaPath = $realDir.DIRECTORY_SEPARATOR.self::META_FILE;
        if (! is_file($metaPath) || ! is_readable($metaPath)) {
            throw new MasterDataTransferException(
                'Backup-Proof ungültig: '.self::META_FILE.' fehlt oder ist nicht lesbar.',
            );
        }

        $metaRaw = @file_get_contents($metaPath);
        if ($metaRaw === false || $metaRaw === '') {
            throw new MasterDataTransferException(
                'Backup-Proof ungültig: '.self::META_FILE.' ist leer oder nicht lesbar.',
            );
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($metaRaw, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new MasterDataTransferException(
                'Backup-Proof ungültig: '.self::META_FILE.' ist kein gültiges JSON.',
            );
        }

        if (! is_array($decoded) || array_is_list($decoded)) {
            throw new MasterDataTransferException(
                'Backup-Proof ungültig: '.self::META_FILE.' muss ein JSON-Objekt sein.',
            );
        }

        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $meta
     * @param  array{receipt_id: string, signature: string, completed_at: string}|null  $contentReceipt
     */
    private function assertProvenance(
        array $meta,
        string $expectedDatabase,
        string $expectedPackageSha256,
        string $expectedPhase,
        ?array $contentReceipt,
    ): void {
        if (($meta['proof_version'] ?? null) !== self::PROOF_VERSION) {
            throw new MasterDataTransferException(
                'Backup-Proof ungültig: proof_version=2 erforderlich (alter/unvollständiger Proof ohne Provenienz abgelehnt).',
            );
        }
        if (! $this->signer->verify($meta)) {
            throw new MasterDataTransferException(
                'Backup-Proof ungültig: Signatur fehlt oder stimmt nicht (Metadaten verändert oder nicht prozessgeneriert).',
            );
        }

        foreach (['backup_run_id', 'phase', 'created_at', 'database_name', 'target_environment', 'package_sha256', 'backup_process_result'] as $field) {
            if (! is_string($meta[$field] ?? null) || trim((string) $meta[$field]) === '') {
                throw new MasterDataTransferException(
                    'Backup-Proof ungültig: Provenienzfeld '.$field.' fehlt oder ist leer.',
                );
            }
        }

        if (($meta['database_name_source'] ?? null) !== 'select_database') {
            throw new MasterDataTransferException(
                'Backup-Proof ungültig: database_name_source muss select_database sein.',
            );
        }
        if ($meta['backup_process_result'] !== 'PASS') {
            throw new MasterDataTransferException(
                'Backup-Proof ungültig: backup_process_result muss PASS sein.',
            );
        }

        if ($meta['database_name'] !== $expectedDatabase) {
            throw new MasterDataTransferException(sprintf(
                'Backup-Proof ungültig: Proof gehört zu DB "%s", erwartet "%s".',
                $meta['database_name'],
                $expectedDatabase,
            ));
        }

        $env = app()->environment();
        if ($meta['target_environment'] !== $env) {
            throw new MasterDataTransferException(sprintf(
                'Backup-Proof ungültig: Zielumgebung "%s" passt nicht zur aktiven Umgebung "%s".',
                $meta['target_environment'],
                $env,
            ));
        }

        $configuredInstance = config('dispo.master_data_transfer_instance_id');
        if (is_string($configuredInstance) && trim($configuredInstance) !== '') {
            if (($meta['instance_identity'] ?? null) !== trim($configuredInstance)) {
                throw new MasterDataTransferException(
                    'Backup-Proof ungültig: Instanzidentität weicht von MASTER_DATA_TRANSFER_INSTANCE_ID ab.',
                );
            }
        }

        if (! in_array($meta['phase'], self::PHASES, true) || $meta['phase'] !== $expectedPhase) {
            throw new MasterDataTransferException(sprintf(
                'Backup-Proof ungültig: Phase "%s" passt nicht zur erwarteten Phase "%s".',
                (string) $meta['phase'],
                $expectedPhase,
            ));
        }

        if (strtolower((string) $meta['package_sha256']) !== strtolower($expectedPackageSha256)) {
            throw new MasterDataTransferException(
                'Backup-Proof ungültig: Proof ist an ein anderes Paket (SHA256) gebunden.',
            );
        }

        $createdAt = $this->parseTimestamp((string) $meta['created_at'], 'created_at');
        $now = CarbonImmutable::now();
        if ($createdAt->greaterThan($now->addSeconds(self::CLOCK_SKEW_SECONDS))) {
            throw new MasterDataTransferException(
                'Backup-Proof ungültig: created_at liegt in der Zukunft.',
            );
        }
        $maxAge = (int) config('dispo.master_data_transfer_backup_proof_max_age_minutes', 1440);
        if ($maxAge > 0 && $createdAt->lessThan($now->subMinutes($maxAge))) {
            throw new MasterDataTransferException(sprintf(
                'Backup-Proof ungültig: Proof ist älter als %d Minuten (created_at=%s).',
                $maxAge,
                $createdAt->toIso8601String(),
            ));
        }

        if ($expectedPhase === self::PHASE_PRE_CONTENT_APPLY) {
            if (($meta['content_receipt_id'] ?? null) !== null) {
                throw new MasterDataTransferException(
                    'Backup-Proof ungültig: pre_content_apply darf keine Content-Receipt-Bindung tragen.',
                );
            }

            return;
        }

        // post_content_apply (Backup B)
        if ($contentReceipt === null) {
            throw new MasterDataTransferException(
                'Backup-Proof (post_content_apply) erfordert ein verifiziertes Content-Apply-Receipt zur Bindung.',
            );
        }
        if (($meta['content_receipt_id'] ?? null) !== $contentReceipt['receipt_id']) {
            throw new MasterDataTransferException(
                'Backup-Proof ungültig: Proof ist nicht an dieses Content-Apply-Receipt gebunden.',
            );
        }
        if (($meta['content_receipt_signature'] ?? null) !== $contentReceipt['signature']) {
            throw new MasterDataTransferException(
                'Backup-Proof ungültig: Receipt-Signatur im Proof stimmt nicht mit dem Receipt überein.',
            );
        }
        $completedAt = $this->parseTimestamp($contentReceipt['completed_at'], 'receipt.completed_at');
        if ($createdAt->lessThan($completedAt)) {
            throw new MasterDataTransferException(sprintf(
                'Backup-Proof ungültig: Backup B (created_at=%s) wurde VOR dem Inhalts-Apply (completed_at=%s) erstellt.',
                $createdAt->toIso8601String(),
                $completedAt->toIso8601String(),
            ));
        }
    }

    private function parseTimestamp(string $value, string $field): CarbonImmutable
    {
        try {
            return CarbonImmutable::parse($value);
        } catch (\Throwable) {
            throw new MasterDataTransferException(
                'Backup-Proof ungültig: '.$field.' ist kein gültiger Zeitstempel.',
            );
        }
    }
}
