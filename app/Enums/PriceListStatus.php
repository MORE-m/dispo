<?php

namespace App\Enums;

enum PriceListStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Entwurf',
            self::Active => 'Aktiv',
            self::Archived => 'Archiviert',
        };
    }

    public function isEditable(): bool
    {
        return $this === self::Draft;
    }
}
