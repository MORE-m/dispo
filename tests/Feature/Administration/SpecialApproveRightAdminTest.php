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
        $passwordHashBefore = $sales->password;

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
                'discount_limit_percent' => '99',
            ])
            ->assertRedirect(route('administration.special-approve-rights.index'));

        $sales->refresh();
        $this->assertTrue($sales->can_special_approve);
        $this->assertSame(Role::Sales, $sales->role);
        $this->assertSame($passwordHashBefore, $sales->password);

        $grantAudit = AuditEvent::query()
            ->where('action', 'user.special_approve_right.updated')
            ->latest('id')
            ->firstOrFail();
        $this->assertSame($admin->id, $grantAudit->user_id);
        $this->assertFalse((bool) ($grantAudit->old_values['can_special_approve'] ?? true));
        $this->assertTrue((bool) ($grantAudit->new_values['can_special_approve'] ?? false));
        $this->assertSame($sales->id, $grantAudit->new_values['target_user_id'] ?? null);
        $this->assertSame($admin->id, $grantAudit->new_values['actor_user_id'] ?? null);

        $this->actingAs($admin)
            ->put(route('administration.special-approve-rights.update', $sales), [
                'can_special_approve' => false,
                'role' => Role::Admin->value,
                'password' => 'hacked-again',
            ])
            ->assertRedirect(route('administration.special-approve-rights.index'));

        $sales->refresh();
        $this->assertFalse($sales->can_special_approve);
        $this->assertSame(Role::Sales, $sales->role);
        $this->assertSame($passwordHashBefore, $sales->password);

        $revokeAudit = AuditEvent::query()
            ->where('action', 'user.special_approve_right.updated')
            ->latest('id')
            ->firstOrFail();
        $this->assertSame($admin->id, $revokeAudit->user_id);
        $this->assertTrue((bool) ($revokeAudit->old_values['can_special_approve'] ?? false));
        $this->assertFalse((bool) ($revokeAudit->new_values['can_special_approve'] ?? true));
        $this->assertSame($sales->id, $revokeAudit->new_values['target_user_id'] ?? null);
        $this->assertSame($admin->id, $revokeAudit->new_values['actor_user_id'] ?? null);
    }

    public function test_management_sales_disposition_pm_and_self_are_forbidden(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $management = User::factory()->role(Role::Management)->create();
        $sales = User::factory()->role(Role::Sales)->create([
            'can_special_approve' => false,
        ]);
        $otherSales = User::factory()->role(Role::Sales)->create([
            'can_special_approve' => true,
        ]);
        $disposition = User::factory()->role(Role::Disposition)->create();
        $pm = User::factory()->role(Role::ProductManagement)->create([
            'can_view_dispo_orders' => true,
        ]);
        $passwordHashBefore = $otherSales->password;

        $this->actingAs($management)
            ->get(route('administration.special-approve-rights.index'))
            ->assertForbidden();
        $this->actingAs($management)
            ->put(route('administration.special-approve-rights.update', $sales), [
                'can_special_approve' => true,
            ])
            ->assertForbidden();

        foreach ([$sales, $disposition, $pm] as $actor) {
            $this->actingAs($actor)
                ->get(route('administration.special-approve-rights.index'))
                ->assertForbidden();
            $this->actingAs($actor)
                ->put(route('administration.special-approve-rights.update', $sales), [
                    'can_special_approve' => true,
                ])
                ->assertForbidden();
            $this->actingAs($actor)
                ->put(route('administration.special-approve-rights.update', $otherSales), [
                    'can_special_approve' => false,
                    'role' => Role::Admin->value,
                    'password' => 'hacked',
                ])
                ->assertForbidden();
        }

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

        $this->assertFalse($sales->fresh()->can_special_approve);
        $this->assertTrue($otherSales->fresh()->can_special_approve);
        $this->assertSame(Role::Sales, $otherSales->fresh()->role);
        $this->assertSame($passwordHashBefore, $otherSales->fresh()->password);
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
