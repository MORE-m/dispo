<?php

namespace App\Enums;

enum StandardOfferVersionStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Entwurf',
            self::Published => 'Veröffentlicht',
            self::Archived => 'Archiviert',
        };
    }

    public function isEditable(): bool
    {
        return $this === self::Draft;
    }

    public function isAdoptable(): bool
    {
        return $this === self::Published;
    }
}
