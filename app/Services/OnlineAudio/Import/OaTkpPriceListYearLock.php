<?php

namespace App\Services\OnlineAudio\Import;

use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * PO-CALC-TKP-1D4c-2: Jahres-Sperre für OA-TKP Draft-Import und Aktivierung.
 *
 * MySQL: GET_LOCK/RELEASE_LOCK auf derselben Connection (verbindungsgebunden).
 * SQLite: bewusst No-Op nur für isolierte Tests (Concurrency auf MySQL nachgewiesen).
 * Andere Treiber: fail-closed – keine scheinbar sichere Aktivierung ohne Sperre.
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

        if ($driver === 'sqlite') {
            // Test-Sonderweg: keine echte Concurrency-Absicherung.
            $this->heldName = $this->lockName($year);

            return;
        }

        if ($driver !== 'mysql') {
            throw new OaTkpStammdatenImportException(
                'OA-TKP-Jahres-Sperre nicht verfügbar.',
                [
                    "Datenbanktreiber „{$driver}“ unterstützt keine OA-TKP-Jahres-Sperre."
                    .' Erlaubt sind MySQL (GET_LOCK) und SQLite (nur Tests).',
                ],
            );
        }

        $name = $this->lockName($year);
        $rows = DB::select('SELECT GET_LOCK(?, ?) AS locked', [$name, $timeoutSeconds]);
        $locked = isset($rows[0]->locked) ? (int) $rows[0]->locked : 0;
        if ($locked !== 1) {
            throw new OaTkpStammdatenImportException(
                'OA-TKP-Jahres-Sperre konnte nicht erworben werden.',
                ["Jahr {$year}: GET_LOCK fehlgeschlagen oder Timeout (parallele Import/Aktivierung?)."],
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

        try {
            DB::select('SELECT RELEASE_LOCK(?) AS released', [$name]);
        } catch (Throwable) {
            // Verbindung verloren → MySQL gibt den Lock automatisch frei.
        }
    }

    private function lockName(int $year): string
    {
        return 'oa_tkp_price_list_year_'.$year;
    }
}
