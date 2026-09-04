<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use App\Services\Navigation\AppNavigation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class NavigationTest extends TestCase
{
    use RefreshDatabase;

    public function test_sales_sees_calculations_but_not_administration(): void
    {
        $user = User::factory()->role(Role::Sales)->create();
        $keys = collect(app(AppNavigation::class)->itemsFor($user))->pluck('key');

        $this->assertTrue($keys->contains('calculations'));
        $this->assertFalse($keys->contains('administration'));

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('overview'));
    }

    public function test_dispo_orders_module_is_available_for_authorized_roles(): void
    {
        $user = User::factory()->role(Role::Sales)->create();
        $keys = collect(app(AppNavigation::class)->itemsFor($user));

        $dispo = $keys->firstWhere('key', 'dispo-orders');
        $this->assertNotNull($dispo);
        $this->assertTrue($dispo['available']);

        $this->actingAs($user)
            ->get(route('dispo-orders.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('dispo-orders/index'));
    }

    public function test_administration_hub_is_available_for_admin_with_dynamic_fields(): void
    {
        $user = User::factory()->role(Role::Admin)->create();

        $adminNav = collect(app(AppNavigation::class)->itemsFor($user))
            ->firstWhere('key', 'administration');
        $this->assertNotNull($adminNav);
        $this->assertTrue($adminNav['available']);

        $this->actingAs($user)
            ->get(route('administration.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('administration/index')
                ->where('modules.0.key', 'dynamic-fields')
                ->where('modules.0.available', true));
    }

    public function test_locked_modules_remain_unavailable_empty_states(): void
    {
        $user = User::factory()->role(Role::Admin)->create();

        $this->actingAs($user)
            ->get(route('reports.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('modules/unavailable')
                ->where('gate', 'UX-GATE-D'));
    }
}
