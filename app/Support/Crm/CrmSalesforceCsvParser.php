<?php

namespace App\Support\Crm;

use App\Enums\CrmAccountType;
use Illuminate\Validation\ValidationException;

/**
 * Semikolon-CSV (UTF-8, optional BOM) für Salesforce-Account-Export.
 */
final class CrmSalesforceCsvParser
{
    public const array REQUIRED_HEADERS = [
        'Accountname',
        'Meridian-ID',
        'Account-ID',
        'Rechnungs-E-Mail',
        'Account-Datensatztyp',
    ];

    /**
     * @return array{
     *     rows: list<array{
     *         line: int,
     *         name: string,
     *         meridian_raw: string,
     *         salesforce_raw: string,
     *         billing_email_raw: string,
     *         record_type_raw: string,
     *         type: ?CrmAccountType,
     *         salesforce: ?array{raw: string, canonical: string},
     *         email_analysis: array<string, mixed>,
     *         errors: list<string>,
     *         warnings: list<string>
     *     }>,
     *     header_ok: bool,
     *     errors: list<string>
     * }
     */
    public function parse(string $binary): array
    {
        if (str_starts_with($binary, "\xEF\xBB\xBF")) {
            $binary = substr($binary, 3);
        }

        if ($binary === '') {
            throw ValidationException::withMessages([
                'file' => 'Die CSV-Datei ist leer.',
            ]);
        }

        $handle = fopen('php://memory', 'r+b');
        if ($handle === false) {
            throw ValidationException::withMessages([
                'file' => 'Die CSV-Datei konnte nicht gelesen werden.',
            ]);
        }
        fwrite($handle, $binary);
        rewind($handle);

        $header = fgetcsv($handle, 0, ';', '"', '\\');
        if ($header === false) {
            fclose($handle);
            throw ValidationException::withMessages([
                'file' => 'CSV-Kopfzeile fehlt.',
            ]);
        }

        $header = array_map(static fn ($h) => trim((string) $h), $header);
        $globalErrors = [];
        foreach (self::REQUIRED_HEADERS as $required) {
            if (! in_array($required, $header, true)) {
                $globalErrors[] = "Spalte „{$required}“ fehlt.";
            }
        }
        if ($globalErrors !== []) {
            fclose($handle);

            return ['rows' => [], 'header_ok' => false, 'errors' => $globalErrors];
        }

        $index = array_flip($header);
        $rows = [];
        $line = 1;
        while (($data = fgetcsv($handle, 0, ';', '"', '\\')) !== false) {
            $line++;
            if ($this->rowEmpty($data)) {
                continue;
            }

            $name = trim((string) ($data[$index['Accountname']] ?? ''));
            $meridianRaw = trim((string) ($data[$index['Meridian-ID']] ?? ''));
            $sfRaw = trim((string) ($data[$index['Account-ID']] ?? ''));
            $emailRaw = trim((string) ($data[$index['Rechnungs-E-Mail']] ?? ''));
            $typeRaw = trim((string) ($data[$index['Account-Datensatztyp']] ?? ''));

            $errors = [];
            $warnings = [];

            if ($name === '') {
                $errors[] = 'Accountname fehlt.';
            }
            if ($sfRaw === '') {
                $errors[] = 'Account-ID fehlt.';
            }

            $type = CrmAccountType::fromExportRecordType($typeRaw);
            if ($type === null) {
                $errors[] = $typeRaw === ''
                    ? 'Account-Datensatztyp fehlt.'
                    : "Unbekannter Account-Datensatztyp „{$typeRaw}“.";
            }

            $salesforce = $sfRaw === '' ? null : SalesforceAccountId::normalize($sfRaw);
            if ($sfRaw !== '' && $salesforce === null) {
                $errors[] = "Ungültige Salesforce-Account-ID „{$sfRaw}“.";
            }

            $emailAnalysis = CrmDomainNormalizer::analyzeEmailField($emailRaw);
            if ($emailAnalysis['invalid'] !== []) {
                $warnings[] = 'Ungültige E-Mail-Anteile: '.implode(', ', $emailAnalysis['invalid']);
            }
            if ($emailAnalysis['divergent']) {
                $warnings[] = 'Mehrere unterschiedliche Domains in Rechnungs-E-Mail – kein Auto-Match.';
            }

            $rows[] = [
                'line' => $line,
                'name' => $name,
                'meridian_raw' => $meridianRaw,
                'salesforce_raw' => $sfRaw,
                'billing_email_raw' => $emailRaw,
                'record_type_raw' => $typeRaw,
                'type' => $type,
                'salesforce' => $salesforce,
                'email_analysis' => $emailAnalysis,
                'errors' => $errors,
                'warnings' => $warnings,
            ];
        }

        fclose($handle);

        return ['rows' => $rows, 'header_ok' => true, 'errors' => []];
    }

    /**
     * @param  list<string|null>  $data
     */
    private function rowEmpty(array $data): bool
    {
        foreach ($data as $cell) {
            if (trim((string) $cell) !== '') {
                return false;
            }
        }

        return true;
    }
}
