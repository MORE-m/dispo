<?php

namespace Tests\Feature\Advertising;

use App\Enums\CalculationKind;
use App\Models\AdvertisingMedium;
use App\Models\InventoryMediumRule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Concerns\CreatesSpotFirstLastCatalog;
use Tests\TestCase;

/**
 * PO-SPOT-SURCHARGE-1: Migration setzt kind=spot_classic und surcharge=30 nur für Erst/Letzt.
 */
class SpotFirstLastCatalogMigrationTest extends TestCase
{
    use CreatesSpotFirstLastCatalog;
    use RefreshDatabase;

    private const MIGRATION = 'migrations/2026_10_18_180000_po_spot_surcharge_1_first_last_catalog.php';

    private function migration(): object
    {
        return require database_path(self::MIGRATION);
    }

    public function test_up_sets_kind_and_surcharge_only_for_first_and_last(): void
    {
        $catalog = $this->createSpotFirstLastCatalog();
        $migration = $this->migration();
        $migration->down();

        AdvertisingMedium::query()->whereKey($catalog['first']->id)->update(['kind' => null]);
        AdvertisingMedium::query()->whereKey($catalog['last']->id)->update(['kind' => null]);
        InventoryMediumRule::query()
            ->whereIn('advertising_medium_id', [$catalog['first']->id, $catalog['last']->id])
            ->update(['surcharge_percent' => 0]);
        InventoryMediumRule::query()
            ->where('advertising_medium_id', $catalog['classic']->id)
            ->update(['surcharge_percent' => 0]);

        $migration->up();

        $this->assertSame(
            CalculationKind::SpotClassic,
            AdvertisingMedium::query()->findOrFail($catalog['first']->id)->kind,
        );
        $this->assertSame(
            CalculationKind::SpotClassic,
            AdvertisingMedium::query()->findOrFail($catalog['last']->id)->kind,
        );
        $this->assertSame(
            CalculationKind::SpotClassic,
            AdvertisingMedium::query()->findOrFail($catalog['classic']->id)->kind,
        );

        foreach (InventoryMediumRule::query()->where('advertising_medium_id', $catalog['first']->id)->get() as $rule) {
            $this->assertSame('30.0000', $rule->surcharge_percent);
        }
        foreach (InventoryMediumRule::query()->where('advertising_medium_id', $catalog['last']->id)->get() as $rule) {
            $this->assertSame('30.0000', $rule->surcharge_percent);
        }
        foreach (InventoryMediumRule::query()->where('advertising_medium_id', $catalog['classic']->id)->get() as $rule) {
            $this->assertSame('0.0000', $rule->surcharge_percent);
        }
    }

    public function test_up_is_idempotent_when_kind_already_spot_classic(): void
    {
        $catalog = $this->createSpotFirstLastCatalog();
        $migration = $this->migration();

        $migration->up();
        $migration->up();

        $this->assertSame(
            CalculationKind::SpotClassic,
            AdvertisingMedium::query()->findOrFail($catalog['first']->id)->kind,
        );
        $this->assertSame('30.0000', $catalog['ruleFirstHamburg']->fresh()->surcharge_percent);
    }

    public function test_up_aborts_on_unexpected_kind(): void
    {
        $catalog = $this->createSpotFirstLastCatalog();
        $migration = $this->migration();
        $migration->down();

        AdvertisingMedium::query()->whereKey($catalog['first']->id)->update(['kind' => 'swf_trailer']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('unerwartete Berechnungsart');
        $migration->up();
    }
}
