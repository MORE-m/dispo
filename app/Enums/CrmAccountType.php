<?php

namespace App\Enums;

enum CrmAccountType: string
{
    case Customer = 'customer';
    case Agency = 'agency';

    public function label(): string
    {
        return match ($this) {
            self::Customer => 'Kunde',
            self::Agency => 'Agentur',
        };
    }

    public static function fromExportRecordType(string $raw): ?self
    {
        $normalized = trim($raw);

        return match ($normalized) {
            'Account KUNDE' => self::Customer,
            'Account AGENTUR' => self::Agency,
            default => null,
        };
    }
}
