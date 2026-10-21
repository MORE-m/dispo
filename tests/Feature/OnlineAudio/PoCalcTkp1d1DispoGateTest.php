<?php

namespace Tests\Feature\OnlineAudio;

use App\Enums\DayGroup;
use App\Enums\PriceListStatus;
use App\Enums\Role;
use App\Models\AdvertisingMedium;
use App\Models\DispoOrder;
use App\Models\DispoOrderPosition;
use App\Models\Inventory;
use App\Models\InventoryMediumRule;
use App\Models\OaPlatform;
use App\Models\OaTkpPriceList;
use App\Models\OaTkpPriceListItem;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\User;
use App\Services\Calculation\CalculationWriter;
use App\Services\DispoOrder\DispoOrderWriter;
use App\Support\PriceList\PriceListCalendar;
use Database\Seeders\InitialCatalogMatCoreSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * PO-CALC-TKP-1D1 Nachzug: Dispo-Handoff vor D3 fail-closed für oa_tkp × tkp.
 */
class PoCalcTkp1d1DispoGateTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function create_from_calculation_rejects_oa_only_without_persisting(): void
    {
        $ctx = $this->seedContext();
        $calc = app(CalculationWriter::class)->create($this->payload([
            $this->oaPosition($ctx),
        ]), $ctx['user']);

        $oaId = (int) $calc->positions->firstOrFail()->id;

        try {
            app(DispoOrderWriter::class)->createFromCalculation($calc, [$oaId], $ctx['user']);
            $this->fail('Erwartete ValidationException für OA-Dispo-Handoff.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString(
                'TKP-Positionen können noch nicht an die Disposition übergeben werden',
                collect($exception->errors())->flatten()->implode(' '),
            );
        }

        $this->assertSame(0, DispoOrder::query()->count());
        $this->assertSame(0, DispoOrderPosition::query()->count());
    }

    #[Test]
    public function mixed_calculation_allows_spot_only_and_rejects_oa_selection(): void
    {
        $ctx = $this->seedContext();
        $spot = $this->createSpotFixture();
        $calc = app(CalculationWriter::class)->create($this->payload([
            $this->spotPosition($spot),
            $this->oaPosition($ctx),
        ]), $ctx['user']);

        $spotId = (int) $calc->positions->first(fn ($p): bool => $p->engine_profile_key === 'spot_classic')->id;
        $oaId = (int) $calc->positions->first(fn ($p): bool => $p->engine_profile_key === 'oa_tkp')->id;

        $order = app(DispoOrderWriter::class)->createFromCalculation($calc, [$spotId], $ctx['user'])->order;
        $this->assertSame(1, DispoOrder::query()->count());
        $this->assertSame(1, $order->positions()->where('line_role', DispoOrderPosition::LINE_ROLE_MEDIA)->count());
        $this->assertSame($spotId, (int) $order->positions()->where('line_role', DispoOrderPosition::LINE_ROLE_MEDIA)->value('calculation_position_id'));

        try {
            app(DispoOrderWriter::class)->createFromCalculation($calc->fresh(), [$oaId], $ctx['user']);
            $this->fail('Erwartete ValidationException für nur-OA-Auswahl.');
        } catch (ValidationException) {
            // erwartet
        }

        try {
            app(DispoOrderWriter::class)->createFromCalculation($calc->fresh(), [$spotId, $oaId], $ctx['user']);
            $this->fail('Erwartete ValidationException für Spot+OA-Auswahl.');
        } catch (ValidationException) {
            // erwartet
        }

        $this->assertSame(1, DispoOrder::query()->count());
        $this->assertSame(1, DispoOrderPosition::query()->where('line_role', DispoOrderPosition::LINE_ROLE_MEDIA)->count());
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
        $inventory = Inventory::factory()->create(['name' => 'D1 Dispo Spot', 'code' => 'D1DISPOSPOT']);
        InventoryMediumRule::factory()->create([
            'inventory_id' => $inventory->id,
            'advertising_medium_id' => $medium->id,
        ]);
        $list = PriceList::factory()->create([
            'inventory_id' => $inventory->id,
            'status' => PriceListStatus::Active,
            'year' => PriceListCalendar::currentYear(),
            'version' => 'D1-DISPO-SPOT',
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
     *     platform: OaPlatform
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
            'name' => 'Fixture Dispo Gate',
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
            'code' => 'fixture_dispo_plat',
            'name' => 'Fixture Dispo Platform',
            'is_active' => true,
            'sort' => 1,
            'is_adserver_relevant' => false,
        ]);

        return compact('user', 'medium', 'inventory', 'platform');
    }
}
