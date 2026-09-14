<?php

namespace App\Support\PriceList\Import;

use App\Support\PrivateFileStorage;

/**
 * BL-P4-01b Limits und Storage-Präfixe.
 *
 * Domänenrahmen: ≤20 Inventare × 24 Stunden × 3 Basisgruppen ≈ 1.500 Zeilen.
 * Grenzen bewusst konservativ über dem Normalfall, aber vor Memory-Materialisierung.
 */
final class PriceListImportLimits
{
    public const MAX_BYTES = 50 * 1024 * 1024;

    /** inkl. Puffer über 14 V1-Inventare / Hilfsblätter */
    public const MAX_SHEETS = 20;

    /** Used-Range-Zeilen je Blatt (inkl. Header) vor Load */
    public const MAX_ROWS_PER_SHEET = 5000;

    /** Gesamte Datenzeilen über alle Blätter nach Parsing */
    public const MAX_DATA_ROWS = 10000;

    /** Kanonischer Vertrag braucht wenige Spalten; Used-Range darüber ablehnen */
    public const MAX_COLUMNS = 32;

    public const STORAGE_PREFIX = 'price-list-imports/';

    public const TEMPORARY_UPLOAD_PREFIX = PrivateFileStorage::TEMPORARY_PREFIX.'price-list-imports/';

    /** @var list<string> */
    public const ALLOWED_EXTENSIONS = ['xlsx', 'xls'];

    /** @var list<string> */
    public const ALLOWED_MIME_TYPES = [
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.ms-excel',
        'application/octet-stream',
    ];
}
