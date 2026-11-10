<?php

namespace Tests\Feature\OnlineAudio;

use App\Enums\EngineCapabilityStatus;
use App\Enums\Role;
use App\Models\User;
use App\Support\Calculation\EngineProfileRegistry;
use App\Support\Calculation\OaTkpWizardCapability;
use Database\Seeders\InitialCatalogMatCoreSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * PO-CALC-TKP-1D4d-UI-WIRE + REL: Inertia trennt Soft-Open, Preview-Surface und HTTP-Mutation.
 *
 * Nach Registry-Release ist der produktive CATALOG released.
 * Planned-Fälle werden isoliert per Test-Override simuliert.
 */
class PoCalcTkp1d4dUiWirePreviewSurfacePropTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        EngineProfileRegistry::clearOaTkpPairFakeForTests();
        OaTkpWizardCapability::clearHttpMutationFakeForTests();
        parent::tearDown();
    }

    #[Test]
    public function planned_capability_off_closes_all_three_signals(): void
    {
        config([OaTkpWizardCapability::CONFIG_KEY => false]);
        $this->seed(InitialCatalogMatCoreSeeder::class);
        $this->simulatePlannedPair();
        $user = User::factory()->create(['role' => Role::Admin]);

        $this->assertSame(
            EngineCapabilityStatus::Planned,
            EngineProfileRegistry::pairStatus('oa_tkp', 'tkp'),
        );
        $this->assertFalse(OaTkpWizardCapability::isEnabled());
        $this->assertFalse(OaTkpWizardCapability::allowsWizardPreviewSurface());
        $this->assertFalse(OaTkpWizardCapability::allowsHttpMutation());

        $this->actingAs($user)
            ->get(route('calculations.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('calculations/wizard')
                ->where('oa_tkp_wizard_capability', false)
                ->where('oa_tkp_preview_surface_allowed', false)
                ->where('oa_tkp_http_mutation_allowed', false)
            );
    }

    #[Test]
    public function planned_capability_on_opens_preview_surface_but_not_mutation(): void
    {
        config([OaTkpWizardCapability::CONFIG_KEY => true]);
        $this->seed(InitialCatalogMatCoreSeeder::class);
        $this->simulatePlannedPair();
        $user = User::factory()->create(['role' => Role::Admin]);

        $this->assertSame(
            EngineCapabilityStatus::Planned,
            EngineProfileRegistry::pairStatus('oa_tkp', 'tkp'),
        );
        $this->assertTrue(OaTkpWizardCapability::isEnabled());
        $this->assertTrue(OaTkpWizardCapability::allowsWizardPreviewSurface());
        $this->assertFalse(OaTkpWizardCapability::allowsHttpMutation());

        $this->actingAs($user)
            ->get(route('calculations.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('calculations/wizard')
                ->where('oa_tkp_wizard_capability', true)
                ->where('oa_tkp_preview_surface_allowed', true)
                ->where('oa_tkp_http_mutation_allowed', false)
            );
    }

    #[Test]
    public function released_catalog_opens_preview_surface_and_mutation_without_capability(): void
    {
        config([OaTkpWizardCapability::CONFIG_KEY => false]);
        $this->seed(InitialCatalogMatCoreSeeder::class);
        $user = User::factory()->create(['role' => Role::Admin]);

        $this->assertFalse(OaTkpWizardCapability::isEnabled());
        $this->assertTrue(OaTkpWizardCapability::allowsWizardPreviewSurface());
        $this->assertTrue(OaTkpWizardCapability::allowsHttpMutation());
        $this->assertSame(
            EngineCapabilityStatus::Released,
            EngineProfileRegistry::pairStatus('oa_tkp', 'tkp'),
        );

        $this->actingAs($user)
            ->get(route('calculations.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('calculations/wizard')
                ->where('oa_tkp_wizard_capability', false)
                ->where('oa_tkp_preview_surface_allowed', true)
                ->where('oa_tkp_http_mutation_allowed', true)
            );
    }

    #[Test]
    public function productive_catalog_stays_released_after_fake_clear(): void
    {
        EngineProfileRegistry::fakeOaTkpPairReleasedForTests();
        EngineProfileRegistry::clearOaTkpPairFakeForTests();

        $this->assertSame(
            EngineCapabilityStatus::Released,
            EngineProfileRegistry::pairStatus('oa_tkp', 'tkp'),
        );
        $this->assertSame('v1', EngineProfileRegistry::currentReleasedVersion('oa_tkp', 'tkp'));
    }

    private function simulatePlannedPair(): void
    {
        $override = new \ReflectionProperty(EngineProfileRegistry::class, 'oaTkpPairOverrideForTests');
        $override->setAccessible(true);
        $override->setValue(null, [
            'pair_status' => EngineCapabilityStatus::Planned,
            'current_released_version' => null,
            'versions' => [
                'v1' => EngineCapabilityStatus::Implemented,
            ],
        ]);
    }
}
