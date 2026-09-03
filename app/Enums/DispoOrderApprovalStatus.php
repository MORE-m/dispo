<?php

namespace App\Enums;

enum DispoOrderApprovalStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Ausstehend',
            self::Approved => 'Genehmigt',
            self::Rejected => 'Abgelehnt',
        };
    }
}
