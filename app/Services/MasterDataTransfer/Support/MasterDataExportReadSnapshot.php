<?php

namespace App\Services\MasterDataTransfer\Support;

use Illuminate\Support\Facades\DB;
use PDO;
use Throwable;

/**
 * Konsistenter read-only Snapshot für den Master-Data-Exporter 2.0.0.
 *
 * - MySQL/MariaDB: REPEATABLE READ für die folgende Transaktion
 * - SQLite: Verbindungs-Transaktion als Snapshot-Grenze
 * - PDO STRINGIFY_FETCHES: Dezimalwerte als String, kein Driver-Float
 * - nested (z. B. RefreshDatabase): bestehende Transaktion wiederverwenden
 * - immer Rollback der eigenen Transaktion (keine Schreib-Commits)
 */
final class MasterDataExportReadSnapshot
{
    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function run(callable $callback): mixed
    {
        $connection = DB::connection();
        $pdo = $connection->getPdo();
        $previousStringify = $pdo->getAttribute(PDO::ATTR_STRINGIFY_FETCHES);
        $pdo->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, true);

        try {
            if ($connection->transactionLevel() > 0) {
                return $callback();
            }

            $driver = $connection->getDriverName();
            if (in_array($driver, ['mysql', 'mariadb'], true)) {
                $connection->statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            }

            $connection->beginTransaction();
            try {
                $result = $callback();
                $connection->rollBack();

                return $result;
            } catch (Throwable $e) {
                if ($connection->transactionLevel() > 0) {
                    $connection->rollBack();
                }

                throw $e;
            }
        } finally {
            $pdo->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, $previousStringify);
        }
    }
}
