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
        foreach ([
            ['email' => 'test@example.com', 'name' => 'Test Benutzer', 'role' => Role::Sales],
            ['email' => 'sales@example.com', 'name' => 'E2E Vertrieb', 'role' => Role::Sales],
        ] as $attrs) {
            User::query()->updateOrCreate(
                ['email' => $attrs['email']],
                [
                    'name' => $attrs['name'],
                    'password' => 'password',
                    'role' => $attrs['role'],
                ],
            );
        }
    }
}
