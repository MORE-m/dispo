<?php

namespace Tests\Feature;

use Tests\TestCase;

class HealthTest extends TestCase
{
    public function test_json_health_endpoint_is_ok(): void
    {
        $this->get(route('health'))
            ->assertOk()
            ->assertJson([
                'status' => 'ok',
            ]);
    }

    public function test_framework_up_endpoint_is_ok(): void
    {
        $this->get('/up')->assertOk();
    }
}
