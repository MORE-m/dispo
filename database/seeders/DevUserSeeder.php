<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Lokale Testbenutzer (idempotent). Passwort ist immer „password“.
 */
class DevUserSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production')) {
            return;
        }

        foreach ([
            ['email' => 'test@example.com', 'name' => 'Test Benutzer', 'role' => Role::Sales],
            ['email' => 'sales@example.com', 'name' => 'E2E Vertrieb', 'role' => Role::Sales],
            ['email' => 'sales-b@example.com', 'name' => 'E2E Vertrieb B', 'role' => Role::Sales],
            ['email' => 'sales-limited@example.com', 'name' => 'E2E Vertrieb Limit', 'role' => Role::Sales, 'discount_limit_percent' => '10'],
            ['email' => 'disposition@example.com', 'name' => 'E2E Disposition', 'role' => Role::Disposition],
            ['email' => 'admin@example.com', 'name' => 'E2E Admin', 'role' => Role::Admin],
            ['email' => 'management@example.com', 'name' => 'E2E Geschäftsführung', 'role' => Role::Management],
        ] as $attrs) {
            User::query()->updateOrCreate(
                ['email' => $attrs['email']],
                [
                    'name' => $attrs['name'],
                    'password' => 'password',
                    'role' => $attrs['role'],
                    'discount_limit_percent' => $attrs['discount_limit_percent'] ?? null,
                ],
            );
        }
    }
}
