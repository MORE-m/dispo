<?php

namespace App\Services\MasterDataTransfer\Support;

use App\Services\MasterDataTransfer\MasterDataTransferContractV2;
use App\Services\MasterDataTransfer\MasterDataTransferException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Kanonischer Schutz-Snapshot über alle ausgeschlossenen Geschäfts-/Techniktabellen.
 *
 * Speichert nur Digests und Counts – keine Rohdaten/PII in Reports.
 */
final class MasterDataProtectedStateSnapshotV2
{
    /** @var list<string> Technische Spalten ohne fachliche Relevanz für den Drift-Vergleich. */
    private const IGNORED_COLUMNS = [
        'created_at',
        'updated_at',
        'deleted_at',
        'email_verified_at',
        'remember_token',
        'two_factor_confirmed_at',
    ];

    /**
     * @return array{
     *     digest: string,
     *     tables: array<string, array{count: int, digest: string}>,
     *     table_count: int
     * }
     */
    public function capture(): array
    {
        $tables = [];
        $missing = [];

        foreach (MasterDataTransferContractV2::EXCLUDED_TABLES as $table) {
            if (! Schema::hasTable($table)) {
                $missing[] = $table;

                continue;
            }
            $tables[$table] = $this->captureTable($table);
        }

        if ($missing !== []) {
            sort($missing);
            throw new MasterDataTransferException(
                'Schutz-Snapshot fail-closed: Schutztabelle(n) nicht prüfbar/fehlend: '.implode(', ', $missing),
            );
        }

        ksort($tables);
        $digest = hash('sha256', json_encode($tables, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

        return [
            'digest' => $digest,
            'tables' => $tables,
            'table_count' => count($tables),
        ];
    }

    /**
     * @param  array{digest: string, tables: array<string, array{count: int, digest: string}>, table_count: int}  $before
     * @param  array{digest: string, tables: array<string, array{count: int, digest: string}>, table_count: int}  $after
     * @return list<string> Konfliktmeldungen (leer = identisch)
     */
    public function diff(array $before, array $after): array
    {
        $conflicts = [];
        if ($before['digest'] === $after['digest'] && $before['tables'] === $after['tables']) {
            return [];
        }

        $allTables = array_unique(array_merge(array_keys($before['tables']), array_keys($after['tables'])));
        sort($allTables);
        foreach ($allTables as $table) {
            $left = $before['tables'][$table] ?? null;
            $right = $after['tables'][$table] ?? null;
            if ($left === null || $right === null) {
                $conflicts[] = 'Schutz-Snapshot Drift: Tabelle fehlt im Vergleich: '.$table;

                continue;
            }
            if ($left['digest'] !== $right['digest'] || $left['count'] !== $right['count']) {
                $conflicts[] = sprintf(
                    'Schutz-Snapshot Drift in %s (count %d→%d)',
                    $table,
                    $left['count'],
                    $right['count'],
                );
            }
        }

        if ($conflicts === []) {
            $conflicts[] = 'Schutz-Snapshot Drift: Gesamtdigest abweichend.';
        }

        return $conflicts;
    }

    /**
     * @return array{count: int, digest: string}
     */
    private function captureTable(string $table): array
    {
        $columns = Schema::getColumnListing($table);
        if ($columns === []) {
            throw new MasterDataTransferException('Schutz-Snapshot: keine Spalten für '.$table);
        }

        $payloadColumns = array_values(array_filter(
            $columns,
            fn (string $col): bool => ! in_array($col, self::IGNORED_COLUMNS, true),
        ));
        sort($payloadColumns);

        $orderColumn = in_array('id', $columns, true) ? 'id' : $payloadColumns[0];
        $rows = DB::table($table)->orderBy($orderColumn)->get($payloadColumns);
        $normalized = [];
        foreach ($rows as $row) {
            $assoc = [];
            foreach ($payloadColumns as $col) {
                $value = $row->{$col} ?? null;
                $assoc[$col] = $this->canonicalizeValue($value);
            }
            $normalized[] = $assoc;
        }

        return [
            'count' => count($normalized),
            'digest' => hash('sha256', json_encode($normalized, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)),
        ];
    }

    private function canonicalizeValue(mixed $value): mixed
    {
        if (is_float($value)) {
            $formatted = sprintf('%.16F', $value);
            $trimmed = rtrim(rtrim($formatted, '0'), '.');

            return $trimmed === '-0' ? '0' : $trimmed;
        }

        if (is_string($value) && $this->looksLikeJson($value)) {
            try {
                $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
                if (is_array($decoded)) {
                    return $this->canonicalizeArray($decoded);
                }
            } catch (\JsonException) {
                return $value;
            }
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>|list<mixed>  $payload
     * @return array<string, mixed>|list<mixed>
     */
    private function canonicalizeArray(array $payload): array
    {
        if (! array_is_list($payload)) {
            ksort($payload);
        }
        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $payload[$key] = $this->canonicalizeArray($value);
            } else {
                $payload[$key] = $this->canonicalizeValue($value);
            }
        }

        return $payload;
    }

    private function looksLikeJson(string $value): bool
    {
        $trim = ltrim($value);

        return $trim !== '' && ($trim[0] === '{' || $trim[0] === '[');
    }
}
