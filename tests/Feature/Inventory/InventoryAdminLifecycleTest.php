<?php

namespace Tests\Feature\Inventory;

use App\Enums\InventoryType;
use App\Enums\Role;
use App\Models\AuditEvent;
use App\Models\Inventory;
use App\Models\InventoryMediumRule;
use App\Models\Organization;
use App\Models\PriceList;
use App\Models\User;
use App\Services\Inventory\Admin\InventoryImpactPreviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesSavedCalculation;
use Tests\Concerns\CreatesSpotClassicCatalog;
use Tests\TestCase;

/**
 * BL-P2-01a Inventar-Admin-Lifecycle + historische Namensstabilität.
 */
class InventoryAdminLifecycleTest extends TestCase
{
    use CreatesSavedCalculation;
    use CreatesSpotClassicCatalog;
    use RefreshDatabase;

    public function test_admin_and_management_can_open_inventory_admin_other_roles_forbidden(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $management = User::factory()->role(Role::Management)->create();

        $this->actingAs($admin)
            ->get(route('administration.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('administration/index')
                ->where('modules', fn ($modules): bool => collect($modules)->contains(
                    fn (array $m): bool => $m['key'] === 'inventories' && $m['available'] === true,
                ))
                ->where('modules', fn ($modules): bool => collect($modules)->contains(
                    fn (array $m): bool => $m['key'] === 'price-lists' && $m['available'] === false,
                )));

        $this->actingAs($admin)
            ->get(route('administration.inventories.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('administration/inventories/index')
                ->has('inventories', 0));

        $this->actingAs($management)
            ->get(route('administration.inventories.create'))
            ->assertOk();

        foreach ([Role::Sales, Role::Disposition, Role::ProductManagement] as $role) {
            $user = User::factory()->role($role)->create();
            $this->actingAs($user)
                ->get(route('administration.inventories.index'))
                ->assertForbidden();
            $this->actingAs($user)
                ->post(route('administration.inventories.store'), [
                    'name' => 'Verboten',
                    'code' => 'FRB',
                    'type' => InventoryType::Sender->value,
                ])
                ->assertForbidden();
        }
    }

    public function test_create_assigns_singleton_organization_and_ignores_client_org(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $organization = Organization::factory()->create(['name' => 'E2E Sendergruppe']);

        $this->actingAs($admin)
            ->post(route('administration.inventories.store'), [
                'organization_id' => $organization->id + 99,
                'name' => 'Radio Test',
                'code' => 'RTS',
                'type' => InventoryType::Sender->value,
                'sort' => 4,
            ])
            ->assertSessionHasErrors('organization_id');

        $this->actingAs($admin)
            ->post(route('administration.inventories.store'), [
                'name' => 'Radio Test',
                'code' => 'RTS',
                'type' => InventoryType::Sender->value,
                'sort' => 4,
                'logo_path' => '/images/senders/radio-test.png',
            ])
            ->assertRedirect();

        $inventory = Inventory::query()->where('code', 'RTS')->firstOrFail();
        $this->assertSame($organization->id, $inventory->organization_id);
        $this->assertSame(1, Organization::query()->count());
        $this->assertTrue($inventory->is_active);
        $this->assertSame(1, $inventory->lock_version);
        $this->assertTrue(AuditEvent::query()->where('action', 'inventory.created')->exists());
    }

    public function test_create_kombi_and_unique_code(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();

        $this->actingAs($admin)
            ->post(route('administration.inventories.store'), [
                'name' => 'Kombi Test',
                'code' => 'KMB',
                'type' => InventoryType::Kombi->value,
                'sort' => 10,
            ])
            ->assertRedirect();

        $inventory = Inventory::query()->where('code', 'KMB')->firstOrFail();
        $this->assertSame(InventoryType::Kombi, $inventory->type);

        $this->actingAs($admin)
            ->get(route('administration.inventories.show', $inventory))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('inventory.membership_note', fn (?string $note): bool => is_string($note) && str_contains($note, 'späteren Slice')));

        $this->actingAs($admin)
            ->post(route('administration.inventories.store'), [
                'name' => 'Duplikat',
                'code' => 'KMB',
                'type' => InventoryType::Sender->value,
            ])
            ->assertSessionHasErrors('code');
    }

    public function test_update_name_sort_logo_code_and_type_immutable(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $inventory = $this->createInventoryViaAdmin($admin, [
            'name' => 'Altname',
            'code' => 'ALT',
            'sort' => 3,
        ]);

        $this->actingAs($admin)
            ->putJson(route('administration.inventories.update', $inventory), [
                'name' => 'Neuname',
                'sort' => 8,
                'logo_path' => '/images/senders/neu.png',
                'code' => 'HCK',
                'lock_version' => $inventory->lock_version,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('code');

        $this->actingAs($admin)
            ->putJson(route('administration.inventories.update', $inventory), [
                'name' => 'Neuname',
                'sort' => 8,
                'logo_path' => '/images/senders/neu.png',
                'type' => InventoryType::Kombi->value,
                'lock_version' => $inventory->lock_version,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('type');

        $this->actingAs($admin)
            ->putJson(route('administration.inventories.update', $inventory), [
                'name' => 'Neuname',
                'sort' => 8,
                'logo_path' => '/images/senders/neu.png',
                'is_active' => false,
                'lock_version' => $inventory->lock_version,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('is_active');

        $this->actingAs($admin)
            ->putJson(route('administration.inventories.update', $inventory), [
                'name' => 'Neuname',
                'sort' => 8,
                'logo_path' => '/images/senders/neu.png',
                'lock_version' => $inventory->lock_version,
            ])
            ->assertOk();

        $inventory->refresh();
        $this->assertSame('ALT', $inventory->code);
        $this->assertSame(InventoryType::Sender, $inventory->type);
        $this->assertSame('Neuname', $inventory->name);
        $this->assertSame(8, $inventory->sort);
        $this->assertSame('/images/senders/neu.png', $inventory->logo_path);
        $this->assertTrue($inventory->is_active);
        $this->assertSame(2, $inventory->lock_version);
        $this->assertTrue(AuditEvent::query()->where('action', 'inventory.updated')->exists());
    }

    public function test_noop_update_does_not_bump_lock_or_audit(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $inventory = $this->createInventoryViaAdmin($admin, [
            'name' => 'Gleich',
            'code' => 'GLC',
            'sort' => 1,
        ]);

        $this->actingAs($admin)
            ->putJson(route('administration.inventories.update', $inventory), [
                'name' => 'Gleich',
                'sort' => 1,
                'logo_path' => '',
                'lock_version' => $inventory->lock_version,
            ])
            ->assertOk();

        $inventory->refresh();
        $this->assertSame(1, $inventory->lock_version);
        $this->assertFalse(AuditEvent::query()->where('action', 'inventory.updated')->exists());
    }

    public function test_lock_conflict_returns_409(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $inventory = $this->createInventoryViaAdmin($admin, [
            'name' => 'Lock',
            'code' => 'LCK',
        ]);

        $this->actingAs($admin)
            ->putJson(route('administration.inventories.update', $inventory), [
                'name' => 'Lock A',
                'sort' => 0,
                'lock_version' => $inventory->lock_version,
            ])
            ->assertOk();

        $this->actingAs($admin)
            ->putJson(route('administration.inventories.update', $inventory), [
                'name' => 'Lock B',
                'sort' => 0,
                'lock_version' => 1,
            ])
            ->assertStatus(409)
            ->assertJsonFragment(['message' => 'Das Inventar wurde parallel geändert. Bitte neu laden und erneut prüfen.']);
    }

    public function test_no_hard_delete_deactivate_reactivate_keeps_rules_and_price_lists(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $catalog = $this->createSpotClassicCatalog();
        $inventory = $catalog['hamburg'];
        $priceListId = PriceList::query()->where('inventory_id', $inventory->id)->value('id');
        $ruleId = InventoryMediumRule::query()->where('inventory_id', $inventory->id)->value('id');

        $this->actingAs($admin)
            ->delete('/administration/inventare/'.$inventory->id)
            ->assertStatus(405);
        $this->assertTrue(Inventory::query()->whereKey($inventory->id)->exists());

        $preview = app(InventoryImpactPreviewService::class)->previewDeactivate($inventory);
        $this->assertTrue($preview['can_proceed']);
        $this->assertGreaterThan(0, $preview['price_lists_count']);
        $this->assertGreaterThan(0, $preview['inventory_medium_rules_count']);
        $this->assertStringContainsString('nicht mehr neu', $preview['new_processes_note']);
        $this->assertStringContainsString('bleiben lesbar', $preview['historical_snapshots_note']);

        $this->actingAs($admin)
            ->postJson(route('administration.inventories.deactivate', $inventory), [
                'lock_version' => $inventory->lock_version,
                'fingerprint' => $preview['fingerprint'],
            ])
            ->assertOk();

        $inventory->refresh();
        $this->assertFalse($inventory->is_active);
        $this->assertTrue(PriceList::query()->whereKey($priceListId)->exists());
        $this->assertTrue(InventoryMediumRule::query()->whereKey($ruleId)->exists());
        $this->assertTrue(AuditEvent::query()->where('action', 'inventory.deactivated')->exists());

        $this->actingAs($admin)
            ->postJson(route('administration.inventories.reactivate', $inventory), [
                'lock_version' => $inventory->lock_version,
            ])
            ->assertOk();

        $this->assertTrue($inventory->fresh()->is_active);
        $this->assertTrue(AuditEvent::query()->where('action', 'inventory.reactivated')->exists());
    }

    public function test_list_filters_and_sort(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $this->createInventoryViaAdmin($admin, [
            'name' => 'Zebra',
            'code' => 'ZZZ',
            'sort' => 20,
        ]);
        $this->createInventoryViaAdmin($admin, [
            'name' => 'Alpha Kombi',
            'code' => 'AAA',
            'type' => InventoryType::Kombi->value,
            'sort' => 5,
        ]);
        Inventory::query()->where('code', 'ZZZ')->update(['is_active' => false]);

        $this->actingAs($admin)
            ->get(route('administration.inventories.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('inventories', 2)
                ->where('inventories.0.code', 'AAA')
                ->where('inventories.1.code', 'ZZZ'));

        $this->actingAs($admin)
            ->get(route('administration.inventories.index', ['status' => 'inactive']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('inventories', 1)
                ->where('inventories.0.code', 'ZZZ'));

        $this->actingAs($admin)
            ->get(route('administration.inventories.index', ['type' => 'kombi']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('inventories', 1)
                ->where('inventories.0.code', 'AAA'));
    }

    public function test_historical_calc_name_and_code_survive_rename_and_deactivation(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $admin = User::factory()->role(Role::Admin)->create();
        $sales = User::factory()->role(Role::Sales)->create();
        $calculation = $this->createSavedCalculation($catalog, [
            ['inventory_id' => $catalog['hamburg']->id],
        ], $sales);
        $position = $calculation->positions()->firstOrFail();

        $this->assertSame('Radio Hamburg', $position->inventory_name);
        $this->assertSame('RH', $position->inventory_code);

        $this->actingAs($admin)
            ->putJson(route('administration.inventories.update', $catalog['hamburg']), [
                'name' => 'Radio Hamburg Neu',
                'sort' => 1,
                'lock_version' => $catalog['hamburg']->fresh()->lock_version,
            ])
            ->assertOk();

        $position->refresh();
        $this->assertSame('Radio Hamburg', $position->inventory_name);
        $this->assertSame('RH', $position->inventory_code);
        $this->assertSame($catalog['hamburg']->id, $position->inventory_id);
        $this->assertSame('Radio Hamburg Neu', $catalog['hamburg']->fresh()->name);

        $this->actingAs($sales)
            ->get(route('calculations.edit', $calculation))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('savedSummary.positions.0.inventory_name', 'Radio Hamburg')
                ->where('savedSummary.positions.0.inventory_code', 'RH')
                ->where('calculation.positions.0.inventory_name', 'Radio Hamburg'));

        $preview = app(InventoryImpactPreviewService::class)
            ->previewDeactivate($catalog['hamburg']->fresh());
        $this->actingAs($admin)
            ->postJson(route('administration.inventories.deactivate', $catalog['hamburg']), [
                'lock_version' => $catalog['hamburg']->fresh()->lock_version,
                'fingerprint' => $preview['fingerprint'],
            ])
            ->assertOk();

        $this->actingAs($sales)
            ->get(route('calculations.edit', $calculation))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('savedSummary.positions.0.inventory_name', 'Radio Hamburg')
                ->where('calculation.positions.0.inventory_id', $catalog['hamburg']->id));

        $this->actingAs($sales)
            ->post(route('calculations.store'), $this->minimalStorePayload($catalog, $catalog['hamburg']->id))
            ->assertSessionHasErrors('positions');
    }

    public function test_backfill_fills_missing_inventory_identity_from_live_row(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $sales = User::factory()->role(Role::Sales)->create();
        $calculation = $this->createSavedCalculation($catalog, [
            ['inventory_id' => $catalog['hamburg']->id],
        ], $sales);
        $positionId = $calculation->positions()->value('id');

        DB::table('calculation_positions')
            ->where('id', $positionId)
            ->update([
                'inventory_name' => null,
                'inventory_code' => null,
            ]);

        $migration = require database_path('migrations/2026_09_13_180100_add_inventory_name_code_to_calculation_positions.php');
        $migration->up();

        $row = DB::table('calculation_positions')->where('id', $positionId)->first();
        $this->assertSame('Radio Hamburg', $row->inventory_name);
        $this->assertSame('RH', $row->inventory_code);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createInventoryViaAdmin(User $admin, array $overrides): Inventory
    {
        $payload = array_merge([
            'name' => 'Inventar',
            'code' => 'INV',
            'type' => InventoryType::Sender->value,
            'sort' => 0,
        ], $overrides);

        $this->actingAs($admin)
            ->post(route('administration.inventories.store'), $payload)
            ->assertRedirect();

        return Inventory::query()->where('code', $payload['code'])->firstOrFail();
    }

    /**
     * @param  array{hamburg: mixed, rock: mixed, medium: mixed}  $catalog
     * @return array<string, mixed>
     */
    private function minimalStorePayload(array $catalog, int $inventoryId): array
    {
        return $this->withLiveSchemaFingerprint([
            'planning_mode' => 'manual',
            'customer_name' => 'Kunde',
            'campaign' => 'Kampagne',
            'product_title' => 'Produkt',
            'order_discount_percent' => '0',
            'ae_enabled' => false,
            'positions' => [[
                'inventory_id' => $inventoryId,
                'advertising_medium_id' => $catalog['medium']->id,
                'spot_method' => 'average',
                'length_seconds' => 30,
                'total_spot_count' => 10,
                'position_discount_percent' => '0',
                'ae_percent' => '15',
                'plan_rows' => [[
                    'hour' => 8,
                    'day_group' => 'mo_fr',
                ]],
            ]],
        ]);
    }
}
