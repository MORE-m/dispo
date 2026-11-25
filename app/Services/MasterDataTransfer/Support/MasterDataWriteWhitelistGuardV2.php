<?php

namespace App\Services\MasterDataTransfer\Support;

use App\Services\MasterDataTransfer\MasterDataTransferContractV2;
use App\Services\MasterDataTransfer\MasterDataTransferException;
use Illuminate\Support\Facades\DB;

/**
 * Präventiver und laufzeitseitiger Schreib-Guard gegen die geschlossene Whitelist.
 */
final class MasterDataWriteWhitelistGuardV2
{
    private bool $monitoring = false;

    /** @var list<string> */
    private array $observedWrites = [];

    /** @var callable|null */
    private $listener = null;

    /**
     * @param  list<string>  $plannedTables
     */
    public function assertPlannedTablesAllowed(array $plannedTables): void
    {
        $unique = array_values(array_unique($plannedTables));
        sort($unique);
        foreach ($unique as $table) {
            if (! MasterDataTransferContractV2::isWriteWhitelistedTable($table)) {
                throw new MasterDataTransferException(
                    'Whitelist-STOP: geplante Schreiboperation außerhalb der Whitelist: '.$table,
                );
            }
        }
    }

    public function startMonitoring(): void
    {
        $this->observedWrites = [];
        $this->monitoring = true;
        $this->listener = function ($query): void {
            if (! $this->monitoring) {
                return;
            }
            $sql = (string) $query->sql;
            $table = $this->extractWriteTable($sql);
            if ($table === null) {
                return;
            }
            if ($this->isForbiddenStatement($sql)) {
                throw new MasterDataTransferException(
                    'Whitelist-STOP: verbotene Schreib-/Schema-Operation erkannt: '.$this->summarizeSql($sql),
                );
            }
            if (! MasterDataTransferContractV2::isWriteWhitelistedTable($table)) {
                throw new MasterDataTransferException(
                    'Whitelist-STOP: Schreibzugriff außerhalb der Whitelist: '.$table,
                );
            }
            $this->observedWrites[] = $table;
        };
        DB::listen($this->listener);
    }

    public function stopMonitoring(): void
    {
        $this->monitoring = false;
    }

    /**
     * @return list<string>
     */
    public function observedWriteTables(): array
    {
        $tables = array_values(array_unique($this->observedWrites));
        sort($tables);

        return $tables;
    }

    public function assertObservedWritesAllowed(): void
    {
        foreach ($this->observedWriteTables() as $table) {
            if (! MasterDataTransferContractV2::isWriteWhitelistedTable($table)) {
                throw new MasterDataTransferException(
                    'Whitelist-STOP: beobachteter Write außerhalb der Whitelist: '.$table,
                );
            }
        }
    }

    private function isForbiddenStatement(string $sql): bool
    {
        $normalized = strtolower(preg_replace('/\s+/', ' ', $sql) ?? $sql);

        return str_contains($normalized, 'delete ')
            || str_contains($normalized, 'truncate ')
            || str_contains($normalized, 'alter ')
            || str_contains($normalized, 'drop ')
            || str_contains($normalized, 'create table')
            || str_contains($normalized, 'pragma foreign_keys');
    }

    private function extractWriteTable(string $sql): ?string
    {
        $normalized = strtolower(preg_replace('/\s+/', ' ', $sql) ?? $sql);
        if (preg_match('/\binsert\s+into\s+[`"]?([a-z0-9_]+)[`"]?/i', $sql, $m) === 1) {
            return $m[1];
        }
        if (preg_match('/\bupdate\s+[`"]?([a-z0-9_]+)[`"]?/i', $sql, $m) === 1) {
            return $m[1];
        }
        if (preg_match('/\bdelete\s+from\s+[`"]?([a-z0-9_]+)[`"]?/i', $sql, $m) === 1) {
            return $m[1];
        }
        if (str_contains($normalized, 'truncate ')) {
            if (preg_match('/\btruncate\s+(?:table\s+)?[`"]?([a-z0-9_]+)[`"]?/i', $sql, $m) === 1) {
                return $m[1];
            }

            return '__truncate__';
        }
        if (str_contains($normalized, 'alter ')) {
            return '__alter__';
        }

        return null;
    }

    private function summarizeSql(string $sql): string
    {
        $flat = preg_replace('/\s+/', ' ', $sql) ?? $sql;

        return mb_substr($flat, 0, 160);
    }
}
