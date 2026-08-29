<?php

namespace App\Services\Audit;

use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * AUD-001, AUD-004 – append-only, keine View-Ereignisse.
 */
final class AuditLogger
{
    /**
     * @param  array<string, mixed>|null  $old
     * @param  array<string, mixed>|null  $new
     */
    public function record(
        Model $auditable,
        string $action,
        ?User $user,
        ?array $old = null,
        ?array $new = null,
    ): AuditEvent {
        return AuditEvent::query()->create([
            'auditable_type' => $auditable::class,
            'auditable_id' => $auditable->getKey(),
            'action' => $action,
            'old_values' => $old,
            'new_values' => $new,
            'user_id' => $user?->id,
            'correlation_id' => (string) str()->uuid(),
            'created_at' => now(),
        ]);
    }
}
