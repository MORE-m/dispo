<?php

namespace Tests\Feature\PriceList;

use App\Enums\PriceListImportStatus;
use App\Enums\PriceListStatus;
use App\Enums\Role;
use App\Models\AuditEvent;
use App\Models\PriceList;
use App\Models\PriceListImport;
use App\Models\PriceListItem;
use App\Models\User;
use App\Support\PriceList\PriceListCalendar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesSpotClassicCatalog;
use Tests\Support\PriceListImportWorkbookFactory;
use Tests\TestCase;

class PriceListImportFeatureTest extends TestCase
{
    use CreatesSpotClassicCatalog;
    use RefreshDatabase;

    public function test_roles_and_happy_path_creates_draft_without_activation(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $year = PriceListCalendar::currentYear();
        $path = $this->xlsxPath([
            ['RH', 8, 'mo_fr', '1.2500'],
            ['RH', 8, 'sa', '1.5000'],
        ]);

        foreach ([Role::Sales, Role::Disposition, Role::ProductManagement] as $role) {
            $user = User::factory()->role($role)->create();
            $this->actingAs($user)
                ->post(route('administration.price-lists.import.upload'), [
                    'year' => $year,
                    'file' => new UploadedFile($path, 'prices.xlsx', null, null, true),
                ])
                ->assertForbidden();
        }

        $admin = User::factory()->role(Role::Admin)->create();
        $activeBefore = PriceList::query()
            ->where('inventory_id', $catalog['hamburg']->id)
            ->where('status', PriceListStatus::Active)
            ->count();

        $preview = $this->actingAs($admin)
            ->post(route('administration.price-lists.import.upload'), [
                'year' => $year,
                'file' => new UploadedFile($path, 'prices.xlsx', null, null, true),
            ])
            ->assertOk()
            ->json();

        $this->assertTrue($preview['preview']['can_proceed']);
        $this->assertSame(0, PriceList::query()->where('status', PriceListStatus::Draft)->where('name', 'like', 'Import %')->count());

        $confirm = $this->actingAs($admin)
            ->postJson($preview['urls']['confirm'], [
                'fingerprint' => $preview['preview']['fingerprint'],
            ])
            ->assertOk()
            ->json();

        $this->assertCount(1, $confirm['created']);
        $draft = PriceList::query()->findOrFail($confirm['created'][0]['id']);
        $this->assertSame(PriceListStatus::Draft, $draft->status);
        $this->assertSame($year, (int) $draft->year);
        $this->assertSame(2, $draft->items()->count());
        $this->assertSame(
            $activeBefore,
            PriceList::query()
                ->where('inventory_id', $catalog['hamburg']->id)
                ->where('status', PriceListStatus::Active)
                ->count(),
        );

        $import = PriceListImport::query()->findOrFail($preview['import']['id']);
        $this->assertSame(PriceListImportStatus::Imported, $import->status);
        Storage::disk(config('dispo.files_disk'))->assertExists($import->stored_path);
        $this->assertSame(1, AuditEvent::query()->where('action', 'price_list_import.confirmed')->count());

        $this->actingAs($admin)
            ->postJson($preview['urls']['confirm'], [
                'fingerprint' => $preview['preview']['fingerprint'],
            ])
            ->assertStatus(422);

        $this->assertSame(1, PriceList::query()->where('id', $draft->id)->count());
    }

    public function test_invalid_rows_block_confirm_and_sparse_hours_are_allowed(): void
    {
        $this->createSpotClassicCatalog();
        $admin = User::factory()->role(Role::Admin)->create();
        $year = PriceListCalendar::currentYear();

        $bad = $this->xlsxPath([
            ['RH', 8, 'mo_fr', '-1'],
        ]);
        $badPreview = $this->actingAs($admin)->post(route('administration.price-lists.import.upload'), [
            'year' => $year,
            'file' => new UploadedFile($bad, 'bad.xlsx', null, null, true),
        ])->assertOk()->json();
        $this->assertFalse($badPreview['preview']['can_proceed']);
        $this->actingAs($admin)->postJson($badPreview['urls']['confirm'], [
            'fingerprint' => $badPreview['preview']['fingerprint'],
        ])->assertStatus(422);
        $this->assertSame(0, PriceList::query()->where('name', 'like', 'Import %')->count());

        $sparse = $this->xlsxPath([
            ['RH', 6, 'mo_fr', '1.0000'],
        ]);
        $sparsePreview = $this->actingAs($admin)->post(route('administration.price-lists.import.upload'), [
            'year' => $year,
            'file' => new UploadedFile($sparse, 'sparse.xlsx', null, null, true),
        ])->assertOk()->json();
        $this->assertTrue($sparsePreview['preview']['can_proceed']);
        $this->actingAs($admin)->postJson($sparsePreview['urls']['confirm'], [
            'fingerprint' => $sparsePreview['preview']['fingerprint'],
        ])->assertOk();
        $draft = PriceList::query()->where('name', 'like', 'Import %RH')->firstOrFail();
        $this->assertSame(1, $draft->items()->count());
        $this->assertSame(0, PriceListItem::query()->where('price_list_id', $draft->id)->where('day_group', 'sa')->count());
    }

    public function test_unknown_inventory_duplicate_and_year_mismatch(): void
    {
        $this->createSpotClassicCatalog();
        $admin = User::factory()->role(Role::Admin)->create();
        $year = PriceListCalendar::currentYear();

        $unknown = $this->xlsxPath([['UNKNOWN', 8, 'mo_fr', '1']]);
        $this->assertFalse(
            $this->actingAs($admin)->post(route('administration.price-lists.import.upload'), [
                'year' => $year,
                'file' => new UploadedFile($unknown, 'u.xlsx', null, null, true),
            ])->json('preview.can_proceed'),
        );

        $dup = tempnam(sys_get_temp_dir(), 'dup').'.xlsx';
        PriceListImportWorkbookFactory::writeXlsx($dup, [
            ['inventory_code', 'hour', 'day_group', 'second_price'],
            ['RH', 8, 'mo_fr', '1'],
            ['RH', '08:00', 'mo_fr', '2'],
        ]);
        $this->assertFalse(
            $this->actingAs($admin)->post(route('administration.price-lists.import.upload'), [
                'year' => $year,
                'file' => new UploadedFile($dup, 'd.xlsx', null, null, true),
            ])->json('preview.can_proceed'),
        );

        $yearMismatch = tempnam(sys_get_temp_dir(), 'ym').'.xlsx';
        PriceListImportWorkbookFactory::canonicalFlat($yearMismatch, [
            ['RH', 8, 'mo_fr', '1'],
        ], $year + 1);
        $this->assertFalse(
            $this->actingAs($admin)->post(route('administration.price-lists.import.upload'), [
                'year' => $year,
                'file' => new UploadedFile($yearMismatch, 'y.xlsx', null, null, true),
            ])->json('preview.can_proceed'),
        );
    }

    public function test_multi_inventory_atomic_and_stale_fingerprint(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $admin = User::factory()->role(Role::Admin)->create();
        $year = PriceListCalendar::currentYear();
        $path = $this->xlsxPath([
            ['RH', 8, 'mo_fr', '1.0000'],
            ['RAH', 9, 'sa', '0.8000'],
        ]);

        $preview = $this->actingAs($admin)->post(route('administration.price-lists.import.upload'), [
            'year' => $year,
            'file' => new UploadedFile($path, 'multi.xlsx', null, null, true),
        ])->assertOk()->json();
        $this->assertTrue($preview['preview']['can_proceed']);
        $this->assertCount(2, $preview['preview']['inventories']);

        $this->actingAs($admin)->postJson($preview['urls']['confirm'], [
            'fingerprint' => str_repeat('0', 64),
        ])->assertStatus(409);

        $ok = $this->actingAs($admin)->postJson($preview['urls']['confirm'], [
            'fingerprint' => $preview['preview']['fingerprint'],
        ])->assertOk()->json();
        $this->assertCount(2, $ok['created']);
        $this->assertSame(
            2,
            PriceList::query()->whereIn('inventory_id', [$catalog['hamburg']->id, $catalog['rock']->id])
                ->where('status', PriceListStatus::Draft)
                ->where('name', 'like', 'Import %')
                ->count(),
        );
    }

    /**
     * @param  list<array{0: string, 1: int|string, 2: string, 3: string|float|int}>  $rows
     */
    private function xlsxPath(array $rows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'pli').'.xlsx';
        PriceListImportWorkbookFactory::canonicalFlat($path, $rows);

        return $path;
    }
}
