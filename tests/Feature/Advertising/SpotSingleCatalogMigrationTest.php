<?php

namespace Tests\Feature\Advertising;

use App\Enums\CalculationKind;
use App\Models\AdvertisingMedium;
use App\Models\InventoryMediumRule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Concerns\CreatesSpotSingleCatalog;
use Tests\TestCase;

/**
 * PO-SPOT-SINGLE-1: Migration setzt kind=spot_classic und surcharge=50 nur für Single.
 */
class SpotSingleCatalogMigrationTest extends TestCase
{
    use CreatesSpotSingleCatalog;
    use RefreshDatabase;

    private const MIGRATION = 'migrations/2026_10_18_200000_po_spot_single_1_catalog.php';

    private function migration(): object
    {
        return require database_path(self::MIGRATION);
    }

    public function test_up_sets_kind_and_surcharge_only_for_single(): void
    {
        $catalog = $this->createSpotSingleCatalog();
        $migration = $this->migration();
        $migration->down();

        AdvertisingMedium::query()->whereKey($catalog['single']->id)->update(['kind' => null]);
        InventoryMediumRule::query()
            ->where('advertising_medium_id', $catalog['single']->id)
            ->update(['surcharge_percent' => 0]);
        InventoryMediumRule::query()
            ->where('advertising_medium_id', $catalog['classic']->id)
            ->update(['surcharge_percent' => 0]);

        $migration->up();

        $this->assertSame(
            CalculationKind::SpotClassic,
            AdvertisingMedium::query()->findOrFail($catalog['single']->id)->kind,
        );
        $this->assertSame(
            CalculationKind::SpotClassic,
            AdvertisingMedium::query()->findOrFail($catalog['classic']->id)->kind,
        );

        foreach (InventoryMediumRule::query()->where('advertising_medium_id', $catalog['single']->id)->get() as $rule) {
            $this->assertSame('50.0000', $rule->surcharge_percent);
        }
        foreach (InventoryMediumRule::query()->where('advertising_medium_id', $catalog['classic']->id)->get() as $rule) {
            $this->assertSame('0.0000', $rule->surcharge_percent);
        }
    }

    public function test_up_is_idempotent_when_kind_already_spot_classic(): void
    {
        $catalog = $this->createSpotSingleCatalog();
        $migration = $this->migration();

        $migration->up();
        $migration->up();

        $this->assertSame(
            CalculationKind::SpotClassic,
            AdvertisingMedium::query()->findOrFail($catalog['single']->id)->kind,
        );
        $this->assertSame('50.0000', $catalog['ruleSingleHamburg']->fresh()->surcharge_percent);
    }

    public function test_up_aborts_on_unexpected_kind(): void
    {
        $catalog = $this->createSpotSingleCatalog();
        $migration = $this->migration();
        $migration->down();

        AdvertisingMedium::query()->whereKey($catalog['single']->id)->update(['kind' => 'swf_trailer']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('unerwartete Berechnungsart');
        $migration->up();
    }

    public function test_mat003_blocked_inventories_have_no_single_rule(): void
    {
        $catalog = $this->createSpotSingleCatalog();

        foreach ([$catalog['moreKombiPlus'], $catalog['bollerwagen'], $catalog['ffn']] as $inventory) {
            $this->assertFalse(
                InventoryMediumRule::query()
                    ->where('inventory_id', $inventory->id)
                    ->where('advertising_medium_id', $catalog['single']->id)
                    ->exists(),
                $inventory->code.' darf keine Single-Regel haben',
            );
        }

        $this->assertSame(5, InventoryMediumRule::query()
            ->where('advertising_medium_id', $catalog['single']->id)
            ->count());
    }
}
