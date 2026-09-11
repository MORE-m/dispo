<?php

namespace App\Enums;

enum FieldType: string
{
    case Period = 'period';
    case Boolean = 'boolean';
    case ShortText = 'short_text';
    case LongText = 'long_text';
    case Select = 'select';
    case MultiSelect = 'multi_select';

    public function isChoice(): bool
    {
        return $this === self::Select || $this === self::MultiSelect;
    }
}
