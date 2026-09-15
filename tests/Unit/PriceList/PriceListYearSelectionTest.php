<?php

namespace Tests\Unit\PriceList;

use App\Enums\DayGroup;
use App\Enums\PriceListStatus;
use App\Models\Inventory;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Support\PriceList\PriceListCalendar;
use App\Support\PriceList\PriceListYearSelection;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PriceListYearSelectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_current_and_next_year_use_europe_berlin_boundaries(): void
    {
        $nyeUtc = Carbon::parse('2026-12-31 23:30:00', 'UTC');
        $this->assertSame(2027, PriceListCalendar::currentYear($nyeUtc));
        $this->assertSame(2028, PriceListCalendar::nextYear($nyeUtc));
        $this->assertTrue(PriceListYearSelection::isAllowedSelectableYear(2027, $nyeUtc));
        $this->assertTrue(PriceListYearSelection::isAllowedSelectableYear(2028, $nyeUtc));
        $this->assertFalse(PriceListYearSelection::isAllowedSelectableYear(2026, $nyeUtc));
        $this->assertFalse(PriceListYearSelection::isAllowedSelectableYear(2029, $nyeUtc));

        $berlin = Carbon::parse('2026-06-15 12:00:00', 'Europe/Berlin');
        $this->assertSame(2026, PriceListCalendar::currentYear($berlin));
        $this->assertSame(2027, PriceListCalendar::nextYear($berlin));
    }

    public function test_options_include_current_even_when_missing_and_next_only_when_active(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00', 'Europe/Berlin'));
        $inventory = Inventory::factory()->create(['name' => 'Opt Sender', 'code' => 'OPT']);

        $options = PriceListYearSelection::optionsForInventory($inventory->id);
        $this->assertCount(1, $options);
        $this->assertSame(2026, $options[0]['year']);
        $this->assertFalse($options[0]['available']);
        $this->assertTrue($options[0]['is_default']);

        $next = PriceList::factory()->create([
            'inventory_id' => $inventory->id,
            'year' => 2027,
            'status' => PriceListStatus::Active,
            'version' => '2027-OPT',
        ]);
        PriceListItem::factory()->create([
            'price_list_id' => $next->id,
            'hour' => 8,
            'day_group' => DayGroup::MoFr,
            'second_price' => '1.0000',
        ]);

        $draftNext = PriceList::factory()->create([
            'inventory_id' => $inventory->id,
            'year' => 2027,
            'status' => PriceListStatus::Draft,
            'version' => 'draft-2027',
        ]);
        $this->assertNotNull($draftNext);

        $options = PriceListYearSelection::optionsForInventory($inventory->id);
        $this->assertCount(2, $options);
        $this->assertSame(2027, $options[1]['year']);
        $this->assertTrue($options[1]['available']);
        $this->assertSame($next->id, $options[1]['price_list_id']);
    }

    public function test_next_year_not_offered_for_archive_only(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00', 'Europe/Berlin'));
        $inventory = Inventory::factory()->create();
        PriceList::factory()->create([
            'inventory_id' => $inventory->id,
            'year' => 2027,
            'status' => PriceListStatus::Archived,
            'version' => 'arch-2027',
        ]);

        $options = PriceListYearSelection::optionsForInventory($inventory->id);
        $this->assertCount(1, $options);
        $this->assertSame(2026, $options[0]['year']);
    }

    public function test_assert_allowed_live_year_rejects_far_future(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00', 'Europe/Berlin'));
        $this->expectException(ValidationException::class);
        PriceListYearSelection::assertAllowedLiveYear(2030);
    }

    public function test_kombi_options_are_inventory_specific(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00', 'Europe/Berlin'));
        $sender = Inventory::factory()->create(['type' => 'sender', 'code' => 'S1']);
        $kombi = Inventory::factory()->create(['type' => 'kombi', 'code' => 'K1']);

        $list = PriceList::factory()->create([
            'inventory_id' => $kombi->id,
            'year' => 2027,
            'status' => PriceListStatus::Active,
            'version' => '2027-K1',
        ]);
        PriceListItem::factory()->create([
            'price_list_id' => $list->id,
            'hour' => 8,
            'day_group' => DayGroup::MoFr,
            'second_price' => '2.0000',
        ]);

        $senderOptions = PriceListYearSelection::optionsForInventory($sender->id);
        $kombiOptions = PriceListYearSelection::optionsForInventory($kombi->id);
        $this->assertCount(1, $senderOptions);
        $this->assertCount(2, $kombiOptions);
        $this->assertSame($list->id, $kombiOptions[1]['price_list_id']);
    }
}
