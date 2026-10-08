<?php

namespace Tests\Feature\ProductionPrice;

use App\Enums\PriceListStatus;
use App\Enums\ProductionType;
use App\Enums\Role;
use App\Models\AuditEvent;
use App\Models\Inventory;
use App\Models\ProductionPriceList;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ProductionPriceListAdminLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_open_pages_and_hub_lists_module(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $list = ProductionPriceList::factory()->create();

        $this->actingAs($admin)
            ->get(route('administration.production-prices.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('administration/production-prices/index')
                ->has('productionPriceLists', 1));

        $this->actingAs($admin)
            ->get(route('administration.production-prices.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('administration/production-prices/create'));

        $this->actingAs($admin)
            ->get(route('administration.production-prices.show', $list))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('administration/production-prices/show')
                ->where('productionPriceList.unit_price', '500.00'));

        $this->actingAs($admin)
            ->get(route('administration.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('modules', fn ($modules): bool => collect($modules)->contains('key', 'production-prices')));
    }

    public function test_create_draft_update_and_noop_update(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $inventory = Inventory::factory()->create();

        $this->actingAs($admin)
            ->post(route('administration.production-prices.store'), [
                'inventory_id' => $inventory->id,
                'year' => 2027,
                'name' => 'Spotproduktion 2027',
                'unit_price' => '1250,5',
                'is_discountable' => true,
            ])
            ->assertRedirect();

        $draft = ProductionPriceList::query()->where('name', 'Spotproduktion 2027')->firstOrFail();
        $this->assertSame(PriceListStatus::Draft, $draft->status);
        $this->assertSame(ProductionType::SpotProduction, $draft->production_type);
        $this->assertSame('1250.50', (string) $draft->unit_price);
        $this->assertTrue($draft->is_discountable);
        $this->assertFalse($draft->is_ae_eligible);
        $this->assertSame(1, $draft->revision_number);
        $this->assertSame('1', $draft->version);
        $this->assertSame(1, $draft->lock_version);
        $this->assertSame(1, AuditEvent::query()->where('action', 'production_price_list.created')->count());

        $this->actingAs($admin)
            ->putJson(route('administration.production-prices.update', $draft), [
                'name' => 'Spotproduktion 2027',
                'unit_price' => '1250.50',
                'is_discountable' => true,
                'lock_version' => 1,
            ])
            ->assertOk();
        $this->assertSame(1, $draft->fresh()->lock_version);
        $this->assertSame(0, AuditEvent::query()->where('action', 'production_price_list.updated')->count());

        $this->actingAs($admin)
            ->putJson(route('administration.production-prices.update', $draft), [
                'name' => 'Spotproduktion 2027 v2',
                'unit_price' => '1300',
                'is_discountable' => false,
                'is_ae_eligible' => true,
                'lock_version' => 1,
            ])
            ->assertOk()
            ->assertJsonPath('lock_version', 2);

        $draft->refresh();
        $this->assertSame('Spotproduktion 2027 v2', $draft->name);
        $this->assertSame('1300.00', (string) $draft->unit_price);
        $this->assertFalse($draft->is_discountable);
        $this->assertTrue($draft->is_ae_eligible);
        $this->assertSame(1, AuditEvent::query()->where('action', 'production_price_list.updated')->count());
    }

    public function test_validation_rejects_negative_price_bad_year_and_unsupported_type(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $inventory = Inventory::factory()->create();
        $base = [
            'inventory_id' => $inventory->id,
            'year' => 2027,
            'name' => 'X',
            'unit_price' => '10.00',
        ];

        $this->actingAs($admin)
            ->postJson(route('administration.production-prices.store'), array_merge($base, ['unit_price' => '-1']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('unit_price');
        $this->actingAs($admin)
            ->postJson(route('administration.production-prices.store'), array_merge($base, ['unit_price' => '10.001']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('unit_price');
        $this->actingAs($admin)
            ->postJson(route('administration.production-prices.store'), array_merge($base, ['year' => 1999]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('year');
        $this->actingAs($admin)
            ->postJson(route('administration.production-prices.store'), array_merge($base, ['year' => 2101]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('year');
        $this->actingAs($admin)
            ->postJson(route('administration.production-prices.store'), array_merge($base, ['production_type' => 'foo']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('production_type');

        $this->actingAs($admin)
            ->postJson(route('administration.production-prices.store'), array_merge($base, ['unit_price' => '0']))
            ->assertRedirect();
        $this->assertSame(1, ProductionPriceList::query()->count());
    }

    public function test_second_activation_archives_first_and_scope_is_respected(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $inventory = Inventory::factory()->create();
        $otherInventory = Inventory::factory()->create();

        $first = ProductionPriceList::factory()->draft()->create([
            'inventory_id' => $inventory->id, 'year' => 2027, 'unit_price' => '100.00',
        ]);
        $second = ProductionPriceList::factory()->draft()->create([
            'inventory_id' => $inventory->id, 'year' => 2027, 'unit_price' => '200.00',
        ]);
        $otherYear = ProductionPriceList::factory()->create([
            'inventory_id' => $inventory->id, 'year' => 2028,
        ]);
        $otherInv = ProductionPriceList::factory()->create([
            'inventory_id' => $otherInventory->id, 'year' => 2027,
        ]);

        $this->actingAs($admin)
            ->postJson(route('administration.production-prices.activate', $first), ['lock_version' => 1])
            ->assertOk()
            ->assertJsonPath('status', 'active')
            ->assertJsonPath('lock_version', 2);

        $first->refresh();
        $this->assertSame(PriceListStatus::Active, $first->status);
        $this->assertNotNull($first->published_at);

        $this->actingAs($admin)
            ->postJson(route('administration.production-prices.activate', $second), ['lock_version' => 1])
            ->assertOk()
            ->assertJsonPath('status', 'active');

        $first->refresh();
        $second->refresh();
        $this->assertSame(PriceListStatus::Archived, $first->status);
        $this->assertNotNull($first->archived_at);
        $this->assertSame(3, $first->lock_version);
        $this->assertSame(PriceListStatus::Active, $second->status);
        $this->assertSame(PriceListStatus::Active, $otherYear->fresh()->status);
        $this->assertSame(PriceListStatus::Active, $otherInv->fresh()->status);
        $this->assertSame(1, ProductionPriceList::query()
            ->where('inventory_id', $inventory->id)
            ->where('year', 2027)
            ->where('status', PriceListStatus::Active->value)
            ->count());
        $this->assertSame(2, AuditEvent::query()->where('action', 'production_price_list.activated')->count());

        // Aktive Liste ist unveränderlich.
        $this->actingAs($admin)
            ->putJson(route('administration.production-prices.update', $second), [
                'name' => 'Neu',
                'unit_price' => '1.00',
                'lock_version' => $second->lock_version,
            ])
            ->assertStatus(422);
    }

    public function test_archive_and_copy(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $active = ProductionPriceList::factory()->create([
            'year' => 2027, 'unit_price' => '321.50', 'is_discountable' => true, 'is_ae_eligible' => true,
        ]);

        $this->actingAs($admin)
            ->postJson(route('administration.production-prices.copy', $active), ['year' => 2028])
            ->assertCreated();

        $copy = ProductionPriceList::query()->where('id', '!=', $active->id)->firstOrFail();
        $this->assertSame(PriceListStatus::Draft, $copy->status);
        $this->assertSame(2028, $copy->year);
        $this->assertSame('321.50', (string) $copy->unit_price);
        $this->assertTrue($copy->is_discountable);
        $this->assertTrue($copy->is_ae_eligible);
        $this->assertSame($active->inventory_id, $copy->inventory_id);
        $this->assertSame(1, AuditEvent::query()->where('action', 'production_price_list.copied')->count());

        $this->actingAs($admin)
            ->postJson(route('administration.production-prices.copy', $active))
            ->assertCreated();
        $sameYearCopy = ProductionPriceList::query()->where('year', 2027)->where('id', '!=', $active->id)->firstOrFail();
        $this->assertSame($active->revision_number + 1, $sameYearCopy->revision_number);
        $this->assertSame((string) $sameYearCopy->revision_number, $sameYearCopy->version);

        $this->actingAs($admin)
            ->postJson(route('administration.production-prices.archive', $active), ['lock_version' => 1])
            ->assertOk()
            ->assertJsonPath('status', 'archived');
        $active->refresh();
        $this->assertSame(PriceListStatus::Archived, $active->status);
        $this->assertNotNull($active->archived_at);
        $this->assertSame(1, AuditEvent::query()->where('action', 'production_price_list.archived')->count());

        $this->actingAs($admin)
            ->postJson(route('administration.production-prices.archive', $active), ['lock_version' => $active->lock_version])
            ->assertStatus(422);

        $this->assertSame(3, ProductionPriceList::query()->count());
    }

    public function test_stale_lock_version_returns_409(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $draft = ProductionPriceList::factory()->draft()->create(['year' => 2027]);

        $this->actingAs($admin)
            ->putJson(route('administration.production-prices.update', $draft), [
                'name' => 'A', 'unit_price' => '5.00', 'lock_version' => 1,
            ])
            ->assertOk();

        $this->actingAs($admin)
            ->putJson(route('administration.production-prices.update', $draft), [
                'name' => 'B', 'unit_price' => '6.00', 'lock_version' => 1,
            ])
            ->assertStatus(409);

        $this->actingAs($admin)
            ->postJson(route('administration.production-prices.activate', $draft), ['lock_version' => 1])
            ->assertStatus(409);
        $this->actingAs($admin)
            ->postJson(route('administration.production-prices.archive', $draft), ['lock_version' => 1])
            ->assertStatus(409);

        $draft->refresh();
        $this->assertSame('A', $draft->name);
        $this->assertSame(PriceListStatus::Draft, $draft->status);
    }

    public function test_sales_and_other_roles_cannot_access(): void
    {
        $list = ProductionPriceList::factory()->draft()->create();

        foreach ([Role::Sales, Role::Disposition, Role::ProductManagement] as $role) {
            $user = User::factory()->role($role)->create();

            $this->actingAs($user)->get(route('administration.production-prices.index'))->assertForbidden();
            $this->actingAs($user)->get(route('administration.production-prices.create'))->assertForbidden();
            $this->actingAs($user)->get(route('administration.production-prices.show', $list))->assertForbidden();
            $this->actingAs($user)
                ->post(route('administration.production-prices.store'), [
                    'inventory_id' => $list->inventory_id,
                    'year' => 2027,
                    'name' => 'Verboten',
                    'unit_price' => '1.00',
                ])
                ->assertForbidden();
            $this->actingAs($user)
                ->put(route('administration.production-prices.update', $list), [
                    'name' => 'x', 'unit_price' => '1.00', 'lock_version' => 1,
                ])
                ->assertForbidden();
            $this->actingAs($user)
                ->post(route('administration.production-prices.activate', $list), ['lock_version' => 1])
                ->assertForbidden();
            $this->actingAs($user)
                ->post(route('administration.production-prices.archive', $list), ['lock_version' => 1])
                ->assertForbidden();
            $this->actingAs($user)
                ->post(route('administration.production-prices.copy', $list))
                ->assertForbidden();
        }

        $this->assertSame(1, ProductionPriceList::query()->count());
    }
}
