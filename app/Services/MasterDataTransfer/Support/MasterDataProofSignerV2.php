<?php

namespace App\Services\MasterDataTransfer\Support;

use App\Services\MasterDataTransfer\MasterDataTransferException;

/**
 * Slice 6b-H / 7: kanonische HMAC-Signatur für Backup-Proofs und Apply-Receipts.
 *
 * Geheimnis: config('dispo.master_data_transfer_proof_secret'); leer → aus APP_KEY abgeleitet
 * (HMAC-Domain-Separation, kein Klartext-APP_KEY im Proof). Schutz gegen freie/versehentliche
 * Edits an Metadaten — nicht gegen einen kompromittierten Host (dort liegt auch der Schlüssel).
 */
final class MasterDataProofSignerV2
{
    public const SIGNATURE_FIELD = 'signature';

    private const DOMAIN = 'dispo-master-data-transfer-proof-v1';

    /**
     * @param  array<string, mixed>  $payload  ohne Signaturfeld
     */
    public function sign(array $payload): string
    {
        unset($payload[self::SIGNATURE_FIELD]);

        return hash_hmac('sha256', self::canonicalJson($payload), $this->secret());
    }

    /**
     * @param  array<string, mixed>  $payloadWithSignature
     */
    public function verify(array $payloadWithSignature): bool
    {
        $given = $payloadWithSignature[self::SIGNATURE_FIELD] ?? null;
        if (! is_string($given) || preg_match('/^[a-f0-9]{64}$/', $given) !== 1) {
            return false;
        }

        return hash_equals($this->sign($payloadWithSignature), $given);
    }

    /**
     * Stabile Referenz auf ein signiertes Dokument (inkl. Signatur).
     *
     * @param  array<string, mixed>  $payloadWithSignature
     */
    public function documentSha256(array $payloadWithSignature): string
    {
        return hash('sha256', self::canonicalJson($payloadWithSignature));
    }

    /**
     * @param  array<mixed>  $payload
     */
    public static function canonicalJson(array $payload): string
    {
        return json_encode(
            self::canonicalize($payload),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }

    private static function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(self::canonicalize(...), $value);
        }
        ksort($value);
        foreach ($value as $key => $item) {
            $value[$key] = self::canonicalize($item);
        }

        return $value;
    }

    private function secret(): string
    {
        $configured = config('dispo.master_data_transfer_proof_secret');
        if (is_string($configured) && trim($configured) !== '') {
            return trim($configured);
        }

        $appKey = (string) config('app.key');
        if ($appKey === '') {
            throw new MasterDataTransferException(
                'Proof-Signatur nicht möglich: weder MASTER_DATA_TRANSFER_PROOF_SECRET noch APP_KEY gesetzt.',
            );
        }

        return hash_hmac('sha256', self::DOMAIN, $appKey);
    }
}
