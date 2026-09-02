<?php

namespace Tests\Concerns;

use App\Enums\Role;
use App\Models\Calculation;
use App\Models\User;
use App\Services\Calculation\CalculationWriter;

trait CreatesSavedCalculation
{
    /**
     * @param  array{hamburg: mixed, rock: mixed, medium: mixed}  $catalog
     * @param  list<array{inventory_id: int, length_seconds?: int, total_spot_count?: int, hour?: int}>  $spots
     */
    protected function createSavedCalculation(array $catalog, array $spots, ?User $user = null): Calculation
    {
        $user ??= User::factory()->role(Role::Sales)->create();

        $payload = [
            'planning_mode' => 'manual',
            'customer_name' => 'Testkunde GmbH',
            'agency_name' => 'Testagentur',
            'campaign' => 'Frühjahr 2026',
            'product_title' => 'Produkt A',
            'order_discount_percent' => '0',
            'positions' => array_map(fn (array $spot): array => [
                'inventory_id' => $spot['inventory_id'],
                'advertising_medium_id' => $catalog['medium']->id,
                'spot_method' => 'average',
                'length_seconds' => $spot['length_seconds'] ?? 30,
                'total_spot_count' => $spot['total_spot_count'] ?? 10,
                'position_discount_percent' => '0',
                'ae_percent' => '0',
                'plan_rows' => [[
                    'hour' => $spot['hour'] ?? 8,
                    'day_group' => 'mo_fr',
                ]],
            ], $spots),
        ];

        return app(CalculationWriter::class)->create($payload, $user);
    }
}
