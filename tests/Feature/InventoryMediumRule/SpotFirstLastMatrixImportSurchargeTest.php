<?php

namespace Tests\Feature\InventoryMediumRule;

use App\Models\InventoryMediumRule;
use App\Services\InventoryMediumRule\Catalog\InitialCatalogBootstrapper;
use App\Services\InventoryMediumRule\Import\CombinationMatrixImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PO-SPOT-SURCHARGE-1 / PO-SPOT-SINGLE-1: Matrix-Create setzt Erst/Letzt=30, Single=50 (kein Import-0).
 */
class SpotFirstLastMatrixImportSurchargeTest extends TestCase
{
    use RefreshDatabase;

    public function test_matrix_import_creates_first_last_with_thirty_percent(): void
    {
        app(InitialCatalogBootstrapper::class)->bootstrap();
        $result = app(CombinationMatrixImporter::class)->import();

        $this->assertGreaterThan(0, $result['created']);

        $firstRules = InventoryMediumRule::query()
            ->whereHas('advertisingMedium', fn ($q) => $q->where('code', 'spot_first'))
            ->get();
        $lastRules = InventoryMediumRule::query()
            ->whereHas('advertisingMedium', fn ($q) => $q->where('code', 'spot_last'))
            ->get();
        $singleRules = InventoryMediumRule::query()
            ->whereHas('advertisingMedium', fn ($q) => $q->where('code', 'spot_single'))
            ->get();
        $classicRules = InventoryMediumRule::query()
            ->whereHas('advertisingMedium', fn ($q) => $q->where('code', 'spot_classic'))
            ->get();

        $this->assertGreaterThan(0, $firstRules->count());
        $this->assertGreaterThan(0, $lastRules->count());
        $this->assertGreaterThan(0, $singleRules->count());
        $this->assertGreaterThan(0, $classicRules->count());

        foreach ($firstRules as $rule) {
            $this->assertSame('30.0000', $rule->surcharge_percent, 'spot_first rule#'.$rule->id);
        }
        foreach ($lastRules as $rule) {
            $this->assertSame('30.0000', $rule->surcharge_percent, 'spot_last rule#'.$rule->id);
        }
        foreach ($singleRules as $rule) {
            $this->assertSame('50.0000', $rule->surcharge_percent, 'spot_single rule#'.$rule->id);
        }
        foreach ($classicRules as $rule) {
            $this->assertSame('0.0000', $rule->surcharge_percent, 'spot_classic rule#'.$rule->id);
        }
    }
}
