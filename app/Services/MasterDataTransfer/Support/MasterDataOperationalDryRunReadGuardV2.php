<?php

namespace App\Services\MasterDataTransfer\Support;

use App\Services\MasterDataTransfer\MasterDataTransferException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Slice 6a: datenbankseitige Schreibfreiheit für den operativen V2-Dry-Run.
 *
 * MySQL/MariaDB: START TRANSACTION READ ONLY + REPEATABLE READ.
 * SQLite (nur APP_ENV=testing): PRAGMA query_only=ON.
 * Nesting unter RefreshDatabase (testing): zusätzlicher Write-Block-Listener.
 * Kein Write+Rollback als Ersatz. Session-/Transaktionszustand wird immer zurückgesetzt.
 */
final class MasterDataOperationalDryRunReadGuardV2
{
    private static bool $blockMutations = false;

    private static bool $listenerRegistered = false;

    private bool $active = false;

    private string $driver = '';

    private bool $startedReadOnlyTransaction = false;

    private bool $sqliteQueryOnly = false;

    private bool $usedWriteBlockListener = false;

    public function enter(): void
    {
        if ($this->active) {
            throw new MasterDataTransferException(
                'Operativer V2-Dry-Run: Read-only-Guard ist bereits aktiv.',
            );
        }

        $connection = DB::connection();
        $driver = $connection->getDriverName();
        $this->driver = $driver;

        try {
            if (in_array($driver, ['mysql', 'mariadb'], true)) {
                $this->enterMysqlReadOnly($connection->transactionLevel());
            } elseif ($driver === 'sqlite') {
                $this->enterSqliteQueryOnly();
            } else {
                throw new MasterDataTransferException(
                    'Read-only-Absicherung für Treiber nicht verfügbar: '.$driver,
                );
            }
        } catch (Throwable $e) {
            $this->leaveQuietly();
            if ($e instanceof MasterDataTransferException) {
                throw $e;
            }

            throw new MasterDataTransferException(
                'Read-only-Absicherung konnte nicht aktiviert werden — Abbruch vor Planung.',
                [$e->getMessage()],
            );
        }

        $this->active = true;
    }

    public function leave(): void
    {
        if (! $this->active && ! $this->startedReadOnlyTransaction && ! $this->sqliteQueryOnly && ! $this->usedWriteBlockListener) {
            return;
        }

        $errors = [];

        self::$blockMutations = false;
        $this->usedWriteBlockListener = false;

        try {
            if ($this->startedReadOnlyTransaction) {
                if (DB::transactionLevel() > 0) {
                    DB::rollBack();
                }
                $this->startedReadOnlyTransaction = false;
            }
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }

        try {
            if (in_array($this->driver, ['mysql', 'mariadb'], true)) {
                DB::statement('SET SESSION TRANSACTION READ WRITE');
            }
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }

        try {
            if ($this->sqliteQueryOnly) {
                DB::statement('PRAGMA query_only = OFF');
                $this->sqliteQueryOnly = false;
            }
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }

        $this->active = false;
        $this->driver = '';

        if ($errors !== []) {
            throw new MasterDataTransferException(
                'Read-only-Guard konnte nicht vollständig zurückgesetzt werden.',
                $errors,
            );
        }
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    private function enterMysqlReadOnly(int $transactionLevel): void
    {
        if ($transactionLevel > 0) {
            if (! app()->environment('testing')) {
                throw new MasterDataTransferException(
                    'Operativer V2-Dry-Run: aktive Transaktion vorhanden — READ ONLY nicht sicher herstellbar.',
                );
            }
            $this->enableWriteBlockListener();

            return;
        }

        DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        DB::unprepared('START TRANSACTION READ ONLY');
        $this->startedReadOnlyTransaction = true;
    }

    private function enterSqliteQueryOnly(): void
    {
        if (! app()->environment('testing')) {
            throw new MasterDataTransferException(
                'Operativer V2-Dry-Run auf SQLite außerhalb von Tests nicht erlaubt.',
            );
        }

        DB::statement('PRAGMA query_only = ON');
        $this->sqliteQueryOnly = true;
    }

    private function enableWriteBlockListener(): void
    {
        if (! self::$listenerRegistered) {
            // beforeExecuting greift vor der Ausführung – zuverlässiger als QueryExecuted.
            DB::connection()->beforeExecuting(static function (string $query, array $bindings, $connection): void {
                if (! self::$blockMutations) {
                    return;
                }
                $sql = strtolower(trim($query));
                if (self::sqlLooksMutating($sql)) {
                    throw new MasterDataTransferException(
                        'Dry-Run Schreibversuch blockiert (Read-only-Guard): '.self::summarizeSql($sql),
                    );
                }
            });
            self::$listenerRegistered = true;
        }

        self::$blockMutations = true;
        $this->usedWriteBlockListener = true;
    }

    private function leaveQuietly(): void
    {
        try {
            $this->leave();
        } catch (Throwable) {
            self::$blockMutations = false;
            $this->active = false;
            $this->startedReadOnlyTransaction = false;
            $this->sqliteQueryOnly = false;
            $this->usedWriteBlockListener = false;
            $this->driver = '';
        }
    }

    private static function sqlLooksMutating(string $sql): bool
    {
        foreach ([
            'insert ',
            'update ',
            'delete ',
            'replace ',
            'alter ',
            'drop ',
            'create ',
            'truncate ',
            'rename ',
            'grant ',
            'revoke ',
            'lock tables',
            'unlock tables',
            'call ',
            'load data',
            'handler ',
            'merge ',
        ] as $needle) {
            if (str_contains($sql, $needle)) {
                return true;
            }
        }

        return (bool) preg_match('/^(insert|update|delete|replace|alter|drop|create|truncate|rename)\b/', $sql);
    }

    private static function summarizeSql(string $sql): string
    {
        $flat = preg_replace('/\s+/', ' ', $sql) ?? $sql;

        return mb_substr($flat, 0, 120);
    }
}
