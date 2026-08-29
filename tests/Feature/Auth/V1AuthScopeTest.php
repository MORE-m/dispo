<?php

namespace Tests\Feature\Auth;

use Tests\TestCase;

class V1AuthScopeTest extends TestCase
{
    public function test_public_registration_is_unavailable(): void
    {
        $this->get('/register')->assertNotFound();
        $this->post('/register')->assertNotFound();
    }

    public function test_email_verification_and_two_factor_routes_are_unavailable(): void
    {
        $this->get('/email/verify')->assertNotFound();
        $this->get('/two-factor-challenge')->assertNotFound();
        $this->post('/user/two-factor-authentication')->assertNotFound();
        $this->get('/.well-known/passkey-endpoints')->assertNotFound();
    }
}
