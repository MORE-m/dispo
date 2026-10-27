<?php

namespace Tests\Feature\OnlineAudio;

use App\Enums\EngineCapabilityStatus;
use App\Enums\OaTargetingCategory;
use App\Enums\PriceListStatus;
use App\Enums\Role;
use App\Models\AdvertisingMedium;
use App\Models\Inventory;
use App\Models\InventoryMediumRule;
use App\Models\OaPlatform;
use App\Models\OaTargetingDefinition;
use App\Models\OaTkpPriceList;
use App\Models\OaTkpPriceListItem;
use App\Models\User;
use App\Services\Calculation\CalculationWriter;
use App\Support\Calculation\EngineProfileRegistry;
use App\Support\Calculation\OaTkpHttpReleaseGuard;
use App\Support\Calculation\OaTkpWizardCapability;
use App\Support\PriceList\PriceListCalendar;
use Database\Seeders\InitialCatalogMatCoreSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * PO-CALC-TKP-1D4b-1: UI-Save folgt demselben Registry-Release-Signal wie HTTP Mutation.
 *
 * Produktive Registry bleibt planned. Release nur isoliert per Test-Fake.
 */
class PoCalcTkp1d4b1SaveReleaseGateTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        OaTkpWizardCapability::clearHttpMutationFakeForTests();
        parent::tearDown();
    }

    #[Test]
    public function registry_remains_planned_and_v1_implemented(): void
    {
        $this->assertSame(
            EngineCapabilityStatus::Planned,
            EngineProfileRegistry::pairStatus('oa_tkp', 'tkp'),
        );
        $this->assertNull(EngineProfileRegistry::currentReleasedVersion('oa_tkp', 'tkp'));
        $this->assertSame(
            EngineCapabilityStatus::Implemented,
            EngineProfileRegistry::statusForVersion('oa_tkp', 'tkp', 'v1'),
        );
        $this->assertFalse(OaTkpWizardCapability::allowsHttpMutation());
    }

    #[Test]
    public function create_and_edit_expose_mutation_prop_false_when_planned(): void
    {
        $this->enableCapability();
        $ctx = $this->seedContext();

        $this->actingAs($ctx['user'])
            ->get(route('calculations.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('calculations/wizard')
                ->where('oa_tkp_wizard_capability', true)
                ->where('oa_tkp_http_mutation_allowed', false)
            );

        $calc = app(CalculationWriter::class)->create($this->previewPayload($ctx), $ctx['user']);

        $this->actingAs($ctx['user'])
            ->get(route('calculations.edit', $calc))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('calculations/wizard')
                ->where('oa_tkp_wizard_capability', true)
                ->where('oa_tkp_http_mutation_allowed', false)
            );
    }

    #[Test]
    public function wizard_capability_on_does_not_open_mutation_prop_or_http_store(): void
    {
        $this->enableCapability();
        $ctx = $this->seedContext();
        $payload = $this->previewPayload($ctx);

        $this->assertFalse(OaTkpWizardCapability::allowsHttpMutation());

        $this->actingAs($ctx['user'])
            ->get(route('calculations.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('oa_tkp_http_mutation_allowed', false)
            );

        $response = $this->actingAs($ctx['user'])
            ->postJson(route('calculations.store'), $payload);
        $response->assertStatus(422);
        $this->assertSame(
            OaTkpHttpReleaseGuard::MESSAGE,
            $response->json('errors.positions.0')
            ?? $response->json('message'),
        );
    }

    #[Test]
    public function simulated_release_opens_mutation_prop_on_create_and_edit(): void
    {
        $this->enableCapability();
        $ctx = $this->seedContext();
        OaTkpWizardCapability::fakeHttpMutationAllowedForTests(true);

        $this->assertTrue(OaTkpWizardCapability::allowsHttpMutation());
        $this->assertSame(
            EngineCapabilityStatus::Planned,
            EngineProfileRegistry::pairStatus('oa_tkp', 'tkp'),
        );

        $this->actingAs($ctx['user'])
            ->get(route('calculations.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('oa_tkp_http_mutation_allowed', true)
                ->where('oa_tkp_wizard_capability', true)
            );

        $calc = app(CalculationWriter::class)->create($this->previewPayload($ctx), $ctx['user']);

        $this->actingAs($ctx['user'])
            ->get(route('calculations.edit', $calc))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('oa_tkp_http_mutation_allowed', true)
            );
    }

    #[Test]
    public function http_guard_follows_same_mutation_signal_as_wizard_prop(): void
    {
        $this->enableCapability();
        $ctx = $this->seedContext();
        $payload = $this->previewPayload($ctx);

        try {
            OaTkpHttpReleaseGuard::assertMutationAllowed($payload);
            $this->fail('Expected ValidationException while planned');
        } catch (ValidationException $e) {
            $this->assertSame(
                OaTkpHttpReleaseGuard::MESSAGE,
                $e->errors()['positions'][0] ?? null,
            );
        }

        OaTkpWizardCapability::fakeHttpMutationAllowedForTests(true);
        OaTkpHttpReleaseGuard::assertMutationAllowed($payload);

        $this->actingAs($ctx['user'])
            ->get(route('calculations.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('oa_tkp_http_mutation_allowed', true)
            );
    }

    #[Test]
    public function budget_tkp_remains_rejected_independent_of_mutation_fake(): void
    {
        $this->enableCapability();
        $ctx = $this->seedContext();
        OaTkpWizardCapability::fakeHttpMutationAllowedForTests(true);

        $payload = $this->previewPayload($ctx);
        $payload['planning_mode'] = 'budget';
        $payload['target_budget_nn'] = '1000.00';

        try {
            OaTkpHttpReleaseGuard::assertBudgetNotTkp($payload);
            $this->fail('Expected budget TKP rejection');
        } catch (ValidationException $e) {
            $this->assertStringContainsString(
                'Budgetvorschläge sind für TKP',
                $e->errors()['positions'][0] ?? '',
            );
        }
    }

    #[Test]
    public function mutation_prop_matches_allows_http_mutation_contract(): void
    {
        $this->enableCapability();
        $ctx = $this->seedContext();

        $this->assertSame(
            OaTkpWizardCapability::allowsHttpMutation(),
            false,
        );

        $this->actingAs($ctx['user'])
            ->get(route('calculations.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('oa_tkp_http_mutation_allowed', OaTkpWizardCapability::allowsHttpMutation())
            );

        OaTkpWizardCapability::fakeHttpMutationAllowedForTests(true);

        $this->actingAs($ctx['user'])
            ->get(route('calculations.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('oa_tkp_http_mutation_allowed', OaTkpWizardCapability::allowsHttpMutation())
            );
    }

    #[Test]
    public function clearing_fake_restores_planned_block(): void
    {
        OaTkpWizardCapability::fakeHttpMutationAllowedForTests(true);
        $this->assertTrue(OaTkpWizardCapability::allowsHttpMutation());

        OaTkpWizardCapability::clearHttpMutationFakeForTests();
        $this->assertFalse(OaTkpWizardCapability::allowsHttpMutation());
        $this->assertSame(
            EngineCapabilityStatus::Planned,
            EngineProfileRegistry::pairStatus('oa_tkp', 'tkp'),
        );
    }

    private function enableCapability(): void
    {
        config([OaTkpWizardCapability::CONFIG_KEY => true]);
    }

    /**
     * @return array{
     *     user: User,
     *     inventory: Inventory,
     *     medium: AdvertisingMedium,
     *     list: OaTkpPriceList,
     *     platform: OaPlatform,
     *     targeting: OaTargetingDefinition
     * }
     */
    private function seedContext(): array
    {
        $this->seed(InitialCatalogMatCoreSeeder::class);
        $user = User::factory()->create(['role' => Role::Admin]);
        $inventory = Inventory::query()->where('code', 'inv_more_kombi_online_audio')->firstOrFail();
        $medium = AdvertisingMedium::query()->where('code', 'pre_stream')->firstOrFail();

        InventoryMediumRule::query()->firstOrCreate(
            [
                'inventory_id' => $inventory->id,
                'advertising_medium_id' => $medium->id,
            ],
            ['is_active' => true],
        );

        $year = PriceListCalendar::currentYear();
        $list = OaTkpPriceList::query()->create([
            'year' => $year,
            'name' => "OA TKP {$year} D4b1",
            'version' => "{$year}.1",
            'revision_number' => 1,
            'status' => PriceListStatus::Active,
            'valid_from' => "{$year}-01-01",
            'published_at' => now(),
        ]);
        OaTkpPriceListItem::query()->create([
            'oa_tkp_price_list_id' => $list->id,
            'advertising_medium_id' => $medium->id,
            'inventory_id' => $inventory->id,
            'list_tkp' => '12.0000',
            'minimum_tkp' => '8.0000',
        ]);

        $platform = OaPlatform::query()->create([
            'code' => 'd4b1_plat',
            'name' => 'D4b1 Platform',
            'is_active' => true,
            'sort' => 10,
            'is_adserver_relevant' => false,
        ]);
        $targeting = OaTargetingDefinition::query()->create([
            'code' => 'd4b1_geo',
            'name' => 'D4b1 Geo',
            'category' => OaTargetingCategory::Technisch,
            'tkp_surcharge' => '1.0000',
            'revision_number' => 1,
            'is_active' => true,
        ]);

        return compact('user', 'inventory', 'medium', 'list', 'platform', 'targeting');
    }

    /**
     * @param  array{
     *     inventory: Inventory,
     *     medium: AdvertisingMedium,
     *     list: OaTkpPriceList,
     *     platform: OaPlatform,
     *     targeting: OaTargetingDefinition
     * }  $ctx
     * @return array<string, mixed>
     */
    private function previewPayload(array $ctx): array
    {
        return $this->withLiveSchemaFingerprint([
            'planning_mode' => 'manual',
            'order_discount_percent' => '0',
            'ae_enabled' => false,
            'positions' => [[
                'client_key' => (string) str()->uuid(),
                'inventory_id' => $ctx['inventory']->id,
                'advertising_medium_id' => $ctx['medium']->id,
                'calculation_method_key' => 'tkp',
                'price_year' => PriceListCalendar::currentYear(),
                'expected_oa_tkp_price_list_id' => $ctx['list']->id,
                'impressions' => 100_000,
                'basis_tkp' => '12.0000',
                'oa_platform_allocations' => [[
                    'oa_platform_id' => $ctx['platform']->id,
                    'impressions' => 100_000,
                ]],
                'oa_targeting_selections' => [[
                    'oa_targeting_definition_id' => $ctx['targeting']->id,
                ]],
                'position_discount_percent' => '0',
                'ae_percent' => '15',
            ]],
        ]);
    }
}
