<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Verhindert destruktive Pest/PHPUnit-MySQL-Läufe gegen die Entwicklungsdatenbank.
 *
 * Erlaubt:
 * - SQLite (z. B. :memory: oder Datei-DB in Tests)
 * - MySQL ausschließlich mit Datenbankname `dispo_test`
 */
final class MysqlTestDatabaseGuard
{
    public const ALLOWED_MYSQL_DATABASE = 'dispo_test';

    public const FORBIDDEN_DEV_DATABASE = 'dispo';

    /**
     * Vor RefreshDatabase / DatabaseMigrations in Tests\TestCase::setUpTraits().
     */
    public static function assertSafeBeforeDestructiveTraits(): void
    {
        $connection = (string) config('database.default');
        $driver = (string) config("database.connections.{$connection}.driver");

        if ($driver === 'sqlite') {
            return;
        }

        if ($driver !== 'mysql') {
            throw new RuntimeException(
                'Testdatenbank-Sicherheitsabbruch: Nicht-SQLite-/Nicht-MySQL-Treiber '
                ."„{$driver}“ ist für destruktive Tests nicht freigegeben.",
            );
        }

        self::assertMysqlDatabaseIsDispoTest(
            configuredDatabase: self::configuredMysqlDatabase($connection),
            context: 'destruktive Test-Traits (RefreshDatabase/DatabaseMigrations)',
        );
    }

    /**
     * Nach Bootstrap in MySQL-Parallelworkern, vor Locks/Writes.
     */
    public static function assertSafeBeforeMysqlWorkerMutation(): void
    {
        $connection = (string) config('database.default');
        $driver = (string) config("database.connections.{$connection}.driver");

        if ($driver !== 'mysql') {
            throw new RuntimeException(
                'Testdatenbank-Sicherheitsabbruch: Parallelworker dürfen nur unter MySQL laufen '
                ."(aktueller Treiber: „{$driver}“).",
            );
        }

        self::assertMysqlDatabaseIsDispoTest(
            configuredDatabase: self::configuredMysqlDatabase($connection),
            context: 'MySQL-Parallelworker',
        );
    }

    /**
     * @throws RuntimeException
     */
    public static function assertMysqlDatabaseIsDispoTest(
        ?string $configuredDatabase,
        string $context,
        bool $verifySelectedDatabase = true,
    ): void {
        $normalized = self::normalizeDatabaseName($configuredDatabase);

        if ($normalized === null || $normalized === '') {
            throw new RuntimeException(
                "Testdatenbank-Sicherheitsabbruch ({$context}): MySQL-Datenbankname ist leer oder null. "
                .'Erlaubt ist ausschließlich „'.self::ALLOWED_MYSQL_DATABASE.'“.',
            );
        }

        if ($normalized === self::FORBIDDEN_DEV_DATABASE) {
            throw new RuntimeException(
                "Testdatenbank-Sicherheitsabbruch ({$context}): Entwicklungsdatenbank „"
                .self::FORBIDDEN_DEV_DATABASE.'“ ist für Tests gesperrt. '
                .'Erlaubt ist ausschließlich „'.self::ALLOWED_MYSQL_DATABASE.'“.',
            );
        }

        if ($normalized !== self::ALLOWED_MYSQL_DATABASE) {
            throw new RuntimeException(
                "Testdatenbank-Sicherheitsabbruch ({$context}): MySQL-Datenbank „{$normalized}“ "
                .'ist nicht freigegeben. Erlaubt ist ausschließlich „'
                .self::ALLOWED_MYSQL_DATABASE.'“.',
            );
        }

        if (! $verifySelectedDatabase) {
            return;
        }

        try {
            $selected = DB::connection()->selectOne('select database() as db_name');
        } catch (\Throwable $exception) {
            throw new RuntimeException(
                "Testdatenbank-Sicherheitsabbruch ({$context}): Konnte die aktive MySQL-Datenbank "
                .'nicht verifizieren (Verbindung fehlgeschlagen).',
                0,
                $exception,
            );
        }

        $selectedName = self::normalizeDatabaseName(
            is_object($selected) ? ($selected->db_name ?? null) : null,
        );

        if ($selectedName !== self::ALLOWED_MYSQL_DATABASE) {
            throw new RuntimeException(
                "Testdatenbank-Sicherheitsabbruch ({$context}): aktive MySQL-Datenbank ist „"
                .($selectedName ?? 'null').'“, erlaubt ist ausschließlich „'
                .self::ALLOWED_MYSQL_DATABASE.'“.',
            );
        }
    }

    public static function normalizeDatabaseName(mixed $database): ?string
    {
        if ($database === null) {
            return null;
        }

        if (! is_string($database) && ! is_numeric($database)) {
            return null;
        }

        $normalized = trim((string) $database);

        return $normalized === '' ? '' : $normalized;
    }

    private static function configuredMysqlDatabase(string $connection): ?string
    {
        $database = config("database.connections.{$connection}.database");

        return is_string($database) || is_numeric($database) || $database === null
            ? self::normalizeDatabaseName($database)
            : null;
    }
}
