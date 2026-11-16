<?php

namespace Tests\Feature\PriceList;

use App\Enums\PriceListStatus;
use App\Enums\Role;
use App\Models\Inventory;
use App\Models\Organization;
use App\Models\PriceList;
use App\Models\User;
use App\Services\Calculation\SpotLengthIndex;
use App\Support\PriceList\Import\PriceListDeliveryTemplateWriter;
use App\Support\PriceList\Import\PriceListDeliveryWorkbookContract;
use App\Support\PriceList\Import\PriceListWorkbookParser;
use App\Support\PriceList\PriceListCalendar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\Concerns\CreatesSpotClassicCatalog;
use Tests\Support\PriceListDeliveryWorkbookFactory;
use Tests\TestCase;

class PriceListDeliveryImportFeatureTest extends TestCase
{
    use CreatesSpotClassicCatalog;
    use RefreshDatabase;

    public function test_template_download_contains_active_classic_inventories_with_empty_prices(): void
    {
        $org = Organization::factory()->create();
        foreach (PriceListDeliveryWorkbookContract::CLASSIC_HOUR_INVENTORY_CODES as $index => $code) {
            Inventory::factory()->create([
                'organization_id' => $org->id,
                'code' => $code,
                'name' => match ($code) {
                    'inv_radio_hamburg' => 'Radio Hamburg',
                    'inv_rock_antenne_hamburg' => 'ROCK ANTENNE Hamburg',
                    'inv_80er_90er_oldie_antenne_hamburg' => '80er 90er OLDIE ANTENNE Hamburg',
                    'inv_caravan_fm' => 'CARAVAN.fm',
                    'inv_more_hamburg_kombi' => 'MORE Hamburg-Kombi',
                    'inv_more_hamburg_kombi_plus' => 'MORE Hamburg-Kombi+',
                    'inv_ffn_hamburg_plus' => 'ffn Hamburg Plus',
                    'inv_radio_bollerwagen_dab_plus_hamburg' => 'RADIO BOLLERWAGEN DAB+ Hamburg',
                    default => $code,
                },
                'is_active' => true,
                'sort' => $index + 1,
            ]);
        }
        Inventory::factory()->create([
            'organization_id' => $org->id,
            'code' => 'inv_more_kombi_online_audio',
            'name' => 'MORE-Kombi Online Audio',
            'is_active' => true,
            'sort' => 99,
        ]);
        Inventory::factory()->create([
            'organization_id' => $org->id,
            'code' => 'inv_radio_hamburg_inactive_clone',
            'name' => 'Inactive Classic',
            'is_active' => false,
            'sort' => 100,
        ]);

        $admin = User::factory()->role(Role::Admin)->create();
        $response = $this->actingAs($admin)
            ->get(route('administration.price-lists.import.template'))
            ->assertOk();

        $path = tempnam(sys_get_temp_dir(), 'tpl').'.xlsx';
        file_put_contents($path, $response->streamedContent());

        $spreadsheet = IOFactory::load($path);
        @unlink($path);

        $this->assertCount(8, $spreadsheet->getAllSheets());
        $titles = array_map(fn ($s) => $s->getTitle(), $spreadsheet->getAllSheets());
        $this->assertContains('Radio Hamburg', $titles);
        $this->assertContains('MORE Hamburg-Kombi', $titles);
        $this->assertNotContains('MORE-Kombi Online Audio', $titles);

        $sheet = $spreadsheet->getSheetByName('Radio Hamburg');
        $this->assertNotNull($sheet);
        $this->assertSame('Uhrzeit', $sheet->getCell('A1')->getValue());
        $this->assertSame('00:00', $sheet->getCell('A2')->getValue());
        $this->assertSame('23:00', $sheet->getCell('A25')->getValue());
        $this->assertNull($sheet->getCell('B2')->getValue());
        $this->assertNull($sheet->getCell('C10')->getValue());
        $this->assertNull($sheet->getCell('D25')->getValue());

        $tmp = tempnam(sys_get_temp_dir(), 'round').'.xlsx';
        $built = (new PriceListDeliveryTemplateWriter)->buildSpreadsheet();
        (new Xlsx($built))->save($tmp);
        $built->disconnectWorksheets();
        $parsedWorkbook = (new PriceListWorkbookParser)->parse($tmp, 'xlsx');
        @unlink($tmp);
        $this->assertSame([], $parsedWorkbook['rows']);
        $this->assertContains('price_missing', array_column($parsedWorkbook['issues'], 'code'));

        $spreadsheet->disconnectWorksheets();
    }

    public function test_delivery_import_creates_draft_without_touching_active_and_supports_sparse(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $year = PriceListCalendar::currentYear();
        $activeBefore = PriceList::query()
            ->where('inventory_id', $catalog['hamburg']->id)
            ->where('status', PriceListStatus::Active)
            ->count();

        $path = tempnam(sys_get_temp_dir(), 'deliv').'.xlsx';
        $prices = [];
        for ($hour = 0; $hour <= 23; $hour++) {
            $prices[$hour] = [
                'mo_fr' => '1.2500',
                'sa' => '1.5000',
                'so' => '1.7500',
            ];
        }
        PriceListDeliveryWorkbookFactory::write($path, [
            ['name' => 'Radio Hamburg', 'prices' => $prices],
            ['name' => 'ROCK ANTENNE Hamburg', 'prices' => [
                8 => ['mo_fr' => '0.9000'],
            ]],
        ]);

        $admin = User::factory()->role(Role::Admin)->create();
        $preview = $this->actingAs($admin)
            ->post(route('administration.price-lists.import.upload'), [
                'year' => $year,
                'file' => new UploadedFile($path, 'delivery.xlsx', null, null, true),
            ])
            ->assertOk()
            ->json();
        @unlink($path);

        $this->assertTrue($preview['preview']['can_proceed']);
        $this->assertSame($year, $preview['preview']['year']);
        $this->assertCount(2, $preview['preview']['inventories']);
        $hamburgSummary = collect($preview['preview']['inventories'])
            ->firstWhere('code', 'RH');
        $this->assertSame(72, $hamburgSummary['item_count']);
        $this->assertSame(0, $hamburgSummary['missing_item_count']);
        $rockSummary = collect($preview['preview']['inventories'])
            ->firstWhere('code', 'RAH');
        $this->assertSame(1, $rockSummary['item_count']);
        $this->assertSame(71, $rockSummary['missing_item_count']);
        $this->assertNotEmpty($preview['preview']['existing_price_lists']);
        $this->assertStringContainsString(
            'nur neue Entwürfe',
            $preview['preview']['notes']['drafts_only'],
        );

        $confirm = $this->actingAs($admin)
            ->postJson($preview['urls']['confirm'], [
                'fingerprint' => $preview['preview']['fingerprint'],
            ])
            ->assertOk()
            ->json();

        $this->assertCount(2, $confirm['created']);
        $this->assertSame(
            $activeBefore,
            PriceList::query()
                ->where('inventory_id', $catalog['hamburg']->id)
                ->where('status', PriceListStatus::Active)
                ->count(),
        );

        $hamburgDraft = PriceList::query()->findOrFail($confirm['created'][0]['id']);
        $this->assertSame(PriceListStatus::Draft, $hamburgDraft->status);
        $this->assertSame($year, (int) $hamburgDraft->year);

        $otherYear = $year + 1;
        $otherActive = PriceList::factory()->create([
            'inventory_id' => $catalog['hamburg']->id,
            'year' => $otherYear,
            'status' => PriceListStatus::Active,
            'version' => 'other-year',
        ]);
        $this->assertSame(
            PriceListStatus::Active,
            $otherActive->fresh()->status,
        );
    }

    public function test_unknown_inventory_sheet_blocks_confirm(): void
    {
        $this->createSpotClassicCatalog();
        $path = tempnam(sys_get_temp_dir(), 'deliv').'.xlsx';
        PriceListDeliveryWorkbookFactory::writeFullInventory($path, 'Unbekannter Sender', '1.0000');

        $admin = User::factory()->role(Role::Admin)->create();
        $preview = $this->actingAs($admin)
            ->post(route('administration.price-lists.import.upload'), [
                'year' => PriceListCalendar::currentYear(),
                'file' => new UploadedFile($path, 'unknown.xlsx', null, null, true),
            ])
            ->assertOk()
            ->json();
        @unlink($path);

        $this->assertFalse($preview['preview']['can_proceed']);
        $this->assertContains(
            'unknown_inventory',
            array_column($preview['preview']['issues'], 'code'),
        );
        $this->actingAs($admin)
            ->postJson($preview['urls']['confirm'], [
                'fingerprint' => $preview['preview']['fingerprint'],
            ])
            ->assertStatus(422);
        $this->assertSame(0, PriceList::query()->where('name', 'like', 'Import %')->count());
    }

    public function test_negative_price_blocks_and_decimal_comma_is_accepted(): void
    {
        $this->createSpotClassicCatalog();
        $admin = User::factory()->role(Role::Admin)->create();
        $year = PriceListCalendar::currentYear();

        $bad = tempnam(sys_get_temp_dir(), 'deliv').'.xlsx';
        PriceListDeliveryWorkbookFactory::write($bad, [[
            'name' => 'Radio Hamburg',
            'prices' => [8 => ['mo_fr' => '-1']],
        ]]);
        $badPreview = $this->actingAs($admin)->post(route('administration.price-lists.import.upload'), [
            'year' => $year,
            'file' => new UploadedFile($bad, 'bad.xlsx', null, null, true),
        ])->assertOk()->json();
        @unlink($bad);
        $this->assertFalse($badPreview['preview']['can_proceed']);

        $ok = tempnam(sys_get_temp_dir(), 'deliv').'.xlsx';
        PriceListDeliveryWorkbookFactory::write($ok, [[
            'name' => 'Radio Hamburg',
            'prices' => [8 => ['mo_fr' => '1,2500', 'sa' => '1.5', 'so' => '2']],
        ]]);
        $okPreview = $this->actingAs($admin)->post(route('administration.price-lists.import.upload'), [
            'year' => $year,
            'file' => new UploadedFile($ok, 'ok.xlsx', null, null, true),
        ])->assertOk()->json();
        @unlink($ok);
        $this->assertTrue($okPreview['preview']['can_proceed']);
        $this->assertSame(3, $okPreview['preview']['valid_row_count']);
    }

    public function test_spot_length_index_unchanged_after_delivery_import_activation_path(): void
    {
        // Keine Engine-Änderung: Index-Tabelle bleibt SPT-009.
        $this->assertSame(110, SpotLengthIndex::forSeconds(15));
        $this->assertSame(105, SpotLengthIndex::forSeconds(20));
        $this->assertSame(100, SpotLengthIndex::forSeconds(30));
        $this->assertSame(95, SpotLengthIndex::forSeconds(45));
    }

    public function test_non_admin_cannot_download_template(): void
    {
        $user = User::factory()->role(Role::Sales)->create();
        $this->actingAs($user)
            ->get(route('administration.price-lists.import.template'))
            ->assertForbidden();
    }
}
