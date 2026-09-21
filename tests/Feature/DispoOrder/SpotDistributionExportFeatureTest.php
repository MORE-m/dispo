<?php

namespace Tests\Feature\DispoOrder;

use App\Enums\Role;
use App\Models\AdvertisingMedium;
use App\Models\AuditEvent;
use App\Models\DispoOrder;
use App\Models\InventoryMediumRule;
use App\Models\User;
use App\Services\Calculation\CalculationWriter;
use App\Services\DispoOrder\DispoOrderWriter;
use App\Services\DispoOrder\SpotDistributionExport\SpotDistributionExportDocument;
use App\Services\DispoOrder\SpotDistributionExport\SpotDistributionExportService;
use App\Services\DynamicField\ConfigurationSnapshotFreezeService;
use App\Support\PriceList\PriceListCalendar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Tests\Concerns\CreatesSpotClassicCatalog;
use Tests\TestCase;

class SpotDistributionExportFeatureTest extends TestCase
{
    use CreatesSpotClassicCatalog;
    use RefreshDatabase;

    public function test_authorized_download_returns_xlsx_and_audits(): void
    {
        $order = $this->createCalendarDispoOrder();
        $user = User::factory()->role(Role::Sales)->create();

        $response = $this->actingAs($user)
            ->get(route('dispo-orders.export-spot-distribution', $order));

        $response->assertOk();
        $response->assertHeader('content-type', SpotDistributionExportService::MIME_TYPE);
        $this->assertStringContainsString(
            'attachment',
            (string) $response->headers->get('content-disposition'),
        );
        $this->assertStringContainsString(
            '_Spotverteilung.xlsx',
            (string) $response->headers->get('content-disposition'),
        );

        $binary = $response->streamedContent();
        $sheet = $this->loadSheet($binary);
        $this->assertSame(SpotDistributionExportDocument::SHEET_TITLE, $sheet->getTitle());
        $this->assertSame(1, $sheet->getParent()->getSheetCount());
        $this->assertSame('Dispoauftrag', $sheet->getCell('A1')->getValue());
        $this->assertSame('Bestandteilausstrahlungen', $sheet->getCell('N1')->getValue());
        $this->assertNotNull($sheet->getCell('A2')->getValue());

        $headerRow = [];
        for ($col = 1; $col <= 14; $col++) {
            $coordinate = Coordinate::stringFromColumnIndex($col).'1';
            $headerRow[] = (string) $sheet->getCell($coordinate)->getValue();
        }
        $joined = implode('|', $headerRow);
        $this->assertStringNotContainsStringIgnoringCase('sekundenpreis', $joined);
        $this->assertStringNotContainsStringIgnoringCase('mediabrutto', $joined);
        $this->assertStringNotContainsStringIgnoringCase('rabatt', $joined);
        $this->assertStringNotContainsStringIgnoringCase('festpreis', $joined);
        $this->assertStringNotContainsStringIgnoringCase('ae-betrag', $joined);

        $audit = AuditEvent::query()
            ->where('action', SpotDistributionExportService::AUDIT_ACTION)
            ->firstOrFail();
        $this->assertSame($order->id, $audit->auditable_id);
        $this->assertSame('xlsx', $audit->new_values['format'] ?? null);
        $this->assertGreaterThan(0, $audit->new_values['exported_row_count'] ?? 0);

        $this->assertFalse(Storage::disk('local')->exists('exports'));
    }

    public function test_show_exposes_export_capability_props(): void
    {
        $order = $this->createCalendarDispoOrder();
        $user = User::factory()->role(Role::Disposition)->create();

        $this->actingAs($user)
            ->get(route('dispo-orders.show', $order))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('dispo-orders/show')
                ->where('spotDistributionExport.enabled', true)
                ->where('spotDistributionExport.mixed_order', false)
                ->where('spotDistributionExport.can_export', true)
                ->has('spotDistributionExport.url'));
    }

    public function test_unauthenticated_is_redirected(): void
    {
        $order = $this->createCalendarDispoOrder();

        $this->get(route('dispo-orders.export-spot-distribution', $order))
            ->assertRedirect();

        $this->assertSame(
            0,
            AuditEvent::query()->where('action', SpotDistributionExportService::AUDIT_ACTION)->count(),
        );
    }

    public function test_unauthorized_role_is_forbidden_without_audit(): void
    {
        $order = $this->createCalendarDispoOrder();
        $user = User::factory()->role(Role::ProductManagement)->create();

        $this->actingAs($user)
            ->get(route('dispo-orders.export-spot-distribution', $order))
            ->assertForbidden();

        $this->assertSame(
            0,
            AuditEvent::query()->where('action', SpotDistributionExportService::AUDIT_ACTION)->count(),
        );
    }

    public function test_no_calendar_returns_422_without_audit(): void
    {
        $order = $this->createAverageOnlyDispoOrder();
        $user = User::factory()->role(Role::Sales)->create();

        $this->actingAs($user)
            ->getJson(route('dispo-orders.export-spot-distribution', $order))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['export']);

        $this->assertSame(
            0,
            AuditEvent::query()->where('action', SpotDistributionExportService::AUDIT_ACTION)->count(),
        );
    }

    public function test_mixed_order_exports_only_calendar_rows(): void
    {
        $order = $this->createMixedDispoOrder();
        $user = User::factory()->role(Role::Sales)->create();

        $binary = $this->actingAs($user)
            ->get(route('dispo-orders.export-spot-distribution', $order))
            ->assertOk()
            ->streamedContent();

        $sheet = $this->loadSheet($binary);
        $this->assertSame(1, $this->dataRowCount($sheet));
        $this->assertSame('Spots', (string) $sheet->getCell('K2')->getValue());
    }

    public function test_snapshot_is_stable_after_catalog_and_calculation_change(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $year = PriceListCalendar::currentYear();
        $date = sprintf('%04d-09-14', $year);

        $calculation = app(CalculationWriter::class)->create(
            $this->calendarPayload($catalog, [
                ['date' => $date, 'hour' => 8, 'spot_count' => 4],
            ]),
            $user,
        );
        $position = $calculation->positions()->firstOrFail();
        $order = app(DispoOrderWriter::class)
            ->createFromCalculation($calculation, [$position->id], $user)
            ->order;

        $first = $this->actingAs($user)
            ->get(route('dispo-orders.export-spot-distribution', $order))
            ->streamedContent();

        $catalog['hamburg']->update(['name' => 'Geänderter Live-Name']);
        $payload = app(CalculationWriter::class)->payloadFromCalculation(
            $calculation->fresh([
                'positions.plannerEntries',
                'positions.planRows',
                'positions.timeRanges',
                'positions.discounts',
                'orderDiscounts',
                'configurationSnapshot',
                'fieldValues',
            ]),
        );
        $payload['lock_version'] = $calculation->lock_version;
        $payload['positions'][0]['planner_entries'] = [
            ['date' => $date, 'hour' => 14, 'spot_count' => 99],
        ];
        app(CalculationWriter::class)->update($calculation->fresh(), $payload, $user);

        $second = $this->actingAs($user)
            ->get(route('dispo-orders.export-spot-distribution', $order))
            ->streamedContent();

        $firstSheet = $this->loadSheet($first);
        $secondSheet = $this->loadSheet($second);

        $this->assertSame(
            (string) $firstSheet->getCell('D2')->getValue(),
            (string) $secondSheet->getCell('D2')->getValue(),
        );
        $this->assertSame(
            (string) $firstSheet->getCell('J2')->getCalculatedValue(),
            (string) $secondSheet->getCell('J2')->getCalculatedValue(),
        );
        $this->assertSame('Radio Hamburg', (string) $secondSheet->getCell('D2')->getValue());
        $this->assertSame('4', (string) $secondSheet->getCell('J2')->getCalculatedValue());
    }

    public function test_tandem_calendar_export_units(): void
    {
        $order = $this->createTandemCalendarDispoOrder();
        $user = User::factory()->role(Role::Sales)->create();

        $sheet = $this->loadSheet(
            $this->actingAs($user)
                ->get(route('dispo-orders.export-spot-distribution', $order))
                ->assertOk()
                ->streamedContent(),
        );

        $this->assertSame('Tandem-Einheiten', (string) $sheet->getCell('K2')->getValue());
        $this->assertSame('3', (string) $sheet->getCell('J2')->getCalculatedValue());
        $this->assertSame('6', (string) $sheet->getCell('N2')->getCalculatedValue());
        $this->assertStringContainsString('Hauptspot', (string) $sheet->getCell('M2')->getValue());
        $this->assertStringContainsString('Reminder', (string) $sheet->getCell('M2')->getValue());
    }

    public function test_multi_calendar_download_keeps_overlapping_cells_per_position(): void
    {
        $order = $this->createMultiCalendarDispoOrder();
        $user = User::factory()->role(Role::Sales)->create();

        $sheet = $this->loadSheet(
            $this->actingAs($user)
                ->get(route('dispo-orders.export-spot-distribution', $order))
                ->assertOk()
                ->streamedContent(),
        );

        $this->assertSame(4, $this->dataRowCount($sheet));

        $labels = [];
        $qtyByLabel = [];
        $overlapKeys = [];
        for ($row = 2; $row <= 5; $row++) {
            $label = (string) $sheet->getCell('C'.$row)->getValue();
            $inventory = (string) $sheet->getCell('D'.$row)->getValue();
            $date = (string) $sheet->getCell('F'.$row)->getValue();
            $hour = (string) $sheet->getCell('H'.$row)->getValue();
            $qty = (int) $sheet->getCell('J'.$row)->getCalculatedValue();

            $labels[] = $label;
            $qtyByLabel[$label] = ($qtyByLabel[$label] ?? 0) + $qty;
            if ($hour === '08:00') {
                $overlapKeys[] = $label.'|'.$inventory.'|'.$date.'|'.$hour;
            }
        }

        $this->assertSame(
            ['Position 1', 'Position 1', 'Position 2', 'Position 3'],
            $labels,
        );
        $this->assertSame([
            'Position 1' => 3,
            'Position 2' => 5,
            'Position 3' => 3,
        ], $qtyByLabel);
        $this->assertCount(3, $overlapKeys);
        $this->assertCount(3, array_unique($overlapKeys));
        $this->assertSame('Tandem-Einheiten', (string) $sheet->getCell('K5')->getValue());
        $this->assertSame('6', (string) $sheet->getCell('N5')->getCalculatedValue());

        $headerJoined = '';
        for ($col = 1; $col <= 14; $col++) {
            $headerJoined .= (string) $sheet->getCell(Coordinate::stringFromColumnIndex($col).'1')->getValue();
        }
        $this->assertStringNotContainsStringIgnoringCase('preis', $headerJoined);
        $this->assertStringNotContainsStringIgnoringCase('rabatt', $headerJoined);
    }

    public function test_formula_injection_is_exported_as_text(): void
    {
        $order = $this->createCalendarDispoOrder(customerName: '=1+1');
        $position = $order->positions()->firstOrFail();
        $position->forceFill([
            'inventory_name' => '+CMD()',
            'advertising_medium_name' => '@SUM(A1)',
        ])->save();

        $user = User::factory()->role(Role::Sales)->create();
        $sheet = $this->loadSheet(
            $this->actingAs($user)
                ->get(route('dispo-orders.export-spot-distribution', $order))
                ->assertOk()
                ->streamedContent(),
        );

        $this->assertSame("'=1+1", (string) $sheet->getCell('B2')->getValue());
        $this->assertSame("'+CMD()", (string) $sheet->getCell('D2')->getValue());
        $this->assertSame("'@SUM(A1)", (string) $sheet->getCell('E2')->getValue());
        $this->assertSame(DataType::TYPE_STRING, $sheet->getCell('B2')->getDataType());
    }

    private function createCalendarDispoOrder(string $customerName = 'Export Kunde GmbH'): DispoOrder
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $year = PriceListCalendar::currentYear();
        $payload = $this->calendarPayload($catalog, [
            ['date' => sprintf('%04d-09-14', $year), 'hour' => 8, 'spot_count' => 3],
            ['date' => sprintf('%04d-09-15', $year), 'hour' => 10, 'spot_count' => 2],
        ], $customerName);

        $calculation = app(CalculationWriter::class)->create($payload, $user);
        $position = $calculation->positions()->firstOrFail();

        return app(DispoOrderWriter::class)
            ->createFromCalculation($calculation, [$position->id], $user)
            ->order;
    }

    private function createAverageOnlyDispoOrder(): DispoOrder
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $payload = $this->averagePayload($catalog);
        $calculation = app(CalculationWriter::class)->create($payload, $user);
        $position = $calculation->positions()->firstOrFail();

        return app(DispoOrderWriter::class)
            ->createFromCalculation($calculation, [$position->id], $user)
            ->order;
    }

    private function createMixedDispoOrder(): DispoOrder
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $year = PriceListCalendar::currentYear();
        $fingerprint = app(ConfigurationSnapshotFreezeService::class)
            ->resolveLiveSchemaForCalculationV3()['schema_fingerprint'];
        $positionFingerprint = app(ConfigurationSnapshotFreezeService::class)
            ->resolveLivePositionSchema((int) $catalog['medium']->id)['schema_fingerprint'];

        $payload = [
            'planning_mode' => 'manual',
            'customer_name' => 'Gemischt GmbH',
            'order_discount_percent' => '0',
            'schema_fingerprint' => $fingerprint,
            'positions' => [
                [
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
                    ],
                ],
                [
                    'inventory_id' => $catalog['rock']->id,
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
                ],
            ],
        ];

        $calculation = app(CalculationWriter::class)->create($payload, $user);
        $ids = $calculation->positions()->pluck('id')->all();

        return app(DispoOrderWriter::class)
            ->createFromCalculation($calculation, $ids, $user)
            ->order;
    }

    private function createTandemCalendarDispoOrder(): DispoOrder
    {
        $catalog = $this->createSpotClassicCatalog();
        $tandem = AdvertisingMedium::factory()->tandem()->create(['code' => 'tandem_spt008']);
        InventoryMediumRule::factory()->create([
            'inventory_id' => $catalog['hamburg']->id,
            'advertising_medium_id' => $tandem->id,
        ]);

        $user = User::factory()->role(Role::Sales)->create();
        $year = PriceListCalendar::currentYear();
        $payload = [
            'planning_mode' => 'manual',
            'customer_name' => 'Tandem Export GmbH',
            'order_discount_percent' => '0',
            'schema_fingerprint' => app(ConfigurationSnapshotFreezeService::class)
                ->resolveLiveSchemaForCalculationV3()['schema_fingerprint'],
            'positions' => [[
                'inventory_id' => $catalog['hamburg']->id,
                'advertising_medium_id' => $tandem->id,
                'schema_fingerprint' => app(ConfigurationSnapshotFreezeService::class)
                    ->resolveLivePositionSchema((int) $tandem->id)['schema_fingerprint'],
                'spot_method' => 'calendar',
                'calculation_method_key' => 'calendar',
                'length_seconds' => 30,
                'component_calculation_strategy' => 'shared_total_length',
                'position_discount_percent' => '0',
                'ae_percent' => '0',
                'planner_entries' => [
                    ['date' => sprintf('%04d-09-14', $year), 'hour' => 8, 'spot_count' => 3],
                ],
                'components' => [
                    ['role' => 'main_spot', 'label' => 'Hauptspot', 'length_seconds' => 20, 'sort' => 1],
                    ['role' => 'reminder', 'label' => 'Reminder', 'length_seconds' => 10, 'sort' => 2],
                ],
            ]],
        ];

        $calculation = app(CalculationWriter::class)->create($payload, $user);
        $position = $calculation->positions()->firstOrFail();

        return app(DispoOrderWriter::class)
            ->createFromCalculation($calculation, [$position->id], $user)
            ->order;
    }

    private function createMultiCalendarDispoOrder(): DispoOrder
    {
        $catalog = $this->createSpotClassicCatalog();
        $tandem = AdvertisingMedium::factory()->tandem()->create(['code' => 'tandem_spt008_multi']);
        InventoryMediumRule::factory()->create([
            'inventory_id' => $catalog['hamburg']->id,
            'advertising_medium_id' => $tandem->id,
        ]);
        InventoryMediumRule::factory()->create([
            'inventory_id' => $catalog['rock']->id,
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

        $payload = [
            'planning_mode' => 'manual',
            'customer_name' => 'Multi Calendar GmbH',
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
        ];

        $calculation = app(CalculationWriter::class)->create($payload, $user);
        $ids = $calculation->positions()->pluck('id')->all();

        return app(DispoOrderWriter::class)
            ->createFromCalculation($calculation, $ids, $user)
            ->order;
    }

    /**
     * @param  list<array{date: string, hour: int, spot_count: int}>  $entries
     * @return array<string, mixed>
     */
    private function calendarPayload(array $catalog, array $entries, string $customerName = 'Export Kunde GmbH'): array
    {
        $fingerprint = app(ConfigurationSnapshotFreezeService::class)
            ->resolveLiveSchemaForCalculationV3()['schema_fingerprint'];
        $positionFingerprint = app(ConfigurationSnapshotFreezeService::class)
            ->resolveLivePositionSchema((int) $catalog['medium']->id)['schema_fingerprint'];

        return [
            'planning_mode' => 'manual',
            'customer_name' => $customerName,
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
                'planner_entries' => $entries,
            ]],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function averagePayload(array $catalog): array
    {
        $fingerprint = app(ConfigurationSnapshotFreezeService::class)
            ->resolveLiveSchemaForCalculationV3()['schema_fingerprint'];
        $positionFingerprint = app(ConfigurationSnapshotFreezeService::class)
            ->resolveLivePositionSchema((int) $catalog['medium']->id)['schema_fingerprint'];

        return [
            'planning_mode' => 'manual',
            'customer_name' => 'Average Only GmbH',
            'order_discount_percent' => '0',
            'schema_fingerprint' => $fingerprint,
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
            ]],
        ];
    }

    private function loadSheet(string $binary): Worksheet
    {
        $path = tempnam(sys_get_temp_dir(), 'spt008');
        $this->assertNotFalse($path);
        file_put_contents($path, $binary);
        try {
            $spreadsheet = IOFactory::load($path);
            $sheet = $spreadsheet->getActiveSheet();
            $this->assertSame(SpotDistributionExportDocument::SHEET_TITLE, $sheet->getTitle());

            return $sheet;
        } finally {
            @unlink($path);
        }
    }

    private function dataRowCount(Worksheet $sheet): int
    {
        return max(0, (int) $sheet->getHighestDataRow() - 1);
    }
}
