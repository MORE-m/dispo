<?php

namespace Database\Factories;

use App\Enums\PriceListStatus;
use App\Models\Inventory;
use App\Models\PriceList;
use App\Support\PriceList\PriceListCalendar;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PriceList>
 */
class PriceListFactory extends Factory
{
    private static int $revisionSequence = 0;

    public function definition(): array
    {
        self::$revisionSequence++;
        $year = PriceListCalendar::currentYear();

        return [
            'inventory_id' => Inventory::factory(),
            'name' => 'Preisliste',
            'year' => $year,
            'version' => 'v'.self::$revisionSequence,
            'revision_number' => self::$revisionSequence,
            'status' => PriceListStatus::Active,
            'valid_from' => sprintf('%04d-01-01', $year),
            'lock_version' => 1,
        ];
    }
}
