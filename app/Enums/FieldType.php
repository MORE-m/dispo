<?php

namespace App\Enums;

enum FieldType: string
{
    case Period = 'period';
    case Boolean = 'boolean';
    case ShortText = 'short_text';
    case LongText = 'long_text';
}
