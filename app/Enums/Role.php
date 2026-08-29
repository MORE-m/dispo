<?php

namespace App\Enums;

enum Role: string
{
    case Admin = 'admin';
    case Sales = 'sales';
    case Disposition = 'disposition';
    case Management = 'management';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Admin',
            self::Sales => 'Vertrieb',
            self::Disposition => 'Disposition',
            self::Management => 'Geschäftsführung',
        };
    }
}
