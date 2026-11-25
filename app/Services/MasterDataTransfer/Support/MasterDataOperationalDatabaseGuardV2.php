<?php

namespace App\Services\MasterDataTransfer\Support;

use App\Services\MasterDataTransfer\MasterDataTransferContractV2;
use App\Services\MasterDataTransfer\MasterDataTransferException;

/**
 * Slice 6a: Operative DB-Allowlist und --confirm-database-Bindung.
 *
 * Kein CLI-Override der Allowlist. Tests dürfen die Zielidentität nur über
 * config('dispo.master_data_transfer_testing_operational_database') simulieren
 * und nur bei APP_ENV=testing.
 */
final class MasterDataOperationalDatabaseGuardV2
{
    /**
     * @return string aktive DB-Identität (Allowlist-Name)
     */
    public function assertDryRunAllowed(string $confirmDatabase): string
    {
        $active = $this->resolveActiveDatabaseName();

        if ($confirmDatabase === '' || $confirmDatabase !== $active) {
            throw new MasterDataTransferException(sprintf(
                'Abbruch: --confirm-database muss exakt die aktive DB sein (aktiv=%s, confirm=%s).',
                $active === '' ? '(leer)' : $active,
                $confirmDatabase === '' ? '(leer)' : $confirmDatabase,
            ));
        }

        if ($active !== MasterDataTransferContractV2::OPERATIONAL_DATABASE) {
            throw new MasterDataTransferException(
                'Operativer V2-Dry-Run nur gegen '
                .MasterDataTransferContractV2::OPERATIONAL_DATABASE
                .' erlaubt (aktiv: '.$active.').',
            );
        }

        if ($this->isExplicitlyForbiddenName($active)) {
            throw new MasterDataTransferException(
                'Operativer V2-Dry-Run gegen geschützte Datenbank gesperrt: '.$active,
            );
        }

        return $active;
    }

    public function resolveActiveDatabaseName(): string
    {
        if (app()->environment('testing')) {
            $override = config('dispo.master_data_transfer_testing_operational_database');
            if (is_string($override) && $override !== '') {
                return $override;
            }
        }

        $connection = (string) config('database.default');
        $database = config('database.connections.'.$connection.'.database');
        if (! is_string($database) && ! is_numeric($database)) {
            return '';
        }

        return trim((string) $database);
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
