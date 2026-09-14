<?php

namespace App\Support\PriceList\Import;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;

/**
 * Begrenzt das Laden auf erlaubte Zeilen/Spalten vor vollständiger Materialisierung.
 */
final class PriceListImportReadFilter implements IReadFilter
{
    public function __construct(
        private readonly int $maxRow,
        private readonly int $maxColumnIndex,
    ) {}

    public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
    {
        if ($row < 1 || $row > $this->maxRow) {
            return false;
        }

        $index = Coordinate::columnIndexFromString($columnAddress);

        return $index >= 1 && $index <= $this->maxColumnIndex;
    }
}
