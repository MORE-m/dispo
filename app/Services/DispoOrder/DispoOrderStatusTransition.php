<?php

namespace App\Services\DispoOrder;

use App\Enums\DispoOrderStatus;
use App\Exceptions\DispoOrderConflictException;

/**
 * Erlaubte Statusübergänge für den Freigabe-Slice.
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
            default => [],
        };
    }

    public static function canTransition(DispoOrderStatus $from, DispoOrderStatus $to): bool
    {
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
