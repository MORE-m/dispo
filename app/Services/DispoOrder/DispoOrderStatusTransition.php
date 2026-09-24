<?php

namespace App\Services\DispoOrder;

use App\Enums\DispoOrderStatus;
use App\Exceptions\DispoOrderConflictException;

/**
 * Erlaubte Statusübergänge: Freigabe + operativer Kern (BL-P8-02a) + Rückfrage (BL-P8-02b)
 * + Abschluss (BL-P8-02d).
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
                DispoOrderStatus::SalesInquiry,
            ],
            DispoOrderStatus::InProgress => [
                DispoOrderStatus::MaterialMissing,
                DispoOrderStatus::MaterialReceived,
                DispoOrderStatus::Disposed,
                DispoOrderStatus::SalesInquiry,
            ],
            DispoOrderStatus::MaterialMissing => [
                DispoOrderStatus::MaterialReceived,
                DispoOrderStatus::InProgress,
                DispoOrderStatus::SalesInquiry,
            ],
            DispoOrderStatus::MaterialReceived => [
                DispoOrderStatus::InProgress,
                DispoOrderStatus::MaterialMissing,
                DispoOrderStatus::Disposed,
                DispoOrderStatus::SalesInquiry,
            ],
            DispoOrderStatus::Disposed => [
                DispoOrderStatus::InProgress,
                DispoOrderStatus::Completed,
            ],
            DispoOrderStatus::SalesInquiry => [
                DispoOrderStatus::AtDisposition,
            ],
            default => [],
        };
    }

    /**
     * Operative Ziele (ohne Freigabe- und ohne Rückfrage-Kanten) für UI-Buttons.
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

    /**
     * Ausgangsstatus, aus denen eine Rückfrage an den Vertrieb gestellt werden darf.
     *
     * @return list<DispoOrderStatus>
     */
    public static function salesInquiryAskSources(): array
    {
        return [
            DispoOrderStatus::AtDisposition,
            DispoOrderStatus::InProgress,
            DispoOrderStatus::MaterialMissing,
            DispoOrderStatus::MaterialReceived,
        ];
    }

    public static function isOperationalTransition(DispoOrderStatus $from, DispoOrderStatus $to): bool
    {
        return in_array($to, self::allowedOperationalTargets($from), true);
    }

    public static function isSalesInquiryAsk(DispoOrderStatus $from, DispoOrderStatus $to): bool
    {
        return $to === DispoOrderStatus::SalesInquiry
            && in_array($from, self::salesInquiryAskSources(), true);
    }

    public static function isSalesInquiryAnswer(DispoOrderStatus $from, DispoOrderStatus $to): bool
    {
        return $from === DispoOrderStatus::SalesInquiry
            && $to === DispoOrderStatus::AtDisposition;
    }

    public static function isSalesInquiryTransition(DispoOrderStatus $from, DispoOrderStatus $to): bool
    {
        return self::isSalesInquiryAsk($from, $to)
            || self::isSalesInquiryAnswer($from, $to);
    }

    public static function isCompletionTransition(DispoOrderStatus $from, DispoOrderStatus $to): bool
    {
        return $from === DispoOrderStatus::Disposed
            && $to === DispoOrderStatus::Completed;
    }

    /**
     * Status, in denen „Rechnung per Ende“ operativ gepflegt werden darf (BL-P8-02d).
     *
     * @return list<DispoOrderStatus>
     */
    public static function invoiceEndEditableStatuses(): array
    {
        return [
            DispoOrderStatus::AtDisposition,
            DispoOrderStatus::InProgress,
            DispoOrderStatus::MaterialMissing,
            DispoOrderStatus::MaterialReceived,
        ];
    }

    public static function isInvoiceEndEditable(DispoOrderStatus $status): bool
    {
        return in_array($status, self::invoiceEndEditableStatuses(), true);
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
