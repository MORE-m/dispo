<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\AdvertisingMedium;
use App\Models\DispoOrder;
use App\Models\Inventory;
use App\Models\User;
use App\Services\Calculation\CalculationWriter;
use App\Services\DispoOrder\DispoOrderWriter;
use App\Services\DynamicField\ConfigurationSnapshotFreezeService;
use App\Support\E2E\E2EIsolatedEnvironmentGuard;
use Illuminate\Database\Seeder;

/**
 * Isolierter Seed für PO-AUTH-SPECIAL-APPROVE-1 Browser-Smoke.
 * Baut auf E2ECalculationSeeder auf und legt eine zweite Special-Draft-Kampagne an.
 */
class E2ESpecialApproveSeeder extends Seeder
{
    public function run(): void
    {
        E2EIsolatedEnvironmentGuard::assertSafeForE2ESeeding();

        $this->call(E2ECalculationSeeder::class);

        User::query()->where('role', Role::Sales)->update(['can_special_approve' => false]);

        $first = DispoOrder::query()->latest('id')->firstOrFail();
        $first->forceFill([
            'campaign' => 'Sonderfreigabe-Smoke-A',
        ])->save();

        $limited = User::query()->where('email', 'sales-limited@example.com')->firstOrFail();
        $hamburg = Inventory::query()->where('code', 'RH')->firstOrFail();
        $medium = AdvertisingMedium::query()->where('code', 'spot_classic')->firstOrFail();
        $freeze = app(ConfigurationSnapshotFreezeService::class);
        $baseFingerprint = $freeze->resolveLiveSchemaForCalculationV3()['schema_fingerprint'];
        $positionFingerprint = $freeze->resolveLivePositionSchema((int) $medium->id)['schema_fingerprint'];

        $calculation = app(CalculationWriter::class)->create([
            'planning_mode' => 'manual',
            'schema_fingerprint' => $baseFingerprint,
            'customer_name' => 'Sonderfreigabe Smoke GmbH',
            'campaign' => 'Sonderfreigabe-Smoke-B',
            'product_title' => 'Sonderfreigabe Smoke Produkt',
            'order_discount_percent' => '0',
            'ae_enabled' => false,
            'positions' => [[
                'inventory_id' => $hamburg->id,
                'advertising_medium_id' => $medium->id,
                'schema_fingerprint' => $positionFingerprint,
                'spot_method' => 'average',
                'length_seconds' => 30,
                'total_spot_count' => 10,
                'position_discount_percent' => '20',
                'ae_percent' => '15',
                'plan_rows' => [['hour' => 8, 'day_group' => 'mo_fr']],
            ]],
        ], $limited);

        app(DispoOrderWriter::class)->createFromCalculation(
            $calculation,
            array_values($calculation->positions()->pluck('id')->all()),
            $limited,
        );
    }
}
