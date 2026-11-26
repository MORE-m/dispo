<?php

namespace App\Services\MasterDataTransfer\Support;

use App\Services\MasterDataTransfer\MasterDataTransferContractV2;
use App\Services\MasterDataTransfer\MasterDataTransferException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Slice 7 (S7-PO-03): persistente, HMAC-signierte Apply-Receipts als Dateien.
 *
 * Ablage: storage/app/master-data-transfer/receipts/ (Verzeichnis 0700, Dateien 0600) —
 * bewusst KEINE DB-Tabelle (Whitelist/Klassifikation unverändert; Receipt entsteht erst NACH Commit,
 * Rollback erzeugt keines).
 *
 * Arten:
 *  - content_apply       (nach erfolgreichem applyOperational-Commit)
 *  - price_status_apply  (nach erfolgreichem Status-Apply-Commit)
 */
final class MasterDataApplyReceiptV2
{
    public const KIND_CONTENT_APPLY = 'content_apply';

    public const KIND_PRICE_STATUS_APPLY = 'price_status_apply';

    public const RECEIPT_VERSION = 1;

    public function __construct(
        private readonly MasterDataProofSignerV2 $signer = new MasterDataProofSignerV2,
    ) {}

    public function receiptDirectory(): string
    {
        $configured = config('dispo.master_data_transfer_receipt_dir');
        if (is_string($configured) && trim($configured) !== '') {
            return rtrim(trim($configured), DIRECTORY_SEPARATOR);
        }

        return storage_path('app'.DIRECTORY_SEPARATOR.'master-data-transfer'.DIRECTORY_SEPARATOR.'receipts');
    }

    /**
     * @param  array{
     *     run_id: string,
     *     package_sha256: string,
     *     database_name: string,
     *     result: string,
     *     report_hash: string,
     *     content_fingerprints_summary: array<string, mixed>,
     *     backup_proof: array{backup_run_id: string, meta_sha256: string, dump_sha256: string, phase: string}
     * }  $data
     * @return array<string, mixed> Receipt inkl. receipt_path
     */
    public function writeContentReceipt(array $data): array
    {
        return $this->write(self::KIND_CONTENT_APPLY, 'cr-', ['content'], $data, [
            'content_receipt_ref' => null,
            'plan_hash' => null,
        ]);
    }

    /**
     * @param  array{
     *     run_id: string,
     *     package_sha256: string,
     *     database_name: string,
     *     result: string,
     *     report_hash: string,
     *     content_fingerprints_summary: array<string, mixed>,
     *     backup_proof: array{backup_run_id: string, meta_sha256: string, dump_sha256: string, phase: string},
     *     plan_hash: string,
     *     content_receipt_ref: array{receipt_id: string, signature: string}
     * }  $data
     * @return array<string, mixed>
     */
    public function writeStatusReceipt(array $data): array
    {
        return $this->write(self::KIND_PRICE_STATUS_APPLY, 'ps-', ['price_status'], $data, [
            'content_receipt_ref' => $data['content_receipt_ref'],
            'plan_hash' => $data['plan_hash'],
        ]);
    }

    /**
     * @return array{
     *     receipt_id: string,
     *     signature: string,
     *     completed_at: string,
     *     package_sha256: string,
     *     database_name: string,
     *     receipt_path: string,
     *     content_fingerprints_summary: array<string, mixed>
     * }
     */
    public function assertValidContentReceipt(string $path, string $expectedPackageSha256, string $expectedDatabase): array
    {
        $receipt = $this->readAndVerify($path, self::KIND_CONTENT_APPLY);

        if (strtolower((string) $receipt['package_sha256']) !== strtolower($expectedPackageSha256)) {
            throw new MasterDataTransferException(
                'Content-Apply-Receipt gehört zu einem anderen Paket (Paket-SHA256 weicht ab).',
            );
        }
        if ($receipt['database_name'] !== $expectedDatabase) {
            throw new MasterDataTransferException(sprintf(
                'Content-Apply-Receipt gehört zu DB "%s", erwartet "%s".',
                (string) $receipt['database_name'],
                $expectedDatabase,
            ));
        }
        if ($receipt['target_environment'] !== app()->environment()) {
            throw new MasterDataTransferException(sprintf(
                'Content-Apply-Receipt: Zielumgebung "%s" passt nicht zur aktiven Umgebung "%s".',
                (string) $receipt['target_environment'],
                app()->environment(),
            ));
        }

        return [
            'receipt_id' => (string) $receipt['receipt_id'],
            'signature' => (string) $receipt['signature'],
            'completed_at' => (string) $receipt['completed_at'],
            'package_sha256' => strtolower((string) $receipt['package_sha256']),
            'database_name' => (string) $receipt['database_name'],
            'receipt_path' => (string) $receipt['receipt_path'],
            'content_fingerprints_summary' => is_array($receipt['content_fingerprints_summary']) ? $receipt['content_fingerprints_summary'] : [],
        ];
    }

    /**
     * @param  list<string>  $scope
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function write(string $kind, string $idPrefix, array $scope, array $data, array $extra): array
    {
        if ($data['result'] !== 'PASS') {
            throw new MasterDataTransferException('Receipt darf nur für erfolgreiche Läufe (result=PASS) geschrieben werden.');
        }

        $receiptId = $idPrefix.Str::uuid()->toString();
        $payload = array_merge([
            'receipt_version' => self::RECEIPT_VERSION,
            'receipt_id' => $receiptId,
            'kind' => $kind,
            'processing_contract_version' => MasterDataTransferContractV2::PROCESSING_CONTRACT_VERSION,
            'schema_version' => MasterDataTransferContractV2::SCHEMA_VERSION,
            'run_id' => $data['run_id'],
            'package_sha256' => strtolower($data['package_sha256']),
            'database_name' => $data['database_name'],
            'target_environment' => app()->environment(),
            'completed_at' => CarbonImmutable::now()->format('Y-m-d\TH:i:s.uP'),
            'scope' => $scope,
            'result' => $data['result'],
            'report_hash' => $data['report_hash'],
            'content_fingerprints_summary' => $data['content_fingerprints_summary'],
            'backup_proof_ref' => $data['backup_proof'],
        ], $extra);
        $payload[MasterDataProofSignerV2::SIGNATURE_FIELD] = $this->signer->sign($payload);

        $dir = $this->receiptDirectory();
        if (! is_dir($dir) && ! @mkdir($dir, 0700, true) && ! is_dir($dir)) {
            throw new MasterDataTransferException('Receipt-Verzeichnis konnte nicht angelegt werden.');
        }
        if (! is_writable($dir)) {
            throw new MasterDataTransferException('Receipt-Verzeichnis ist nicht beschreibbar.');
        }

        $file = $dir.DIRECTORY_SEPARATOR.str_replace('_', '-', $kind).'-'.$receiptId.'.json';
        $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (@file_put_contents($file, $json."\n", LOCK_EX) === false) {
            throw new MasterDataTransferException('Receipt konnte nicht geschrieben werden.');
        }
        @chmod($file, 0600);

        $verify = @file_get_contents($file);
        if ($verify === false || $verify === '') {
            throw new MasterDataTransferException('Receipt-Persistierung fehlgeschlagen (Datei leer oder nicht lesbar).');
        }

        $payload['receipt_path'] = $file;

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function readAndVerify(string $path, string $expectedKind): array
    {
        $path = trim($path);
        if ($path === '') {
            throw new MasterDataTransferException(
                '--require-content-apply-receipt ist Pflicht und muss auf eine Receipt-Datei zeigen.',
            );
        }
        $real = realpath($path);
        if ($real === false || ! is_file($real) || ! is_readable($real)) {
            throw new MasterDataTransferException('Apply-Receipt ungültig: Datei fehlt oder ist nicht lesbar.');
        }

        $raw = @file_get_contents($real);
        if ($raw === false || $raw === '') {
            throw new MasterDataTransferException('Apply-Receipt ungültig: Datei ist leer.');
        }
        try {
            /** @var mixed $decoded */
            $decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new MasterDataTransferException('Apply-Receipt ungültig: kein gültiges JSON.');
        }
        if (! is_array($decoded) || array_is_list($decoded)) {
            throw new MasterDataTransferException('Apply-Receipt ungültig: JSON-Objekt erwartet.');
        }

        if (! $this->signer->verify($decoded)) {
            throw new MasterDataTransferException(
                'Apply-Receipt ungültig: Signatur fehlt oder stimmt nicht (Receipt verändert oder nicht prozessgeneriert).',
            );
        }
        if (($decoded['receipt_version'] ?? null) !== self::RECEIPT_VERSION) {
            throw new MasterDataTransferException('Apply-Receipt ungültig: receipt_version nicht unterstützt.');
        }
        if (($decoded['kind'] ?? null) !== $expectedKind) {
            throw new MasterDataTransferException(sprintf(
                'Apply-Receipt ungültig: Art "%s" erwartet, gefunden "%s".',
                $expectedKind,
                is_string($decoded['kind'] ?? null) ? $decoded['kind'] : '(leer)',
            ));
        }
        if (($decoded['processing_contract_version'] ?? null) !== MasterDataTransferContractV2::PROCESSING_CONTRACT_VERSION
            || ($decoded['schema_version'] ?? null) !== MasterDataTransferContractV2::SCHEMA_VERSION) {
            throw new MasterDataTransferException('Apply-Receipt ungültig: Vertrags-/Schemaversion passt nicht.');
        }
        if (($decoded['result'] ?? null) !== 'PASS') {
            throw new MasterDataTransferException('Apply-Receipt ungültig: result ist nicht PASS.');
        }
        foreach (['receipt_id', 'package_sha256', 'database_name', 'target_environment', 'completed_at', 'run_id', 'report_hash'] as $field) {
            if (! is_string($decoded[$field] ?? null) || trim((string) $decoded[$field]) === '') {
                throw new MasterDataTransferException('Apply-Receipt ungültig: Feld '.$field.' fehlt.');
            }
        }
        try {
            CarbonImmutable::parse((string) $decoded['completed_at']);
        } catch (\Throwable) {
            throw new MasterDataTransferException('Apply-Receipt ungültig: completed_at ist kein Zeitstempel.');
        }

        $decoded['receipt_path'] = $real;

        return $decoded;
    }
}
