<?php

namespace App\Enums;

enum CrmImportStatus: string
{
    case Uploaded = 'uploaded';
    case Validated = 'validated';
    case Applied = 'applied';
    case Failed = 'failed';
}
