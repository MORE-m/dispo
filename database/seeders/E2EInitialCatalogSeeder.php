<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\User;
use App\Services\InventoryMediumRule\Catalog\InitialCatalogBootstrapper;
use App\Support\E2E\E2EIsolatedEnvironmentGuard;
use Illuminate\Database\Seeder;

/**
 * BL-P2-02c isolierter E2E-Seed: Testbenutzer + Initialkatalog.
 * Fail-closed über E2EIsolatedEnvironmentGuard (keine Dev-/Prod-DB).
 */
class E2EInitialCatalogSeeder extends Seeder
{
    public function run(): void
    {
        E2EIsolatedEnvironmentGuard::assertSafeForE2ESeeding();

        foreach ([
            ['email' => 'sales@example.com', 'name' => 'E2E Vertrieb', 'role' => Role::Sales],
            ['email' => 'admin@example.com', 'name' => 'E2E Admin', 'role' => Role::Admin],
        ] as $attrs) {
            User::query()->updateOrCreate(
                ['email' => $attrs['email']],
                [
                    'name' => $attrs['name'],
                    'password' => 'password',
                    'role' => $attrs['role'],
                ],
            );
        }

        app(InitialCatalogBootstrapper::class)->bootstrap();
    }
}
