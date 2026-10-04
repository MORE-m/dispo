<?php

namespace App\Services\User\Admin;

use App\Enums\Role;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * PO-AUTH-SPECIAL-APPROVE-1: schmaler Admin-Pfad nur für can_special_approve.
 */
final class SpecialApproveRightAdminWriter
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    public function update(User $target, bool $canSpecialApprove, User $actor): User
    {
        if (! $actor->canManageSpecialApproveRights()) {
            throw new AuthorizationException('Nur Admin darf Sonderfreigaberechte vergeben oder entziehen.');
        }

        if ((int) $actor->id === (int) $target->id) {
            throw ValidationException::withMessages([
                'user' => 'Das eigene Sonderfreigaberecht kann über diesen Weg nicht geändert werden.',
            ]);
        }

        return DB::transaction(function () use ($target, $canSpecialApprove, $actor): User {
            $locked = User::query()->whereKey($target->id)->lockForUpdate()->firstOrFail();

            if (! $locked->hasRole(Role::Sales)) {
                throw ValidationException::withMessages([
                    'user' => 'Sonderfreigaberechte können nur an Vertriebsnutzer vergeben werden.',
                ]);
            }

            $before = (bool) $locked->can_special_approve;
            if ($before === $canSpecialApprove) {
                return $locked->fresh() ?? $locked;
            }

            $locked->forceFill([
                'can_special_approve' => $canSpecialApprove,
            ])->save();

            $fresh = $locked->fresh() ?? $locked;

            $this->audit->record(
                $fresh,
                'user.special_approve_right.updated',
                $actor,
                [
                    'target_user_id' => $fresh->id,
                    'target_email' => $fresh->email,
                    'can_special_approve' => $before,
                ],
                [
                    'target_user_id' => $fresh->id,
                    'target_email' => $fresh->email,
                    'can_special_approve' => (bool) $fresh->can_special_approve,
                    'actor_user_id' => $actor->id,
                    'actor_email' => $actor->email,
                ],
            );

            return $fresh;
        });
    }
}
