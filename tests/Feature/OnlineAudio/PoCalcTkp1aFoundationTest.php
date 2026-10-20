<?php

namespace Tests\Feature\OnlineAudio;

use App\Enums\CalculationMethodMode;
use App\Enums\EngineCapabilityStatus;
use App\Enums\Role;
use App\Models\AdvertisingCategory;
use App\Models\AdvertisingCategoryCalculationMethod;
use App\Models\AdvertisingMedium;
use App\Models\AdvertisingMediumCalculationMethod;
use App\Models\CalculationMethod;
use App\Models\Inventory;
use App\Models\InventoryMediumRule;
use App\Models\OaAdserverSurcharge;
use App\Models\OaPlatform;
use App\Models\OaTargetingDefinition;
use App\Models\OaTkpPriceList;
use App\Models\User;
use App\Services\Calculation\CalculationWriter;
use App\Services\DynamicField\ConfigurationSnapshotFreezeService;
use App\Support\Advertising\AdvertisingMediumLiveBookability;
use App\Support\Advertising\CanonicalAdvertisingCategories;
use App\Support\Calculation\EngineProfileRegistry;
use App\Support\OnlineAudio\OaTkpCoreMedia;
use Database\Seeders\InitialCatalogMatCoreSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;

/**
 * PO-CALC-TKP-1A: Schema, Medium-Assignments, Registry planned, fail-closed Bookability.
 */
class PoCalcTkp1aFoundationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function foundation_tables_exist_empty_without_operative_seeds(): void
    {
        foreach ([
            'oa_platforms',
            'oa_tkp_price_lists',
            'oa_tkp_price_list_items',
            'oa_targeting_definitions',
            'oa_adserver_surcharges',
        ] as $table) {
            $this->assertTrue(Schema::hasTable($table), $table);
        }

        $this->assertSame(0, OaPlatform::query()->count());
        $this->assertSame(0, OaTkpPriceList::query()->count());
        $this->assertSame(0, OaTargetingDefinition::query()->count());
        $this->assertSame(0, OaAdserverSurcharge::query()->count());
    }

    #[Test]
    public function core_media_have_tkp_oa_tkp_override_while_registry_stays_planned(): void
    {
        $this->seed(InitialCatalogMatCoreSeeder::class);

        $methodId = (int) CalculationMethod::query()->where('key', 'tkp')->value('id');
        $categoryId = (int) AdvertisingCategory::query()
            ->where('key', CanonicalAdvertisingCategories::ONLINE_AUDIO)
            ->value('id');

        $this->assertSame(
            0,
            AdvertisingCategoryCalculationMethod::query()
                ->where('advertising_category_id', $categoryId)
                ->where('calculation_method_id', $methodId)
                ->count(),
        );

        foreach (OaTkpCoreMedia::codes() as $code) {
            $medium = AdvertisingMedium::query()->where('code', $code)->firstOrFail();
            $this->assertSame(CalculationMethodMode::Override, $medium->calculation_method_mode, $code);
            $this->assertSame($methodId, (int) $medium->default_calculation_method_id, $code);

            $assignment = AdvertisingMediumCalculationMethod::query()
                ->where('advertising_medium_id', $medium->id)
                ->where('calculation_method_id', $methodId)
                ->firstOrFail();
            $this->assertSame(EngineProfileRegistry::PROFILE_OA_TKP, $assignment->engine_profile_key);
            $this->assertTrue($assignment->is_active);

            $this->assertSame(
                EngineProfileRegistry::PROFILE_OA_TKP,
                $this->effectiveEngineProfile($medium, 'tkp'),
                $code,
            );
        }

        $this->assertTrue(EngineProfileRegistry::hasProfile(EngineProfileRegistry::PROFILE_OA_TKP));
        $this->assertSame(
            EngineCapabilityStatus::Planned,
            EngineProfileRegistry::pairStatus(EngineProfileRegistry::PROFILE_OA_TKP, 'tkp'),
        );
        $this->assertNull(
            EngineProfileRegistry::currentReleasedVersion(EngineProfileRegistry::PROFILE_OA_TKP, 'tkp'),
        );
        $pairs = EngineProfileRegistry::pairsForMethodKey('tkp');
        $this->assertCount(1, $pairs);
        $this->assertSame('oa_tkp', $pairs[0]['engine_profile_key']);
        $this->assertSame('planned', $pairs[0]['pair_status']);
        $this->assertNull($pairs[0]['current_released_version']);
    }

    #[Test]
    public function native_ad_has_no_effective_tkp_oa_tkp_assignment(): void
    {
        $this->seed(InitialCatalogMatCoreSeeder::class);

        $native = AdvertisingMedium::query()->where('code', 'native_ad')->firstOrFail();
        $this->assertSame(CalculationMethodMode::Inherit, $native->calculation_method_mode);
        $this->assertNull($native->kind);

        $methodId = (int) CalculationMethod::query()->where('key', 'tkp')->value('id');
        $this->assertSame(
            0,
            AdvertisingMediumCalculationMethod::query()
                ->where('advertising_medium_id', $native->id)
                ->where('calculation_method_id', $methodId)
                ->count(),
        );

        $categoryId = (int) AdvertisingCategory::query()
            ->where('key', CanonicalAdvertisingCategories::ONLINE_AUDIO)
            ->value('id');
        $this->assertSame(
            0,
            AdvertisingCategoryCalculationMethod::query()
                ->where('advertising_category_id', $categoryId)
                ->where('calculation_method_id', $methodId)
                ->count(),
        );

        $this->assertNull($this->effectiveEngineProfile($native, 'tkp'));
    }

    #[Test]
    public function oa_medium_tkp_is_not_bookable_while_planned(): void
    {
        $this->seed(InitialCatalogMatCoreSeeder::class);

        $medium = AdvertisingMedium::query()->where('code', 'pre_stream')->firstOrFail();
        $this->assertNull($medium->kind);

        $result = app(AdvertisingMediumLiveBookability::class)->evaluate($medium, 'tkp');

        $this->assertFalse($result->isBookableForNewPositions);
        $reason = (string) $result->unbookableReason;
        $this->assertTrue(
            str_contains($reason, 'Keine freigegebene Berechnungsmethode')
            || str_contains($reason, 'noch nicht freigegeben')
            || str_contains($reason, 'nicht für neue Kalkulationen freigegeben'),
            $reason,
        );

        $native = AdvertisingMedium::query()->where('code', 'native_ad')->firstOrFail();
        $nativeResult = app(AdvertisingMediumLiveBookability::class)->evaluate($native, 'tkp');
        $this->assertFalse($nativeResult->isBookableForNewPositions);
    }

    #[Test]
    public function preview_create_with_oa_medium_and_tkp_fails_closed_without_server_error(): void
    {
        $this->seed(InitialCatalogMatCoreSeeder::class);
        $user = User::factory()->role(Role::Sales)->create();
        $medium = AdvertisingMedium::query()->where('code', 'pre_stream')->firstOrFail();
        $inventoryId = $this->ensureOaInventoryRule($medium);

        $response = $this->actingAs($user)->postJson(route('calculations.preview'), [
            'planning_mode' => 'manual',
            'order_discount_percent' => '0',
            'positions' => [[
                'inventory_id' => $inventoryId,
                'advertising_medium_id' => $medium->id,
                'calculation_method_key' => 'tkp',
                'length_seconds' => 30,
                'total_spot_count' => 1,
                'position_discount_percent' => '0',
                'ae_percent' => '0',
                'plan_rows' => [['hour' => 8, 'day_group' => 'mo_fr']],
            ]],
        ]);

        $this->assertContains($response->status(), [422, 403]);
        $this->assertNotSame(500, $response->status());
    }

    #[Test]
    public function writer_rejects_tkp_for_oa_medium_with_validation_exception(): void
    {
        $this->seed(InitialCatalogMatCoreSeeder::class);
        $user = User::factory()->role(Role::Sales)->create();
        $medium = AdvertisingMedium::query()->where('code', 'pre_stream')->firstOrFail();
        $inventoryId = $this->ensureOaInventoryRule($medium);
        $freeze = app(ConfigurationSnapshotFreezeService::class);

        try {
            app(CalculationWriter::class)->create([
                'planning_mode' => 'manual',
                'order_discount_percent' => '0',
                'schema_fingerprint' => $freeze->resolveLiveSchemaForCalculationV3()['schema_fingerprint'],
                'positions' => [[
                    'inventory_id' => $inventoryId,
                    'advertising_medium_id' => $medium->id,
                    'schema_fingerprint' => $freeze->resolveLivePositionSchema((int) $medium->id)['schema_fingerprint'],
                    'calculation_method_key' => 'tkp',
                    'length_seconds' => 30,
                    'total_spot_count' => 1,
                    'position_discount_percent' => '0',
                    'ae_percent' => '0',
                    'plan_rows' => [['hour' => 8, 'day_group' => 'mo_fr']],
                ]],
            ], $user);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $messages = collect($e->errors())->flatten()->implode(' ');
            $this->assertTrue(
                str_contains($messages, 'noch nicht freigegeben')
                || str_contains($messages, 'nicht mehr verfügbar')
                || str_contains($messages, 'nicht verfügbar')
                || str_contains($messages, 'Keine freigegebene')
                || str_contains($messages, 'nicht freigegeben'),
                $messages,
            );
        }
    }

    private function effectiveEngineProfile(AdvertisingMedium $medium, string $methodKey): ?string
    {
        $medium->load([
            'category',
            'defaultCalculationMethod',
            'category.defaultCalculationMethod',
            'calculationMethodAssignments.calculationMethod',
            'category.calculationMethodAssignments.calculationMethod',
        ]);

        $resolver = app(AdvertisingMediumLiveBookability::class);
        $method = new ReflectionMethod($resolver, 'resolveActiveEngineProfileKey');
        $method->setAccessible(true);

        /** @var string|null $profile */
        $profile = $method->invoke($resolver, $medium, $methodKey, null, null);

        return $profile;
    }

    private function ensureOaInventoryRule(AdvertisingMedium $medium): int
    {
        $inventoryId = (int) Inventory::query()
            ->where('code', 'inv_more_kombi_online_audio')
            ->value('id');

        InventoryMediumRule::query()->firstOrCreate(
            [
                'inventory_id' => $inventoryId,
                'advertising_medium_id' => $medium->id,
            ],
            [
                'is_active' => true,
                'sort' => 10,
                'is_discountable' => true,
                'is_ae_eligible' => true,
                'component_calculation_strategy' => 'shared_total_length',
                'lock_version' => 1,
            ],
        );

        return $inventoryId;
    }
}
