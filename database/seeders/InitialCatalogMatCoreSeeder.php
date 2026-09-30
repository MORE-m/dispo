<?php

namespace Database\Seeders;

use App\Services\InventoryMediumRule\Catalog\InitialCatalogBootstrapper;
use Illuminate\Database\Seeder;

/**
 * PO-MAT-CORE-CATALOG-1 / BL-P2-02c: 14 Inventare + 42 Werbemittel.
 *
 * Explizit aufrufen – nicht Teil von DatabaseSeeder.
 * Reihenfolge: dieser Seeder → CombinationMatrixMatCoreSeeder (#109).
 */
class InitialCatalogMatCoreSeeder extends Seeder
{
    public function run(): void
    {
        $result = app(InitialCatalogBootstrapper::class)->bootstrap();

        if ($this->command !== null) {
            $this->command->info(sprintf(
                'MAT-CORE Katalog: Inventare created=%d unchanged=%d; Medien created=%d unchanged=%d',
                $result['inventories_created'],
                $result['inventories_unchanged'],
                $result['media_created'],
                $result['media_unchanged'],
            ));
        }
    }
}
