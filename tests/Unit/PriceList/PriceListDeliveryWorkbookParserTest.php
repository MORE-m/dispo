<?php

namespace Tests\Unit\PriceList;

use App\Support\PriceList\Import\PriceListDeliveryWorkbookContract;
use App\Support\PriceList\Import\PriceListDeliveryWorkbookParser;
use App\Support\PriceList\Import\PriceListWorkbookParser;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\Support\PriceListDeliveryWorkbookFactory;
use Tests\TestCase;

class PriceListDeliveryWorkbookParserTest extends TestCase
{
    public function test_contract_headers_match_reference_shape(): void
    {
        $this->assertTrue(PriceListDeliveryWorkbookContract::isTimeHeader('Uhrzeit'));
        $this->assertSame(
            'mo_fr',
            PriceListDeliveryWorkbookContract::matchPriceHeader("Mo - Fr\n€/1 Sek"),
        );
        $this->assertSame(
            'sa',
            PriceListDeliveryWorkbookContract::matchPriceHeader("Samstag\n€/1 Sek"),
        );
        $this->assertSame(
            'so',
            PriceListDeliveryWorkbookContract::matchPriceHeader("Sonntag\n€/1 Sek"),
        );
        $this->assertNull(
            PriceListDeliveryWorkbookContract::matchPriceHeader("Mo - Fr\n€/30 Sek"),
        );
        $this->assertTrue(PriceListDeliveryWorkbookContract::looksLikeHeaderRow([
            'Uhrzeit',
            "Mo - Fr\n€/1 Sek",
            "Samstag\n€/1 Sek",
            "Sonntag\n€/1 Sek",
        ]));
    }

    public function test_full_sheet_maps_to_72_canonical_rows(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'deliv').'.xlsx';
        PriceListDeliveryWorkbookFactory::writeFullInventory($path, 'Radio Hamburg', '1.5');

        $parsed = (new PriceListWorkbookParser)->parse($path, 'xlsx');
        @unlink($path);

        $this->assertCount(72, $parsed['rows']);
        $this->assertSame('Radio Hamburg', $parsed['rows'][0]['inventory_raw']);
        $this->assertSame(0, $parsed['rows'][0]['hour_raw']);
        $this->assertSame('mo_fr', $parsed['rows'][0]['day_group_raw']);
        $groups = array_unique(array_column($parsed['rows'], 'day_group_raw'));
        sort($groups);
        $this->assertSame(['mo_fr', 'sa', 'so'], $groups);
        $this->assertSame([], array_filter(
            $parsed['issues'],
            fn (array $i): bool => $i['severity'] === 'error',
        ));
    }

    public function test_empty_prices_are_warnings_not_zero_rows(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'deliv').'.xlsx';
        PriceListDeliveryWorkbookFactory::writeEmptyTemplate($path, 'Radio Hamburg');

        $parsed = (new PriceListWorkbookParser)->parse($path, 'xlsx');
        @unlink($path);

        $this->assertSame([], $parsed['rows']);
        $warnings = array_values(array_filter(
            $parsed['issues'],
            fn (array $i): bool => $i['code'] === 'price_missing',
        ));
        $this->assertCount(72, $warnings);
        $this->assertSame('B', $warnings[0]['column']);
    }

    public function test_sparse_prices_emit_missing_warnings_and_filled_rows(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'deliv').'.xlsx';
        $prices = [];
        $prices[8] = ['mo_fr' => '2.0000', 'sa' => '2.5000'];
        PriceListDeliveryWorkbookFactory::write($path, [
            ['name' => 'Radio Hamburg', 'prices' => $prices],
        ]);

        $parsed = (new PriceListWorkbookParser)->parse($path, 'xlsx');
        @unlink($path);

        $this->assertCount(2, $parsed['rows']);
        $this->assertGreaterThan(0, count(array_filter(
            $parsed['issues'],
            fn (array $i): bool => $i['code'] === 'price_missing',
        )));
    }

    public function test_duplicate_hour_is_error(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'deliv').'.xlsx';
        PriceListDeliveryWorkbookFactory::writeFullInventory($path, 'Radio Hamburg');
        $spreadsheet = IOFactory::load($path);
        $spreadsheet->getActiveSheet()->setCellValue('A3', '00:00');
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        $parsed = (new PriceListWorkbookParser)->parse($path, 'xlsx');
        @unlink($path);

        $codes = array_column($parsed['issues'], 'code');
        $this->assertContains('hour_duplicate', $codes);
    }

    public function test_formula_cells_rejected(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'deliv').'.xlsx';
        PriceListDeliveryWorkbookFactory::writeWithFormula($path, 'Radio Hamburg');

        $parsed = (new PriceListWorkbookParser)->parse($path, 'xlsx');
        @unlink($path);

        $this->assertContains('formula_not_allowed', array_column($parsed['issues'], 'code'));
    }

    public function test_reference_file_is_detected_as_delivery_format(): void
    {
        $reference = base_path('docs/readiness/import-templates/Template_Preisanlieferung.xlsx');
        $this->assertFileExists($reference);

        $spreadsheet = IOFactory::load($reference);
        $this->assertTrue(PriceListDeliveryWorkbookParser::looksLikeDeliverySpreadsheet($spreadsheet));
        $parsed = (new PriceListDeliveryWorkbookParser)->extract($spreadsheet);
        $spreadsheet->disconnectWorksheets();

        $this->assertSame('MORE Hamburg-Kombi', $parsed['rows'][0]['inventory_raw'] ?? null);
        $this->assertCount(72, $parsed['rows']);
    }
}
