<?php

namespace App\Support\Spreadsheet;

/**
 * Sichere Download-Dateinamen ohne Pfad-/Steuerzeichen.
 */
final class SafeDownloadFilename
{
    public static function make(string $baseName, string $extension = 'xlsx'): string
    {
        $extension = ltrim(strtolower(trim($extension)), '.');
        if ($extension === '') {
            $extension = 'xlsx';
        }

        $safe = preg_replace('/[^\p{L}\p{N}\-_ .]+/u', '_', $baseName) ?? '';
        $safe = preg_replace('/[\/\\\\:\*\?"<>\|\x00-\x1F\x7F]+/', '_', $safe) ?? '';
        $safe = trim(preg_replace('/_+/', '_', $safe) ?? '', " ._-\t\n\r\0\x0B");

        if ($safe === '') {
            $safe = 'export';
        }

        if (strlen($safe) > 180) {
            $safe = rtrim(substr($safe, 0, 180), ' ._');
        }

        return $safe.'.'.$extension;
    }
}
