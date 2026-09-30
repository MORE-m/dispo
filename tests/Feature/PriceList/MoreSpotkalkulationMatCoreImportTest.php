<?php

namespace Tests\Feature\PriceList;

use App\Enums\PriceListStatus;
use App\Models\Inventory;
use App\Models\Organization;
use App\Models\PriceList;
use App\Models\User;
use App\Services\PriceList\Import\MoreSpotkalkulationMatCoreImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\MoreSpotkalkulationWorkbookFactory;
use Tests\TestCase;

class MoreSpotkalkulationMatCoreImportTest extends TestCase
{
    use RefreshDatabase;

    private string $path;

    protected function setUp(): void
    {
        parent::setUp();
        $this->path = tempnam(sys_get_temp_dir(), 'more-spot-feat-').'.xlsx';
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
    public function it_creates_drafts_with_sparse_hours_and_can_activate(): void
    {
        $org = Organization::factory()->create();
        Inventory::factory()->create([
            'organization_id' => $org->id,
            'name' => 'Radio Hamburg',
            'code' => 'inv_radio_hamburg',
            'sort' => 1,
        ]);
        Inventory::factory()->create([
            'organization_id' => $org->id,
            'name' => 'MORE Hamburg-Kombi+',
            'code' => 'inv_more_hamburg_kombi_plus',
            'sort' => 2,
        ]);
        $actor = User::factory()->create(['email' => 'admin@example.com']);

        $result = app(MoreSpotkalkulationMatCoreImporter::class)->import(
            $actor,
            year: 2026,
            activate: true,
            workbookPath: $this->path,
        );

        $this->assertSame(2026, $result['year']);
        $this->assertCount(2, $result['created_drafts']);
        $this->assertCount(2, $result['activated']);
        $this->assertGreaterThanOrEqual(1, $result['skipped_empty_cells']);
        $this->assertGreaterThanOrEqual(1, $result['skipped_average_rows']);

        $rhh = PriceList::query()
            ->where('status', PriceListStatus::Active)
            ->whereHas('inventory', fn ($q) => $q->where('code', 'inv_radio_hamburg'))
            ->where('year', 2026)
            ->with('items')
            ->firstOrFail();

        $this->assertTrue($rhh->items->contains(
            fn ($item): bool => (int) $item->hour === 0 && $item->day_group->value === 'mo_fr',
        ));
        $this->assertFalse($rhh->items->contains(
            fn ($item): bool => (int) $item->hour === 7 && $item->day_group->value === 'so',
        ));
        $this->assertFalse($rhh->items->contains(
            fn ($item): bool => $item->day_group->isDerived(),
        ));

        $kombiPlus = PriceList::query()
            ->where('status', PriceListStatus::Active)
            ->whereHas('inventory', fn ($q) => $q->where('code', 'inv_more_hamburg_kombi_plus'))
            ->where('year', 2026)
            ->with('items')
            ->firstOrFail();
        $this->assertCount(3, $kombiPlus->items);
        $this->assertTrue($kombiPlus->items->every(fn ($item): bool => (int) $item->hour === 6));
    }
}
