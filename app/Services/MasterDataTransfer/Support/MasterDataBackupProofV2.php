<?php

namespace App\Services\MasterDataTransfer\Support;

use App\Services\MasterDataTransfer\MasterDataTransferException;

/**
 * Slice 6b: Verifizierter Pre-Apply-Backup-Nachweis.
 *
 * Verbindliches Verzeichnisformat (CLI-21 / Runbook §4):
 *
 *   <proof-dir>/
 *     BACKUP_GATE.txt   → erste nicht-leere Zeile exakt "PASS"
 *     DUMP_META.json    → Metadaten zum Dump
 *     <dump_filename>   → Dump-Datei, SHA256 + Größe müssen passen
 *
 * DUMP_META.json Pflichtfelder:
 * - dump_filename (nicht leer, relativ zum Proof-Verzeichnis, kein Path-Traversal)
 * - dump_sha256 (64 Hex)
 * - dump_bytes (positive Ganzzahl)
 * - restore_path (nicht leer; dokumentierter Restore-Weg)
 */
final class MasterDataBackupProofV2
{
    public const GATE_FILE = 'BACKUP_GATE.txt';

    public const META_FILE = 'DUMP_META.json';

    /**
     * @return array{
     *     proof_dir: string,
     *     gate: string,
     *     dump_filename: string,
     *     dump_sha256: string,
     *     dump_bytes: int,
     *     restore_path: string
     * }
     */
    public function assertValid(string $requireBackupProof): array
    {
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

        if (! is_array($decoded)) {
            throw new MasterDataTransferException(
                'Backup-Proof ungültig: '.self::META_FILE.' muss ein JSON-Objekt sein.',
            );
        }

        $dumpFilename = $decoded['dump_filename'] ?? null;
        $dumpSha = $decoded['dump_sha256'] ?? null;
        $dumpBytes = $decoded['dump_bytes'] ?? null;
        $restorePath = $decoded['restore_path'] ?? null;

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
            throw new MasterDataTransferException(
                'Backup-Proof ungültig: dump_sha256 fehlt.',
            );
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
        $restorePath = trim($restorePath);

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

        return [
            'proof_dir' => $realDir,
            'gate' => 'PASS',
            'dump_filename' => $dumpFilename,
            'dump_sha256' => $dumpSha,
            'dump_bytes' => $dumpBytes,
            'restore_path' => $restorePath,
        ];
    }
}
