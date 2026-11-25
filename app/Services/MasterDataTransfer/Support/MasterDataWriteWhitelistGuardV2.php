<?php

namespace App\Services\MasterDataTransfer\Support;

use App\Services\MasterDataTransfer\MasterDataTransferContractV2;
use App\Services\MasterDataTransfer\MasterDataTransferException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use ReflectionProperty;

/**
 * Präventiver und laufzeitseitiger Schreib-Guard gegen die geschlossene Whitelist.
 */
final class MasterDataWriteWhitelistGuardV2
{
    private bool $monitoring = false;

    /** @var list<string> */
    private array $observedWrites = [];

    /** @var (\Closure(QueryExecuted): void)|null */
    private $listener = null;

    /**
     * @param  list<mixed>  $plannedWrites
     */
    public function assertPlannedWritesAllowed(array $plannedWrites): void
    {
        foreach ($plannedWrites as $write) {
            if (! $write instanceof MasterDataPlannedWriteV2) {
                throw new MasterDataTransferException(
                    'Whitelist-STOP: ungeprüfte Operation im Plan (kein PlannedWrite).',
                );
            }
            foreach ($write->tables as $table) {
                if (! MasterDataTransferContractV2::isWriteWhitelistedTable($table)) {
                    throw new MasterDataTransferException(
                        'Whitelist-STOP: geplante Schreiboperation außerhalb der Whitelist: '.$table,
                    );
                }
            }
        }
    }

    /**
     * Klassifiziert und prüft eine SQL-Anweisung (für Tests und Runtime).
     */
    public function assertSqlAllowed(string $sql): void
    {
        $classification = $this->classifySql($sql);
        if ($classification['kind'] === 'read' || $classification['kind'] === 'transaction') {
            return;
        }

        if ($classification['kind'] === 'forbidden' || $classification['kind'] === 'unknown_mutating') {
            throw new MasterDataTransferException(
                'Whitelist-STOP: verbotene Schreib-/Schema-Operation erkannt: '.$this->summarizeSql($sql),
            );
        }

        // kind === write
        $table = $classification['table'];
        if ($table === null || $table === '') {
            throw new MasterDataTransferException(
                'Whitelist-STOP: mutierende SQL ohne auflösbare Zieltabelle: '.$this->summarizeSql($sql),
            );
        }
        if (! MasterDataTransferContractV2::isWriteWhitelistedTable($table)) {
            throw new MasterDataTransferException(
                'Whitelist-STOP: Schreibzugriff außerhalb der Whitelist: '.$table,
            );
        }
    }

    public function startMonitoring(): void
    {
        $this->observedWrites = [];
        $this->monitoring = true;

        if ($this->listener !== null) {
            return;
        }

        $this->listener = function (QueryExecuted $query): void {
            if (! $this->monitoring) {
                return;
            }
            $sql = (string) $query->sql;
            $classification = $this->classifySql($sql);
            if ($classification['kind'] === 'read' || $classification['kind'] === 'transaction') {
                return;
            }
            if ($classification['kind'] === 'forbidden' || $classification['kind'] === 'unknown_mutating') {
                throw new MasterDataTransferException(
                    'Whitelist-STOP: verbotene Schreib-/Schema-Operation erkannt: '.$this->summarizeSql($sql),
                );
            }
            $table = $classification['table'];
            if ($table === null || $table === '') {
                throw new MasterDataTransferException(
                    'Whitelist-STOP: mutierende SQL ohne auflösbare Zieltabelle: '.$this->summarizeSql($sql),
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
        $this->observedWrites = [];
        $this->unregisterOwnListener();
    }

    public function isMonitoring(): bool
    {
        return $this->monitoring;
    }

    /**
     * Anzahl der von diesem Guard registrierten QueryExecuted-Listener (Test-/Diagnosehilfe).
     */
    public function registeredOwnListenerCount(): int
    {
        if ($this->listener === null) {
            return 0;
        }

        $dispatcher = DB::connection()->getEventDispatcher();
        if ($dispatcher === null) {
            return 0;
        }

        $ref = new ReflectionProperty($dispatcher, 'listeners');
        /** @var array<string, list<mixed>> $listeners */
        $listeners = $ref->getValue($dispatcher);
        $eventListeners = $listeners[QueryExecuted::class] ?? [];

        $count = 0;
        foreach ($eventListeners as $listener) {
            if ($listener === $this->listener) {
                $count++;
            }
        }

        return $count;
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

    /**
     * @return array{kind: 'read'|'transaction'|'write'|'forbidden'|'unknown_mutating', table: ?string}
     */
    public function classifySql(string $sql): array
    {
        $normalized = strtolower(trim(preg_replace('/\s+/', ' ', $sql) ?? $sql));
        if ($normalized === '') {
            return ['kind' => 'read', 'table' => null];
        }

        // Transaktionssteuerung
        if (preg_match('/^(begin(\s+transaction)?|start\s+transaction|commit|rollback|savepoint\b|release\s+savepoint)\b/', $normalized) === 1) {
            return ['kind' => 'transaction', 'table' => null];
        }

        // FK-Deaktivierung / Schema-Pragmas
        if (str_contains($normalized, 'foreign_key_checks')
            || str_contains($normalized, 'pragma foreign_keys')
            || str_contains($normalized, 'pragma writable_schema')
            || preg_match('/\bset\s+.*foreign_key/i', $normalized) === 1) {
            return ['kind' => 'forbidden', 'table' => null];
        }

        // Schema / destruktiv
        if (preg_match('/^(delete|truncate|alter|drop|create\s+table|create\s+index|create\s+unique|rename\s+table|replace\s+into)\b/', $normalized) === 1
            || str_contains($normalized, ' drop table')
            || str_contains($normalized, ' drop database')) {
            return ['kind' => 'forbidden', 'table' => $this->extractTableFromMutatingSql($sql, $normalized)];
        }

        if (preg_match('/^insert\s+into\b/', $normalized) === 1) {
            return ['kind' => 'write', 'table' => $this->extractTableFromMutatingSql($sql, $normalized)];
        }

        if (preg_match('/^update\b/', $normalized) === 1) {
            return ['kind' => 'write', 'table' => $this->extractTableFromMutatingSql($sql, $normalized)];
        }

        // Lesend
        if (preg_match('/^(select|show|explain|describe|desc|pragma)\b/', $normalized) === 1) {
            return ['kind' => 'read', 'table' => null];
        }

        // Unbekannt und potenziell mutierend → fail-closed
        if ($this->looksPotentiallyMutating($normalized)) {
            return ['kind' => 'unknown_mutating', 'table' => null];
        }

        return ['kind' => 'read', 'table' => null];
    }

    private function looksPotentiallyMutating(string $normalized): bool
    {
        foreach (['insert ', 'update ', 'delete ', 'replace ', 'truncate ', 'alter ', 'drop ', 'create ', 'grant ', 'revoke ', 'call ', 'exec ', 'execute ', 'load ', 'copy ', 'merge ', 'upsert '] as $token) {
            if (str_contains($normalized, $token)) {
                return true;
            }
        }

        return false;
    }

    private function extractTableFromMutatingSql(string $sql, string $normalized): ?string
    {
        if (preg_match('/\binsert\s+into\s+[`"\[]?([a-zA-Z0-9_]+)[`"\]]?/i', $sql, $m) === 1) {
            return strtolower($m[1]);
        }
        if (preg_match('/\bupdate\s+[`"\[]?([a-zA-Z0-9_]+)[`"\]]?/i', $sql, $m) === 1) {
            return strtolower($m[1]);
        }
        if (preg_match('/\bdelete\s+from\s+[`"\[]?([a-zA-Z0-9_]+)[`"\]]?/i', $sql, $m) === 1) {
            return strtolower($m[1]);
        }
        if (preg_match('/\btruncate\s+(?:table\s+)?[`"\[]?([a-zA-Z0-9_]+)[`"\]]?/i', $sql, $m) === 1) {
            return strtolower($m[1]);
        }
        if (preg_match('/\b(?:alter|drop)\s+table\s+[`"\[]?([a-zA-Z0-9_]+)[`"\]]?/i', $sql, $m) === 1) {
            return strtolower($m[1]);
        }
        if (preg_match('/\bcreate\s+table\s+(?:if\s+not\s+exists\s+)?[`"\[]?([a-zA-Z0-9_]+)[`"\]]?/i', $sql, $m) === 1) {
            return strtolower($m[1]);
        }
        if (str_contains($normalized, 'truncate ')) {
            return '__truncate__';
        }
        if (str_contains($normalized, 'alter ')) {
            return '__alter__';
        }

        return null;
    }

    private function unregisterOwnListener(): void
    {
        if ($this->listener === null) {
            return;
        }

        $dispatcher = DB::connection()->getEventDispatcher();
        if ($dispatcher !== null) {
            $ref = new ReflectionProperty($dispatcher, 'listeners');
            /** @var array<string, list<mixed>> $listeners */
            $listeners = $ref->getValue($dispatcher);
            $event = QueryExecuted::class;
            if (isset($listeners[$event])) {
                $listeners[$event] = array_values(array_filter(
                    $listeners[$event],
                    fn (mixed $listener): bool => $listener !== $this->listener,
                ));
                if ($listeners[$event] === []) {
                    unset($listeners[$event]);
                }
                $ref->setValue($dispatcher, $listeners);
            }
        }

        $this->listener = null;
    }

    private function summarizeSql(string $sql): string
    {
        $flat = preg_replace('/\s+/', ' ', $sql) ?? $sql;

        return mb_substr($flat, 0, 160);
    }
}
