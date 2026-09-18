<?php

namespace Tests\Feature\Calculation;

use App\Enums\DayGroup;
use App\Enums\Role;
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
 * BL-P4-02d MySQL: Settlement-Spalten, Preview/Store, Dispo-Snapshot.
 */
class SpotClassicFixedPriceSettlementMysqlTest extends TestCase
{
    use CreatesSpotClassicCatalog;
    use DatabaseMigrations;

    public function test_mysql_fixed_price_preview_store_and_dispo_snapshot(): void
    {
        $this->requireMysql('BL-P4-02d');

        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();

        $payload = [
            'planning_mode' => 'manual',
            'customer_name' => 'MySQL Festpreis',
            'order_discount_percent' => '0',
            'schema_fingerprint' => app(ConfigurationSnapshotFreezeService::class)
                ->resolveLiveSchemaForCalculationV3()['schema_fingerprint'],
            'positions' => [[
                'inventory_id' => $catalog['hamburg']->id,
                'advertising_medium_id' => $catalog['medium']->id,
                'schema_fingerprint' => app(ConfigurationSnapshotFreezeService::class)
                    ->resolveLivePositionSchema((int) $catalog['medium']->id)['schema_fingerprint'],
                'spot_method' => 'average',
                'length_seconds' => 30,
                'total_spot_count' => 10,
                'pricing_settlement_mode' => 'fixed_price',
                'fixed_price_nn' => '240.00',
                'plan_rows' => [['hour' => 8, 'day_group' => DayGroup::MoFr->value]],
                'time_ranges' => [[
                    'start_hour' => 8,
                    'end_hour_exclusive' => 9,
                    'day_group' => DayGroup::MoFr->value,
                    'spot_count' => 10,
                ]],
            ]],
        ];

        $writer = app(CalculationWriter::class);
        $preview = $writer->preview($payload, $user)->toArray();
        $calc = $writer->create($payload, $user);

        $this->assertSame('300.00', $preview['media_gross']);
        $this->assertSame('240.00', $preview['nn_invest']);
        $this->assertSame('240.00', (string) $calc->nn_invest);
        $this->assertSame('fixed_price', $calc->positions->first()->pricing_settlement_mode->value);

        $order = app(DispoOrderWriter::class)
            ->createFromCalculation($calc, [$calc->positions->first()->id], $user)
            ->order
            ->load('positions');

        $this->assertSame('fixed_price', $order->positions->first()->pricing_settlement_mode->value);
        $this->assertSame('240.00', (string) $order->positions->first()->fixed_price_nn);
    }

    private function requireMysql(string $label): void
    {
        MysqlTestDatabaseGuard::assertSafeBeforeDestructiveTraits();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped($label.' erfordert MySQL (GitHub-Job mysql).');
        }
    }
}
