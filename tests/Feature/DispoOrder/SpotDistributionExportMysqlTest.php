<?php

namespace Tests\Feature\DispoOrder;

use App\Enums\Role;
use App\Models\AuditEvent;
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

    private function requireMysql(string $label): void
    {
        MysqlTestDatabaseGuard::assertSafeBeforeDestructiveTraits();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped($label.' erfordert MySQL (GitHub-Job mysql).');
        }
    }
}
