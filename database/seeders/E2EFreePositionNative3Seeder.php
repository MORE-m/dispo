<?php

namespace Database\Seeders;

use App\Enums\EngineCapabilityStatus;
use App\Enums\Role;
use App\Models\AdvertisingMedium;
use App\Models\Inventory;
use App\Models\InventoryMediumRule;
use App\Models\User;
use App\Support\Calculation\EngineProfileRegistry;
use App\Support\E2E\E2EIsolatedEnvironmentGuard;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;
use ReflectionClassConstant;
use RuntimeException;

/**
 * E2E-Seeder NATIVE-3/4 Free-Position-Smoke (isolierte SQLite, eigener Port).
 *
 * Nach NATIVE-5 setzt InitialCatalogMatCoreSeeder Native-Ad bereits auf free_position
 * (released/v1). Kein Registry-Fake mehr nötig; Isolation gegen dispo_mat_core bleibt.
 */
class E2EFreePositionNative3Seeder extends Seeder
{
    public const string FIXTURE_PATH = 'e2e-free-position-native-3.json';

    public const string INVENTORY_CODE = 'FREESND';

    public function run(): void
    {
        E2EIsolatedEnvironmentGuard::assertSafeForE2ESeeding();

        $database = (string) config('database.connections.'.config('database.default').'.database');
        if (str_contains($database, 'dispo_mat_core')) {
            throw new RuntimeException(
                'E2E Free-Position-Smoke Abbruch: DB_DATABASE zeigt auf dispo_mat_core ('.$database.').',
            );
        }

        $catalog = (new ReflectionClassConstant(EngineProfileRegistry::class, 'CATALOG'))->getValue();
        $catalogPair = $catalog[EngineProfileRegistry::PROFILE_FREE_POSITION]['free_position'] ?? null;
        if ($catalogPair === null || $catalogPair['pair_status'] !== EngineCapabilityStatus::Released) {
            throw new RuntimeException(
                'E2E Free-Position-Smoke Abbruch: Registry-CATALOG free_position×free_position ist nicht released.',
            );
        }
        if (($catalogPair['current_released_version'] ?? null) !== 'v1'
            || ($catalogPair['versions']['v1'] ?? null) !== EngineCapabilityStatus::Released
        ) {
            throw new RuntimeException(
                'E2E Free-Position-Smoke Abbruch: Registry free_position×free_position ist nicht released/v1.',
            );
        }

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

        $this->call(InitialCatalogMatCoreSeeder::class);

        $medium = AdvertisingMedium::query()->where('code', 'native_ad')->firstOrFail();

        // Deterministischer Start: nur das Free-Medium ist im isolierten Katalog aktiv.
        AdvertisingMedium::query()
            ->where('id', '!=', $medium->id)
            ->update(['is_active' => false]);

        $medium = $medium->fresh();

        $inventory = Inventory::factory()->create([
            'name' => 'Free Sender',
            'code' => self::INVENTORY_CODE,
            'sort' => 1,
        ]);
        InventoryMediumRule::factory()->create([
            'inventory_id' => $inventory->id,
            'advertising_medium_id' => $medium->id,
        ]);

        File::put(
            database_path(self::FIXTURE_PATH),
            json_encode([
                'inventory' => [
                    'id' => (int) $inventory->id,
                    'code' => self::INVENTORY_CODE,
                    'name' => (string) $inventory->name,
                ],
                'medium' => [
                    'id' => (int) $medium->id,
                    'code' => 'native_ad',
                    'name' => (string) $medium->name,
                ],
                'registry' => [
                    'catalog_pair_status' => $catalogPair['pair_status']->value,
                    'current_released_version' => $catalogPair['current_released_version'],
                    'e2e_fake_released' => (bool) config('app.e2e_free_position_pair_released'),
                ],
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
        );
    }
}
