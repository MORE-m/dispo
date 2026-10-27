<?php

namespace App\Services\OnlineAudio\Import;

use App\Enums\PriceListStatus;
use App\Models\OaTkpPriceList;

/**
 * PO-CALC-TKP-1D4c-2: CLI-Schutz vor Active/Archived-Import und historischem Überschreiben.
 *
 * Der Domain-Importer darf weiterhin andere Status (API-Kompatibilität); der CLI-Pfad
 * erzwingt ausschließlich Draft und blockiert firstOrNew auf non-draft Revisionen.
 *
 * Statusprüfung und Mutation teilen dieselbe Jahres-Sperre wie die Aktivierung,
 * damit paralleles Activate die Revision nicht zwischen Check und Write „active“ setzen
 * und der Import sie anschließend überschreiben kann.
 */
final class OaTkpPriceListDraftImportGuard
{
    public function __construct(
        private readonly OaTkpPriceListImporter $importer,
        private readonly OaTkpPriceListYearLock $yearLock,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array{price_list_id: int|null, items_written: int, dry_run: bool}
     */
    public function importDraftOnly(array $payload, bool $dryRun = false): array
    {
        $statusRaw = isset($payload['status']) ? trim((string) $payload['status']) : PriceListStatus::Draft->value;
        if ($statusRaw === '') {
            $statusRaw = PriceListStatus::Draft->value;
        }

        if ($statusRaw !== PriceListStatus::Draft->value) {
            throw new OaTkpStammdatenImportException(
                'TKP-Preislisten-CLI akzeptiert nur Draft.',
                [
                    "status „{$statusRaw}“ ist über oa:import-price-list nicht erlaubt (nur draft)."
                    .' Aktivierung erfolgt ausschließlich über oa:activate-price-list.',
                ],
            );
        }

        $year = (int) ($payload['year'] ?? 0);
        $revision = (int) ($payload['revision_number'] ?? 1);
        $payload['status'] = PriceListStatus::Draft->value;

        if ($dryRun) {
            $this->assertRevisionIsDraftOrAbsent($year, $revision);

            return $this->importer->import($payload, true);
        }

        $this->yearLock->acquire($year);

        try {
            $this->assertRevisionIsDraftOrAbsent($year, $revision);

            return $this->importer->import($payload, false);
        } finally {
            $this->yearLock->release();
        }
    }

    private function assertRevisionIsDraftOrAbsent(int $year, int $revision): void
    {
        $existing = OaTkpPriceList::query()
            ->where('year', $year)
            ->where('revision_number', $revision)
            ->first();

        if ($existing !== null && $existing->status !== PriceListStatus::Draft) {
            throw new OaTkpStammdatenImportException(
                'Historische Preislistenrevision ist geschützt.',
                [
                    "Jahr {$year} Revision {$revision} ist „{$existing->status->value}“ und darf über die CLI nicht überschrieben werden.",
                ],
            );
        }
    }
}
