<?php

namespace App\Services\MasterDataTransfer\Support;

use App\Services\MasterDataTransfer\MasterDataTransferContractV2;
use App\Services\MasterDataTransfer\MasterDataTransferException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Slice 6a: Operative DB-Allowlist und --confirm-database-Bindung.
 *
 * Kein CLI-Override der Allowlist. Tests dürfen die Zielidentität nur über
 * config('dispo.master_data_transfer_testing_operational_database') simulieren,
 * und nur bei APP_ENV=testing auf nachweislich isolierter Testdatenbank.
 */
final class MasterDataOperationalDatabaseGuardV2
{
    /**
     * @return string operative DB-Identität (Allowlist-Name)
     */
    public function assertDryRunAllowed(string $confirmDatabase): string
    {
        if ($confirmDatabase === '') {
            throw new MasterDataTransferException(
                'Abbruch: --confirm-database muss exakt die aktive DB sein (aktiv=(leer), confirm=(leer)).',
            );
        }

        if (app()->environment('testing')) {
            $override = config('dispo.master_data_transfer_testing_operational_database');
            if (is_string($override) && $override !== '') {
                return $this->assertTestingOverrideAllowed($confirmDatabase, $override);
            }
        }

        return $this->assertOperationalConnectionAllowed($confirmDatabase);
    }

    private function assertTestingOverrideAllowed(string $confirmDatabase, string $override): string
    {
        if (! $this->isIsolatedTestDatabase()) {
            throw new MasterDataTransferException(
                'Test-Override der operativen DB-Identität nur auf isolierter Testdatenbank erlaubt.',
            );
        }

        if ($override !== MasterDataTransferContractV2::OPERATIONAL_DATABASE) {
            throw new MasterDataTransferException(
                'Test-Override muss exakt '
                .MasterDataTransferContractV2::OPERATIONAL_DATABASE
                .' sein (gesetzt: '.$override.').',
            );
        }

        if ($confirmDatabase !== $override) {
            throw new MasterDataTransferException(sprintf(
                'Abbruch: --confirm-database muss exakt die aktive DB sein (aktiv=%s, confirm=%s).',
                $override,
                $confirmDatabase,
            ));
        }

        return $override;
    }

    private function assertOperationalConnectionAllowed(string $confirmDatabase): string
    {
        $connectionName = (string) config('database.default');
        if ($connectionName === '') {
            throw new MasterDataTransferException(
                'Operativer V2-Dry-Run abgebrochen: Datenbankverbindung unbestimmt.',
            );
        }

        $driver = (string) config('database.connections.'.$connectionName.'.driver');
        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            throw new MasterDataTransferException(
                'Operativer V2-Dry-Run nur mit MySQL/MariaDB erlaubt (Treiber: '
                .($driver === '' ? '(leer)' : $driver).').',
            );
        }

        $configured = $this->configuredDatabaseName($connectionName);
        if ($configured === '') {
            throw new MasterDataTransferException(
                'Operativer V2-Dry-Run abgebrochen: konfigurierter DB-Name fehlt.',
            );
        }

        $actual = $this->actualDatabaseNameFromConnection();
        if ($actual === '') {
            throw new MasterDataTransferException(
                'Operativer V2-Dry-Run abgebrochen: tatsächliche DB-Identität (SELECT DATABASE()) fehlt.',
            );
        }

        if ($configured !== $actual) {
            throw new MasterDataTransferException(sprintf(
                'Abbruch: konfigurierte DB weicht von tatsächlicher Verbindung ab (config=%s, actual=%s).',
                $configured,
                $actual,
            ));
        }

        if ($confirmDatabase !== $configured) {
            throw new MasterDataTransferException(sprintf(
                'Abbruch: --confirm-database muss exakt die aktive DB sein (aktiv=%s, confirm=%s).',
                $configured,
                $confirmDatabase,
            ));
        }

        if ($configured !== MasterDataTransferContractV2::OPERATIONAL_DATABASE) {
            throw new MasterDataTransferException(
                'Operativer V2-Dry-Run nur gegen '
                .MasterDataTransferContractV2::OPERATIONAL_DATABASE
                .' erlaubt (aktiv: '.$configured.').',
            );
        }

        if ($this->isExplicitlyForbiddenName($configured)) {
            throw new MasterDataTransferException(
                'Operativer V2-Dry-Run gegen geschützte Datenbank gesperrt: '.$configured,
            );
        }

        return $configured;
    }

    public function configuredDatabaseName(?string $connectionName = null): string
    {
        $connection = $connectionName ?? (string) config('database.default');
        $database = config('database.connections.'.$connection.'.database');
        if (! is_string($database) && ! is_numeric($database)) {
            return '';
        }

        return trim((string) $database);
    }

    public function actualDatabaseNameFromConnection(): string
    {
        try {
            $value = DB::selectOne('SELECT DATABASE() AS db_name');
        } catch (Throwable $e) {
            throw new MasterDataTransferException(
                'Operativer V2-Dry-Run abgebrochen: SELECT DATABASE() fehlgeschlagen.',
                [$e->getMessage()],
            );
        }

        if (! is_object($value) || ! isset($value->db_name) || ! is_string($value->db_name)) {
            return '';
        }

        return trim($value->db_name);
    }

    public function isIsolatedTestDatabase(): bool
    {
        if (! app()->environment('testing')) {
            return false;
        }

        $connection = (string) config('database.default');
        $driver = (string) config('database.connections.'.$connection.'.driver');
        $database = $this->configuredDatabaseName($connection);

        if ($database === '') {
            return false;
        }

        if ($this->isExplicitlyForbiddenName($database)) {
            return false;
        }

        if ($database === ':memory:') {
            return true;
        }

        if ($driver === 'sqlite') {
            $filename = pathinfo($database, PATHINFO_FILENAME);

            return ! $this->isExplicitlyForbiddenName($filename)
                && strcasecmp($filename, MasterDataTransferContractV2::OPERATIONAL_DATABASE) !== 0;
        }

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            return strcasecmp($database, 'dispo_test') === 0;
        }

        return false;
    }

    private function isExplicitlyForbiddenName(string $database): bool
    {
        $forbidden = [
            'dispo_mat_core',
            'dispo',
            'production',
            'dispo_production',
            'prod',
            'staging',
            'dispo_staging',
        ];

        foreach ($forbidden as $name) {
            if (strcasecmp($database, $name) === 0) {
                return true;
            }
        }

        return false;
    }
}
