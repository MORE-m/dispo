<?php

namespace Database\Seeders;

use App\Enums\InventoryType;
use App\Models\Inventory;
use App\Models\Organization;
use App\Support\E2E\E2EIsolatedEnvironmentGuard;
use Illuminate\Database\Seeder;

/**
 * Isolierte Fixtures für die einklappbare Inventarauswahl im Wizard.
 *
 * Baut auf {@see E2ECalculationSeeder} auf und ergänzt ein aktives Inventar
 * ohne kalkulierbares Medium (keine InventoryMediumRule).
 */
class E2EWizardInventoryCollapseSeeder extends Seeder
{
    public function run(): void
    {
        E2EIsolatedEnvironmentGuard::assertSafeForE2ESeeding();

        $this->call(E2ECalculationSeeder::class);

        $organization = Organization::query()->firstOrFail();

        Inventory::query()->updateOrCreate(
            ['code' => 'ONLINE_E2E'],
            [
                'organization_id' => $organization->id,
                'name' => 'Online Audio E2E',
                'type' => InventoryType::Kombi,
                'sort' => 90,
                'is_active' => true,
                'logo_path' => null,
            ],
        );
    }
}
