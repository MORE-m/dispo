<?php

namespace App\Enums;

enum FieldSetVersionStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Archived = 'archived';
}
