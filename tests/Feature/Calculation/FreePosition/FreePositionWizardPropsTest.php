<?php

namespace Tests\Feature\Calculation\FreePosition;

use App\Models\Calculation;
use App\Support\Calculation\EngineProfileRegistry;
use App\Support\Calculation\FreePositionHttpReleaseGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesFreePositionCatalog;
use Tests\TestCase;

/**
 * NATIVE-3: Wizard-Props (Edit) müssen für gespeicherte Free-Positionen `free_price` liefern,
 * sonst scheitert die Reload-Hydration im Frontend. Release nur über Registry-Test-Fake.
 */
class FreePositionWizardPropsTest extends TestCase
{
    use CreatesFreePositionCatalog;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        EngineProfileRegistry::clearFreePositionPairFakeForTests();
        parent::tearDown();
    }

    #[Test]
    public function edit_props_expose_free_price_for_stored_free_position(): void
    {
        $ctx = $this->seedFreeContext();
        EngineProfileRegistry::fakeFreePositionPairReleasedForTests();

        $this->actingAs($ctx['user'])->post(route('calculations.store'), $this->withLiveSchemaFingerprint([
            'planning_mode' => 'manual',
            'order_discount_percent' => '0',
            'ae_enabled' => false,
            'positions' => [$this->freePosition($ctx, [
                'free_price' => '5000',
                'position_discounts' => [['type' => 'quantity', 'custom_label' => null, 'percent' => '10']],
            ])],
        ]))->assertRedirect();

        $calculation = Calculation::query()->firstOrFail();

        $this->actingAs($ctx['user'])
            ->get(route('calculations.edit', $calculation))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('calculations/wizard')
                ->where('calculation.positions.0.calculation_method_key', 'free_position')
                ->where('calculation.positions.0.engine_profile_key', 'free_position')
                ->where('calculation.positions.0.free_price', '5000.00')
                ->where('calculation.positions.0.position_discounts.0.percent', '10.0000')
                ->where('oa_tkp_wizard_capability', false)
                ->etc());
    }

    #[Test]
    public function pair_stays_planned_without_test_fake(): void
    {
        $this->assertFalse(FreePositionHttpReleaseGuard::pairIsReleased());
    }
}
