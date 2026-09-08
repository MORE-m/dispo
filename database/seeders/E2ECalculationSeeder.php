<?php

namespace Database\Seeders;

use App\Enums\DayGroup;
use App\Enums\PriceListStatus;
use App\Enums\Role;
use App\Models\AdvertisingMedium;
use App\Models\Inventory;
use App\Models\InventoryMediumRule;
use App\Models\Organization;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\User;
use App\Services\Calculation\CalculationWriter;
use App\Services\DispoOrder\DispoOrderWriter;
use App\Services\DynamicField\ConfigurationSnapshotFreezeService;
use Illuminate\Database\Seeder;

/**
 * Minimaler Katalog und Testbenutzer für Playwright-E2E (nur lokal/CI).
 */
class E2ECalculationSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            ['email' => 'sales@example.com', 'name' => 'E2E Vertrieb', 'role' => Role::Sales],
            ['email' => 'sales-b@example.com', 'name' => 'E2E Vertrieb B', 'role' => Role::Sales],
            ['email' => 'sales-limited@example.com', 'name' => 'E2E Vertrieb Limit', 'role' => Role::Sales, 'discount_limit_percent' => '10'],
            ['email' => 'disposition@example.com', 'name' => 'E2E Disposition', 'role' => Role::Disposition],
            ['email' => 'admin@example.com', 'name' => 'E2E Admin', 'role' => Role::Admin],
            ['email' => 'management@example.com', 'name' => 'E2E Geschäftsführung', 'role' => Role::Management],
        ] as $attrs) {
            User::query()->updateOrCreate(
                ['email' => $attrs['email']],
                [
                    'name' => $attrs['name'],
                    'password' => 'password',
                    'role' => $attrs['role'],
                    'discount_limit_percent' => $attrs['discount_limit_percent'] ?? null,
                ],
            );
        }

        if (Organization::query()->exists()) {
            return;
        }

        $organization = Organization::factory()->create(['name' => 'E2E Sendergruppe']);
        $medium = AdvertisingMedium::factory()->create(['code' => 'spot_classic']);

        /** @var array<string, Inventory> $inventories */
        $inventories = [];
        foreach ([
            ['RH', 'Radio Hamburg', 1, '/images/senders/radio-hamburg.png'],
            ['RAH', 'ROCK ANTENNE Hamburg', 2, null],
            ['OAH', '80er 90er OLDIE ANTENNE Hamburg', 3, '/images/senders/80er-90er-oldie-antenne-hamburg.png'],
        ] as [$code, $name, $sort, $logoPath]) {
            $inventory = Inventory::factory()->create([
                'organization_id' => $organization->id,
                'name' => $name,
                'code' => $code,
                'sort' => $sort,
                'logo_path' => $logoPath,
            ]);
            $inventories[$code] = $inventory;

            InventoryMediumRule::factory()->create([
                'inventory_id' => $inventory->id,
                'advertising_medium_id' => $medium->id,
            ]);

            $list = PriceList::factory()->create([
                'inventory_id' => $inventory->id,
                'status' => PriceListStatus::Active,
                'version' => 'e2e-'.$code,
                'valid_from' => now()->toDateString(),
            ]);

            foreach (range(0, 23) as $hour) {
                foreach ([DayGroup::MoFr, DayGroup::Sa, DayGroup::So] as $group) {
                    $base = $code === 'RH' ? '1.0000' : '0.8000';
                    PriceListItem::factory()->create([
                        'price_list_id' => $list->id,
                        'hour' => $hour,
                        'day_group' => $group,
                        'second_price' => $hour >= 14 ? ($code === 'RH' ? '1.5000' : '1.2000') : $base,
                    ]);
                }
            }
        }

        $limited = User::query()->where('email', 'sales-limited@example.com')->firstOrFail();
        $hamburg = $inventories['RH'];
        $fingerprint = app(ConfigurationSnapshotFreezeService::class)
            ->resolveLiveSchemaForCalculation()['schema_fingerprint'];
        $calculation = app(CalculationWriter::class)->create([
            'planning_mode' => 'manual',
            'schema_fingerprint' => $fingerprint,
            'customer_name' => 'Sonderfreigabe E2E GmbH',
            'campaign' => 'Sonderfreigabe-Kampagne',
            'product_title' => 'Sonderfreigabe Produkt',
            'order_discount_percent' => '0',
            'ae_enabled' => false,
            'positions' => [[
                'inventory_id' => $hamburg->id,
                'advertising_medium_id' => $medium->id,
                'spot_method' => 'average',
                'length_seconds' => 30,
                'total_spot_count' => 10,
                'position_discount_percent' => '20',
                'ae_percent' => '15',
                'plan_rows' => [['hour' => 8, 'day_group' => 'mo_fr']],
            ]],
        ], $limited);

        /** @var list<int> $positionIds */
        $positionIds = array_values($calculation->positions()->pluck('id')->all());

        app(DispoOrderWriter::class)->createFromCalculation(
            $calculation,
            $positionIds,
            $limited,
        );
    }
}
