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

    public function test_locked_modules_are_empty_states_not_fake_pages(): void
    {
        $user = User::factory()->role(Role::Admin)->create();

        $this->actingAs($user)
            ->get(route('administration.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('modules/unavailable')
                ->where('gate', 'UX-GATE-D'));
    }
}
