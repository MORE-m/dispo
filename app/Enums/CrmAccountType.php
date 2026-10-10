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

    /**
     * Explizites Mapping der gelieferten Salesforce-Datensatztypen (PO-BLP203-1 D1).
     * Gesellschafter/Sonstiges → intern Kunde; unbekannte Typen bleiben blockierend.
     */
    public static function fromExportRecordType(string $raw): ?self
    {
        $normalized = trim($raw);

        return match ($normalized) {
            'Account KUNDE',
            'Account GESELLSCHAFTER',
            'Account SONSTIGE' => self::Customer,
            'Account AGENTUR' => self::Agency,
            default => null,
        };
    }
}
