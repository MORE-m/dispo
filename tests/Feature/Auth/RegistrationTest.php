<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Öffentliche Self-Registration ist in V1 deaktiviert (ADR-002).
     */
    public function test_registration_screen_is_not_available(): void
    {
        $this->get('/register')->assertNotFound();
        $this->post('/register', [
            'name' => 'Test',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertNotFound();
    }
}
