<?php

namespace App\Enums;

enum PriceListImportStatus: string
{
    case Uploaded = 'uploaded';
    case Validated = 'validated';
    case Imported = 'imported';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Uploaded => 'Hochgeladen',
            self::Validated => 'Geprüft',
            self::Imported => 'Importiert',
            self::Failed => 'Fehlgeschlagen',
        };
    }
}
