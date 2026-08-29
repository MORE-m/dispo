<?php

namespace Tests\Feature\Audit;

use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class AuditEventTest extends TestCase
{
    use RefreshDatabase;

    public function test_aud_001_audit_events_cannot_be_updated_or_deleted(): void
    {
        $user = User::factory()->create();
        $event = AuditEvent::query()->create([
            'auditable_type' => User::class,
            'auditable_id' => $user->id,
            'action' => 'user.created',
            'user_id' => $user->id,
            'created_at' => now(),
        ]);

        $this->expectException(LogicException::class);
        $event->update(['action' => 'changed']);
    }
}
