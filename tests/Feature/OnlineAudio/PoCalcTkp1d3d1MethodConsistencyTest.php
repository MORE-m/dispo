<?php

namespace Tests\Feature\OnlineAudio;

use App\Enums\DayGroup;
use App\Enums\EngineCapabilityStatus;
use App\Enums\OaTargetingCategory;
use App\Enums\PriceListStatus;
use App\Enums\Role;
use App\Enums\SpotCalculationMethod;
use App\Models\AdvertisingMedium;
use App\Models\CalculationPosition;
use App\Models\DispoOrder;
use App\Models\DispoOrderPosition;
use App\Models\Inventory;
use App\Models\InventoryMediumRule;
use App\Models\OaPlatform;
use App\Models\OaTargetingDefinition;
use App\Models\OaTkpPriceList;
use App\Models\OaTkpPriceListItem;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\User;
use App\Services\Calculation\CalculationWriter;
use App\Services\DispoOrder\DispoOrderWriter;
use App\Support\Calculation\EngineProfileRegistry;
use App\Support\Calculation\OaTkpWizardCapability;
use App\Support\DispoOrder\DispoPositionMethodClassifier;
use App\Support\PriceList\PriceListCalendar;
use Database\Seeders\InitialCatalogMatCoreSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * PO-CALC-TKP-1D3d-1: Methodenkonsistenz Adoption/Handoff/Show fail-closed.
 */
class PoCalcTkp1d3d1MethodConsistencyTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function adoption_api_marks_inconsistent_pair_non_adoptable(): void
    {
        $ctx = $this->seedContext();
        $calc = app(CalculationWriter::class)->create($this->payload([
            $this->oaPosition($ctx),
        ]), $ctx['user']);
        $oa = $calc->positions->firstOrFail();

        // Gezielte Korruptions-Fixture (nicht über produktiven Writer).
        $oa->forceFill([
            'engine_profile_key' => EngineProfileRegistry::PROFILE_SPOT_CLASSIC,
            'calculation_method_key' => 'tkp',
            'calculation_method_name' => 'TKP',
        ])->save();

        $row = $this->actingAs($ctx['user'])
            ->getJson(route('dispo-orders.positions', $calc->fresh()))
            ->assertOk()
            ->json('positions.0');

        $this->assertSame(DispoPositionMethodClassifier::KIND_INCONSISTENT, $row['method_classification']);
        $this->assertFalse($row['adoptable']);
        $this->assertSame(
            DispoPositionMethodClassifier::MESSAGE_INCONSISTENT,
            $row['method_inconsistency_message'],
        );
    }

    #[Test]
    public function adoption_api_marks_valid_oa_and_spot_adoptable(): void
    {
        $ctx = $this->seedContext();
        $spot = $this->createSpotFixture();
        $calc = app(CalculationWriter::class)->create($this->payload([
            $this->spotPosition($spot),
            $this->oaPosition($ctx),
        ]), $ctx['user']);

        $rows = collect($this->actingAs($ctx['user'])
            ->getJson(route('dispo-orders.positions', $calc))
            ->assertOk()
            ->json('positions'));

        $spotRow = $rows->first(fn (array $row): bool => ($row['engine_profile_key'] ?? null) === 'spot_classic');
        $oaRow = $rows->first(fn (array $row): bool => ($row['engine_profile_key'] ?? null) === 'oa_tkp');

        $this->assertNotNull($spotRow);
        $this->assertNotNull($oaRow);
        $this->assertSame(DispoPositionMethodClassifier::KIND_SPOT_FAMILY, $spotRow['method_classification']);
        $this->assertTrue($spotRow['adoptable']);
        $this->assertNull($spotRow['method_inconsistency_message']);
        $this->assertSame(DispoPositionMethodClassifier::KIND_OA_TKP, $oaRow['method_classification']);
        $this->assertTrue($oaRow['adoptable']);
        $this->assertNull($oaRow['method_inconsistency_message']);
    }

    #[Test]
    public function handoff_rejects_inconsistent_position_ids_without_creating_order(): void
    {
        $ctx = $this->seedContext();
        $calc = app(CalculationWriter::class)->create($this->payload([
            $this->oaPosition($ctx),
        ]), $ctx['user']);
        $oa = $calc->positions->firstOrFail();
        $oa->forceFill([
            'engine_profile_key' => 'oa_tkp',
            'calculation_method_key' => 'average',
            'calculation_method_name' => 'Durchschnitt',
        ])->save();

        $before = DispoOrder::query()->count();

        $this->actingAs($ctx['user'])
            ->postJson(route('dispo-orders.store', $calc), [
                'position_ids' => [(int) $oa->id],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['position_ids']);

        $this->assertSame($before, DispoOrder::query()->count());
    }

    #[Test]
    public function mixed_handoff_rolls_back_when_one_position_is_inconsistent(): void
    {
        $ctx = $this->seedContext();
        $spot = $this->createSpotFixture();
        $calc = app(CalculationWriter::class)->create($this->payload([
            $this->spotPosition($spot),
            $this->oaPosition($ctx),
        ]), $ctx['user']);

        $spotPos = $calc->positions->first(fn (CalculationPosition $p): bool => $p->engine_profile_key === 'spot_classic');
        $oaPos = $calc->positions->first(fn (CalculationPosition $p): bool => $p->engine_profile_key === 'oa_tkp');
        $this->assertNotNull($spotPos);
        $this->assertNotNull($oaPos);

        $oaPos->forceFill([
            'engine_profile_key' => 'oa_tkp',
            'calculation_method_key' => 'calendar',
            'calculation_method_name' => 'Kalender',
        ])->save();

        $beforeOrders = DispoOrder::query()->count();
        $beforePositions = DispoOrderPosition::query()->count();

        try {
            app(DispoOrderWriter::class)->createFromCalculation(
                $calc->fresh(),
                [(int) $spotPos->id, (int) $oaPos->id],
                $ctx['user'],
            );
            $this->fail('Expected ValidationException for inconsistent OA pair.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('position_ids', $exception->errors());
            $this->assertStringContainsString(
                'Methoden-/Engine-Kombination',
                $exception->errors()['position_ids'][0],
            );
        }

        $this->assertSame($beforeOrders, DispoOrder::query()->count());
        $this->assertSame($beforePositions, DispoOrderPosition::query()->count());
    }

    #[Test]
    public function historical_show_exposes_inconsistent_classification_without_spot_fallback_props_semantics(): void
    {
        $ctx = $this->seedContext();
        $calc = app(CalculationWriter::class)->create($this->payload([
            $this->oaPosition($ctx),
        ]), $ctx['user']);
        $oa = $calc->positions->firstOrFail();
        $order = app(DispoOrderWriter::class)
            ->createFromCalculation($calc, [(int) $oa->id], $ctx['user'])
            ->order;

        $dispoPosition = $order->positions->firstOrFail();
        // Historische Korruption am bereits gespeicherten Dispo-Snapshot (keine Migration/Korrektur).
        $dispoPosition->forceFill([
            'engine_profile_key' => EngineProfileRegistry::PROFILE_SPOT_CLASSIC,
            'calculation_method_key' => 'tkp',
            'calculation_method_name' => 'TKP',
            'spot_method' => SpotCalculationMethod::Tkp,
        ])->save();

        $this->actingAs($ctx['user'])
            ->get(route('dispo-orders.show', $order->fresh()))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('dispo-orders/show')
                ->where('order.positions.0.method_classification', DispoPositionMethodClassifier::KIND_INCONSISTENT)
                ->where(
                    'order.positions.0.method_inconsistency_message',
                    DispoPositionMethodClassifier::MESSAGE_INCONSISTENT,
                )
                ->where('order.positions.0.calculation_method_key', 'tkp')
                ->where('order.positions.0.engine_profile_key', 'spot_classic'));
    }

    #[Test]
    public function valid_oa_and_spot_handoff_still_succeed(): void
    {
        $ctx = $this->seedContext();
        $spot = $this->createSpotFixture();
        $calc = app(CalculationWriter::class)->create($this->payload([
            $this->spotPosition($spot),
            $this->oaPosition($ctx),
        ]), $ctx['user']);

        $ids = $calc->positions->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $order = app(DispoOrderWriter::class)
            ->createFromCalculation($calc, $ids, $ctx['user'])
            ->order;

        $this->assertCount(2, $order->positions);
        $kinds = $order->positions
            ->map(fn (DispoOrderPosition $p): string => DispoPositionMethodClassifier::classifyPosition($p))
            ->sort()
            ->values()
            ->all();
        $this->assertSame([
            DispoPositionMethodClassifier::KIND_OA_TKP,
            DispoPositionMethodClassifier::KIND_SPOT_FAMILY,
        ], $kinds);
    }

    #[Test]
    public function registry_and_http_gates_remain_closed(): void
    {
        $this->assertSame(
            EngineCapabilityStatus::Planned,
            EngineProfileRegistry::pairStatus('oa_tkp', 'tkp'),
        );
        $this->assertNull(EngineProfileRegistry::currentReleasedVersion('oa_tkp', 'tkp'));

        config([OaTkpWizardCapability::CONFIG_KEY => true]);
        $ctx = $this->seedContext();
        $payload = $this->payload([$this->oaPosition($ctx)]);

        $this->actingAs($ctx['user'])
            ->postJson(route('calculations.store'), $payload)
            ->assertStatus(422);
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

    /**
     * @param  array<string, mixed>  $ctx
     * @param  array<string, mixed>  $override
     * @return array<string, mixed>
     */
    private function oaPosition(array $ctx, array $override = []): array
    {
        return array_merge([
            'inventory_id' => (int) $ctx['inventory']->id,
            'advertising_medium_id' => (int) $ctx['medium']->id,
            'calculation_method_key' => 'tkp',
            'impressions' => 100_000,
            'basis_tkp' => '10.0000',
            'oa_platform_allocations' => [
                ['oa_platform_id' => (int) $ctx['platform']->id, 'impressions' => 100_000],
            ],
            'oa_targeting_selections' => [],
            'position_discount_percent' => '0',
            'ae_percent' => '15',
        ], $override);
    }

    /**
     * @return array{medium: AdvertisingMedium, inventory: Inventory}
     */
    private function createSpotFixture(): array
    {
        $medium = AdvertisingMedium::query()->where('code', 'spot_classic')->firstOrFail();
        $inventory = Inventory::factory()->create(['name' => 'D3d1 Dispo Spot', 'code' => 'D3D1DISPOSPOT']);
        InventoryMediumRule::factory()->create([
            'inventory_id' => $inventory->id,
            'advertising_medium_id' => $medium->id,
        ]);
        $list = PriceList::factory()->create([
            'inventory_id' => $inventory->id,
            'status' => PriceListStatus::Active,
            'year' => PriceListCalendar::currentYear(),
            'version' => 'D3D1-DISPO-SPOT',
            'valid_from' => now()->toDateString(),
        ]);
        foreach (range(0, 23) as $hour) {
            foreach ([DayGroup::MoFr, DayGroup::Sa, DayGroup::So] as $group) {
                PriceListItem::factory()->create([
                    'price_list_id' => $list->id,
                    'hour' => $hour,
                    'day_group' => $group,
                    'second_price' => '1.0000',
                ]);
            }
        }

        return compact('medium', 'inventory');
    }

    /**
     * @param  array{medium: AdvertisingMedium, inventory: Inventory}  $spot
     * @return array<string, mixed>
     */
    private function spotPosition(array $spot): array
    {
        return [
            'inventory_id' => $spot['inventory']->id,
            'advertising_medium_id' => $spot['medium']->id,
            'spot_method' => 'average',
            'length_seconds' => 30,
            'total_spot_count' => 10,
            'position_discount_percent' => '0',
            'ae_percent' => '15',
            'plan_rows' => [['hour' => 8, 'day_group' => 'mo_fr']],
        ];
    }

    /**
     * @return array{
     *     user: User,
     *     medium: AdvertisingMedium,
     *     inventory: Inventory,
     *     platform: OaPlatform,
     *     targeting: OaTargetingDefinition,
     *     list: OaTkpPriceList
     * }
     */
    private function seedContext(): array
    {
        $this->seed(InitialCatalogMatCoreSeeder::class);
        $user = User::factory()->role(Role::Sales)->create();
        $medium = AdvertisingMedium::query()->where('code', 'pre_stream')->firstOrFail();
        $inventory = Inventory::query()->where('code', 'inv_more_kombi_online_audio')->firstOrFail();
        $list = OaTkpPriceList::query()->create([
            'year' => PriceListCalendar::currentYear(),
            'name' => 'Fixture D3d1',
            'version' => PriceListCalendar::currentYear().'.1',
            'revision_number' => 1,
            'lock_version' => 1,
            'status' => PriceListStatus::Active,
            'published_at' => now(),
        ]);
        OaTkpPriceListItem::query()->create([
            'oa_tkp_price_list_id' => $list->id,
            'advertising_medium_id' => (int) $medium->id,
            'inventory_id' => (int) $inventory->id,
            'list_tkp' => '12.0000',
            'minimum_tkp' => '8.0000',
        ]);
        $platform = OaPlatform::query()->create([
            'code' => 'fixture_d3d1_plat',
            'name' => 'Fixture D3d1 Platform',
            'is_active' => true,
            'sort' => 1,
            'is_adserver_relevant' => false,
        ]);
        $targeting = OaTargetingDefinition::query()->create([
            'code' => 'geo_d3d1',
            'name' => 'Geo D3d1',
            'category' => OaTargetingCategory::Technisch,
            'tkp_surcharge' => '2.0000',
            'revision_number' => 1,
            'is_active' => true,
        ]);

        return compact('user', 'medium', 'inventory', 'platform', 'targeting', 'list');
    }
}
