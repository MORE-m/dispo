<?php

namespace App\Services\DispoOrder;

use App\Enums\DispoOrderStatus;
use App\Enums\Role;
use App\Exceptions\DispoOrderConflictException;
use App\Models\DispoOrder;
use App\Models\DispoOrderStatusEvent;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Bewusster Abschluss disposed → completed inkl. Admin-Override (BL-P8-02d / STA-006).
 */
final class DispoOrderCompletionService
{
    public const int OVERRIDE_REASON_MAX = 2000;

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly DispoOrderCompletionReadiness $readiness,
    ) {}

    public function complete(
        DispoOrder $order,
        User $user,
        int $expectedLockVersion,
        ?string $overrideReason = null,
    ): DispoOrder {
        if (! Gate::forUser($user)->allows('complete', $order)) {
            abort(403);
        }

        $trimmedOverride = $overrideReason === null ? null : trim($overrideReason);
        if ($trimmedOverride === '') {
            $trimmedOverride = null;
        }

        return DB::transaction(function () use ($order, $user, $expectedLockVersion, $trimmedOverride): DispoOrder {
            $locked = $this->lockOrder($order);
            $this->assertLockVersion($locked, $expectedLockVersion);

            if (! Gate::forUser($user)->allows('complete', $locked)) {
                abort(403);
            }

            if ($locked->status !== DispoOrderStatus::Disposed) {
                throw ValidationException::withMessages([
                    'order' => sprintf(
                        'Abschluss ist nur aus dem Status „%s“ möglich (aktuell: „%s“).',
                        DispoOrderStatus::Disposed->label(),
                        $locked->status->label(),
                    ),
                ]);
            }

            $evaluation = $this->readiness->evaluate($locked);
            $isOverride = false;

            if (! $evaluation['ready']) {
                $canForce = Gate::forUser($user)->allows('forceComplete', $locked)
                    && $user->hasRole(Role::Admin);

                if (! $canForce) {
                    throw ValidationException::withMessages(
                        $this->blockingMessages($evaluation),
                    );
                }

                if ($trimmedOverride === null) {
                    throw ValidationException::withMessages([
                        'override_reason' => 'Eine Begründung ist für den Admin-Override erforderlich.',
                    ]);
                }

                if (mb_strlen($trimmedOverride) > self::OVERRIDE_REASON_MAX) {
                    throw ValidationException::withMessages([
                        'override_reason' => 'Die Begründung darf maximal 2000 Zeichen haben.',
                    ]);
                }

                $isOverride = true;
            }

            $from = $locked->status;
            $to = DispoOrderStatus::Completed;
            DispoOrderStatusTransition::assertCanTransition($from, $to);

            $locked->status = $to;
            $locked->lock_version = $locked->lock_version + 1;
            $locked->save();

            $violationsPayload = $isOverride
                ? $this->violationsPayload($evaluation)
                : null;

            $event = new DispoOrderStatusEvent;
            $event->dispo_order_id = $locked->id;
            $event->from_status = $from;
            $event->to_status = $to;
            $event->changed_by_id = $user->id;
            $event->changed_by_name = $user->name;
            $event->changed_at = now();
            $event->reason = $isOverride ? $trimmedOverride : null;
            $event->is_reopen = false;
            $event->is_completion_override = $isOverride;
            $event->completion_override_violations = $violationsPayload;
            $event->lock_version_after = $locked->lock_version;
            $event->save();

            $fresh = $this->reload($locked);

            $this->audit->record(
                $fresh,
                'dispo_order.completed',
                $user,
                [
                    'status' => $from->value,
                    'lock_version' => $expectedLockVersion,
                ],
                [
                    'status' => $fresh->status->value,
                    'lock_version' => $fresh->lock_version,
                    'from_status' => $from->value,
                    'to_status' => $to->value,
                    'override' => $isOverride,
                    'override_reason' => $isOverride ? $trimmedOverride : null,
                    'violated_checks' => $isOverride
                        ? array_map(
                            static fn (array $item): string => $item['key'],
                            $violationsPayload ?? [],
                        )
                        : [],
                    'violations' => $violationsPayload,
                    'status_event_id' => $event->id,
                    'changed_at' => $event->changed_at->toIso8601String(),
                    'changed_by_id' => $user->id,
                    'changed_by_name' => $user->name,
                ],
            );

            return $fresh;
        });
    }

    /**
     * @return array{
     *     ready: bool,
     *     checks: list<array{
     *         key: string,
     *         label: string,
     *         passed: bool,
     *         violations: list<array{message: string}>
     *     }>
     * }
     */
    public function readinessProp(DispoOrder $order): array
    {
        return $this->readiness->evaluate($order);
    }

    /**
     * @return array{
     *     completed_by_name: string,
     *     completed_at: string,
     *     is_completion_override: bool,
     *     override_reason: string|null,
     *     override_violations: list<array{key: string, label: string, messages: list<string>}>
     * }|null
     */
    public function completionSummaryProp(DispoOrder $order): ?array
    {
        if ($order->status !== DispoOrderStatus::Completed) {
            return null;
        }

        $event = ($order->relationLoaded('statusEvents')
            ? $order->statusEvents
            : $order->statusEvents()->get()
        )->first(
            fn (DispoOrderStatusEvent $item): bool => $item->to_status === DispoOrderStatus::Completed,
        );

        if ($event === null) {
            $event = DispoOrderStatusEvent::query()
                ->where('dispo_order_id', $order->id)
                ->where('to_status', DispoOrderStatus::Completed->value)
                ->orderByDesc('id')
                ->first();
        }

        if ($event === null) {
            return null;
        }

        $violations = [];
        foreach ($event->completion_override_violations ?? [] as $item) {
            $messages = [];
            foreach (($item['violations'] ?? []) as $violation) {
                if (is_array($violation) && isset($violation['message'])) {
                    $messages[] = (string) $violation['message'];
                }
            }
            $violations[] = [
                'key' => (string) ($item['key'] ?? ''),
                'label' => (string) ($item['label'] ?? ''),
                'messages' => $messages,
            ];
        }

        return [
            'completed_by_name' => $event->changed_by_name,
            'completed_at' => $event->changed_at->toIso8601String(),
            'is_completion_override' => (bool) $event->is_completion_override,
            'override_reason' => $event->is_completion_override ? $event->reason : null,
            'override_violations' => $violations,
        ];
    }

    /**
     * @param  array{ready: bool, checks: list<array{key: string, label: string, passed: bool, violations: list<array{message: string}>}>}  $evaluation
     * @return array<string, string>
     */
    private function blockingMessages(array $evaluation): array
    {
        $messages = [];
        foreach ($evaluation['checks'] as $check) {
            if ($check['passed']) {
                continue;
            }
            foreach ($check['violations'] as $index => $violation) {
                $messages['completion.'.$check['key'].'.'.$index] = $violation['message'];
            }
            if ($check['violations'] === []) {
                $messages['completion.'.$check['key']] = $check['label'].' ist nicht erfüllt.';
            }
        }

        if ($messages === []) {
            $messages['completion'] = 'Der Auftrag ist noch nicht abschlussbereit.';
        }

        return $messages;
    }

    /**
     * @param  array{ready: bool, checks: list<array{key: string, label: string, passed: bool, violations: list<array{message: string}>}>}  $evaluation
     * @return list<array{key: string, label: string, violations: list<array{message: string}>}>
     */
    private function violationsPayload(array $evaluation): array
    {
        $payload = [];
        foreach ($evaluation['checks'] as $check) {
            if ($check['passed']) {
                continue;
            }
            $payload[] = [
                'key' => $check['key'],
                'label' => $check['label'],
                'violations' => $check['violations'],
            ];
        }

        return $payload;
    }

    private function lockOrder(DispoOrder $order): DispoOrder
    {
        return DispoOrder::query()
            ->whereKey($order->id)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function assertLockVersion(DispoOrder $order, int $expectedLockVersion): void
    {
        if ($order->lock_version !== $expectedLockVersion) {
            throw new DispoOrderConflictException(
                'Der Dispoauftrag wurde parallel geändert. Bitte die Seite neu laden.',
            );
        }
    }

    private function reload(DispoOrder $order): DispoOrder
    {
        $order->refresh();
        $order->load([
            'positions',
            'creator',
            'approvalRequests',
            'pendingApprovalRequest',
            'latestApprovalRequest',
            'statusEvents',
        ]);

        return $order;
    }
}
