<?php

namespace Tests\Unit\Calculation;

use App\Enums\BudgetStrategy;
use App\Enums\DayGroup;
use App\Enums\PlanningMode;
use App\Models\PriceListItem;
use App\Services\Calculation\BudgetProposalFingerprint;
use App\Services\Calculation\BudgetSpotAllocator;
use App\Services\Calculation\BudgetSpotProposalService;
use App\Services\Calculation\CalculationEngine;
use App\Services\Calculation\CatalogResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreatesSpotClassicCatalog;
use Tests\TestCase;

class BudgetSpotProposalServiceTest extends TestCase
{
    use CreatesSpotClassicCatalog;
    use RefreshDatabase;

    public function test_equal_spot_count_for_two_senders(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $service = $this->service();

        $proposal = $service->propose($this->basePayload(
            $catalog['hamburg']->id,
            '5000.00',
            $catalog['rock']->id,
        ), null);

        $this->assertSame(BudgetStrategy::EqualSpotCount->value, $proposal['strategy']);
        $this->assertCount(2, $proposal['positions']);
        $spots = array_column($proposal['positions'], 'total_spot_count');
        $this->assertSame($spots[0], $spots[1]);
        $this->assertGreaterThan(0, $spots[0]);
        $this->assertTrue((float) $proposal['used_nn'] <= 5000.00);
        $this->assertTrue($proposal['next_package_exceeds_budget']);
    }

    public function test_budget_not_exceeded(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $service = $this->service();

        $proposal = $service->propose($this->basePayload(
            $catalog['hamburg']->id,
            '500.00',
            $catalog['rock']->id,
        ), null);

        $this->assertTrue((float) $proposal['used_nn'] <= 500.00);
    }

    public function test_insufficient_budget_returns_zero_spots(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $service = $this->service();

        $proposal = $service->propose($this->basePayload(
            $catalog['hamburg']->id,
            '10.00',
            $catalog['rock']->id,
        ), null);

        $this->assertSame(0, $proposal['spots_per_sender']);
        $this->assertTrue($proposal['insufficient_budget']);
        $this->assertNotNull($proposal['minimum_budget_nn']);
    }

    public function test_hour_distribution_max_diff_one(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $service = $this->service();

        $proposal = $service->propose($this->basePayload(
            $catalog['hamburg']->id,
            '2000.00',
        ), null);

        $buckets = $proposal['positions'][0]['buckets'] ?? [];
        $counts = array_column($buckets, 'spot_count');
        if (count($counts) > 1) {
            $this->assertLessThanOrEqual(1, max($counts) - min($counts));
        }
    }

    public function test_position_discounts_by_inventory_are_used(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $service = $this->service();

        $without = $service->propose($this->basePayload($catalog['hamburg']->id, '2000.00'), null);
        $with = $service->propose([
            ...$this->basePayload($catalog['hamburg']->id, '2000.00'),
            'budget_position_discounts_by_inventory' => [
                [
                    'inventory_id' => $catalog['hamburg']->id,
                    'discounts' => [
                        ['type' => 'quantity', 'custom_label' => null, 'percent' => '10'],
                    ],
                ],
            ],
        ], null);

        $this->assertGreaterThan(
            '0.00',
            $with['positions'][0]['position_discount_amount'] ?? '0',
        );
    }

    public function test_order_discounts_change_spot_count(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $service = $this->service();

        $without = $service->propose($this->basePayload($catalog['hamburg']->id, '2000.00'), null);
        $with = $service->propose([
            ...$this->basePayload($catalog['hamburg']->id, '2000.00'),
            'order_discounts' => [
                ['type' => 'quantity', 'custom_label' => null, 'percent' => '10'],
            ],
        ], null);

        $this->assertGreaterThan(
            '0.00',
            $with['order_discount_total'] ?? '0',
        );
        $this->assertNotSame(
            (int) $without['spots_per_sender'],
            (int) $with['spots_per_sender'],
        );
    }

    public function test_ae_enabled_reduces_spot_count(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $service = $this->service();

        $withoutAe = $service->propose($this->basePayload($catalog['hamburg']->id, '2000.00'), null);
        $withAe = $service->propose([
            ...$this->basePayload($catalog['hamburg']->id, '2000.00'),
            'ae_enabled' => true,
        ], null);

        $this->assertGreaterThan('0.00', $withAe['ae_total'] ?? '0');
        $this->assertNotSame(
            (int) $withoutAe['spots_per_sender'],
            (int) $withAe['spots_per_sender'],
        );
    }

    public function test_missing_price_raises_validation_error(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $service = $this->service();

        PriceListItem::query()
            ->where('hour', 9)
            ->where('day_group', DayGroup::MoFr)
            ->delete();

        $this->expectException(ValidationException::class);

        $service->propose($this->basePayload($catalog['hamburg']->id, '500.00'), null);
    }

    private function service(): BudgetSpotProposalService
    {
        return new BudgetSpotProposalService(
            new CalculationEngine,
            new CatalogResolver,
            new BudgetSpotAllocator,
            new BudgetProposalFingerprint,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function basePayload(int $firstInventoryId, string $budget, ?int $secondInventoryId = null): array
    {
        $wishIds = [$firstInventoryId];
        if ($secondInventoryId !== null) {
            $wishIds[] = $secondInventoryId;
        }

        return [
            'planning_mode' => PlanningMode::Budget->value,
            'target_budget_nn' => $budget,
            'budget_strategy' => BudgetStrategy::EqualSpotCount->value,
            'budget_wish_inventory_ids' => $wishIds,
            'budget_spot_length_seconds' => 30,
            'budget_distribution_ranges' => [
                [
                    'start_hour' => 6,
                    'end_hour_exclusive' => 18,
                    'day_group' => DayGroup::MoFr->value,
                ],
            ],
            'order_discount_percent' => '0',
            'order_discounts' => [],
            'ae_enabled' => false,
            'positions' => [],
        ];
    }
}
