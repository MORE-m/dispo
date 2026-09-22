<?php

namespace Tests\Unit\Support\Spreadsheet;

use App\Support\Spreadsheet\SafeDownloadFilename;
use App\Support\Spreadsheet\SpreadsheetText;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SpreadsheetSafetyTest extends TestCase
{
    #[DataProvider('injectionProvider')]
    public function test_formula_injection_is_neutralized(string $input, string $expected): void
    {
        $this->assertSame($expected, SpreadsheetText::neutralize($input));
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    public static function injectionProvider(): array
    {
        return [
            ['=CMD()', "'=CMD()"],
            ['+1+1', "'+1+1"],
            ['-2', "'-2"],
            ['@SUM(A1)', "'@SUM(A1)"],
            ['Radio Hamburg', 'Radio Hamburg'],
            ['', ''],
        ];
    }

    public function test_safe_download_filename(): void
    {
        $this->assertSame(
            'DO-2026-1-1_Spotverteilung.xlsx',
            SafeDownloadFilename::make('DO-2026-1-1_Spotverteilung'),
        );
        $this->assertSame(
            'Auftrag_mit_Pfad.xlsx',
            SafeDownloadFilename::make('Auftrag/mit\\Pfad'),
        );
        $this->assertStringEndsWith('.xlsx', SafeDownloadFilename::make("bad\0name"));
        $this->assertSame('export.xlsx', SafeDownloadFilename::make('***'));
    }
}
