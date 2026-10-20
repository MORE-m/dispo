<?php

namespace App\Enums;

/**
 * PO-CALC-TKP-1A: Targeting-Kategorien laut OA-008 (technisch / DMP).
 */
enum OaTargetingCategory: string
{
    case Technisch = 'technisch';
    case Dmp = 'dmp';

    public function label(): string
    {
        return match ($this) {
            self::Technisch => 'Technisch',
            self::Dmp => 'DMP',
        };
    }
}
