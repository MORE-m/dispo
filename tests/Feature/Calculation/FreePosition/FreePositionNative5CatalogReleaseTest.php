<?php

namespace Tests\Feature\Calculation\FreePosition;

use App\Enums\CalculationKind;
use App\Enums\CalculationMethodMode;
use App\Enums\EngineCapabilityStatus;
use App\Enums\Role;
use App\Models\AdvertisingMedium;
use App\Models\Calculation;
use App\Models\Inventory;
use App\Models\InventoryMediumRule;
use App\Models\User;
use App\Support\Advertising\AdvertisingMediumLiveBookability;
use App\Support\Calculation\EngineProfileRegistry;
use App\Support\Calculation\FreePositionHttpReleaseGuard;
use App\Support\OnlineAudio\OaTkpCoreMedia;
use Database\Seeders\InitialCatalogMatCoreSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Tests\TestCase;

/**
 * NATIVE-5: echter CATALOG-Release free_position×free_position = released/v1.
 *
 * Kein Test-Fake. Katalog aus InitialCatalogMatCoreSeeder (native_ad = free_position,
 * Podcast-Rule); die vier historischen Native-Ad-Rules kommen aus der Matrix und werden
 * hier für ein Sender-Inventar nachgebildet.
 */
class FreePositionNative5CatalogReleaseTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        EngineProfileRegistry::clearFreePositionPairFakeForTests();
        parent::tearDown();
    }

    #[Test]
    public function catalog_is_released_v1_without_fake(): void
    {
        $this->assertFalse((bool) config('app.e2e_free_position_pair_released'));
        $this->assertSame(
            EngineCapabilityStatus::Released,
            EngineProfileRegistry::pairStatus('free_position', 'free_position'),
        );
        $this->assertSame('v1', EngineProfileRegistry::currentReleasedVersion('free_position', 'free_position'));
        $this->assertSame(
            EngineCapabilityStatus::Released,
            EngineProfileRegistry::statusForVersion('free_position', 'free_position', 'v1'),
        );

        // Direkt am CATALOG (kein Override-Layer).
        $catalog = (new ReflectionClass(EngineProfileRegistry::class))->getConstant('CATALOG');
        $pair = $catalog['free_position']['free_position'];
        $this->assertSame(EngineCapabilityStatus::Released, $pair['pair_status']);
        $this->assertSame('v1', $pair['current_released_version']);
        $this->assertSame(EngineCapabilityStatus::Released, $pair['versions']['v1']);
        EngineProfileRegistry::validateDefinitions($catalog);

        $this->assertTrue(FreePositionHttpReleaseGuard::pairIsReleased());
    }

    #[Test]
    public function native_ad_is_bookable_for_free_position_after_initial_catalog(): void
    {
        $this->seed(InitialCatalogMatCoreSeeder::class);
        $native = AdvertisingMedium::query()->where('code', 'native_ad')->firstOrFail();

        $this->assertSame(CalculationKind::FreePosition, $native->kind);
        $this->assertSame(CalculationMethodMode::Override, $native->calculation_method_mode);

        $bookability = app(AdvertisingMediumLiveBookability::class);
        foreach ([null, 'free_position'] as $requested) {
            $result = $bookability->evaluate($native, $requested);
            $this->assertTrue($result->isBookableForNewPositions, (string) $requested);
            $this->assertSame('free_position', $result->engineProfileKey);
            $this->assertSame('free_position', $result->calculationMethodKey);
            $this->assertSame('v1', $result->algorithmVersion);
        }
    }

    #[Test]
    public function native_ad_stays_unbookable_for_tkp_and_is_not_an_oa_core_medium(): void
    {
        $this->seed(InitialCatalogMatCoreSeeder::class);
        $native = AdvertisingMedium::query()->where('code', 'native_ad')->firstOrFail();

        $this->assertFalse(
            app(AdvertisingMediumLiveBookability::class)->evaluate($native, 'tkp')->isBookableForNewPositions,
        );
        $this->assertFalse(OaTkpCoreMedia::contains('native_ad'));
        $this->assertNotContains('native_ad', OaTkpCoreMedia::codes());
    }

    #[Test]
    public function podcast_inventory_is_bookable_with_native_ad_and_freezes_redaktion(): void
    {
        $ctx = $this->seedContext();

        $podcast = Inventory::query()->where('code', 'inv_more_kombi_podcast')->firstOrFail();
        $rule = InventoryMediumRule::query()
            ->where('inventory_id', $podcast->id)
            ->where('advertising_medium_id', $ctx['native']->id)
            ->firstOrFail();
        $this->assertTrue($rule->is_active);
        $this->assertSame('redaktion', $rule->planning_responsibility_key);

        $this->actingAs($ctx['user'])
            ->post(route('calculations.store'), $this->payload([
                $this->nativePosition($podcast, $ctx['native'], ['free_price' => '2500.00']),
            ]))
            ->assertRedirect();

        $position = Calculation::query()->firstOrFail()->positions()->firstOrFail();
        $this->assertSame((int) $podcast->id, (int) $position->inventory_id);
        $this->assertSame('free_position', $position->engine_profile_key);
        $this->assertSame('v1', $position->algorithm_version);
        $this->assertSame('2500.00', (string) $position->media_gross);
        $this->assertSame('redaktion', $position->planning_responsibility_key);
    }

    #[Test]
    public function more_kombi_online_audio_is_not_bookable_with_native_ad(): void
    {
        $ctx = $this->seedContext();
        $moreOa = Inventory::query()->where('code', 'inv_more_kombi_online_audio')->firstOrFail();

        $this->assertFalse(
            InventoryMediumRule::query()
                ->where('inventory_id', $moreOa->id)
                ->where('advertising_medium_id', $ctx['native']->id)
                ->exists(),
        );

        $payload = $this->payload([$this->nativePosition($moreOa, $ctx['native'])]);

        // Fail-closed: weder Preview noch Store ohne Rule; nichts wird gespeichert.
        $this->actingAs($ctx['user'])
            ->postJson(route('calculations.preview'), $payload)
            ->assertStatus(422);
        $this->actingAs($ctx['user'])
            ->postJson(route('calculations.store'), $payload)
            ->assertStatus(422);
        $this->assertSame(0, Calculation::query()->count());
    }

    #[Test]
    public function hauptfall_preview_and_store_without_fake_yield_nn_3060(): void
    {
        $ctx = $this->seedContext();
        $payload = $this->payload(
            [$this->nativePosition($ctx['radio'], $ctx['native'], ['position_discount_percent' => '10'])],
            ['order_discount_percent' => '20', 'ae_enabled' => true],
        );

        $response = $this->actingAs($ctx['user'])->postJson(route('calculations.preview'), $payload);
        $response->assertOk();
        $this->assertSame('5000.00', $response->json('totals.media_gross'));
        $this->assertSame('3060.00', $response->json('totals.nn_invest'));
        $this->assertSame('3060.00', $response->json('totals.positions.0.nn_invest'));
        $this->assertSame('free_position', $response->json('totals.positions.0.method_result.method'));
        $this->assertSame(0, Calculation::query()->count());

        $this->actingAs($ctx['user'])->post(route('calculations.store'), $payload)->assertRedirect();

        $calculation = Calculation::query()->firstOrFail();
        $position = $calculation->positions()->firstOrFail();
        $this->assertSame('3060.00', (string) $calculation->nn_invest);
        $this->assertSame('3060.00', (string) $position->nn_invest);
        $this->assertSame('5000.00', (string) $position->media_gross);
        $this->assertSame('free_position', $position->engine_profile_key);
        $this->assertSame('free_position', $position->calculation_method_key);
        $this->assertSame('v1', $position->algorithm_version);
        $this->assertSame(0, (int) $position->length_seconds);
        $this->assertSame('redaktion', $position->planning_responsibility_key);
    }

    #[Test]
    public function medium_is_discountable_false_ignores_discounts(): void
    {
        $ctx = $this->seedContext();
        $ctx['native']->update(['is_discountable' => false]);

        $nn = $this->previewHauptfall($ctx);

        // Keine Rabatte, AE bleibt: 5000 − 15 % = 4250.
        $this->assertSame('4250.00', $nn['nn_invest']);
        $this->assertSame('0.00', $nn['discount_total']);
    }

    #[Test]
    public function rule_is_discountable_false_ignores_discounts(): void
    {
        $ctx = $this->seedContext();
        InventoryMediumRule::query()
            ->where('inventory_id', $ctx['radio']->id)
            ->where('advertising_medium_id', $ctx['native']->id)
            ->update(['is_discountable' => false]);

        $nn = $this->previewHauptfall($ctx);

        $this->assertSame('4250.00', $nn['nn_invest']);
        $this->assertSame('0.00', $nn['discount_total']);
    }

    #[Test]
    public function medium_is_ae_eligible_false_yields_no_ae(): void
    {
        $ctx = $this->seedContext();
        $ctx['native']->update(['is_ae_eligible' => false]);

        $nn = $this->previewHauptfall($ctx);

        // Rabatte greifen (5000 → 4500 → 3600), AE entfällt.
        $this->assertSame('3600.00', $nn['nn_invest']);
        $this->assertSame('0.00', $nn['ae_total']);
    }

    #[Test]
    public function rule_is_ae_eligible_false_yields_no_ae(): void
    {
        $ctx = $this->seedContext();
        InventoryMediumRule::query()
            ->where('inventory_id', $ctx['radio']->id)
            ->where('advertising_medium_id', $ctx['native']->id)
            ->update(['is_ae_eligible' => false]);

        $nn = $this->previewHauptfall($ctx);

        $this->assertSame('3600.00', $nn['nn_invest']);
        $this->assertSame('0.00', $nn['ae_total']);
    }

    /**
     * @param  array<string, mixed>  $ctx
     * @return array{nn_invest: string, ae_total: string, discount_total: string}
     */
    private function previewHauptfall(array $ctx): array
    {
        $response = $this->actingAs($ctx['user'])->postJson(
            route('calculations.preview'),
            $this->payload(
                [$this->nativePosition($ctx['radio'], $ctx['native'], ['position_discount_percent' => '10'])],
                ['order_discount_percent' => '20', 'ae_enabled' => true],
            ),
        );
        $response->assertOk();
        $this->assertSame('5000.00', $response->json('totals.media_gross'));

        $totals = (array) $response->json('totals');
        foreach (['position_discount_total', 'order_discount_total', 'ae_total', 'nn_invest'] as $key) {
            $this->assertArrayHasKey($key, $totals);
        }
        $discounts = bcadd(
            (string) $totals['position_discount_total'],
            (string) $totals['order_discount_total'],
            2,
        );

        return [
            'nn_invest' => (string) $totals['nn_invest'],
            'ae_total' => (string) $totals['ae_total'],
            'discount_total' => $discounts,
        ];
    }

    /**
     * @return array{user: User, native: AdvertisingMedium, radio: Inventory}
     */
    private function seedContext(): array
    {
        $this->seed(InitialCatalogMatCoreSeeder::class);

        $native = AdvertisingMedium::query()->where('code', 'native_ad')->firstOrFail();
        $radio = Inventory::query()->where('code', 'inv_radio_hamburg')->firstOrFail();

        // Historische Native-Ad-Rule (Matrix) für ein Sender-Inventar nachbilden.
        InventoryMediumRule::factory()->create([
            'inventory_id' => $radio->id,
            'advertising_medium_id' => $native->id,
            'is_active' => true,
            'booking_code' => 'Online Audio (UA)',
            'planning_responsibility_key' => 'redaktion',
        ]);

        $user = User::factory()->role(Role::Sales)->create();

        return compact('user', 'native', 'radio');
    }

    /**
     * @param  array<string, mixed>  $override
     * @return array<string, mixed>
     */
    private function nativePosition(Inventory $inventory, AdvertisingMedium $native, array $override = []): array
    {
        return array_merge([
            'client_key' => (string) str()->uuid(),
            'inventory_id' => (int) $inventory->id,
            'advertising_medium_id' => (int) $native->id,
            'calculation_method_key' => 'free_position',
            'free_price' => '5000.00',
            'position_discount_percent' => '0',
            'ae_percent' => '15',
        ], $override);
    }

    /**
     * @param  list<array<string, mixed>>  $positions
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $positions, array $overrides = []): array
    {
        return $this->withLiveSchemaFingerprint(array_merge([
            'planning_mode' => 'manual',
            'order_discount_percent' => '0',
            'ae_enabled' => false,
            'positions' => $positions,
        ], $overrides));
    }
}
