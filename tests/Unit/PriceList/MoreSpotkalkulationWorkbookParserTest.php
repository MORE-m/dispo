<?php

namespace Tests\Unit\PriceList;

use App\Support\PriceList\Import\MoreSpotkalkulationWorkbookParser;
use App\Support\PriceList\Import\PriceListWorkbookParser;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\MoreSpotkalkulationWorkbookFactory;
use Tests\TestCase;

class MoreSpotkalkulationWorkbookParserTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        parent::setUp();
        $this->path = tempnam(sys_get_temp_dir(), 'more-spot-').'.xlsx';
        MoreSpotkalkulationWorkbookFactory::writeMinimal($this->path);
    }

    protected function tearDown(): void
    {
        if (is_file($this->path)) {
            unlink($this->path);
        }
        parent::tearDown();
    }

    #[Test]
    public function it_maps_sheets_to_inventory_codes_and_imports_base_sek_only(): void
    {
        $parsed = (new MoreSpotkalkulationWorkbookParser)->parse($this->path, 'xlsx');

        $this->assertSame(2026, $parsed['meta']['year']);
        $this->assertContains('Preise RHH', $parsed['meta']['sheets_parsed']);
        $this->assertContains('Preise MOREHHKombi+', $parsed['meta']['sheets_parsed']);
        $this->assertGreaterThanOrEqual(1, $parsed['meta']['skipped_average_rows']);
        $this->assertGreaterThanOrEqual(1, $parsed['meta']['skipped_empty_cells']);

        $codes = array_values(array_unique(array_column($parsed['rows'], 'inventory_raw')));
        $this->assertEqualsCanonicalizing(['inv_radio_hamburg', 'inv_more_hamburg_kombi_plus'], $codes);

        foreach ($parsed['rows'] as $row) {
            $this->assertContains($row['day_group_raw'], ['mo_fr', 'sa', 'so']);
            $this->assertIsInt($row['hour_raw']);
            $this->assertGreaterThanOrEqual(0, $row['hour_raw']);
            $this->assertLessThanOrEqual(23, $row['hour_raw']);
            $this->assertNotNull($row['second_price_raw']);
            $this->assertSame(2026, $row['year_raw']);
        }

        $rhhHour0 = collect($parsed['rows'])->first(
            fn (array $row): bool => $row['inventory_raw'] === 'inv_radio_hamburg'
                && $row['hour_raw'] === 0
                && $row['day_group_raw'] === 'mo_fr',
        );
        $this->assertNotNull($rhhHour0);
        $this->assertEquals(1.5, (float) $rhhHour0['second_price_raw']);

        $missingSo = collect($parsed['rows'])->first(
            fn (array $row): bool => $row['inventory_raw'] === 'inv_radio_hamburg'
                && $row['hour_raw'] === 7
                && $row['day_group_raw'] === 'so',
        );
        $this->assertNull($missingSo);

        $warningCodes = array_column(
            array_filter($parsed['issues'], fn (array $i): bool => $i['severity'] === 'warning'),
            'code',
        );
        $this->assertContains('price_missing', $warningCodes);

        $errorCodes = array_column(
            array_filter($parsed['issues'], fn (array $i): bool => $i['severity'] === 'error'),
            'code',
        );
        $this->assertNotContains('formula_not_allowed', $errorCodes);
    }

    #[Test]
    public function price_list_workbook_parser_delegates_to_more_adapter(): void
    {
        $parsed = (new PriceListWorkbookParser)->parse($this->path, 'xlsx');
        $this->assertNotEmpty($parsed['rows']);
        $this->assertSame('inv_radio_hamburg', $parsed['rows'][0]['inventory_raw']);
    }

    #[Test]
    public function it_skips_average_rows_and_does_not_import_derived_day_groups(): void
    {
        $parsed = (new MoreSpotkalkulationWorkbookParser)->parse($this->path, 'xlsx');
        foreach ($parsed['rows'] as $row) {
            $this->assertNotContains($row['day_group_raw'], ['mo_sa', 'mo_so']);
            $this->assertLessThan(37, $row['source_row']);
        }
        $this->assertGreaterThanOrEqual(2, $parsed['meta']['skipped_average_rows']);
    }
}
