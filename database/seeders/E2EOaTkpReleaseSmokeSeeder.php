<?php

namespace Database\Seeders;

use App\Enums\OaTargetingCategory;
use App\Enums\PriceListStatus;
use App\Enums\Role;
use App\Models\AdvertisingMedium;
use App\Models\Inventory;
use App\Models\OaTargetingDefinition;
use App\Models\OaTkpPriceList;
use App\Models\OaTkpPriceListItem;
use App\Models\User;
use App\Support\Calculation\EngineProfileRegistry;
use App\Support\E2E\E2EIsolatedEnvironmentGuard;
use App\Support\OnlineAudio\OaTkpCoreMedia;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * E2E-Seeder PO-CALC-TKP-1D4d Release-Smoke (isolierte SQLite, Port 8075).
 *
 * Deterministisch: fünf Core-Medien, CombinationMatrix-Regeln, aktive 2026.1,
 * Midroll 80/20, DMP 25/5. Keine Endnutzer-Plattformen. Kein Fake-Release.
 */
class E2EOaTkpReleaseSmokeSeeder extends Seeder
{
    public const string FIXTURE_PATH = 'e2e-po-calc-tkp-1d4d-release-smoke.json';

    public const int PRICE_YEAR = 2026;

    public const string PRICE_VERSION = '2026.1';

    public const string MIDROLL_LIST_TKP = '80.0000';

    public const string MIDROLL_MIN_TKP = '20.0000';

    public const string STREAM_LIST_TKP = '56.0000';

    public const string STREAM_MIN_TKP = '14.0000';

    public const string DMP_CODE = 'dmp';

    public const string DMP_SURCHARGE = '25.0000';

    public const string DMP_MIN_SURCHARGE = '5.0000';

    public const string INVENTORY_CODE = 'inv_more_kombi_online_audio';

    public function run(): void
    {
        E2EIsolatedEnvironmentGuard::assertSafeForE2ESeeding();

        $database = (string) config('database.connections.'.config('database.default').'.database');
        if (strcasecmp(basename($database), 'dispo_mat_core') === 0
            || strcasecmp($database, 'dispo_mat_core') === 0
            || str_contains($database, 'dispo_mat_core')) {
            throw new RuntimeException(
                'E2E Release-Smoke Abbruch: DB_DATABASE zeigt auf dispo_mat_core ('.$database.').',
            );
        }

        if (EngineProfileRegistry::pairStatus('oa_tkp', 'tkp')->value !== 'released'
            || EngineProfileRegistry::currentReleasedVersion('oa_tkp', 'tkp') !== 'v1') {
            throw new RuntimeException(
                'E2E Release-Smoke Abbruch: Registry oa_tkp×tkp ist nicht released/v1.',
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
        $this->call(CombinationMatrixMatCoreSeeder::class);

        $inventory = Inventory::query()->where('code', self::INVENTORY_CODE)->firstOrFail();

        $list = OaTkpPriceList::query()->create([
            'year' => self::PRICE_YEAR,
            'name' => 'E2E D4d Release Smoke 2026.1',
            'version' => self::PRICE_VERSION,
            'revision_number' => 1,
            'lock_version' => 1,
            'status' => PriceListStatus::Active,
            'published_at' => now(),
        ]);

        $media = [];
        foreach (OaTkpCoreMedia::codes() as $code) {
            $medium = AdvertisingMedium::query()->where('code', $code)->firstOrFail();
            $isMidroll = $code === 'midroll_music_environment';
            OaTkpPriceListItem::query()->create([
                'oa_tkp_price_list_id' => $list->id,
                'advertising_medium_id' => (int) $medium->id,
                'inventory_id' => (int) $inventory->id,
                'list_tkp' => $isMidroll ? self::MIDROLL_LIST_TKP : self::STREAM_LIST_TKP,
                'minimum_tkp' => $isMidroll ? self::MIDROLL_MIN_TKP : self::STREAM_MIN_TKP,
            ]);
            $media[$code] = [
                'id' => (int) $medium->id,
                'code' => $code,
                'name' => (string) $medium->name,
            ];
        }

        $native = AdvertisingMedium::query()->where('code', 'native_ad')->firstOrFail();

        $dmp = OaTargetingDefinition::query()->create([
            'code' => self::DMP_CODE,
            'name' => 'DMP',
            'category' => OaTargetingCategory::Dmp,
            'tkp_surcharge' => self::DMP_SURCHARGE,
            'minimum_tkp_surcharge' => self::DMP_MIN_SURCHARGE,
            'revision_number' => 1,
            'is_active' => true,
        ]);

        $fixture = [
            'inventory' => [
                'id' => (int) $inventory->id,
                'code' => self::INVENTORY_CODE,
                'name' => (string) $inventory->name,
            ],
            'priceList' => [
                'id' => (int) $list->id,
                'year' => self::PRICE_YEAR,
                'version' => self::PRICE_VERSION,
            ],
            'media' => $media,
            'nativeAd' => [
                'id' => (int) $native->id,
                'code' => 'native_ad',
                'name' => (string) $native->name,
            ],
            'targeting' => [
                'id' => (int) $dmp->id,
                'code' => self::DMP_CODE,
                'name' => 'DMP',
                'tkp_surcharge' => self::DMP_SURCHARGE,
                'minimum_tkp_surcharge' => self::DMP_MIN_SURCHARGE,
            ],
            'release' => [
                'engine_profile_key' => 'oa_tkp',
                'calculation_method_key' => 'tkp',
                'pair_status' => EngineProfileRegistry::pairStatus('oa_tkp', 'tkp')->value,
                'current_released_version' => EngineProfileRegistry::currentReleasedVersion('oa_tkp', 'tkp'),
                'algorithm_version' => 'v1',
            ],
            'signals' => [
                'oa_tkp_wizard_capability' => false,
                'oa_tkp_preview_surface_allowed' => true,
                'oa_tkp_http_mutation_allowed' => true,
            ],
            'midroll' => [
                'list_tkp' => self::MIDROLL_LIST_TKP,
                'minimum_tkp' => self::MIDROLL_MIN_TKP,
            ],
        ];

        File::put(
            database_path(self::FIXTURE_PATH),
            json_encode($fixture, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
        );
    }
}
