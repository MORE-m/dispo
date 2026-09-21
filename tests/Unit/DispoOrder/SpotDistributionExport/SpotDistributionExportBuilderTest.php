<?php

namespace Tests\Unit\DispoOrder\SpotDistributionExport;

use App\Enums\CalculationKind;
use App\Enums\DispoOrderApprovalKind;
use App\Enums\DispoOrderStatus;
use App\Enums\PricingSettlementMode;
use App\Enums\Role;
use App\Enums\SpotCalculationMethod;
use App\Enums\SpotComponentProfile;
use App\Models\DispoOrder;
use App\Models\DispoOrderPosition;
use App\Models\User;
use App\Services\DispoOrder\SpotDistributionExport\SpotDistributionExportBuilder;
use App\Services\DispoOrder\SpotDistributionExport\SpotDistributionExportDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreatesMinimalDispoConfigurationSnapshot;
use Tests\Concerns\CreatesSavedCalculation;
use Tests\Concerns\CreatesSpotClassicCatalog;
use Tests\TestCase;

class SpotDistributionExportBuilderTest extends TestCase
{
    use CreatesMinimalDispoConfigurationSnapshot;
    use CreatesSavedCalculation;
    use CreatesSpotClassicCatalog;
    use RefreshDatabase;

    public function test_builds_sorted_rows_from_calendar_snapshot_only(): void
    {
        $order = $this->makeOrder();
        $this->addCalendarPosition($order, [
            'sort' => 2,
            'planner_entries_snapshot' => [
                ['date' => '2026-09-16', 'hour' => 14, 'day_group' => 'mo_fr', 'spot_count' => 2],
                ['date' => '2026-09-15', 'hour' => 10, 'day_group' => 'mo_fr', 'spot_count' => 1],
                ['date' => '2026-09-15', 'hour' => 8, 'day_group' => 'mo_fr', 'spot_count' => 3],
                ['date' => '2026-09-15', 'hour' => 9, 'day_group' => 'mo_fr', 'spot_count' => 0],
            ],
        ]);
        $this->addCalendarPosition($order, [
            'sort' => 1,
            'inventory_name' => 'Sender A',
            'planner_entries_snapshot' => [
                ['date' => '2026-09-20', 'hour' => 11, 'day_group' => 'so', 'spot_count' => 4],
            ],
        ]);
        $this->addAveragePosition($order, ['sort' => 3]);

        $document = (new SpotDistributionExportBuilder)->build($order->fresh(['positions']));

        $this->assertSame(SpotDistributionExportDocument::defaultHeaders(), $document->headers);
        $this->assertSame(2, $document->exportedPositionCount);
        $this->assertCount(4, $document->rows);

        $this->assertSame('Position 1', $document->rows[0]->positionLabel);
        $this->assertSame('2026-09-20', $document->rows[0]->dateIso);
        $this->assertSame('Sonntag', $document->rows[0]->weekdayLabel);
        $this->assertSame('11:00', $document->rows[0]->hourLabel);

        $this->assertSame('Position 2', $document->rows[1]->positionLabel);
        $this->assertSame('2026-09-15', $document->rows[1]->dateIso);
        $this->assertSame(8, $document->rows[1]->hour);
        $this->assertSame(3, $document->rows[1]->quantity);
        $this->assertSame('Spots', $document->rows[1]->quantityUnit);
        $this->assertSame(3, $document->rows[1]->componentAirings);

        $this->assertSame(10, $document->rows[2]->hour);
        $this->assertSame(14, $document->rows[3]->hour);

        foreach ($document->headers as $header) {
            $this->assertStringNotContainsStringIgnoringCase('preis', $header);
            $this->assertStringNotContainsStringIgnoringCase('rabatt', $header);
            $this->assertStringNotContainsStringIgnoringCase('ae', $header);
            $this->assertStringNotContainsStringIgnoringCase('festpreis', $header);
            $this->assertStringNotContainsStringIgnoringCase('netto', $header);
        }
    }

    public function test_average_only_order_fails_closed(): void
    {
        $order = $this->makeOrder();
        $this->addAveragePosition($order);

        $this->expectException(ValidationException::class);
        (new SpotDistributionExportBuilder)->build($order->fresh(['positions']));
    }

    public function test_tandem_units_and_airings_without_price_multiplication(): void
    {
        $order = $this->makeOrder();
        $this->addCalendarPosition($order, [
            'component_profile' => SpotComponentProfile::Tandem,
            'component_calculation_strategy' => 'shared_total_length',
            'length_seconds' => 30,
            'components_snapshot' => [
                ['role' => 'main_spot', 'label' => 'Hauptspot', 'length_seconds' => 20, 'sort' => 1],
                ['role' => 'reminder', 'label' => 'Reminder', 'length_seconds' => 10, 'sort' => 2],
            ],
            'planner_entries_snapshot' => [
                ['date' => '2026-09-14', 'hour' => 8, 'day_group' => 'mo_fr', 'spot_count' => 5],
            ],
        ]);

        $row = (new SpotDistributionExportBuilder)->build($order->fresh(['positions']))->rows[0];

        $this->assertSame(5, $row->quantity);
        $this->assertSame('Tandem-Einheiten', $row->quantityUnit);
        $this->assertSame(10, $row->componentAirings);
        $this->assertSame(30, $row->totalLengthSeconds);
        $this->assertSame('Hauptspot 20 s | Reminder 10 s', $row->componentsLabel);
    }

    public function test_tridem_reminder_numbering(): void
    {
        $order = $this->makeOrder();
        $this->addCalendarPosition($order, [
            'component_profile' => SpotComponentProfile::Tridem,
            'component_calculation_strategy' => 'shared_total_length',
            'length_seconds' => 40,
            'components_snapshot' => [
                ['role' => 'main_spot', 'label' => 'Hauptspot', 'length_seconds' => 20, 'sort' => 1],
                ['role' => 'reminder', 'label' => 'Reminder', 'length_seconds' => 10, 'sort' => 2],
                ['role' => 'reminder', 'label' => 'Reminder', 'length_seconds' => 10, 'sort' => 3],
            ],
            'planner_entries_snapshot' => [
                ['date' => '2026-09-14', 'hour' => 8, 'day_group' => 'mo_fr', 'spot_count' => 2],
            ],
        ]);

        $row = (new SpotDistributionExportBuilder)->build($order->fresh(['positions']))->rows[0];

        $this->assertSame('Tridem-Einheiten', $row->quantityUnit);
        $this->assertSame(6, $row->componentAirings);
        $this->assertSame('Hauptspot 20 s | Reminder 1 10 s | Reminder 2 10 s', $row->componentsLabel);
    }

    public function test_hauptspot_allonge_components(): void
    {
        $order = $this->makeOrder();
        $this->addCalendarPosition($order, [
            'component_calculation_strategy' => 'shared_total_length',
            'length_seconds' => 30,
            'components_snapshot' => [
                ['role' => 'main_spot', 'label' => 'Hauptspot', 'length_seconds' => 20, 'sort' => 1],
                ['role' => 'allonge', 'label' => 'Allonge', 'length_seconds' => 10, 'sort' => 2],
            ],
            'planner_entries_snapshot' => [
                ['date' => '2026-09-14', 'hour' => 8, 'day_group' => 'mo_fr', 'spot_count' => 3],
            ],
        ]);

        $row = (new SpotDistributionExportBuilder)->build($order->fresh(['positions']))->rows[0];

        $this->assertSame('Hauptspot 20 s | Allonge 10 s', $row->componentsLabel);
        $this->assertSame(3, $row->componentAirings);
        $this->assertSame('Spots', $row->quantityUnit);
    }

    public function test_legacy_without_components_uses_spot_length(): void
    {
        $order = $this->makeOrder();
        $this->addCalendarPosition($order, [
            'length_seconds' => 25,
            'components_snapshot' => [],
            'planner_entries_snapshot' => [
                ['date' => '2026-09-14', 'hour' => 8, 'day_group' => 'mo_fr', 'spot_count' => 1],
            ],
        ]);

        $row = (new SpotDistributionExportBuilder)->build($order->fresh(['positions']))->rows[0];
        $this->assertSame('Spot 25 s', $row->componentsLabel);
        $this->assertSame(25, $row->totalLengthSeconds);
    }

    public function test_unknown_component_role_fails_closed(): void
    {
        $order = $this->makeOrder();
        $this->addCalendarPosition($order, [
            'components_snapshot' => [
                ['role' => 'abbinder', 'label' => 'Abbinder', 'length_seconds' => 5, 'sort' => 1],
            ],
            'planner_entries_snapshot' => [
                ['date' => '2026-09-14', 'hour' => 8, 'day_group' => 'mo_fr', 'spot_count' => 1],
            ],
        ]);

        $this->expectException(ValidationException::class);
        (new SpotDistributionExportBuilder)->build($order->fresh(['positions']));
    }

    public function test_invalid_date_fails_closed(): void
    {
        $order = $this->makeOrder();
        $this->addCalendarPosition($order, [
            'planner_entries_snapshot' => [
                ['date' => '2026-02-30', 'hour' => 8, 'day_group' => 'mo_fr', 'spot_count' => 1],
            ],
        ]);

        $this->expectException(ValidationException::class);
        (new SpotDistributionExportBuilder)->build($order->fresh(['positions']));
    }

    public function test_invalid_hour_fails_closed(): void
    {
        $order = $this->makeOrder();
        $this->addCalendarPosition($order, [
            'planner_entries_snapshot' => [
                ['date' => '2026-09-14', 'hour' => 24, 'day_group' => 'mo_fr', 'spot_count' => 1],
            ],
        ]);

        $this->expectException(ValidationException::class);
        (new SpotDistributionExportBuilder)->build($order->fresh(['positions']));
    }

    public function test_capability_for_mixed_and_empty_orders(): void
    {
        $builder = new SpotDistributionExportBuilder;

        $mixed = $this->makeOrder();
        $this->addCalendarPosition($mixed);
        $this->addAveragePosition($mixed);
        $capability = $builder->capability($mixed->fresh(['positions']));
        $this->assertTrue($capability['enabled']);
        $this->assertTrue($capability['mixed_order']);

        $empty = DispoOrder::query()->create([
            'calculation_id' => $mixed->calculation_id,
            'configuration_snapshot_id' => $mixed->configuration_snapshot_id,
            'number' => 'DO-2026-9-2',
            'number_year' => 2026,
            'number_org_seq' => 9,
            'number_calc_seq' => 2,
            'status' => DispoOrderStatus::Draft,
            'created_by_id' => $mixed->created_by_id,
            'source_calculation_number' => $mixed->source_calculation_number,
            'customer_name' => 'Average only',
            'approval_kind' => DispoOrderApprovalKind::Regular,
            'requires_special_approval' => false,
            'media_gross' => '0.00',
            'position_discount_total' => '0.00',
            'order_discount_total' => '0.00',
            'ae_total' => '0.00',
            'nn_invest' => '0.00',
            'order_discount_percent' => '0',
            'ae_enabled' => true,
            'lock_version' => 1,
        ]);
        $this->addAveragePosition($empty);
        $emptyCapability = $builder->capability($empty->fresh(['positions']));
        $this->assertFalse($emptyCapability['enabled']);
        $this->assertNotNull($emptyCapability['disabled_reason']);
    }

    private function makeOrder(): DispoOrder
    {
        $catalog = $this->createSpotClassicCatalog();
        $calculation = $this->createSavedCalculation($catalog, [
            ['inventory_id' => $catalog['hamburg']->id],
        ]);
        $user = User::factory()->role(Role::Sales)->create();

        return $this->createDispoOrderWithMinimalSnapshot($calculation, [
            'calculation_id' => $calculation->id,
            'number' => 'DO-2026-9-1',
            'number_year' => 2026,
            'number_org_seq' => 9,
            'number_calc_seq' => 1,
            'status' => DispoOrderStatus::Draft,
            'created_by_id' => $user->id,
            'source_calculation_number' => $calculation->number,
            'customer_name' => 'Export Kunde',
            'approval_kind' => DispoOrderApprovalKind::Regular,
            'requires_special_approval' => false,
            'media_gross' => '0.00',
            'position_discount_total' => '0.00',
            'order_discount_total' => '0.00',
            'ae_total' => '0.00',
            'nn_invest' => '0.00',
            'order_discount_percent' => '0',
            'ae_enabled' => true,
            'lock_version' => 1,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function addCalendarPosition(DispoOrder $order, array $overrides = []): DispoOrderPosition
    {
        return DispoOrderPosition::query()->create(array_merge([
            'dispo_order_id' => $order->id,
            'sort' => 1,
            'inventory_name' => 'Radio Hamburg',
            'advertising_medium_name' => 'Spot Classic',
            'kind' => CalculationKind::SpotClassic,
            'spot_method' => SpotCalculationMethod::Calendar,
            'length_seconds' => 30,
            'total_spot_count' => 3,
            'pricing_settlement_mode' => PricingSettlementMode::Normal,
            'planner_entries_snapshot' => [
                ['date' => '2026-09-14', 'hour' => 8, 'day_group' => 'mo_fr', 'spot_count' => 3],
            ],
            'components_snapshot' => [],
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function addAveragePosition(DispoOrder $order, array $overrides = []): DispoOrderPosition
    {
        return DispoOrderPosition::query()->create(array_merge([
            'dispo_order_id' => $order->id,
            'sort' => 2,
            'inventory_name' => 'Average Sender',
            'advertising_medium_name' => 'Spot Classic',
            'kind' => CalculationKind::SpotClassic,
            'spot_method' => SpotCalculationMethod::Average,
            'length_seconds' => 30,
            'total_spot_count' => 10,
            'pricing_settlement_mode' => PricingSettlementMode::Normal,
            'time_ranges_snapshot' => [
                ['start_hour' => 8, 'end_hour_exclusive' => 9, 'day_group' => 'mo_fr', 'spot_count' => 10],
            ],
            'planner_entries_snapshot' => [],
            'components_snapshot' => [],
        ], $overrides));
    }
}
