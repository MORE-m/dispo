<?php

namespace Database\Seeders;

use App\Services\InventoryMediumRule\Import\CombinationMatrixImporter;
use Illuminate\Database\Seeder;

/**
 * PO-MAT-CORE-MATRIX-1 / BL-P2-02b: Produktivmatrix → inventory_medium_rules.
 *
 * Voraussetzung: Inventare und Werbemittel existieren bereits mit exakten
 * Excel-Namen. Nicht Teil von DatabaseSeeder (explizit aufrufen).
 */
class CombinationMatrixMatCoreSeeder extends Seeder
{
    public function run(?string $workbookPath = null): void
    {
        $result = app(CombinationMatrixImporter::class)->import($workbookPath);

        if ($this->command !== null) {
            $this->command->info(sprintf(
                'MAT-CORE Matrix: %d Zeilen (created=%d updated=%d unchanged=%d)',
                $result['parsed_rows'],
                $result['created'],
                $result['updated'],
                $result['unchanged'],
            ));
        }
    }
}
