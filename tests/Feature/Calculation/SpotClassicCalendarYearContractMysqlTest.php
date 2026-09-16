<?php

namespace Tests\Feature\Calculation;

use App\Enums\Role;
use App\Models\User;
use App\Services\Calculation\CalculationWriter;
use App\Services\DynamicField\ConfigurationSnapshotFreezeService;
use App\Support\PriceList\PriceListCalendar;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreatesSpotClassicCatalog;
use Tests\Support\MysqlTestDatabaseGuard;
use Tests\TestCase;

/**
 * BL-P4-02b: MySQL-Nachweis Jahresvertrag Kalenderplaner.
 */
class SpotClassicCalendarYearContractMysqlTest extends TestCase
{
    use CreatesSpotClassicCatalog;
    use DatabaseMigrations;

    public function test_mysql_rejects_planner_date_outside_pinned_price_year(): void
    {
        $this->requireMysql('BL-P4-02b-YEAR');

        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $currentYear = PriceListCalendar::currentYear();
        $nextYear = $currentYear + 1;

        $this->expectException(ValidationException::class);

        app(CalculationWriter::class)->preview(
            $this->calendarPayload($catalog, [
                ['date' => sprintf('%04d-06-01', $nextYear), 'hour' => 8, 'spot_count' => 1],
            ]),
            $user,
        );
    }

    /**
     * @param  list<array{date: string, hour: int, spot_count: int}>  $entries
     * @return array<string, mixed>
     */
    private function calendarPayload(array $catalog, array $entries): array
    {
        $fingerprint = app(ConfigurationSnapshotFreezeService::class)
            ->resolveLiveSchemaForCalculationV3()['schema_fingerprint'];
        $positionFingerprint = app(ConfigurationSnapshotFreezeService::class)
            ->resolveLivePositionSchema((int) $catalog['medium']->id)['schema_fingerprint'];

        return [
            'planning_mode' => 'manual',
            'order_discount_percent' => '0',
            'schema_fingerprint' => $fingerprint,
            'positions' => [[
                'inventory_id' => $catalog['hamburg']->id,
                'advertising_medium_id' => $catalog['medium']->id,
                'schema_fingerprint' => $positionFingerprint,
                'spot_method' => 'calendar',
                'calculation_method_key' => 'calendar',
                'length_seconds' => 30,
                'position_discount_percent' => '0',
                'ae_percent' => '0',
                'planner_entries' => $entries,
            ]],
        ];
    }

    private function requireMysql(string $label): void
    {
        MysqlTestDatabaseGuard::assertSafeBeforeDestructiveTraits();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped($label.' erfordert MySQL (GitHub-Job mysql).');
        }
    }
}
