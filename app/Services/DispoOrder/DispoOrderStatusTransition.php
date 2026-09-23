<?php

namespace App\Services\DispoOrder;

use App\Enums\DispoOrderStatus;
use App\Exceptions\DispoOrderConflictException;

/**
 * Erlaubte Statusübergänge: Freigabe-Slice + operativer Kern (BL-P8-02a).
 */
final class DispoOrderStatusTransition
{
    /**
     * @return list<DispoOrderStatus>
     */
    public static function allowedTargets(DispoOrderStatus $from): array
    {
        return match ($from) {
            DispoOrderStatus::Draft => [DispoOrderStatus::AwaitingSalesApproval],
            DispoOrderStatus::AwaitingSalesApproval => [
                DispoOrderStatus::AtDisposition,
                DispoOrderStatus::ApprovalRejected,
            ],
            DispoOrderStatus::AtDisposition => [
                DispoOrderStatus::InProgress,
            ],
            DispoOrderStatus::InProgress => [
                DispoOrderStatus::MaterialMissing,
                DispoOrderStatus::MaterialReceived,
                DispoOrderStatus::Disposed,
            ],
            DispoOrderStatus::MaterialMissing => [
                DispoOrderStatus::MaterialReceived,
                DispoOrderStatus::InProgress,
            ],
            DispoOrderStatus::MaterialReceived => [
                DispoOrderStatus::InProgress,
                DispoOrderStatus::MaterialMissing,
                DispoOrderStatus::Disposed,
            ],
            DispoOrderStatus::Disposed => [
                DispoOrderStatus::InProgress,
            ],
            default => [],
        };
    }

    /**
     * Operative Ziele (ohne Freigabe-Kanten) für den aktuellen Status.
     *
     * @return list<DispoOrderStatus>
     */
    public static function allowedOperationalTargets(DispoOrderStatus $from): array
    {
        return match ($from) {
            DispoOrderStatus::AtDisposition => [
                DispoOrderStatus::InProgress,
            ],
            DispoOrderStatus::InProgress => [
                DispoOrderStatus::MaterialMissing,
                DispoOrderStatus::MaterialReceived,
                DispoOrderStatus::Disposed,
            ],
            DispoOrderStatus::MaterialMissing => [
                DispoOrderStatus::MaterialReceived,
                DispoOrderStatus::InProgress,
            ],
            DispoOrderStatus::MaterialReceived => [
                DispoOrderStatus::InProgress,
                DispoOrderStatus::MaterialMissing,
                DispoOrderStatus::Disposed,
            ],
            DispoOrderStatus::Disposed => [
                DispoOrderStatus::InProgress,
            ],
            default => [],
        };
    }

    public static function isOperationalTransition(DispoOrderStatus $from, DispoOrderStatus $to): bool
    {
        return in_array($to, self::allowedOperationalTargets($from), true);
    }

    public static function requiresReason(DispoOrderStatus $from, DispoOrderStatus $to): bool
    {
        return $from === DispoOrderStatus::Disposed
            && $to === DispoOrderStatus::InProgress;
    }

    public static function isReopen(DispoOrderStatus $from, DispoOrderStatus $to): bool
    {
        return self::requiresReason($from, $to);
    }

    public static function canTransition(DispoOrderStatus $from, DispoOrderStatus $to): bool
    {
        if ($from === $to) {
            return false;
        }

        return in_array($to, self::allowedTargets($from), true);
    }

    public static function assertCanTransition(DispoOrderStatus $from, DispoOrderStatus $to): void
    {
        if (! self::canTransition($from, $to)) {
            throw new DispoOrderConflictException(
                sprintf(
                    'Der Statusübergang von „%s“ nach „%s“ ist nicht erlaubt.',
                    $from->label(),
                    $to->label(),
                ),
            );
        }
    }
}
