<?php

namespace Tests\Unit\OnlineAudio;

use App\Enums\OaTargetingCategory;
use App\Enums\PriceListStatus;
use App\Models\OaPlatform;
use App\Models\OaTargetingDefinition;
use App\Models\OaTkpPriceList;
use App\Models\OaTkpPriceListItem;
use App\Services\OnlineAudio\Import\OaPlatformImporter;
use App\Services\OnlineAudio\Import\OaTargetingDefinitionImporter;
use App\Services\OnlineAudio\Import\OaTkpPriceListImporter;
use App\Services\OnlineAudio\Import\OaTkpStammdatenImportException;
use Database\Seeders\InitialCatalogMatCoreSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * PO-CALC-TKP-1A: Import-Validierung (Fixture-Werte, keine produktiven Seeds).
 */
class OaTkpStammdatenImportTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function platform_importer_rejects_duplicates_and_upserts_fixtures(): void
    {
        $importer = app(OaPlatformImporter::class);

        try {
            $importer->import([
                ['code' => 'fixture_a', 'name' => 'Fixture A'],
                ['code' => 'fixture_a', 'name' => 'Dup'],
            ]);
            $this->fail('Expected import exception');
        } catch (OaTkpStammdatenImportException $e) {
            $this->assertNotEmpty($e->errors);
        }

        $result = $importer->import([
            [
                'code' => 'fixture_plat',
                'name' => 'Fixture Platform',
                'sort' => 5,
                'is_adserver_relevant' => true,
            ],
        ]);
        $this->assertSame(1, $result['created']);

        $again = $importer->import([
            [
                'code' => 'fixture_plat',
                'name' => 'Fixture Platform Updated',
                'sort' => 6,
                'is_adserver_relevant' => false,
            ],
        ]);
        $this->assertSame(1, $again['updated']);
        $this->assertSame(1, OaPlatform::query()->count());
        $this->assertSame('Fixture Platform Updated', OaPlatform::query()->firstOrFail()->name);
    }

    #[Test]
    public function targeting_importer_requires_absolute_surcharge_and_known_category(): void
    {
        $importer = app(OaTargetingDefinitionImporter::class);

        try {
            $importer->import([
                [
                    'code' => 'fix_geo',
                    'name' => 'Fixture',
                    'category' => 'percent',
                    'tkp_surcharge' => '1.00',
                ],
            ]);
            $this->fail('Expected import exception');
        } catch (OaTkpStammdatenImportException $e) {
            $this->assertTrue(
                collect($e->errors)->contains(fn (string $msg): bool => str_contains($msg, 'category')),
            );
        }

        $result = $importer->import([
            [
                'code' => 'fixture_geo',
                'name' => 'Fixture Geo',
                'category' => OaTargetingCategory::Technisch->value,
                'tkp_surcharge' => '1.2500',
            ],
        ]);
        $this->assertSame(1, $result['created']);
        $row = OaTargetingDefinition::query()->firstOrFail();
        $this->assertSame('1.2500', (string) $row->tkp_surcharge);
        $this->assertSame(OaTargetingCategory::Technisch, $row->category);
    }

    #[Test]
    public function price_list_importer_validates_core_media_and_minimum_rule(): void
    {
        $this->seed(InitialCatalogMatCoreSeeder::class);
        $importer = app(OaTkpPriceListImporter::class);

        try {
            $importer->import([
                'year' => 2026,
                'revision_number' => 1,
                'status' => PriceListStatus::Draft->value,
                'items' => [[
                    'medium_code' => 'native_ad',
                    'inventory_code' => 'inv_more_kombi_online_audio',
                    'list_tkp' => '10.00',
                    'minimum_tkp' => '8.00',
                ]],
            ]);
            $this->fail('Expected native_ad rejection');
        } catch (OaTkpStammdatenImportException $e) {
            $this->assertTrue(
                collect($e->errors)->contains(fn (string $msg): bool => str_contains($msg, 'native_ad')),
            );
        }

        try {
            $importer->import([
                'year' => 2026,
                'revision_number' => 1,
                'status' => PriceListStatus::Draft->value,
                'items' => [[
                    'medium_code' => 'pre_stream',
                    'inventory_code' => 'inv_more_kombi_online_audio',
                    'list_tkp' => '8.00',
                    'minimum_tkp' => '10.00',
                ]],
            ]);
            $this->fail('Expected minimum > list rejection');
        } catch (OaTkpStammdatenImportException $e) {
            $this->assertTrue(
                collect($e->errors)->contains(fn (string $msg): bool => str_contains($msg, 'minimum_tkp')),
            );
        }

        $dry = $importer->import([
            'year' => 2026,
            'revision_number' => 1,
            'status' => PriceListStatus::Draft->value,
            'items' => [[
                'medium_code' => 'pre_stream',
                'inventory_code' => 'inv_more_kombi_online_audio',
                'list_tkp' => '12.5000',
                'minimum_tkp' => '8.0000',
            ]],
        ], dryRun: true);
        $this->assertTrue($dry['dry_run']);
        $this->assertSame(0, OaTkpPriceList::query()->count());

        $written = $importer->import([
            'year' => 2026,
            'revision_number' => 1,
            'status' => PriceListStatus::Draft->value,
            'name' => 'Fixture OA TKP 2026',
            'items' => [[
                'medium_code' => 'pre_stream',
                'inventory_code' => 'inv_more_kombi_online_audio',
                'list_tkp' => '12.5000',
                'minimum_tkp' => '8.0000',
            ]],
        ]);
        $this->assertFalse($written['dry_run']);
        $this->assertSame(1, $written['items_written']);
        $this->assertSame(1, OaTkpPriceListItem::query()->count());
        $item = OaTkpPriceListItem::query()->firstOrFail();
        $this->assertSame('12.5000', (string) $item->list_tkp);
        $this->assertSame('8.0000', (string) $item->minimum_tkp);
    }
}
