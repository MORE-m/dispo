<?php

namespace Tests\Support;

use RuntimeException;

/**
 * Früher Fail-Closed-Schutz für Pest/PHPUnit – vor Composer-/Laravel-Bootstrap.
 *
 * Keine Laravel-Abhängigkeiten, keine Facades, kein config()/env().
 * Nur reines PHP; Datei wird in tests/bootstrap.php per require_once geladen.
 */
final class TestDatabaseBootstrapGuard
{
    public const ALLOWED_MYSQL_DATABASE = 'dispo_test';

    /** @var list<string> */
    private const FORBIDDEN_DEV_DATABASE_NAMES = [
        'dispo',
        'dispo_mat_core',
    ];

    /** @var list<string> */
    private const ALLOWED_CONNECTIONS = [
        'sqlite',
        'mysql',
    ];

    /**
     * Prüft die effektive Prozessumgebung ($_ENV / $_SERVER / getenv).
     */
    public static function assertCurrentProcessEnvironmentIsSafe(): void
    {
        self::assertSafe([
            'APP_ENV' => self::readProcessEnv('APP_ENV'),
            'DB_CONNECTION' => self::readProcessEnv('DB_CONNECTION'),
            'DB_DATABASE' => self::readProcessEnv('DB_DATABASE'),
            'DB_URL' => self::readProcessEnv('DB_URL'),
        ]);
    }

    /**
     * @param  array<string, mixed>  $environment
     */
    public static function assertSafe(array $environment): void
    {
        $appEnv = self::normalizeScalar($environment['APP_ENV'] ?? null);
        if ($appEnv !== 'testing') {
            self::abort(
                'APP_ENV muss „testing“ sein'
                .(isset($appEnv) && $appEnv !== '' ? " (aktuell: „{$appEnv}“)" : ' (aktuell: leer/null)').'.',
            );
        }

        $connection = self::normalizeScalar($environment['DB_CONNECTION'] ?? null);
        if ($connection === null || $connection === '') {
            self::abort('DB_CONNECTION fehlt oder ist leer.');
        }

        if (! in_array($connection, self::ALLOWED_CONNECTIONS, true)) {
            self::abort(
                "DB_CONNECTION „{$connection}“ ist für automatisierte Tests nicht freigegeben. "
                .'Erlaubt sind ausschließlich sqlite und mysql.',
            );
        }

        $database = self::normalizeScalar($environment['DB_DATABASE'] ?? null);
        $dbUrl = self::normalizeScalar($environment['DB_URL'] ?? null);

        self::assertDatabaseUrlIsSafe($connection, $dbUrl);

        if ($connection === 'sqlite') {
            self::assertSqliteDatabaseIsSafe($database);

            return;
        }

        self::assertMysqlDatabaseIsSafe($database);
    }

    private static function assertSqliteDatabaseIsSafe(?string $database): void
    {
        if ($database === null || $database === '') {
            self::abort('SQLite DB_DATABASE fehlt oder ist leer.');
        }

        if ($database === ':memory:') {
            return;
        }

        if (self::isForbiddenDevDatabaseName($database) || self::sqlitePathTargetsForbiddenDevName($database)) {
            self::abort(
                "SQLite-Datenbank „{$database}“ trifft einen gesperrten Dev-Namen "
                .'(dispo / dispo_mat_core).',
            );
        }
    }

    private static function assertMysqlDatabaseIsSafe(?string $database): void
    {
        if ($database === null || $database === '') {
            self::abort(
                'MySQL-Datenbankname ist leer oder null. '
                .'Erlaubt ist ausschließlich „'.self::ALLOWED_MYSQL_DATABASE.'“. '
                .'Aktueller DB-Name: '.(isset($database) ? 'leer' : 'null').'.',
            );
        }

        if ($database !== self::ALLOWED_MYSQL_DATABASE) {
            self::abort(
                "MySQL-Datenbank „{$database}“ ist nicht freigegeben. "
                .'Erlaubt ist ausschließlich „'.self::ALLOWED_MYSQL_DATABASE.'“.',
            );
        }
    }

    private static function assertDatabaseUrlIsSafe(string $connection, ?string $dbUrl): void
    {
        if ($dbUrl === null || $dbUrl === '') {
            return;
        }

        // Nicht eindeutig sicher interpretierbare oder nicht-MySQL-URLs: fail-closed.
        if ($connection !== 'mysql') {
            self::abort(
                'DB_URL ist gesetzt, aber für DB_CONNECTION „'.$connection.'“ nicht freigegeben. '
                .'Leere DB_URL ist der Normalfall.',
            );
        }

        $parts = parse_url($dbUrl);
        if ($parts === false) {
            self::abort('DB_URL ist gesetzt, konnte aber nicht sicher geparst werden.');
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if ($scheme !== 'mysql' && $scheme !== 'mysqli') {
            self::abort(
                'DB_URL-Schema „'.($scheme !== '' ? $scheme : 'unbekannt').'“ ist für Tests nicht freigegeben.',
            );
        }

        $path = (string) ($parts['path'] ?? '');
        $nameFromUrl = ltrim($path, '/');
        // query/fragment nie als DB-Name interpretieren; leerer Path = unsicher.
        if ($nameFromUrl === '' || str_contains($nameFromUrl, '/') || str_contains($nameFromUrl, '\\')) {
            self::abort('DB_URL adressiert keine eindeutig sichere MySQL-Testdatenbank.');
        }

        if ($nameFromUrl !== self::ALLOWED_MYSQL_DATABASE) {
            self::abort(
                "DB_URL adressiert MySQL-Datenbank „{$nameFromUrl}“, erlaubt ist ausschließlich „"
                .self::ALLOWED_MYSQL_DATABASE.'“.',
            );
        }
    }

    private static function sqlitePathTargetsForbiddenDevName(string $database): bool
    {
        $normalized = str_replace('\\', '/', $database);
        $base = basename($normalized);
        $stem = preg_replace('/\.(sqlite3?|db)$/i', '', $base);
        $stem = is_string($stem) ? $stem : $base;

        return self::isForbiddenDevDatabaseName($stem) || self::isForbiddenDevDatabaseName($base);
    }

    private static function isForbiddenDevDatabaseName(string $name): bool
    {
        $normalized = strtolower(trim($name));

        foreach (self::FORBIDDEN_DEV_DATABASE_NAMES as $forbidden) {
            if ($normalized === strtolower($forbidden)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return string|null null = unset; '' = explizit leer
     */
    private static function readProcessEnv(string $key): ?string
    {
        if (array_key_exists($key, $_ENV)) {
            return self::normalizeScalar($_ENV[$key]);
        }

        if (array_key_exists($key, $_SERVER)) {
            return self::normalizeScalar($_SERVER[$key]);
        }

        $value = getenv($key);
        if ($value === false) {
            return null;
        }

        return self::normalizeScalar($value);
    }

    private static function normalizeScalar(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        return trim((string) $value);
    }

    private static function abort(string $detail): never
    {
        throw new RuntimeException('Testdatenbank-Sicherheitsabbruch: '.$detail);
    }
}
