<?php

namespace Tests\Feature\Calculation;

use App\Enums\ComponentCalculationStrategy;
use App\Enums\Role;
use App\Models\AdvertisingMedium;
use App\Models\InventoryMediumRule;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\User;
use App\Services\Calculation\CalculationWriter;
use App\Services\DispoOrder\DispoOrderWriter;
use App\Services\DynamicField\ConfigurationSnapshotFreezeService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesSpotClassicCatalog;
use Tests\Support\MysqlTestDatabaseGuard;
use Tests\TestCase;

/**
 * BL-P4-02e MySQL: Tandem/Tridem Migration/Relations, Preview/Store, Profil-Freeze.
 */
class SpotClassicTandemTridemMysqlTest extends TestCase
{
    use CreatesSpotClassicCatalog;
    use DatabaseMigrations;

    public function test_mysql_tandem_preview_matches_store(): void
    {
        $this->requireMysql('BL-P4-02e tandem');

        $catalog = $this->createSpotClassicCatalog();
        $tandem = $this->attachTandem($catalog);
        $this->setHamburgSecondPrice($catalog, '2.0000');
        $user = User::factory()->role(Role::Sales)->create();

        $payload = $this->tandemPayload($catalog, $tandem);
        $writer = app(CalculationWriter::class);
        $preview = $writer->preview($payload, $user)->toArray();
        $calc = $writer->create($payload, $user);

        $this->assertSame('600.00', $preview['media_gross']);
        $this->assertSame('600.00', (string) $calc->media_gross);
        $this->assertSame('tandem', $calc->positions->first()->component_profile?->value);
        $this->assertTrue($calc->positions->first()->components()->exists());
    }

    public function test_mysql_tridem_with_ae_fixed_price(): void
    {
        $this->requireMysql('BL-P4-02e tridem fixed');

        $catalog = $this->createSpotClassicCatalog();
        $tridem = $this->attachTridem($catalog);
        $this->setHamburgSecondPrice($catalog, '2.0000');
        $user = User::factory()->role(Role::Sales)->create();

        $payload = $this->tridemPayload($catalog, $tridem);
        $payload['ae_enabled'] = true;
        $payload['positions'][0]['pricing_settlement_mode'] = 'fixed_price';
        $payload['positions'][0]['fixed_price_nn'] = '5000.00';

        $writer = app(CalculationWriter::class);
        $preview = $writer->preview($payload, $user)->toArray();
        $calc = $writer->create($payload, $user);

        $this->assertSame('760.00', $preview['media_gross']);
        $this->assertSame('5000.00', $preview['nn_invest']);
        $this->assertSame('882.35', $preview['ae_total']);
        $this->assertSame('fixed_price', $calc->positions->first()->pricing_settlement_mode->value);
    }

    public function test_mysql_null_profile_allonge_individual_still_works(): void
    {
        $this->requireMysql('BL-P4-02e regression allonge');

        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();

        InventoryMediumRule::query()
            ->where('inventory_id', $catalog['hamburg']->id)
            ->where('advertising_medium_id', $catalog['medium']->id)
            ->update(['component_calculation_strategy' => ComponentCalculationStrategy::Individual->value]);

        $this->setHamburgSecondPrice($catalog, '2.0000');

        $payload = [
            'planning_mode' => 'manual',
            'customer_name' => 'MySQL Allonge Regression',
            'schema_fingerprint' => app(ConfigurationSnapshotFreezeService::class)
                ->resolveLiveSchemaForCalculationV3()['schema_fingerprint'],
            'positions' => [[
                'inventory_id' => $catalog['hamburg']->id,
                'advertising_medium_id' => $catalog['medium']->id,
                'schema_fingerprint' => app(ConfigurationSnapshotFreezeService::class)
                    ->resolveLivePositionSchema((int) $catalog['medium']->id)['schema_fingerprint'],
                'spot_method' => 'average',
                'length_seconds' => 30,
                'component_calculation_strategy' => 'individual',
                'components' => [
                    ['role' => 'main_spot', 'label' => 'Hauptspot', 'length_seconds' => 20, 'sort' => 0],
                    ['role' => 'allonge', 'label' => 'Allonge', 'length_seconds' => 10, 'sort' => 1],
                ],
                'total_spot_count' => 10,
                'plan_rows' => [['hour' => 8, 'day_group' => 'mo_fr']],
                'time_ranges' => [[
                    'start_hour' => 8,
                    'end_hour_exclusive' => 9,
                    'day_group' => 'mo_fr',
                    'spot_count' => 10,
                ]],
            ]],
        ];

        $writer = app(CalculationWriter::class);
        $preview = $writer->preview($payload, $user)->toArray();
        $calc = $writer->create($payload, $user);

        $this->assertSame('640.00', $preview['media_gross']);
        $this->assertNull($calc->positions->first()->component_profile);
        $this->assertSame('individual', $calc->positions->first()->component_calculation_strategy);
    }

    public function test_mysql_dispo_snapshot_freezes_component_profile(): void
    {
        $this->requireMysql('BL-P4-02e profile freeze');

        $catalog = $this->createSpotClassicCatalog();
        $tandem = $this->attachTandem($catalog);
        $this->setHamburgSecondPrice($catalog, '2.0000');
        $user = User::factory()->role(Role::Sales)->create();
        $writer = app(CalculationWriter::class);
        $calc = $writer->create($this->tandemPayload($catalog, $tandem), $user);

        $order = app(DispoOrderWriter::class)
            ->createFromCalculation($calc, [$calc->positions->first()->id], $user)
            ->order
            ->load('positions');

        $this->assertSame('tandem', $order->positions->first()->component_profile?->value);
        $this->assertCount(2, $order->positions->first()->components_snapshot);

        $payload = $writer->payloadFromCalculation($calc);
        $payload['lock_version'] = $calc->lock_version;
        $payload['positions'][0]['advertising_medium_id'] = $catalog['medium']->id;
        $payload['positions'][0]['schema_fingerprint'] = app(ConfigurationSnapshotFreezeService::class)
            ->resolveLivePositionSchema((int) $catalog['medium']->id)['schema_fingerprint'];
        $payload['positions'][0]['components'] = [];
        $payload['positions'][0]['component_calculation_strategy'] = null;
        $payload['positions'][0]['length_seconds'] = 30;
        $writer->update($calc, $payload, $user);

        $order->refresh()->load('positions');
        $this->assertSame('tandem', $order->positions->first()->component_profile?->value);
        $this->assertCount(2, $order->positions->first()->components_snapshot);
    }

    /**
     * @param  array{hamburg: mixed}  $catalog
     */
    private function attachTandem(array $catalog): AdvertisingMedium
    {
        $medium = AdvertisingMedium::factory()->tandem()->create(['code' => 'mysql_tandem_'.uniqid()]);
        InventoryMediumRule::factory()->create([
            'inventory_id' => $catalog['hamburg']->id,
            'advertising_medium_id' => $medium->id,
            'component_calculation_strategy' => ComponentCalculationStrategy::SharedTotalLength,
        ]);

        return $medium;
    }

    /**
     * @param  array{hamburg: mixed}  $catalog
     */
    private function attachTridem(array $catalog): AdvertisingMedium
    {
        $medium = AdvertisingMedium::factory()->tridem()->create(['code' => 'mysql_tridem_'.uniqid()]);
        InventoryMediumRule::factory()->create([
            'inventory_id' => $catalog['hamburg']->id,
            'advertising_medium_id' => $medium->id,
            'component_calculation_strategy' => ComponentCalculationStrategy::SharedTotalLength,
        ]);

        return $medium;
    }

    /**
     * @param  array{hamburg: mixed}  $catalog
     */
    private function setHamburgSecondPrice(array $catalog, string $price): void
    {
        foreach (PriceList::query()->where('inventory_id', $catalog['hamburg']->id)->pluck('id') as $listId) {
            PriceListItem::query()->where('price_list_id', $listId)->update(['second_price' => $price]);
        }
    }

    /**
     * @param  array{hamburg: mixed}  $catalog
     * @return array<string, mixed>
     */
    private function tandemPayload(array $catalog, AdvertisingMedium $medium): array
    {
        return [
            'planning_mode' => 'manual',
            'customer_name' => 'MySQL Tandem',
            'schema_fingerprint' => app(ConfigurationSnapshotFreezeService::class)
                ->resolveLiveSchemaForCalculationV3()['schema_fingerprint'],
            'positions' => [[
                'inventory_id' => $catalog['hamburg']->id,
                'advertising_medium_id' => $medium->id,
                'schema_fingerprint' => app(ConfigurationSnapshotFreezeService::class)
                    ->resolveLivePositionSchema((int) $medium->id)['schema_fingerprint'],
                'spot_method' => 'average',
                'length_seconds' => 30,
                'component_calculation_strategy' => 'shared_total_length',
                'components' => [
                    ['role' => 'main_spot', 'label' => 'Hauptspot', 'length_seconds' => 20, 'sort' => 1],
                    ['role' => 'reminder', 'label' => 'Reminder', 'length_seconds' => 10, 'sort' => 2],
                ],
                'total_spot_count' => 10,
                'plan_rows' => [['hour' => 8, 'day_group' => 'mo_fr']],
                'time_ranges' => [[
                    'start_hour' => 8,
                    'end_hour_exclusive' => 9,
                    'day_group' => 'mo_fr',
                    'spot_count' => 10,
                ]],
            ]],
        ];
    }

    /**
     * @param  array{hamburg: mixed}  $catalog
     * @return array<string, mixed>
     */
    private function tridemPayload(array $catalog, AdvertisingMedium $medium): array
    {
        $payload = $this->tandemPayload($catalog, $medium);
        $payload['customer_name'] = 'MySQL Tridem';
        $payload['positions'][0]['length_seconds'] = 40;
        $payload['positions'][0]['components'] = [
            ['role' => 'main_spot', 'label' => 'Hauptspot', 'length_seconds' => 20, 'sort' => 1],
            ['role' => 'reminder', 'label' => 'Reminder', 'length_seconds' => 10, 'sort' => 2],
            ['role' => 'reminder', 'label' => 'Reminder', 'length_seconds' => 10, 'sort' => 3],
        ];

        return $payload;
    }

    private function requireMysql(string $label): void
    {
        MysqlTestDatabaseGuard::assertSafeBeforeDestructiveTraits();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped($label.' erfordert MySQL (GitHub-Job mysql).');
        }
    }
}
