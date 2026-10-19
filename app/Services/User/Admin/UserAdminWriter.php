<?php

namespace App\Services\User\Admin;

use App\Enums\Role;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * PO-USER-ADMIN-1: schmaler Admin-Pfad für Benutzer anlegen/bearbeiten.
 * Sonderfreigabe nur über {@see SpecialApproveRightAdminWriter}.
 */
final class UserAdminWriter
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly SpecialApproveRightAdminWriter $specialApproveWriter,
    ) {}

    /**
     * @param  array{
     *     name: string,
     *     email: string,
     *     role: Role,
     *     password: string,
     *     discount_limit_percent: string|null,
     *     can_special_approve: bool,
     * }  $data
     */
    public function create(array $data, User $actor): User
    {
        $this->assertActorCanManage($actor);

        // Nicht-Vertrieb: Flag immer false (kein zweiter Speicherpfad).
        if ($data['role'] !== Role::Sales) {
            $data['can_special_approve'] = false;
        }

        return DB::transaction(function () use ($data, $actor): User {
            $user = new User;
            $user->forceFill([
                'name' => $data['name'],
                'email' => $data['email'],
                'role' => $data['role'],
                // Klartext: Cast `hashed` übernimmt Hashing (kein Doppel-Hash).
                'password' => $data['password'],
                'discount_limit_percent' => $data['discount_limit_percent'],
                'can_special_approve' => false,
                'is_active' => true,
                'can_view_dispo_orders' => false,
            ])->save();

            $fresh = $user->fresh() ?? $user;

            if ($data['can_special_approve']) {
                $fresh = $this->specialApproveWriter->update($fresh, true, $actor);
            }

            $this->audit->record(
                $fresh,
                'user.admin.created',
                $actor,
                null,
                $this->auditSnapshot($fresh),
            );

            return $fresh;
        });
    }

    /**
     * @param  array{
     *     name: string,
     *     email: string,
     *     role: Role,
     *     discount_limit_percent: string|null,
     *     can_special_approve: bool,
     *     is_active: bool,
     * }  $data
     */
    public function update(User $target, array $data, User $actor): User
    {
        $this->assertActorCanManage($actor);

        // Nicht-Vertrieb: Flag immer false (kein zweiter Speicherpfad).
        if ($data['role'] !== Role::Sales) {
            $data['can_special_approve'] = false;
        }

        return DB::transaction(function () use ($target, $data, $actor): User {
            $locked = User::query()->whereKey($target->id)->lockForUpdate()->firstOrFail();
            $before = $this->auditSnapshot($locked);
            $willBeSales = $data['role'] === Role::Sales;
            $desiredSpecial = $data['can_special_approve'];

            // Passwort bewusst nicht im Payload – Edit überschreibt nie das Passwort.
            $locked->forceFill([
                'name' => $data['name'],
                'email' => $data['email'],
                'role' => $data['role'],
                'discount_limit_percent' => $data['discount_limit_percent'],
                'is_active' => $data['is_active'],
            ])->save();

            $fresh = $locked->fresh() ?? $locked;

            if (! $willBeSales && (bool) $fresh->can_special_approve) {
                $fresh->forceFill(['can_special_approve' => false])->save();
                $fresh = $fresh->fresh() ?? $fresh;
            } elseif ($willBeSales && (bool) $fresh->can_special_approve !== $desiredSpecial) {
                $fresh = $this->specialApproveWriter->update($fresh, $desiredSpecial, $actor);
            }

            $fresh = $fresh->fresh() ?? $fresh;

            $this->audit->record(
                $fresh,
                'user.admin.updated',
                $actor,
                $before,
                $this->auditSnapshot($fresh),
            );

            return $fresh;
        });
    }

    private function assertActorCanManage(User $actor): void
    {
        if (! $actor->canManageUsers()) {
            throw new AuthorizationException('Nur Admin darf Benutzer verwalten.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function auditSnapshot(User $user): array
    {
        return [
            'user_id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role->value,
            'discount_limit_percent' => $user->discount_limit_percent,
            'can_special_approve' => (bool) $user->can_special_approve,
            'is_active' => (bool) $user->is_active,
        ];
    }
}
