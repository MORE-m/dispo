<?php

namespace Tests\Feature\DispoOrder;

use App\Enums\DerivedCampaignPeriodStatus;
use App\Enums\Role;
use App\Models\User;
use App\Services\Calculation\CalculationWriter;
use App\Services\DispoOrder\DispoOrderWriter;
use App\Services\DynamicField\ConfigurationSnapshotFreezeService;
use App\Support\DispoOrder\DerivedCampaignPeriodContract;
use App\Support\PriceList\PriceListCalendar;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesSpotClassicCatalog;
use Tests\Support\MysqlTestDatabaseGuard;
use Tests\TestCase;

/**
 * DSP-DCP-001 MySQL: kleines Subset (JSON-Snapshot + Presenter).
 */
class DerivedCampaignPeriodMysqlTest extends TestCase
{
    use CreatesSpotClassicCatalog;
    use DatabaseMigrations;

    public function test_mysql_create_calendar_persists_derived_json_snapshot(): void
    {
        $this->requireMysql('DSP-DCP-001 derived campaign period');

        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $year = PriceListCalendar::currentYear();
        $start = sprintf('%04d-09-14', $year);
        $end = sprintf('%04d-09-16', $year);
        $fingerprint = app(ConfigurationSnapshotFreezeService::class)
            ->resolveLiveSchemaForCalculationV3()['schema_fingerprint'];
        $positionFingerprint = app(ConfigurationSnapshotFreezeService::class)
            ->resolveLivePositionSchema((int) $catalog['medium']->id)['schema_fingerprint'];

        $calculation = app(CalculationWriter::class)->create([
            'planning_mode' => 'manual',
            'customer_name' => 'MySQL Derived GmbH',
            'order_discount_percent' => '0',
            'schema_fingerprint' => $fingerprint,
            'dynamic_field_values' => [
                'campaign_period' => ['start' => '2026-01-01', 'end' => '2026-01-31'],
            ],
            'positions' => [[
                'inventory_id' => $catalog['hamburg']->id,
                'advertising_medium_id' => $catalog['medium']->id,
                'schema_fingerprint' => $positionFingerprint,
                'spot_method' => 'calendar',
                'calculation_method_key' => 'calendar',
                'length_seconds' => 30,
                'position_discount_percent' => '0',
                'ae_percent' => '0',
                'planner_entries' => [
                    ['date' => $end, 'hour' => 10, 'spot_count' => 1],
                    ['date' => $start, 'hour' => 8, 'spot_count' => 2],
                ],
            ]],
        ], $user);

        $position = $calculation->positions()->firstOrFail();
        $order = app(DispoOrderWriter::class)
            ->createFromCalculation($calculation, [$position->id], $user)
            ->order
            ->fresh();

        $this->assertSame($start, $order->derived_campaign_period_start?->toDateString());
        $this->assertSame($end, $order->derived_campaign_period_end?->toDateString());
        $this->assertSame(DerivedCampaignPeriodStatus::Complete, $order->derived_campaign_period_status);
        $this->assertIsArray($order->derived_campaign_period_snapshot);
        $this->assertSame(
            DerivedCampaignPeriodContract::CONTRACT_VERSION,
            $order->derived_campaign_period_snapshot['contract_version'] ?? null,
        );
        $this->assertSame(1, $order->derived_campaign_period_snapshot['contributing_count'] ?? null);

        $raw = DB::table('dispo_orders')->where('id', $order->id)->value('derived_campaign_period_snapshot');
        $this->assertNotNull($raw);
        $decoded = is_string($raw) ? json_decode($raw, true) : $raw;
        $this->assertIsArray($decoded);
        $this->assertSame(DerivedCampaignPeriodContract::CONTRACT_VERSION, $decoded['contract_version'] ?? null);

        $this->actingAs($user)
            ->get(route('dispo-orders.show', $order))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('order.derived_campaign_period.start', $start)
                ->where('order.derived_campaign_period.end', $end)
                ->where('order.derived_campaign_period.status', 'complete')
                ->where('order.derived_campaign_period.conflict_with_calculation', true));
    }

    public function test_mysql_average_closed_flight_derives_complete(): void
    {
        $this->requireMysql('DSP-DCP-001 average flight');

        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $fingerprint = app(ConfigurationSnapshotFreezeService::class)
            ->resolveLiveSchemaForCalculationV3()['schema_fingerprint'];
        $positionFingerprint = app(ConfigurationSnapshotFreezeService::class)
            ->resolveLivePositionSchema((int) $catalog['medium']->id)['schema_fingerprint'];

        $calculation = app(CalculationWriter::class)->create([
            'planning_mode' => 'manual',
            'customer_name' => 'MySQL Average Derived GmbH',
            'order_discount_percent' => '0',
            'schema_fingerprint' => $fingerprint,
            'dynamic_field_values' => ['campaign_period' => null],
            'positions' => [[
                'inventory_id' => $catalog['hamburg']->id,
                'advertising_medium_id' => $catalog['medium']->id,
                'schema_fingerprint' => $positionFingerprint,
                'spot_method' => 'average',
                'length_seconds' => 30,
                'total_spot_count' => 10,
                'position_discount_percent' => '0',
                'ae_percent' => '0',
                'plan_rows' => [['hour' => 8, 'day_group' => 'mo_fr']],
                'time_ranges' => [[
                    'start_hour' => 8,
                    'end_hour_exclusive' => 9,
                    'day_group' => 'mo_fr',
                    'spot_count' => 10,
                ]],
                'dynamic_field_values' => [
                    'period_open' => false,
                    'position_flight_period' => [
                        'start' => '2026-03-01',
                        'end' => '2026-03-31',
                    ],
                ],
            ]],
        ], $user);

        $position = $calculation->positions()->firstOrFail();
        $order = app(DispoOrderWriter::class)
            ->createFromCalculation($calculation, [$position->id], $user)
            ->order
            ->fresh();

        $this->assertSame('2026-03-01', $order->derived_campaign_period_start?->toDateString());
        $this->assertSame('2026-03-31', $order->derived_campaign_period_end?->toDateString());
        $this->assertSame(DerivedCampaignPeriodStatus::Complete, $order->derived_campaign_period_status);
        $this->assertSame(
            DerivedCampaignPeriodContract::SOURCE_FLIGHT,
            $order->derived_campaign_period_snapshot['positions'][0]['source'] ?? null,
        );
    }

    private function requireMysql(string $label): void
    {
        MysqlTestDatabaseGuard::assertSafeBeforeDestructiveTraits();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped($label.' erfordert MySQL (GitHub-Job mysql).');
        }
    }
}
