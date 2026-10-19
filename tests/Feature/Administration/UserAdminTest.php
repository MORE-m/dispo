<?php

namespace Tests\Feature\Administration;

use App\Enums\Role;
use App\Models\DispoOrder;
use App\Models\User;
use App\Policies\DispoOrderPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesSavedCalculation;
use Tests\Concerns\CreatesSpotClassicCatalog;
use Tests\TestCase;

class UserAdminTest extends TestCase
{
    use CreatesSavedCalculation;
    use CreatesSpotClassicCatalog;
    use RefreshDatabase;

    public function test_admin_can_reach_list_create_and_edit(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $target = User::factory()->role(Role::Sales)->create();

        $this->actingAs($admin)
            ->get(route('administration.users.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('administration/users/index')
                ->has('users'));

        $this->actingAs($admin)
            ->get(route('administration.users.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('administration/users/create')
                ->has('roleOptions', 5));

        $this->actingAs($admin)
            ->get(route('administration.users.edit', $target))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('administration/users/edit')
                ->where('user.id', $target->id));
    }

    public function test_non_admin_roles_are_forbidden(): void
    {
        $target = User::factory()->role(Role::Sales)->create();
        $actors = [
            User::factory()->role(Role::Sales)->create(),
            User::factory()->role(Role::Disposition)->create(),
            User::factory()->role(Role::Management)->create(),
            User::factory()->role(Role::ProductManagement)->create(),
        ];

        foreach ($actors as $actor) {
            $this->actingAs($actor)
                ->get(route('administration.users.index'))
                ->assertForbidden();
            $this->actingAs($actor)
                ->get(route('administration.users.create'))
                ->assertForbidden();
            $this->actingAs($actor)
                ->get(route('administration.users.edit', $target))
                ->assertForbidden();
            $this->actingAs($actor)
                ->post(route('administration.users.store'), [
                    'name' => 'Hack',
                    'email' => 'hack-'.$actor->id.'@example.com',
                    'role' => Role::Sales->value,
                    'password' => 'password',
                    'password_confirmation' => 'password',
                ])
                ->assertForbidden();
            $this->actingAs($actor)
                ->put(route('administration.users.update', $target), [
                    'name' => 'Hack',
                    'email' => $target->email,
                    'role' => Role::Admin->value,
                    'can_special_approve' => false,
                    'is_active' => true,
                ])
                ->assertForbidden();
        }
    }

    public function test_admin_can_create_user_with_hashed_password_and_defaults(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();

        $this->actingAs($admin)
            ->post(route('administration.users.store'), [
                'name' => 'Neue Vertrieb',
                'email' => 'Neue.Vertrieb@Example.com',
                'role' => Role::Sales->value,
                'password' => 'password',
                'password_confirmation' => 'password',
                'discount_limit_percent' => '12.5',
                'can_special_approve' => true,
                'is_active' => false,
                'can_view_dispo_orders' => true,
            ])
            ->assertRedirect();

        $created = User::query()->where('email', 'neue.vertrieb@example.com')->firstOrFail();
        $this->assertSame('Neue Vertrieb', $created->name);
        $this->assertSame(Role::Sales, $created->role);
        $this->assertTrue(Hash::check('password', $created->password));
        $this->assertNotSame('password', $created->password);
        $this->assertSame('12.5000', (string) $created->discount_limit_percent);
        $this->assertTrue($created->can_special_approve);
        $this->assertTrue($created->is_active);
        $this->assertFalse($created->can_view_dispo_orders);

        $this->assertDatabaseHas('audit_events', [
            'action' => 'user.admin.created',
            'auditable_id' => $created->id,
            'user_id' => $admin->id,
        ]);
    }

    public function test_create_rejects_duplicate_email(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        User::factory()->create(['email' => 'taken@example.com']);

        $this->actingAs($admin)
            ->post(route('administration.users.store'), [
                'name' => 'Duplikat',
                'email' => 'taken@example.com',
                'role' => Role::Sales->value,
                'password' => 'password',
                'password_confirmation' => 'password',
            ])
            ->assertSessionHasErrors('email');
    }

    public function test_admin_can_edit_fields_without_touching_password(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $target = User::factory()->role(Role::Sales)->create([
            'name' => 'Alt',
            'email' => 'alt@example.com',
            'discount_limit_percent' => '5',
            'can_special_approve' => false,
            'is_active' => true,
        ]);
        $passwordBefore = $target->password;

        $this->actingAs($admin)
            ->put(route('administration.users.update', $target), [
                'name' => 'Neu',
                'email' => 'neu@example.com',
                'role' => Role::Disposition->value,
                'discount_limit_percent' => '15',
                'can_special_approve' => true,
                'is_active' => false,
                'password' => 'should-be-ignored',
            ])
            ->assertRedirect(route('administration.users.edit', $target));

        $target->refresh();
        $this->assertSame('Neu', $target->name);
        $this->assertSame('neu@example.com', $target->email);
        $this->assertSame(Role::Disposition, $target->role);
        $this->assertSame('15.0000', (string) $target->discount_limit_percent);
        $this->assertFalse($target->can_special_approve);
        $this->assertFalse($target->is_active);
        $this->assertSame($passwordBefore, $target->password);

        $this->assertDatabaseHas('audit_events', [
            'action' => 'user.admin.updated',
            'auditable_id' => $target->id,
            'user_id' => $admin->id,
        ]);
    }

    public function test_special_approve_via_user_admin_uses_existing_writer_and_policy(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $creator = User::factory()->role(Role::Sales)->create();
        $sales = User::factory()->role(Role::Sales)->create([
            'can_special_approve' => false,
        ]);
        $order = new DispoOrder(['created_by_id' => $creator->id]);
        $policy = app(DispoOrderPolicy::class);

        $this->assertFalse($policy->approveSpecial($sales, $order));

        $this->actingAs($admin)
            ->put(route('administration.users.update', $sales), [
                'name' => $sales->name,
                'email' => $sales->email,
                'role' => Role::Sales->value,
                'can_special_approve' => true,
                'is_active' => true,
            ])
            ->assertRedirect();

        $sales->refresh();
        $this->assertTrue($sales->can_special_approve);
        $this->assertTrue($policy->approveSpecial($sales, $order));
        $this->assertFalse($policy->approveSpecial($creator, $order));
        $this->assertDatabaseHas('audit_events', [
            'action' => 'user.special_approve_right.updated',
            'auditable_id' => $sales->id,
        ]);

        $this->actingAs($admin)
            ->put(route('administration.users.update', $sales), [
                'name' => $sales->name,
                'email' => $sales->email,
                'role' => Role::Sales->value,
                'can_special_approve' => false,
                'is_active' => true,
            ])
            ->assertRedirect();

        $sales->refresh();
        $this->assertFalse($sales->can_special_approve);
        $this->assertFalse($policy->approveSpecial($sales, $order));
    }

    public function test_non_sales_role_clears_special_approve_instead_of_granting(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $target = User::factory()->role(Role::Sales)->create([
            'can_special_approve' => true,
        ]);

        $this->actingAs($admin)
            ->put(route('administration.users.update', $target), [
                'name' => $target->name,
                'email' => $target->email,
                'role' => Role::Admin->value,
                'can_special_approve' => true,
                'is_active' => true,
            ])
            ->assertRedirect();

        $target->refresh();
        $this->assertSame(Role::Admin, $target->role);
        $this->assertFalse($target->can_special_approve);
    }

    public function test_inactive_user_cannot_login_and_session_is_blocked(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $sales = User::factory()->role(Role::Sales)->create([
            'email' => 'inactive-candidate@example.com',
            'is_active' => true,
        ]);

        $this->post(route('login.store'), [
            'email' => $sales->email,
            'password' => 'password',
        ])->assertRedirect(route('dashboard', absolute: false));
        $this->assertAuthenticatedAs($sales);

        $this->actingAs($admin)
            ->put(route('administration.users.update', $sales), [
                'name' => $sales->name,
                'email' => $sales->email,
                'role' => Role::Sales->value,
                'can_special_approve' => false,
                'is_active' => false,
            ])
            ->assertRedirect();

        $this->assertFalse($sales->fresh()->is_active);

        $this->post(route('logout'));
        $this->assertGuest();

        $this->post(route('login.store'), [
            'email' => $sales->email,
            'password' => 'password',
        ]);
        $this->assertGuest();

        // Session-Block: als deaktivierter Nutzer authentifiziert → Middleware loggt aus.
        $this->actingAs($sales->fresh());
        $this->get(route('dashboard'))
            ->assertRedirect(route('login'));
        $this->assertGuest();

        $this->actingAs($admin)
            ->put(route('administration.users.update', $sales), [
                'name' => $sales->name,
                'email' => $sales->email,
                'role' => Role::Sales->value,
                'can_special_approve' => false,
                'is_active' => true,
            ])
            ->assertRedirect();

        $this->post(route('logout'));
        $this->assertGuest();

        $this->post(route('login.store'), [
            'email' => $sales->email,
            'password' => 'password',
        ]);
        $this->assertAuthenticatedAs($sales->fresh());
    }

    public function test_discount_limit_change_is_picked_up_by_new_calculation(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $sales = User::factory()->role(Role::Sales)->create([
            'discount_limit_percent' => '10',
        ]);
        $catalog = $this->createSpotClassicCatalog();

        $this->actingAs($admin)
            ->put(route('administration.users.update', $sales), [
                'name' => $sales->name,
                'email' => $sales->email,
                'role' => Role::Sales->value,
                'discount_limit_percent' => '7.5',
                'can_special_approve' => false,
                'is_active' => true,
            ])
            ->assertRedirect();

        $this->assertSame('7.5000', (string) $sales->fresh()->discount_limit_percent);

        $calculation = $this->createSavedCalculation($catalog, [
            ['inventory_id' => $catalog['hamburg']->id],
        ], $sales->fresh());

        $this->assertSame('7.5000', (string) $calculation->personal_discount_limit_percent);
    }

    public function test_hub_module_only_for_admin(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $management = User::factory()->role(Role::Management)->create();

        $this->actingAs($admin)
            ->get(route('administration.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('modules', fn ($modules): bool => collect($modules)->contains(
                    fn (array $m): bool => $m['key'] === 'users'
                        && $m['available'] === true
                        && $m['href'] === '/administration/benutzer',
                )));

        $this->actingAs($management)
            ->get(route('administration.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('modules', fn ($modules): bool => collect($modules)->contains(
                    fn (array $m): bool => $m['key'] === 'users'
                        && $m['available'] === false
                        && $m['href'] === null,
                )));
    }

    public function test_no_delete_route(): void
    {
        $this->assertFalse(
            collect(\Illuminate\Support\Facades\Route::getRoutes())->contains(
                fn ($route): bool => str_contains((string) $route->getName(), 'administration.users.destroy')
                    || (in_array('DELETE', $route->methods(), true)
                        && str_contains($route->uri(), 'administration/benutzer')),
            ),
        );
    }
}
