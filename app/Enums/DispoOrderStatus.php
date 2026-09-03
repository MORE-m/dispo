<?php

namespace App\Enums;

enum DispoOrderStatus: string
{
    case Draft = 'draft';
    case AwaitingSalesApproval = 'awaiting_sales_approval';
    case ApprovalRejected = 'approval_rejected';
    case AtDisposition = 'at_disposition';
    case InProgress = 'in_progress';
    case SalesInquiry = 'sales_inquiry';
    case MaterialMissing = 'material_missing';
    case MaterialReceived = 'material_received';
    case Disposed = 'disposed';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Entwurf',
            self::AwaitingSalesApproval => 'Wartet auf Vertriebsfreigabe',
            self::ApprovalRejected => 'Freigabe abgelehnt',
            self::AtDisposition => 'Liegt bei Disposition',
            self::InProgress => 'In Bearbeitung',
            self::SalesInquiry => 'Rückfrage Vertrieb',
            self::MaterialMissing => 'Material fehlt',
            self::MaterialReceived => 'Material erhalten',
            self::Disposed => 'Disponiert',
            self::Completed => 'Abgeschlossen',
            self::Cancelled => 'Storniert',
        };
    }
}
