<?php

namespace Tests\Feature\Administration;

use App\Enums\Role;
use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SpecialApproveRightAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_list_grant_and_revoke_with_audit(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $sales = User::factory()->role(Role::Sales)->create([
            'email' => 'sales-flag@example.com',
            'can_special_approve' => false,
        ]);

        $this->actingAs($admin)
            ->get(route('administration.special-approve-rights.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('administration/special-approve-rights/index')
                ->has('salesUsers', 1)
                ->where('salesUsers.0.id', $sales->id)
                ->where('salesUsers.0.can_special_approve', false));

        $this->actingAs($admin)
            ->put(route('administration.special-approve-rights.update', $sales), [
                'can_special_approve' => true,
                'role' => Role::Admin->value,
                'password' => 'hacked',
            ])
            ->assertRedirect(route('administration.special-approve-rights.index'));

        $sales->refresh();
        $this->assertTrue($sales->can_special_approve);
        $this->assertSame(Role::Sales, $sales->role);

        $grantAudit = AuditEvent::query()
            ->where('action', 'user.special_approve_right.updated')
            ->latest('id')
            ->firstOrFail();
        $this->assertSame($admin->id, $grantAudit->user_id);
        $this->assertFalse((bool) ($grantAudit->old_values['can_special_approve'] ?? true));
        $this->assertTrue((bool) ($grantAudit->new_values['can_special_approve'] ?? false));
        $this->assertSame($sales->id, $grantAudit->new_values['target_user_id'] ?? null);

        $this->actingAs($admin)
            ->put(route('administration.special-approve-rights.update', $sales), [
                'can_special_approve' => false,
            ])
            ->assertRedirect(route('administration.special-approve-rights.index'));

        $this->assertFalse($sales->fresh()->can_special_approve);
    }

    public function test_management_sales_and_self_are_forbidden(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $management = User::factory()->role(Role::Management)->create();
        $sales = User::factory()->role(Role::Sales)->create();
        $otherSales = User::factory()->role(Role::Sales)->create();

        $this->actingAs($management)
            ->get(route('administration.special-approve-rights.index'))
            ->assertForbidden();
        $this->actingAs($management)
            ->put(route('administration.special-approve-rights.update', $sales), [
                'can_special_approve' => true,
            ])
            ->assertForbidden();

        $this->actingAs($sales)
            ->get(route('administration.special-approve-rights.index'))
            ->assertForbidden();

        $this->actingAs($admin)
            ->put(route('administration.special-approve-rights.update', $admin), [
                'can_special_approve' => true,
            ])
            ->assertSessionHasErrors('user');

        $this->actingAs($admin)
            ->put(route('administration.special-approve-rights.update', $management), [
                'can_special_approve' => true,
            ])
            ->assertSessionHasErrors('user');

        $this->assertFalse($otherSales->fresh()->can_special_approve);
        $this->assertSame(0, AuditEvent::query()->where('action', 'user.special_approve_right.updated')->count());
    }

    public function test_hub_module_only_available_for_admin(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $management = User::factory()->role(Role::Management)->create();

        $this->actingAs($admin)
            ->get(route('administration.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('administration/index')
                ->where('modules', fn ($modules): bool => collect($modules)->contains(
                    fn (array $m): bool => $m['key'] === 'special-approve-rights'
                        && $m['available'] === true
                        && $m['href'] === '/administration/sonderfreigaben',
                )));

        $this->actingAs($management)
            ->get(route('administration.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('administration/index')
                ->where('modules', fn ($modules): bool => collect($modules)->contains(
                    fn (array $m): bool => $m['key'] === 'special-approve-rights'
                        && $m['available'] === false
                        && $m['href'] === null,
                )));
    }
}
