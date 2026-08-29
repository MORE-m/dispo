<?php

namespace App\Enums;

enum DayGroup: string
{
    case MoFr = 'mo_fr';
    case Sa = 'sa';
    case So = 'so';
    case MoSa = 'mo_sa';
    case MoSo = 'mo_so';

    public function label(): string
    {
        return match ($this) {
            self::MoFr => 'Mo–Fr',
            self::Sa => 'Sa',
            self::So => 'So',
            self::MoSa => 'Mo–Sa',
            self::MoSo => 'Mo–So',
        };
    }

    public function isDerived(): bool
    {
        return $this === self::MoSa || $this === self::MoSo;
    }
}
