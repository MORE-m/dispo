<?php

namespace Tests\Unit\PriceList;

use App\Enums\DayGroup;
use App\Models\Inventory;
use App\Support\PriceList\Import\InventoryAliasResolver;
use App\Support\PriceList\Import\PriceListImportLimits;
use App\Support\PriceList\Import\PriceListWorkbookParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\PriceListImportWorkbookFactory;
use Tests\TestCase;

class PriceListWorkbookParserTest extends TestCase
{
    use RefreshDatabase;

    public function test_hour_and_day_group_parsers(): void
    {
        $this->assertSame(8, PriceListWorkbookParser::parseHour('08:00'));
        $this->assertSame(6, PriceListWorkbookParser::parseHour(6));
        $this->assertSame('invalid', PriceListWorkbookParser::parseHour('25'));
        $this->assertSame(DayGroup::MoFr, PriceListWorkbookParser::parseDayGroup('Mo–Fr'));
        $this->assertSame('derived', PriceListWorkbookParser::parseDayGroup('mo_sa'));
    }

    public function test_sparse_rows_and_duplicates_across_normalization(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'pli').'.xlsx';
        PriceListImportWorkbookFactory::writeXlsx($path, [
            ['inventory_code', 'hour', 'day_group', 'second_price'],
            ['RH', '08', 'mo_fr', '1.0000'],
            ['RH', '8', 'mo_fr', '2.0000'],
        ]);

        $parsed = (new PriceListWorkbookParser)->parse($path, 'xlsx');
        $this->assertCount(2, $parsed['rows']);
        @unlink($path);
    }

    public function test_formula_cells_are_rejected_without_evaluation(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'pli').'.xlsx';
        PriceListImportWorkbookFactory::withFormulas($path);

        $parsed = (new PriceListWorkbookParser)->parse($path, 'xlsx');
        @unlink($path);

        $this->assertSame([], $parsed['rows']);
        $codes = array_column($parsed['issues'], 'code');
        $this->assertContains('formula_not_allowed', $codes);
        foreach ($parsed['issues'] as $issue) {
            if ($issue['code'] === 'formula_not_allowed') {
                $this->assertNotNull($issue['sheet']);
                $this->assertNotNull($issue['row']);
                $this->assertNotNull($issue['column']);
            }
        }
    }

    public function test_preflight_rejects_oversized_used_range_before_materializing_rows(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'pli').'.xlsx';
        PriceListImportWorkbookFactory::oversizedRows($path, PriceListImportLimits::MAX_ROWS_PER_SHEET + 1);

        $parsed = (new PriceListWorkbookParser)->parse($path, 'xlsx');
        @unlink($path);

        $this->assertSame([], $parsed['rows']);
        $this->assertSame('too_many_rows', $parsed['issues'][0]['code'] ?? null);
    }

    public function test_preflight_rejects_too_many_sheets(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'pli').'.xlsx';
        PriceListImportWorkbookFactory::tooManySheets($path, PriceListImportLimits::MAX_SHEETS + 1);

        $parsed = (new PriceListWorkbookParser)->parse($path, 'xlsx');
        @unlink($path);

        $this->assertSame([], $parsed['rows']);
        $this->assertSame('too_many_sheets', $parsed['issues'][0]['code'] ?? null);
    }

    public function test_preflight_rejects_oversized_columns(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'pli').'.xlsx';
        PriceListImportWorkbookFactory::oversizedColumns($path, PriceListImportLimits::MAX_COLUMNS + 1);

        $parsed = (new PriceListWorkbookParser)->parse($path, 'xlsx');
        @unlink($path);

        $this->assertSame([], $parsed['rows']);
        $this->assertSame('too_many_columns', $parsed['issues'][0]['code'] ?? null);
    }

    public function test_xls_workbook_parses_canonical_rows(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'pli').'.xls';
        PriceListImportWorkbookFactory::canonicalFlat($path, [
            ['RH', 8, 'mo_fr', '1.2500'],
        ], format: 'xls');

        $parsed = (new PriceListWorkbookParser)->parse($path, 'xls');
        @unlink($path);

        $this->assertCount(1, $parsed['rows']);
        $this->assertSame('RH', $parsed['rows'][0]['inventory_raw']);
    }

    public function test_alias_resolver_uses_documented_aliases_only(): void
    {
        $ffn = Inventory::factory()->create(['name' => 'ffn Hamburg Plus', 'code' => 'FFN']);
        $resolver = new InventoryAliasResolver(collect([$ffn]));
        $ok = $resolver->resolve('radio ffn');
        $this->assertSame($ffn->id, $ok['inventory']->id);
        $bad = $resolver->resolve('radio ffn almost');
        $this->assertArrayHasKey('error', $bad);
    }
}
