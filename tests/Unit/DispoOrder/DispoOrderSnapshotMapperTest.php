<?php

namespace Tests\Unit\DispoOrder;

use App\Enums\Role;
use App\Models\User;
use App\Services\DispoOrder\DispoOrderSnapshotMapper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesSavedCalculation;
use Tests\Concerns\CreatesSpotClassicCatalog;
use Tests\TestCase;

class DispoOrderSnapshotMapperTest extends TestCase
{
    use CreatesSavedCalculation;
    use CreatesSpotClassicCatalog;
    use RefreshDatabase;

    public function test_header_mapper_copies_calculation_fields(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $calculation = $this->createSavedCalculation($catalog, [
            ['inventory_id' => $catalog['hamburg']->id],
        ]);
        $calculation->load('advisor', 'positions');
        $selected = $calculation->positions;

        $header = app(DispoOrderSnapshotMapper::class)->headerFromCalculation($calculation, $selected);

        $this->assertSame($calculation->number, $header['source_calculation_number']);
        $this->assertSame('Testkunde GmbH', $header['customer_name']);
        $this->assertSame($calculation->advisor?->name, $header['advisor_name']);
        $this->assertSame((string) $calculation->nn_invest, $header['nn_invest']);
    }

    public function test_position_mapper_copies_snapshot_values_without_recalculation(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $calculation = $this->createSavedCalculation($catalog, [
            ['inventory_id' => $catalog['hamburg']->id],
        ]);
        $position = $calculation->positions()->with(['inventory', 'advertisingMedium', 'timeRanges'])->firstOrFail();

        $snapshot = app(DispoOrderSnapshotMapper::class)->positionFromCalculationPosition($position, 0);

        $this->assertSame($position->inventory->name, $snapshot['inventory_name']);
        $this->assertSame($position->advertisingMedium->name, $snapshot['advertising_medium_name']);
        $this->assertSame((string) $position->nn_invest, $snapshot['nn_invest']);
        $this->assertNotEmpty($snapshot['time_ranges_snapshot']);
    }
}
