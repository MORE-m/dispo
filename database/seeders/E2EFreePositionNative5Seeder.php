<?php

namespace Database\Seeders;

use App\Enums\EngineCapabilityStatus;
use App\Enums\Role;
use App\Models\AdvertisingMedium;
use App\Models\Inventory;
use App\Models\InventoryMediumRule;
use App\Models\User;
use App\Support\Advertising\AdvertisingMediumLiveBookability;
use App\Support\Calculation\EngineProfileRegistry;
use App\Support\E2E\E2EIsolatedEnvironmentGuard;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;
use ReflectionClassConstant;
use RuntimeException;

/**
 * E2E-Seeder NATIVE-5 Release-Smoke (isolierte SQLite, Port 8080).
 *
 * Kein Registry-Fake: free_position×free_position ist im CATALOG released/v1.
 * Katalog kommt aus InitialCatalogMatCoreSeeder (native_ad = free_position + Podcast-Rule).
 * Die vier historischen Native-Ad-Rules (Matrix/Operativdaten) werden hier nachgebildet;
 * MORE-Kombi Online Audio × native_ad bleibt bewusst ohne Rule.
 */
class E2EFreePositionNative5Seeder extends Seeder
{
    public const string FIXTURE_PATH = 'e2e-free-position-native-5-release.json';

    public const string PODCAST_CODE = 'inv_more_kombi_podcast';

    public const string MORE_OA_CODE = 'inv_more_kombi_online_audio';

    /**
     * Vier historische Native-Ad-Rules (Inventar-Code => Buchungskennzeichen).
     *
     * @var array<string, string>
     */
    public const array HISTORICAL_RULES = [
        'inv_radio_hamburg' => 'Online Audio (UA)',
        'inv_rock_antenne_hamburg' => 'Online Audio (UA)',
        'inv_80er_90er_oldie_antenne_hamburg' => 'Online Audio (UA)',
        'inv_caravan_fm' => 'UC',
    ];

    public function run(): void
    {
        E2EIsolatedEnvironmentGuard::assertSafeForE2ESeeding();

        $connection = (string) config('database.default');
        $database = (string) config("database.connections.{$connection}.database");
        if (str_contains($database, 'dispo_mat_core')) {
            throw new RuntimeException(
                'E2E NATIVE-5 Abbruch: DB_DATABASE zeigt auf dispo_mat_core ('.$database.').',
            );
        }

        if ((bool) config('app.e2e_free_position_pair_released')) {
            throw new RuntimeException(
                'E2E NATIVE-5 Abbruch: E2E_FREE_POSITION_PAIR_RELEASED-Fake darf nicht gesetzt sein.',
            );
        }

        // Registry-CATALOG direkt lesen (kein Fake, keine Override-Schicht).
        $catalog = (new ReflectionClassConstant(EngineProfileRegistry::class, 'CATALOG'))->getValue();
        $catalogPair = $catalog[EngineProfileRegistry::PROFILE_FREE_POSITION]['free_position'] ?? null;
        if ($catalogPair === null
            || $catalogPair['pair_status'] !== EngineCapabilityStatus::Released
            || ($catalogPair['current_released_version'] ?? null) !== 'v1'
            || ($catalogPair['versions']['v1'] ?? null) !== EngineCapabilityStatus::Released
        ) {
            throw new RuntimeException(
                'E2E NATIVE-5 Abbruch: Registry-CATALOG free_position×free_position ist nicht released/v1.',
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

        // Konfiguriert native_ad (free_position/Override/Assignment) und die Podcast-Rule.
        $this->call(InitialCatalogMatCoreSeeder::class);

        $medium = AdvertisingMedium::query()->where('code', 'native_ad')->firstOrFail();

        foreach (self::HISTORICAL_RULES as $inventoryCode => $bookingCode) {
            $inventory = Inventory::query()->where('code', $inventoryCode)->firstOrFail();
            $exists = InventoryMediumRule::query()
                ->where('inventory_id', $inventory->id)
                ->where('advertising_medium_id', $medium->id)
                ->exists();
            if (! $exists) {
                InventoryMediumRule::factory()->create([
                    'inventory_id' => $inventory->id,
                    'advertising_medium_id' => $medium->id,
                    'is_active' => true,
                    'booking_code' => $bookingCode,
                    'planning_responsibility_key' => 'redaktion',
                ]);
            }
        }

        $podcast = Inventory::query()->where('code', self::PODCAST_CODE)->firstOrFail();
        $moreOa = Inventory::query()->where('code', self::MORE_OA_CODE)->firstOrFail();

        $podcastRule = InventoryMediumRule::query()
            ->where('inventory_id', $podcast->id)
            ->where('advertising_medium_id', $medium->id)
            ->first();
        if ($podcastRule === null || ! $podcastRule->is_active
            || (string) $podcastRule->planning_responsibility_key !== 'redaktion'
        ) {
            throw new RuntimeException(
                'E2E NATIVE-5 Abbruch: Podcast×native_ad Rule fehlt oder ist nicht active/redaktion.',
            );
        }

        $moreOaRuleExists = InventoryMediumRule::query()
            ->where('inventory_id', $moreOa->id)
            ->where('advertising_medium_id', $medium->id)
            ->exists();
        if ($moreOaRuleExists) {
            throw new RuntimeException(
                'E2E NATIVE-5 Abbruch: MORE-Kombi Online Audio × native_ad darf keine Rule haben.',
            );
        }

        // Deterministischer Start: nur das Free-Medium ist im isolierten Katalog aktiv.
        // Inventare bleiben aktiv/auswählbar (MORE OA wird mangels Rule als nicht kalkulierbar angezeigt).
        AdvertisingMedium::query()
            ->where('id', '!=', $medium->id)
            ->update(['is_active' => false]);
        $medium = $medium->fresh();

        $activeRuleCount = InventoryMediumRule::query()
            ->where('advertising_medium_id', $medium->id)
            ->where('is_active', true)
            ->count();
        if ($activeRuleCount !== 5) {
            throw new RuntimeException(
                'E2E NATIVE-5 Abbruch: erwartet 5 aktive native_ad-Rules, gefunden '.$activeRuleCount.'.',
            );
        }

        $mediumBookable = app(AdvertisingMediumLiveBookability::class)
            ->evaluate($medium, 'free_position')->isBookableForNewPositions;

        $inventoryRow = function (Inventory $inventory) use ($medium, $mediumBookable): array {
            $rule = InventoryMediumRule::query()
                ->where('inventory_id', $inventory->id)
                ->where('advertising_medium_id', $medium->id)
                ->where('is_active', true)
                ->first();

            return [
                'id' => (int) $inventory->id,
                'code' => (string) $inventory->code,
                'name' => (string) $inventory->name,
                'has_native_rule' => $rule !== null,
                'bookable' => $rule !== null && $mediumBookable,
                'planning_responsibility_key' => $rule?->planning_responsibility_key,
                'booking_code' => $rule?->booking_code,
            ];
        };

        $historical = [];
        foreach (array_keys(self::HISTORICAL_RULES) as $code) {
            $historical[] = $inventoryRow(Inventory::query()->where('code', $code)->firstOrFail());
        }

        File::put(
            database_path(self::FIXTURE_PATH),
            json_encode([
                'medium' => [
                    'id' => (int) $medium->id,
                    'code' => 'native_ad',
                    'name' => (string) $medium->name,
                    'bookable' => $mediumBookable,
                ],
                'inventories' => [
                    'historical' => $historical,
                    'podcast' => $inventoryRow($podcast),
                    'more_oa' => $inventoryRow($moreOa),
                ],
                'registry' => [
                    'catalog_pair_status' => $catalogPair['pair_status']->value,
                    'current_released_version' => $catalogPair['current_released_version'],
                    'e2e_fake_released' => (bool) config('app.e2e_free_position_pair_released'),
                ],
                'active_rule_count' => $activeRuleCount,
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
        );
    }
}
