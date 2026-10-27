<?php

namespace App\Services\OnlineAudio\Import;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * PO-CALC-TKP-1D4c-2: Jahres-Sperre für OA-TKP-Aktivierung.
 *
 * MySQL: GET_LOCK – funktioniert auch ohne bestehende Zeilen für das Jahr.
 * SQLite: No-Op (Concurrency nur auf MySQL nachgewiesen).
 */
final class OaTkpPriceListYearLock
{
    private ?string $heldName = null;

    public function acquire(int $year, int $timeoutSeconds = 30): void
    {
        if ($this->heldName !== null) {
            throw new RuntimeException('OA-TKP-Jahres-Sperre ist bereits gehalten.');
        }

        $driver = DB::connection()->getDriverName();
        if ($driver !== 'mysql') {
            $this->heldName = $this->lockName($year);

            return;
        }

        $name = $this->lockName($year);
        $rows = DB::select('SELECT GET_LOCK(?, ?) AS locked', [$name, $timeoutSeconds]);
        $locked = isset($rows[0]->locked) ? (int) $rows[0]->locked : 0;
        if ($locked !== 1) {
            throw new OaTkpStammdatenImportException(
                'OA-TKP-Aktivierung konnte nicht gesperrt werden.',
                ["Jahr {$year}: GET_LOCK fehlgeschlagen oder Timeout (parallele Aktivierung?)."],
            );
        }

        $this->heldName = $name;
    }

    public function release(): void
    {
        if ($this->heldName === null) {
            return;
        }

        $name = $this->heldName;
        $this->heldName = null;

        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        DB::select('SELECT RELEASE_LOCK(?) AS released', [$name]);
    }

    private function lockName(int $year): string
    {
        return 'oa_tkp_price_list_year_'.$year;
    }
}
