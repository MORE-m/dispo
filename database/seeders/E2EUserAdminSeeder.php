<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\User;
use App\Support\E2E\E2EIsolatedEnvironmentGuard;
use Illuminate\Database\Seeder;

/**
 * PO-USER-ADMIN-1: minimale Benutzer für isolierte Playwright-Suite.
 */
class E2EUserAdminSeeder extends Seeder
{
    public function run(): void
    {
        E2EIsolatedEnvironmentGuard::assertSafeForE2ESeeding();

        foreach ([
            ['email' => 'admin@example.com', 'name' => 'E2E Admin', 'role' => Role::Admin],
            ['email' => 'sales@example.com', 'name' => 'E2E Vertrieb', 'role' => Role::Sales],
        ] as $attrs) {
            User::query()->updateOrCreate(
                ['email' => $attrs['email']],
                [
                    'name' => $attrs['name'],
                    'password' => 'password',
                    'role' => $attrs['role'],
                    'discount_limit_percent' => null,
                    'can_special_approve' => false,
                    'is_active' => true,
                ],
            );
        }
    }
}
