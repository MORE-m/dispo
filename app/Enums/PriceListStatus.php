<?php

namespace App\Enums;

enum PriceListStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Archived = 'archived';
}
