<?php

namespace Tests\Feature\Advertising;

use App\Enums\CalculationKind;
use App\Enums\CalculationMethodMode;
use App\Models\AdvertisingMedium;
use App\Models\AdvertisingMediumCalculationMethod;
use App\Models\CalculationPosition;
use App\Models\InventoryMediumRule;
use App\Services\InventoryMediumRule\Catalog\InitialCatalogBootstrapper;
use App\Support\Calculation\EngineProfileRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

/**
 * PO-ALLONGE-1: Migration erhält Regelwerte und schaltet 30/0 nicht frei.
 */
class SwfAllongeMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'migrations/2026_10_14_140000_po_allonge_1_swf_allonge_confirmation.php';

    public function test_up_down_reup_keeps_rule_values_and_does_not_confirm(): void
    {
        app(InitialCatalogBootstrapper::class)->bootstrap();
        $allonge = AdvertisingMedium::query()->where('code', 'allonge')->firstOrFail();
        $rule = InventoryMediumRule::factory()->create([
            'advertising_medium_id' => $allonge->id,
            'default_length_seconds' => 30,
            'surcharge_percent' => '0',
            'swf_allonge_confirmed_at' => now(),
        ]);
        $confirmed = $rule->swf_allonge_confirmed_at?->format('Y-m-d H:i:s');

        $migration = require database_path(self::MIGRATION);
        $migration->down();

        $this->assertFalse(Schema::hasColumn('inventory_medium_rules', 'swf_allonge_confirmed_at'));
        $kept = InventoryMediumRule::query()->findOrFail($rule->id);
        $this->assertSame(30, $kept->default_length_seconds);
        $this->assertSame('0.0000', $kept->surcharge_percent);
        $this->assertNull(AdvertisingMedium::query()->findOrFail($allonge->id)->kind);

        $migration->up();
        $restored = InventoryMediumRule::query()->findOrFail($rule->id);
        $this->assertSame(30, $restored->default_length_seconds);
        $this->assertSame('0.0000', $restored->surcharge_percent);
        $this->assertNull($restored->swf_allonge_confirmed_at);
        $this->assertNotSame($confirmed, $restored->swf_allonge_confirmed_at);

        $medium = AdvertisingMedium::query()->findOrFail($allonge->id);
        $this->assertSame(CalculationKind::SwfAllonge, $medium->kind);
        $this->assertSame(CalculationMethodMode::Override, $medium->calculation_method_mode);
        $this->assertSame(
            EngineProfileRegistry::PROFILE_SWF_ALLONGE,
            AdvertisingMediumCalculationMethod::query()
                ->where('advertising_medium_id', $medium->id)
                ->value('engine_profile_key'),
        );

        $restored->swf_allonge_confirmed_at = now();
        $restored->default_length_seconds = 12;
        $restored->save();
        $migration->up();
        $again = $restored->fresh();
        $this->assertNotNull($again);
        $this->assertSame(12, $again->default_length_seconds);
        $this->assertNotNull($again->swf_allonge_confirmed_at);
    }

    public function test_down_is_refused_when_allonge_positions_exist(): void
    {
        app(InitialCatalogBootstrapper::class)->bootstrap();
        $allonge = AdvertisingMedium::query()->where('code', 'allonge')->firstOrFail();
        CalculationPosition::factory()->create([
            'kind' => CalculationKind::SwfAllonge,
            'advertising_medium_id' => $allonge->id,
        ]);

        $migration = require database_path(self::MIGRATION);
        $this->expectException(RuntimeException::class);
        $migration->down();
    }
}
