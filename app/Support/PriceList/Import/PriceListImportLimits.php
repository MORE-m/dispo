<?php

namespace App\Support\PriceList\Import;

/**
 * BL-P4-01b Limits und Storage-Präfixe.
 */
final class PriceListImportLimits
{
    public const MAX_BYTES = 50 * 1024 * 1024;

    public const MAX_SHEETS = 50;

    public const MAX_DATA_ROWS = 100_000;

    public const STORAGE_PREFIX = 'price-list-imports/';

    /** @var list<string> */
    public const ALLOWED_EXTENSIONS = ['xlsx', 'xls'];

    /** @var list<string> */
    public const ALLOWED_MIME_TYPES = [
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.ms-excel',
        'application/octet-stream',
    ];
}
