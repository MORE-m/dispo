<?php

namespace Database\Seeders;

use App\Enums\CalculationKind;
use App\Enums\CalculationMethodMode;
use App\Enums\EngineCapabilityStatus;
use App\Enums\Role;
use App\Models\AdvertisingMedium;
use App\Models\AdvertisingMediumCalculationMethod;
use App\Models\CalculationMethod;
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
 * E2E-Seeder NATIVE-3 Free-Position-Smoke (isolierte SQLite, eigener Port).
 *
 * `native_ad` wird wie in CreatesFreePositionCatalog für free_position konfiguriert.
 * Das Release des Pairs simuliert ausschließlich der Test-Fake im E2E-Server
 * (E2E_FREE_POSITION_PAIR_RELEASED=1); der Registry-CATALOG bleibt planned.
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

        // Produktiver Katalog muss planned bleiben (der Fake wirkt nur als Test-Override).
        $catalog = (new ReflectionClassConstant(EngineProfileRegistry::class, 'CATALOG'))->getValue();
        $catalogPair = $catalog[EngineProfileRegistry::PROFILE_FREE_POSITION]['free_position'] ?? null;
        if ($catalogPair === null || $catalogPair['pair_status'] !== EngineCapabilityStatus::Planned) {
            throw new RuntimeException(
                'E2E Free-Position-Smoke Abbruch: Registry-CATALOG free_position×free_position ist nicht planned.',
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

        $method = CalculationMethod::query()->where('key', 'free_position')->firstOrFail();
        $medium = AdvertisingMedium::query()->where('code', 'native_ad')->firstOrFail();

        $medium->kind = CalculationKind::FreePosition;
        $medium->calculation_method_mode = CalculationMethodMode::Override;
        $medium->default_calculation_method_id = (int) $method->id;
        $medium->is_discountable = true;
        $medium->is_ae_eligible = true;
        $medium->save();

        AdvertisingMediumCalculationMethod::query()
            ->where('advertising_medium_id', $medium->id)
            ->delete();

        $assignment = new AdvertisingMediumCalculationMethod;
        $assignment->advertising_medium_id = $medium->id;
        $assignment->calculation_method_id = (int) $method->id;
        $assignment->engine_profile_key = EngineProfileRegistry::PROFILE_FREE_POSITION;
        $assignment->is_active = true;
        $assignment->sort = 10;
        $assignment->lock_version = 1;
        $assignment->save();

        // Deterministischer Start: nur das Free-Medium ist im isolierten Katalog aktiv, damit der
        // Wizard (firstValidPosition) direkt mit Free-Sender × native_ad startet.
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
                    'e2e_fake_released' => (bool) config('app.e2e_free_position_pair_released'),
                ],
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
        );
    }
}
