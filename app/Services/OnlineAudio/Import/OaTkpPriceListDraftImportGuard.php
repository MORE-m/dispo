<?php

namespace App\Services\OnlineAudio\Import;

use App\Enums\PriceListStatus;
use App\Models\OaTkpPriceList;

/**
 * PO-CALC-TKP-1D4c-2: CLI-Schutz vor Active/Archived-Import und historischem Überschreiben.
 *
 * Der Domain-Importer darf weiterhin andere Status (API-Kompatibilität); der CLI-Pfad
 * erzwingt ausschließlich Draft und blockiert firstOrNew auf non-draft Revisionen.
 */
final class OaTkpPriceListDraftImportGuard
{
    public function __construct(
        private readonly OaTkpPriceListImporter $importer,
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

        $payload['status'] = PriceListStatus::Draft->value;

        return $this->importer->import($payload, $dryRun);
    }
}
