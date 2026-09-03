<?php

namespace App\Services\DispoOrder;

use App\Models\DispoOrder;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;

/**
 * Session-Kontext für den Nachbesserungsablauf (Kalkulation → neuer Draft).
 */
final class DispoOrderRevisionContext
{
    public const SESSION_KEY = 'dispo_order_revision';

    public function start(User $user, DispoOrder $predecessor): void
    {
        abort_unless($user->can('revise', $predecessor), 403);

        $predecessor->loadMissing(['latestApprovalRequest', 'calculation']);

        Session::put(self::SESSION_KEY, [
            'predecessor_id' => $predecessor->id,
            'calculation_id' => $predecessor->calculation_id,
            'user_id' => $user->id,
            'predecessor_number' => $predecessor->number,
            'rejection_reason' => $predecessor->latestApprovalRequest?->rejection_reason,
        ]);
    }

    /**
     * @return array{
     *     predecessor_id: int,
     *     calculation_id: int,
     *     user_id: int,
     *     predecessor_number: string,
     *     rejection_reason: ?string,
     *     return_url: string
     * }|null
     */
    public function current(Request|User $requestOrUser): ?array
    {
        $user = $requestOrUser instanceof Request
            ? $requestOrUser->user()
            : $requestOrUser;

        if (! $user instanceof User) {
            return null;
        }

        /** @var array<string, mixed>|null $data */
        $data = Session::get(self::SESSION_KEY);

        if (! is_array($data)) {
            return null;
        }

        if ((int) ($data['user_id'] ?? 0) !== (int) $user->id) {
            $this->clear();

            return null;
        }

        $predecessorId = (int) ($data['predecessor_id'] ?? 0);
        $predecessor = DispoOrder::query()->find($predecessorId);

        if ($predecessor === null || ! $user->can('revise', $predecessor)) {
            $this->clear();

            return null;
        }

        $predecessor->loadMissing('latestApprovalRequest');

        $latest = $predecessor->latestApprovalRequest;

        return [
            'predecessor_id' => $predecessor->id,
            'calculation_id' => $predecessor->calculation_id,
            'user_id' => $user->id,
            'predecessor_number' => $predecessor->number,
            'rejection_reason' => $latest !== null
                ? $latest->rejection_reason
                : (isset($data['rejection_reason']) ? (string) $data['rejection_reason'] : null),
            'return_url' => route('dispo-orders.show', $predecessor),
        ];
    }

    /**
     * @return array{
     *     predecessor_id: int,
     *     calculation_id: int,
     *     user_id: int,
     *     predecessor_number: string,
     *     rejection_reason: ?string,
     *     return_url: string
     * }|null
     */
    public function currentForCalculation(Request|User $requestOrUser, int $calculationId): ?array
    {
        $context = $this->current($requestOrUser);

        if ($context === null || (int) $context['calculation_id'] !== $calculationId) {
            return null;
        }

        return $context;
    }

    public function clear(): void
    {
        Session::forget(self::SESSION_KEY);
    }
}
