<?php

namespace Tests\Feature\DispoOrder;

use App\Enums\Role;
use App\Models\AdvertisingMedium;
use App\Models\AuditEvent;
use App\Models\InventoryMediumRule;
use App\Models\User;
use App\Services\Calculation\CalculationWriter;
use App\Services\DispoOrder\DispoOrderWriter;
use App\Services\DispoOrder\SpotDistributionExport\SpotDistributionExportService;
use App\Services\DynamicField\ConfigurationSnapshotFreezeService;
use App\Support\PriceList\PriceListCalendar;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Concerns\CreatesSpotClassicCatalog;
use Tests\Support\MysqlTestDatabaseGuard;
use Tests\TestCase;

/**
 * SPT-008 MySQL: JSON-Snapshot-Roundtrip als Exportquelle.
 */
class SpotDistributionExportMysqlTest extends TestCase
{
    use CreatesSpotClassicCatalog;
    use DatabaseMigrations;

    public function test_mysql_json_snapshot_export_and_audit(): void
    {
        $this->requireMysql('SPT-008 spot distribution export');

        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $year = PriceListCalendar::currentYear();
        $fingerprint = app(ConfigurationSnapshotFreezeService::class)
            ->resolveLiveSchemaForCalculationV3()['schema_fingerprint'];
        $positionFingerprint = app(ConfigurationSnapshotFreezeService::class)
            ->resolveLivePositionSchema((int) $catalog['medium']->id)['schema_fingerprint'];

        $calculation = app(CalculationWriter::class)->create([
            'planning_mode' => 'manual',
            'customer_name' => 'MySQL Export GmbH',
            'order_discount_percent' => '0',
            'schema_fingerprint' => $fingerprint,
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
                    ['date' => sprintf('%04d-09-14', $year), 'hour' => 8, 'spot_count' => 2],
                    ['date' => sprintf('%04d-09-14', $year), 'hour' => 10, 'spot_count' => 1],
                ],
            ]],
        ], $user);

        $position = $calculation->positions()->firstOrFail();
        $order = app(DispoOrderWriter::class)
            ->createFromCalculation($calculation, [$position->id], $user)
            ->order
            ->fresh(['positions']);

        $this->assertIsArray($order->positions->first()->planner_entries_snapshot);
        $this->assertCount(2, $order->positions->first()->planner_entries_snapshot);

        $response = $this->actingAs($user)
            ->get(route('dispo-orders.export-spot-distribution', $order));

        $response->assertOk();
        $binary = $response->streamedContent();
        $path = tempnam(sys_get_temp_dir(), 'spt008m');
        file_put_contents($path, $binary);
        try {
            $sheet = IOFactory::load($path)->getActiveSheet();
            $this->assertSame('Spotverteilung', $sheet->getTitle());
            $this->assertSame(2, max(0, (int) $sheet->getHighestDataRow() - 1));
        } finally {
            @unlink($path);
        }

        $this->assertSame(
            1,
            AuditEvent::query()->where('action', SpotDistributionExportService::AUDIT_ACTION)->count(),
        );

        $this->actingAs(User::factory()->role(Role::ProductManagement)->create())
            ->get(route('dispo-orders.export-spot-distribution', $order))
            ->assertForbidden();
    }

    public function test_mysql_multi_calendar_positions_with_overlapping_date_hour(): void
    {
        $this->requireMysql('SPT-008 multi calendar export');

        $catalog = $this->createSpotClassicCatalog();
        $tandem = AdvertisingMedium::factory()->tandem()->create(['code' => 'tandem_spt008_mysql']);
        InventoryMediumRule::factory()->create([
            'inventory_id' => $catalog['hamburg']->id,
            'advertising_medium_id' => $tandem->id,
        ]);

        $user = User::factory()->role(Role::Sales)->create();
        $year = PriceListCalendar::currentYear();
        $date = sprintf('%04d-09-14', $year);
        $fingerprint = app(ConfigurationSnapshotFreezeService::class)
            ->resolveLiveSchemaForCalculationV3()['schema_fingerprint'];
        $classicFp = app(ConfigurationSnapshotFreezeService::class)
            ->resolveLivePositionSchema((int) $catalog['medium']->id)['schema_fingerprint'];
        $tandemFp = app(ConfigurationSnapshotFreezeService::class)
            ->resolveLivePositionSchema((int) $tandem->id)['schema_fingerprint'];

        $calculation = app(CalculationWriter::class)->create([
            'planning_mode' => 'manual',
            'customer_name' => 'MySQL Multi Export GmbH',
            'order_discount_percent' => '0',
            'schema_fingerprint' => $fingerprint,
            'positions' => [
                [
                    'inventory_id' => $catalog['hamburg']->id,
                    'advertising_medium_id' => $catalog['medium']->id,
                    'schema_fingerprint' => $classicFp,
                    'spot_method' => 'calendar',
                    'calculation_method_key' => 'calendar',
                    'length_seconds' => 30,
                    'position_discount_percent' => '0',
                    'ae_percent' => '0',
                    'planner_entries' => [
                        ['date' => $date, 'hour' => 8, 'spot_count' => 2],
                        ['date' => $date, 'hour' => 9, 'spot_count' => 1],
                    ],
                ],
                [
                    'inventory_id' => $catalog['rock']->id,
                    'advertising_medium_id' => $catalog['medium']->id,
                    'schema_fingerprint' => $classicFp,
                    'spot_method' => 'calendar',
                    'calculation_method_key' => 'calendar',
                    'length_seconds' => 30,
                    'position_discount_percent' => '0',
                    'ae_percent' => '0',
                    'planner_entries' => [
                        ['date' => $date, 'hour' => 8, 'spot_count' => 5],
                    ],
                ],
                [
                    'inventory_id' => $catalog['hamburg']->id,
                    'advertising_medium_id' => $tandem->id,
                    'schema_fingerprint' => $tandemFp,
                    'spot_method' => 'calendar',
                    'calculation_method_key' => 'calendar',
                    'length_seconds' => 30,
                    'component_calculation_strategy' => 'shared_total_length',
                    'position_discount_percent' => '0',
                    'ae_percent' => '0',
                    'planner_entries' => [
                        ['date' => $date, 'hour' => 8, 'spot_count' => 3],
                    ],
                    'components' => [
                        ['role' => 'main_spot', 'label' => 'Hauptspot', 'length_seconds' => 20, 'sort' => 1],
                        ['role' => 'reminder', 'label' => 'Reminder', 'length_seconds' => 10, 'sort' => 2],
                    ],
                ],
                [
                    'inventory_id' => $catalog['rock']->id,
                    'advertising_medium_id' => $catalog['medium']->id,
                    'schema_fingerprint' => $classicFp,
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
                ],
            ],
        ], $user);

        $ids = $calculation->positions()->pluck('id')->all();
        $order = app(DispoOrderWriter::class)
            ->createFromCalculation($calculation, $ids, $user)
            ->order
            ->fresh(['positions']);

        $this->assertCount(4, $order->positions);
        foreach ($order->positions->where('spot_method', 'calendar') as $position) {
            $this->assertIsArray($position->planner_entries_snapshot);
            $this->assertNotEmpty($position->planner_entries_snapshot);
        }

        $response = $this->actingAs($user)
            ->get(route('dispo-orders.export-spot-distribution', $order));
        $response->assertOk();

        $path = tempnam(sys_get_temp_dir(), 'spt008mm');
        file_put_contents($path, $response->streamedContent());
        try {
            $sheet = IOFactory::load($path)->getActiveSheet();
            $this->assertSame(4, max(0, (int) $sheet->getHighestDataRow() - 1));

            $labels = [];
            $qtyByLabel = [];
            for ($row = 2; $row <= 5; $row++) {
                $label = (string) $sheet->getCell('C'.$row)->getValue();
                $labels[] = $label;
                $qtyByLabel[$label] = ($qtyByLabel[$label] ?? 0)
                    + (int) $sheet->getCell('J'.$row)->getCalculatedValue();
            }

            $this->assertSame(['Position 1', 'Position 1', 'Position 2', 'Position 3'], $labels);
            $this->assertSame([
                'Position 1' => 3,
                'Position 2' => 5,
                'Position 3' => 3,
            ], $qtyByLabel);
            $this->assertSame('Tandem-Einheiten', (string) $sheet->getCell('K5')->getValue());
        } finally {
            @unlink($path);
        }
    }

    private function requireMysql(string $label): void
    {
        MysqlTestDatabaseGuard::assertSafeBeforeDestructiveTraits();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped($label.' erfordert MySQL (GitHub-Job mysql).');
        }
    }
}
