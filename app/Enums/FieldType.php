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
    case File = 'file';

    public function isChoice(): bool
    {
        return $this === self::Select || $this === self::MultiSelect;
    }

    public function isFile(): bool
    {
        return $this === self::File;
    }
}
