<?php

namespace App\Enums;

enum CrmAccountVersionSource: string
{
    case Import = 'import';
    case Provisional = 'provisional';
    case ManualLink = 'manual_link';
}
