<?php

namespace Tests\Feature\Administration;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Support\MysqlTestDatabaseGuard;
use Tests\TestCase;

/**
 * PO-USER-ADMIN-1: MySQL – is_active Default, Unique-E-Mail, Role-/Decimal-Casts.
 */
class UserAdminMysqlTest extends TestCase
{
    use RefreshDatabase;

    public function test_mysql_migration_keeps_existing_users_active_and_create_works(): void
    {
        $this->requireMysql('PO-USER-ADMIN-1 is_active');

        $existing = User::factory()->role(Role::Sales)->create([
            'email' => 'existing-mysql@example.com',
        ]);
        $this->assertTrue((bool) $existing->fresh()->is_active);

        $admin = User::factory()->role(Role::Admin)->create();

        $this->actingAs($admin)
            ->post(route('administration.users.store'), [
                'name' => 'MySQL User',
                'email' => 'mysql-user@example.com',
                'role' => Role::Sales->value,
                'password' => 'password',
                'password_confirmation' => 'password',
                'discount_limit_percent' => '9.25',
            ])
            ->assertRedirect();

        $created = User::query()->where('email', 'mysql-user@example.com')->firstOrFail();
        $this->assertTrue($created->is_active);
        $this->assertSame(Role::Sales, $created->role);
        $this->assertSame('9.2500', (string) $created->discount_limit_percent);
        $this->assertTrue(Hash::check('password', $created->password));

        $this->actingAs($admin)
            ->post(route('administration.users.store'), [
                'name' => 'Dup',
                'email' => 'mysql-user@example.com',
                'role' => Role::Sales->value,
                'password' => 'password',
                'password_confirmation' => 'password',
            ])
            ->assertSessionHasErrors('email');
    }

    public function test_mysql_inactive_blocks_login(): void
    {
        $this->requireMysql('PO-USER-ADMIN-1 login block');

        $user = User::factory()->role(Role::Sales)->create([
            'email' => 'mysql-inactive@example.com',
            'is_active' => false,
        ]);

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ]);
        $this->assertGuest();
    }

    private function requireMysql(string $label): void
    {
        MysqlTestDatabaseGuard::assertSafeBeforeDestructiveTraits();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped($label.' erfordert MySQL (GitHub-Job mysql).');
        }
    }
}
