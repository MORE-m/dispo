<?php

namespace App\Support\E2E;

use RuntimeException;

/**
 * Fail-closed Guard für E2E-Seeder: denselben Vertrag wie Playwright/E2E-Routen.
 *
 * Erlaubt nur:
 * - APP_ENV=testing
 * - E2E_SERVER=1 (config app.e2e_server)
 * - Datenbankname nicht die lokale Dev-DB „dispo“
 */
final class E2EIsolatedEnvironmentGuard
{
    public const FORBIDDEN_DEV_DATABASE = 'dispo';

    /**
     * @throws RuntimeException
     */
    public static function assertSafeForE2ESeeding(): void
    {
        if (! app()->environment('testing') || ! (bool) config('app.e2e_server')) {
            throw new RuntimeException(
                'E2E-Seeder-Sicherheitsabbruch: erlaubt nur APP_ENV=testing und E2E_SERVER=1 '
                .'(isolierter Playwright-/E2E-Pfad).',
            );
        }

        $connection = (string) config('database.default');
        $driver = (string) config("database.connections.{$connection}.driver");
        $database = config("database.connections.{$connection}.database");

        if (self::targetsForbiddenDevDatabase($database, $driver)) {
            throw new RuntimeException(
                'E2E-Seeder-Sicherheitsabbruch: Entwicklungsdatenbank „'
                .self::FORBIDDEN_DEV_DATABASE.'“ ist gesperrt. '
                .'E2E-Seeder dürfen nur gegen eine isolierte Test-/E2E-Datenbank laufen.',
            );
        }
    }

    public static function targetsForbiddenDevDatabase(mixed $database, string $driver = ''): bool
    {
        if ($database === null) {
            return false;
        }

        if (! is_string($database) && ! is_numeric($database)) {
            return false;
        }

        $normalized = trim((string) $database);
        if ($normalized === '' || $normalized === ':memory:') {
            return false;
        }

        if (strcasecmp($normalized, self::FORBIDDEN_DEV_DATABASE) === 0) {
            return true;
        }

        // SQLite-Dateiwege: basename ohne Extension (z. B. …/dispo.sqlite).
        if ($driver === 'sqlite' || str_contains($normalized, DIRECTORY_SEPARATOR) || str_contains($normalized, '/')) {
            $filename = pathinfo($normalized, PATHINFO_FILENAME);

            return strcasecmp($filename, self::FORBIDDEN_DEV_DATABASE) === 0;
        }

        return false;
    }
}
