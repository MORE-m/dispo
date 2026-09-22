<?php

namespace App\Support\Spreadsheet;

/**
 * Schützt Textzellen vor Spreadsheet-/Formula-Injection.
 */
final class SpreadsheetText
{
    /**
     * Neutralisiert führende Formelzeichen, ohne den fachlichen Inhalt zu verfälschen.
     */
    public static function neutralize(?string $value): string
    {
        $text = $value ?? '';

        if ($text === '') {
            return '';
        }

        $first = $text[0];
        if (in_array($first, ['=', '+', '-', '@'], true)) {
            return "'".$text;
        }

        return $text;
    }
}
