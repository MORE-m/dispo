<?php

namespace Tests\Unit\Calculation;

use App\Services\Calculation\StoredPositionTotals;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesSavedCalculation;
use Tests\Concerns\CreatesSpotClassicCatalog;
use Tests\TestCase;

class StoredPositionTotalsTest extends TestCase
{
    use CreatesSavedCalculation;
    use CreatesSpotClassicCatalog;
    use RefreshDatabase;

    public function test_sum_of_all_positions_matches_calculation_totals(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $calculation = $this->createSavedCalculation($catalog, [
            ['inventory_id' => $catalog['hamburg']->id],
            ['inventory_id' => $catalog['rock']->id, 'total_spot_count' => 5, 'hour' => 10],
        ]);
        $calculation->load('positions');

        $aggregated = StoredPositionTotals::sum($calculation->positions);

        $this->assertSame((string) $calculation->media_gross, $aggregated['media_gross']);
        $this->assertSame((string) $calculation->position_discount_total, $aggregated['position_discount_total']);
        $this->assertSame((string) $calculation->order_discount_total, $aggregated['order_discount_total']);
        $this->assertSame((string) $calculation->ae_total, $aggregated['ae_total']);
        $this->assertSame((string) $calculation->nn_invest, $aggregated['nn_invest']);
    }
}
