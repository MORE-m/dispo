<?php

namespace Database\Seeders;

use App\Enums\OaTargetingCategory;
use App\Enums\Role;
use App\Models\AdvertisingMedium;
use App\Models\Inventory;
use App\Models\InventoryMediumRule;
use App\Models\OaPlatform;
use App\Models\OaTargetingDefinition;
use App\Models\User;
use App\Support\E2E\E2EIsolatedEnvironmentGuard;
use App\Support\OnlineAudio\OaTkpCoreMedia;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * PO-CALC-TKP-1D4c-4d: isolierte Admin-UI Playwright-/Browser-Abnahme.
 *
 * Nur APP_ENV=testing + E2E_SERVER=1. Keine produktive DB, keine Registry-Änderung.
 */
class E2EOaTkpAdminUiSeeder extends Seeder
{
    public const string MATRIX_RELATIVE = 'docs/readiness/import-templates/oa-tkp-price-matrix-2026-2027.csv';

    public function run(): void
    {
        E2EIsolatedEnvironmentGuard::assertSafeForE2ESeeding();

        foreach ([
            ['email' => 'admin@example.com', 'name' => 'E2E Admin', 'role' => Role::Admin],
            ['email' => 'management@example.com', 'name' => 'E2E Management', 'role' => Role::Management],
            ['email' => 'sales@example.com', 'name' => 'E2E Vertrieb', 'role' => Role::Sales],
        ] as $attrs) {
            User::query()->updateOrCreate(
                ['email' => $attrs['email']],
                [
                    'name' => $attrs['name'],
                    'password' => 'password',
                    'role' => $attrs['role'],
                    'discount_limit_percent' => null,
                    'can_special_approve' => false,
                    'is_active' => true,
                ],
            );
        }

        $this->call(InitialCatalogMatCoreSeeder::class);
        $this->seedOaInventoryMediumRulesFromMatrix();
        $this->seedTargetings();
        $this->seedPlatforms();
    }

    private function seedOaInventoryMediumRulesFromMatrix(): void
    {
        $path = base_path(self::MATRIX_RELATIVE);
        if (! File::isFile($path)) {
            throw new RuntimeException('OA-Matrix-CSV fehlt: '.$path);
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('OA-Matrix-CSV nicht lesbar: '.$path);
        }

        try {
            $header = fgetcsv($handle, 0, ';');
            if (! is_array($header)) {
                throw new RuntimeException('OA-Matrix-CSV ohne Header.');
            }
            $header = array_map(static fn ($h): string => (string) $h, $header);
            $yearIdx = array_search('price_year', $header, true);
            $mediumIdx = array_search('medium_code', $header, true);
            $inventoryIdx = array_search('inventory_code', $header, true);
            if ($yearIdx === false || $mediumIdx === false || $inventoryIdx === false) {
                throw new RuntimeException('OA-Matrix-CSV Spalten unvollständig.');
            }

            $pairs = [];
            while (($row = fgetcsv($handle, 0, ';')) !== false) {
                if (! is_array($row) || ($row[$yearIdx] ?? '') !== '2026') {
                    continue;
                }
                $medium = (string) ($row[$mediumIdx] ?? '');
                $inventory = (string) ($row[$inventoryIdx] ?? '');
                if ($medium === '' || $inventory === '' || ! OaTkpCoreMedia::contains($medium)) {
                    continue;
                }
                $pairs[$medium.'|'.$inventory] = [$medium, $inventory];
            }
        } finally {
            fclose($handle);
        }

        if (count($pairs) < 40) {
            throw new RuntimeException('Erwartet ≥40 OA-Paare aus Matrix, gefunden: '.count($pairs));
        }

        foreach ($pairs as [$mediumCode, $inventoryCode]) {
            $medium = AdvertisingMedium::query()->where('code', $mediumCode)->firstOrFail();
            $inventory = Inventory::query()->where('code', $inventoryCode)->firstOrFail();
            InventoryMediumRule::query()->firstOrCreate(
                [
                    'inventory_id' => (int) $inventory->id,
                    'advertising_medium_id' => (int) $medium->id,
                ],
                ['is_active' => true],
            );
        }
    }

    private function seedTargetings(): void
    {
        OaTargetingDefinition::query()->updateOrCreate(
            ['code' => 'dmp', 'revision_number' => 1],
            [
                'name' => 'DMP',
                'category' => OaTargetingCategory::Dmp,
                'tkp_surcharge' => '25.0000',
                'minimum_tkp_surcharge' => '5.0000',
                'is_active' => true,
            ],
        );

        OaTargetingDefinition::query()->updateOrCreate(
            ['code' => 'geo', 'revision_number' => 1],
            [
                'name' => 'Geo',
                'category' => OaTargetingCategory::Technisch,
                'tkp_surcharge' => '15.0000',
                'minimum_tkp_surcharge' => '3.0000',
                'is_active' => true,
            ],
        );
    }

    private function seedPlatforms(): void
    {
        foreach ([
            ['code' => 'spotify', 'name' => 'Spotify', 'sort' => 10],
            ['code' => 'youtube', 'name' => 'YouTube', 'sort' => 20],
            ['code' => 'deezer', 'name' => 'Deezer', 'sort' => 30],
        ] as $row) {
            OaPlatform::query()->updateOrCreate(
                ['code' => $row['code']],
                [
                    'name' => $row['name'],
                    'is_active' => true,
                    'sort' => $row['sort'],
                    'is_adserver_relevant' => true,
                ],
            );
        }
    }
}
