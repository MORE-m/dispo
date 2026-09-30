<?php

namespace Tests\Feature\InventoryMediumRule;

use App\Enums\InventoryType;
use App\Models\AdvertisingMedium;
use App\Models\Inventory;
use App\Models\InventoryMediumRule;
use App\Models\Organization;
use App\Services\InventoryMediumRule\Import\CombinationMatrixImporter;
use App\Support\InventoryMediumRule\Import\CombinationMatrixWorkbookParser;
use App\Support\InventoryMediumRule\InventoryMediumRuleOperativeContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * PO-MAT-CORE-MATRIX-1 / BL-P2-02b: Produktivmatrix-Import.
 */
class CombinationMatrixMatCoreImportTest extends TestCase
{
    use RefreshDatabase;

    private function workbookPath(): string
    {
        return CombinationMatrixImporter::defaultWorkbookPath();
    }

    public function test_parser_builds_desired_state_per_contract(): void
    {
        $parsed = (new CombinationMatrixWorkbookParser)->parse($this->workbookPath());

        $this->assertCount(201, $parsed['rows']);
        $this->assertSame(9, $parsed['skipped']['must_not_plan']);
        $this->assertSame(3, $parsed['skipped']['blocked_mat003']);
        $this->assertSame(34, $parsed['skipped']['planable_without_booking']);
        $this->assertSame(28, $parsed['skipped']['skip_media']);

        $keys = array_map(
            static fn (array $row): string => $row['inventory_name'].'|'.$row['medium_name'],
            $parsed['rows'],
        );
        $this->assertCount(201, array_unique($keys));

        $this->assertFalse(in_array('MORE Hamburg-Kombi+|Single-Spot', $keys, true));
        $this->assertFalse(in_array('ffn Hamburg Plus|Single-Spot', $keys, true));
        $this->assertFalse(in_array('RADIO BOLLERWAGEN DAB+ Hamburg|Single-Spot', $keys, true));

        foreach ($parsed['rows'] as $row) {
            $this->assertNotSame('Pre-/In-Stream', $row['medium_name']);
            $this->assertNotSame('Pre-/In-Stream Influencer', $row['medium_name']);
            $this->assertNotSame('', $row['booking_code']);
            $this->assertArrayHasKey(
                $row['planning_responsibility_key'],
                InventoryMediumRuleOperativeContract::PLANNING_RESPONSIBILITIES,
            );
        }

        $rockStinger = collect($parsed['rows'])->first(
            static fn (array $row): bool => $row['inventory_name'] === 'ROCK ANTENNE Hamburg'
                && $row['medium_name'] === 'Stinger',
        );
        $this->assertNotNull($rockStinger);
        $this->assertSame('SWF (K)', $rockStinger['booking_code']);
        $this->assertSame('disposition_abbinder', $rockStinger['planning_responsibility_key']);
    }

    public function test_import_is_idempotent_and_respects_exclusions(): void
    {
        $this->seedCatalogFromParsedRows();

        $importer = app(CombinationMatrixImporter::class);
        $first = $importer->import($this->workbookPath());
        $this->assertSame(201, $first['parsed_rows']);
        $this->assertSame(201, $first['created']);
        $this->assertSame(0, $first['updated']);
        $this->assertSame(0, $first['unchanged']);
        $this->assertSame(201, InventoryMediumRule::query()->count());

        $werbespot = InventoryMediumRule::query()
            ->whereHas('inventory', fn ($q) => $q->where('name', 'Radio Hamburg'))
            ->whereHas('advertisingMedium', fn ($q) => $q->where('name', 'Werbespot'))
            ->firstOrFail();
        $this->assertSame('Spots (L)', $werbespot->booking_code);
        $this->assertSame('disposition', $werbespot->planning_responsibility_key);
        $this->assertNull($werbespot->hint_text);
        $this->assertTrue($werbespot->is_active);

        $this->assertNull(
            InventoryMediumRule::query()
                ->whereHas('inventory', fn ($q) => $q->where('name', 'MORE Hamburg-Kombi+'))
                ->whereHas('advertisingMedium', fn ($q) => $q->where('name', 'Single-Spot'))
                ->first(),
        );
        $this->assertNull(
            InventoryMediumRule::query()
                ->whereHas('inventory', fn ($q) => $q->where('name', 'CARAVAN.fm'))
                ->whereHas('advertisingMedium', fn ($q) => $q->where('name', 'Closer'))
                ->first(),
        );
        $this->assertNull(
            InventoryMediumRule::query()
                ->whereHas('advertisingMedium', fn ($q) => $q->where('name', 'Pre-/In-Stream'))
                ->first(),
        );

        $second = $importer->import($this->workbookPath());
        $this->assertSame(201, $second['parsed_rows']);
        $this->assertSame(0, $second['created']);
        $this->assertSame(0, $second['updated']);
        $this->assertSame(201, $second['unchanged']);
        $this->assertSame(201, InventoryMediumRule::query()->count());
    }

    public function test_import_updates_changed_operative_fields(): void
    {
        $this->seedCatalogFromParsedRows();
        $importer = app(CombinationMatrixImporter::class);
        $importer->import($this->workbookPath());

        $rule = InventoryMediumRule::query()
            ->whereHas('inventory', fn ($q) => $q->where('name', 'Radio Hamburg'))
            ->whereHas('advertisingMedium', fn ($q) => $q->where('name', 'Werbespot'))
            ->firstOrFail();
        $rule->booking_code = 'ALT';
        $rule->planning_responsibility_key = 'oap';
        $rule->lock_version = 1;
        $rule->save();

        $result = $importer->import($this->workbookPath());
        $this->assertSame(1, $result['updated']);
        $this->assertSame(200, $result['unchanged']);

        $rule->refresh();
        $this->assertSame('Spots (L)', $rule->booking_code);
        $this->assertSame('disposition', $rule->planning_responsibility_key);
        $this->assertSame(2, $rule->lock_version);
    }

    public function test_import_fails_closed_when_inventory_missing(): void
    {
        $parsed = (new CombinationMatrixWorkbookParser)->parse($this->workbookPath());
        $media = array_values(array_unique(array_column($parsed['rows'], 'medium_name')));
        foreach ($media as $index => $name) {
            AdvertisingMedium::factory()->create([
                'name' => $name,
                'code' => 'm'.$index,
                'sort' => $index,
            ]);
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Inventar für Matrix-Import nicht eindeutig aktiv gefunden');
        app(CombinationMatrixImporter::class)->import($this->workbookPath());
    }

    private function seedCatalogFromParsedRows(): void
    {
        $parsed = (new CombinationMatrixWorkbookParser)->parse($this->workbookPath());
        $organization = Organization::factory()->create();

        $inventories = array_values(array_unique(array_column($parsed['rows'], 'inventory_name')));
        foreach ($inventories as $index => $name) {
            $isKombi = str_contains($name, 'Kombi');
            Inventory::factory()->create([
                'organization_id' => $organization->id,
                'name' => $name,
                'code' => 'I'.$index,
                'type' => $isKombi ? InventoryType::Kombi : InventoryType::Sender,
                'sort' => $index,
            ]);
        }

        // Also create blocked/must-not media names so absence of rules is meaningful.
        $extraMedia = ['Single-Spot', 'Closer', 'Pre-/In-Stream', 'Pre-/In-Stream Influencer', 'Showsponsoring-Single-Spot'];
        $media = array_values(array_unique([
            ...array_column($parsed['rows'], 'medium_name'),
            ...$extraMedia,
        ]));
        foreach ($media as $index => $name) {
            AdvertisingMedium::factory()->create([
                'name' => $name,
                'code' => 'med'.$index,
                'sort' => $index,
            ]);
        }
    }
}
