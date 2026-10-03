<?php

namespace Tests\Concerns;

use App\Enums\DayGroup;
use App\Enums\InventoryType;
use App\Enums\PriceListStatus;
use App\Models\AdvertisingMedium;
use App\Models\Inventory;
use App\Models\InventoryMediumRule;
use App\Models\Organization;
use App\Models\PriceList;
use App\Models\PriceListItem;

/**
 * Isolierte Fixture für den 8-Inventar-Kalkulierbarkeits-Smoke (PRI-OPS-1-Raster).
 *
 * Synthetisches Abdeckungsmuster (nicht Workbook-Import, nicht Dev-DB-Kopie):
 * - 5 Inventare: Stunden 0–23 × mo_fr/sa/so (72 Zellen)
 * - Kombi+ / ffn: Stunden 5–18 (42 Zellen)
 * - Bollerwagen: Stunden 5–23 (57 Zellen)
 * - Summe 501 Zellen / 75 Lücken = nachgebildetes Muster, keine Import-Validierung
 * - MAT-003: kein spot_single auf Kombi+, ffn, Bollerwagen
 *
 * Deterministische second_price je Inventar (nur Fixture-Erwartungen).
 */
trait CreatesEightSpotInventoryCalculabilityCatalog
{
    /**
     * Import-Raster laut MORE-Workbook Zeilen 13–36.
     */
    public const IMPORT_HOUR_MIN = 0;

    public const IMPORT_HOUR_MAX = 23;

    /**
     * @return list<array{
     *     code: string,
     *     name: string,
     *     type: InventoryType,
     *     sort: int,
     *     hour_min: int,
     *     hour_max: int,
     *     expected_items: int,
     *     allows_single: bool,
     *     second_price: string,
     *     smoke_hour: int
     * }>
     */
    protected function eightSpotInventoryDefinitions(): array
    {
        return [
            [
                'code' => 'inv_radio_hamburg',
                'name' => 'Radio Hamburg',
                'type' => InventoryType::Sender,
                'sort' => 1,
                'hour_min' => 0,
                'hour_max' => 23,
                'expected_items' => 72,
                'allows_single' => true,
                'second_price' => '1.0000',
                'smoke_hour' => 10,
            ],
            [
                'code' => 'inv_rock_antenne_hamburg',
                'name' => 'ROCK ANTENNE Hamburg',
                'type' => InventoryType::Sender,
                'sort' => 2,
                'hour_min' => 0,
                'hour_max' => 23,
                'expected_items' => 72,
                'allows_single' => true,
                'second_price' => '1.1000',
                'smoke_hour' => 10,
            ],
            [
                'code' => 'inv_80er_90er_oldie_antenne_hamburg',
                'name' => '80er 90er OLDIE ANTENNE Hamburg',
                'type' => InventoryType::Sender,
                'sort' => 3,
                'hour_min' => 0,
                'hour_max' => 23,
                'expected_items' => 72,
                'allows_single' => true,
                'second_price' => '1.2000',
                'smoke_hour' => 10,
            ],
            [
                'code' => 'inv_caravan_fm',
                'name' => 'CARAVAN.fm',
                'type' => InventoryType::Sender,
                'sort' => 4,
                'hour_min' => 0,
                'hour_max' => 23,
                'expected_items' => 72,
                'allows_single' => true,
                'second_price' => '1.3000',
                'smoke_hour' => 10,
            ],
            [
                'code' => 'inv_more_hamburg_kombi',
                'name' => 'MORE Hamburg-Kombi',
                'type' => InventoryType::Kombi,
                'sort' => 5,
                'hour_min' => 0,
                'hour_max' => 23,
                'expected_items' => 72,
                'allows_single' => true,
                'second_price' => '1.4000',
                'smoke_hour' => 10,
            ],
            [
                'code' => 'inv_more_hamburg_kombi_plus',
                'name' => 'MORE Hamburg-Kombi+',
                'type' => InventoryType::Kombi,
                'sort' => 6,
                'hour_min' => 5,
                'hour_max' => 18,
                'expected_items' => 42,
                'allows_single' => false,
                'second_price' => '2.0000',
                'smoke_hour' => 10,
            ],
            [
                'code' => 'inv_ffn_hamburg_plus',
                'name' => 'ffn Hamburg Plus',
                'type' => InventoryType::Sender,
                'sort' => 7,
                'hour_min' => 5,
                'hour_max' => 18,
                'expected_items' => 42,
                'allows_single' => false,
                'second_price' => '2.1000',
                'smoke_hour' => 10,
            ],
            [
                'code' => 'inv_radio_bollerwagen_dab_plus_hamburg',
                'name' => 'RADIO BOLLERWAGEN DAB+ Hamburg',
                'type' => InventoryType::Sender,
                'sort' => 8,
                'hour_min' => 5,
                'hour_max' => 23,
                'expected_items' => 57,
                'allows_single' => false,
                'second_price' => '2.2000',
                'smoke_hour' => 10,
            ],
        ];
    }

    /**
     * @return array{
     *     organization: Organization,
     *     spotClassic: AdvertisingMedium,
     *     spotSingle: AdvertisingMedium,
     *     inventories: array<string, Inventory>,
     *     definitions: list<array<string, mixed>>,
     *     year: int
     * }
     */
    protected function createEightSpotInventoryCalculabilityCatalog(int $year = 2026): array
    {
        $organization = Organization::factory()->create();
        $spotClassic = AdvertisingMedium::factory()->create([
            'name' => 'Werbespot',
            'code' => 'spot_classic',
            'sort' => 1,
        ]);
        $spotSingle = AdvertisingMedium::factory()->create([
            'name' => 'Single-Spot',
            'code' => 'spot_single',
            'sort' => 2,
        ]);

        $inventories = [];
        foreach ($this->eightSpotInventoryDefinitions() as $definition) {
            $inventory = Inventory::factory()->create([
                'organization_id' => $organization->id,
                'name' => $definition['name'],
                'code' => $definition['code'],
                'type' => $definition['type'],
                'sort' => $definition['sort'],
                'is_active' => true,
            ]);
            $inventories[$definition['code']] = $inventory;

            InventoryMediumRule::factory()->create([
                'inventory_id' => $inventory->id,
                'advertising_medium_id' => $spotClassic->id,
                'is_active' => true,
                'surcharge_percent' => '0.0000',
            ]);

            if ($definition['allows_single']) {
                InventoryMediumRule::factory()->create([
                    'inventory_id' => $inventory->id,
                    'advertising_medium_id' => $spotSingle->id,
                    'is_active' => true,
                    'surcharge_percent' => '0.0000',
                ]);
            }

            $list = PriceList::factory()->create([
                'inventory_id' => $inventory->id,
                'status' => PriceListStatus::Active,
                'year' => $year,
                'name' => $definition['name'].' '.$year,
                'version' => $year.'-'.$definition['code'],
                'valid_from' => sprintf('%d-01-01', $year),
            ]);

            for ($hour = $definition['hour_min']; $hour <= $definition['hour_max']; $hour++) {
                foreach ([DayGroup::MoFr, DayGroup::Sa, DayGroup::So] as $group) {
                    PriceListItem::factory()->create([
                        'price_list_id' => $list->id,
                        'hour' => $hour,
                        'day_group' => $group,
                        'second_price' => $definition['second_price'],
                    ]);
                }
            }
        }

        return [
            'organization' => $organization,
            'spotClassic' => $spotClassic,
            'spotSingle' => $spotSingle,
            'inventories' => $inventories,
            'definitions' => $this->eightSpotInventoryDefinitions(),
            'year' => $year,
        ];
    }

    /**
     * Erwartetes Mediabrutto für Average, eine Stunde, Index 100, kein Aufschlag.
     */
    protected function expectedMediaGrossFromSecondPrice(string $secondPrice, int $lengthSeconds, int $spots): string
    {
        $gross = (float) $secondPrice * $lengthSeconds * $spots;

        return number_format($gross, 2, '.', '');
    }
}
