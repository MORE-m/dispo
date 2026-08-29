<?php

namespace Tests\Feature\Auth;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AUTH-001, AUTH-003
 */
class RoleAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_and_management_may_access_administration(): void
    {
        foreach ([Role::Admin, Role::Management] as $role) {
            $user = User::factory()->role($role)->create();

            $this->actingAs($user)
                ->get(route('admin.access'))
                ->assertOk()
                ->assertJson(['ok' => true]);
        }
    }

    public function test_sales_and_disposition_are_denied_administration(): void
    {
        foreach ([Role::Sales, Role::Disposition] as $role) {
            $user = User::factory()->role($role)->create();

            $this->actingAs($user)
                ->get(route('admin.access'))
                ->assertForbidden();
        }
    }

    public function test_guests_cannot_access_administration(): void
    {
        $this->get(route('admin.access'))->assertRedirect(route('login'));
    }
}
