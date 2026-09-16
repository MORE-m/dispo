<?php

namespace Tests\Feature\Calculation;

use App\Enums\ComponentCalculationStrategy;
use App\Enums\Role;
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
 * BL-P4-02c MySQL: Migration/Relations, Preview/Store, Snapshot-Retention.
 */
class SpotClassicComponentsMysqlTest extends TestCase
{
    use CreatesSpotClassicCatalog;
    use DatabaseMigrations;

    public function test_mysql_components_preview_store_and_snapshot_retention(): void
    {
        $this->requireMysql('BL-P4-02c');

        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();

        InventoryMediumRule::query()
            ->where('inventory_id', $catalog['hamburg']->id)
            ->update(['component_calculation_strategy' => ComponentCalculationStrategy::Individual->value]);

        foreach (PriceList::query()->where('inventory_id', $catalog['hamburg']->id)->pluck('id') as $listId) {
            PriceListItem::query()->where('price_list_id', $listId)->update(['second_price' => '2.0000']);
        }

        $payload = [
            'planning_mode' => 'manual',
            'customer_name' => 'MySQL Komponenten',
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
        $this->assertSame('640.00', (string) $calc->media_gross);
        $this->assertTrue($calc->positions->first()->components()->exists());

        $order = app(DispoOrderWriter::class)
            ->createFromCalculation($calc, [$calc->positions->first()->id], $user)
            ->order
            ->load('positions');

        InventoryMediumRule::query()
            ->where('inventory_id', $catalog['hamburg']->id)
            ->update(['component_calculation_strategy' => ComponentCalculationStrategy::SharedTotalLength->value]);

        $payload = $writer->payloadFromCalculation($calc);
        $payload['lock_version'] = $calc->lock_version;
        $payload['positions'][0]['components'] = [];
        $payload['positions'][0]['component_calculation_strategy'] = null;
        $payload['positions'][0]['length_seconds'] = 30;
        $writer->update($calc, $payload, $user);

        $order->refresh()->load('positions');
        $this->assertSame('individual', $order->positions->first()->component_calculation_strategy);
        $this->assertCount(2, $order->positions->first()->components_snapshot);
    }

    public function test_mysql_calendar_shared_total_length_components(): void
    {
        $this->requireMysql('BL-P4-02c calendar');

        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();

        InventoryMediumRule::query()
            ->where('inventory_id', $catalog['hamburg']->id)
            ->update(['component_calculation_strategy' => ComponentCalculationStrategy::SharedTotalLength->value]);

        $list = PriceList::query()
            ->where('inventory_id', $catalog['hamburg']->id)
            ->where('status', 'active')
            ->firstOrFail();
        PriceListItem::query()->where('price_list_id', $list->id)->where('hour', 8)->update(['second_price' => '2.0000']);
        PriceListItem::query()->where('price_list_id', $list->id)->where('hour', 14)->update(['second_price' => '3.0000']);

        $year = (int) $list->year;
        $payload = [
            'planning_mode' => 'manual',
            'customer_name' => 'MySQL Calendar Komponenten',
            'schema_fingerprint' => app(ConfigurationSnapshotFreezeService::class)
                ->resolveLiveSchemaForCalculationV3()['schema_fingerprint'],
            'positions' => [[
                'inventory_id' => $catalog['hamburg']->id,
                'advertising_medium_id' => $catalog['medium']->id,
                'schema_fingerprint' => app(ConfigurationSnapshotFreezeService::class)
                    ->resolveLivePositionSchema((int) $catalog['medium']->id)['schema_fingerprint'],
                'spot_method' => 'calendar',
                'length_seconds' => 30,
                'component_calculation_strategy' => 'shared_total_length',
                'components' => [
                    ['role' => 'main_spot', 'label' => 'Hauptspot', 'length_seconds' => 20, 'sort' => 0],
                    ['role' => 'allonge', 'label' => 'Allonge', 'length_seconds' => 10, 'sort' => 1],
                ],
                'total_spot_count' => 15,
                'planner_entries' => [
                    ['date' => "{$year}-09-14", 'hour' => 8, 'spot_count' => 10],
                    ['date' => "{$year}-09-14", 'hour' => 14, 'spot_count' => 5],
                ],
            ]],
        ];

        $writer = app(CalculationWriter::class);
        $preview = $writer->preview($payload, $user)->toArray();
        $calc = $writer->create($payload, $user);

        // 10×2×30×1 + 5×3×30×1 = 600 + 450 = 1050
        $this->assertSame('1050.00', $preview['media_gross']);
        $this->assertSame('1050.00', (string) $calc->media_gross);
        $this->assertSame('shared_total_length', $calc->positions->first()->component_calculation_strategy);
        $this->assertCount(2, $calc->positions->first()->components);
    }

    private function requireMysql(string $label): void
    {
        MysqlTestDatabaseGuard::assertSafeBeforeDestructiveTraits();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped($label.' erfordert MySQL (GitHub-Job mysql).');
        }
    }
}
