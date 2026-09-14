<?php

namespace Tests\Feature\PriceList;

use App\Enums\Role;
use App\Models\PriceList;
use App\Models\User;
use App\Support\PriceList\PriceListCalendar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\Concerns\CreatesSpotClassicCatalog;
use Tests\Support\PriceListImportWorkbookFactory;
use Tests\TestCase;

/**
 * MySQL-relevante Import-Regressionen (läuft auch unter SQLite).
 */
class PriceListImportConcurrencyTest extends TestCase
{
    use CreatesSpotClassicCatalog;
    use RefreshDatabase;

    public function test_second_confirm_does_not_create_duplicate_drafts(): void
    {
        $this->createSpotClassicCatalog();
        $admin = User::factory()->role(Role::Admin)->create();
        $year = PriceListCalendar::currentYear();
        $path = tempnam(sys_get_temp_dir(), 'pli').'.xlsx';
        PriceListImportWorkbookFactory::canonicalFlat($path, [
            ['RH', 10, 'mo_fr', '1.1000'],
            ['RAH', 11, 'so', '0.9000'],
        ]);

        $preview = $this->actingAs($admin)->post(route('administration.price-lists.import.upload'), [
            'year' => $year,
            'file' => new UploadedFile($path, 'c.xlsx', null, null, true),
        ])->assertOk()->json();

        $this->actingAs($admin)->postJson($preview['urls']['confirm'], [
            'fingerprint' => $preview['preview']['fingerprint'],
        ])->assertOk();

        $count = PriceList::query()->where('name', 'like', 'Import %')->count();
        $this->assertSame(2, $count);

        $this->actingAs($admin)->postJson($preview['urls']['confirm'], [
            'fingerprint' => $preview['preview']['fingerprint'],
        ])->assertStatus(422);

        $this->assertSame($count, PriceList::query()->where('name', 'like', 'Import %')->count());
    }
}
