<?php

namespace Tests\Feature\Calculation;

use App\Enums\DayGroup;
use App\Enums\PriceListStatus;
use App\Enums\Role;
use App\Models\AdvertisingMedium;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\User;
use App\Services\Calculation\CatalogResolver;
use App\Support\Calculation\CalculationMethodFreezeDescriptor;
use App\Support\Calculation\CalculationMethodFreezeResolver;
use App\Support\Calculation\EngineProfileRegistry;
use App\Support\PriceList\PriceListCalendar;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesSavedCalculation;
use Tests\Concerns\CreatesSpotClassicCatalog;
use Tests\Support\MysqlTestDatabaseGuard;
use Tests\TestCase;

/**
 * BL-P4-02a: MySQL-Nachweis – Methodenwechsel behält historischen Preislisten-Pin.
 */
class PriceListPinOnMethodChangeMysqlTest extends TestCase
{
    use CreatesSavedCalculation;
    use CreatesSpotClassicCatalog;
    use DatabaseMigrations;

    public function test_mysql_method_change_keeps_pin_after_newer_active(): void
    {
        $this->requireMysql('BL-P4-02a-PIN');

        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $calculation = $this->createSavedCalculation($catalog, [
            ['inventory_id' => $catalog['hamburg']->id],
        ], $user);

        $position = $calculation->positions()->firstOrFail();
        $pinnedId = (int) $position->price_list_id;
        $pinnedVersion = $position->price_list_version;

        /** @var PriceList $previous */
        $previous = PriceList::query()->whereKey($pinnedId)->firstOrFail();
        $previous->update(['status' => PriceListStatus::Archived]);

        $newer = PriceList::factory()->create([
            'inventory_id' => $catalog['hamburg']->id,
            'year' => PriceListCalendar::currentYear(),
            'status' => PriceListStatus::Active,
            'version' => 'mysql-newer-RH',
            'revision_number' => ((int) $previous->revision_number) + 1,
            'valid_from' => $previous->valid_from,
        ]);
        foreach (range(0, 23) as $hour) {
            foreach ([DayGroup::MoFr, DayGroup::Sa, DayGroup::So] as $group) {
                PriceListItem::factory()->create([
                    'price_list_id' => $newer->id,
                    'hour' => $hour,
                    'day_group' => $group,
                    'second_price' => '9.0000',
                ]);
            }
        }

        $position->loadMissing(['planRows', 'timeRanges']);
        $payload = [
            'inventory_id' => (int) $position->inventory_id,
            'advertising_medium_id' => (int) $position->advertising_medium_id,
            'spot_method' => 'calendar',
            'calculation_method_key' => 'calendar',
            'length_seconds' => (int) $position->length_seconds,
            'total_spot_count' => (int) $position->total_spot_count,
            'position_discount_percent' => (string) $position->position_discount_percent,
            'ae_percent' => (string) $position->ae_percent,
            'plan_rows' => $position->planRows->map(fn ($row): array => [
                'hour' => (int) $row->hour,
                'day_group' => $row->day_group->value,
            ])->all(),
            'time_ranges' => $position->timeRanges->map(fn ($range): array => [
                'start_hour' => (int) $range->start_hour,
                'end_hour_exclusive' => (int) $range->end_hour_exclusive,
                'day_group' => $range->day_group->value,
                'spot_count' => (int) $range->spot_count,
                'sort' => (int) $range->sort,
            ])->all(),
            'price_year' => PriceListCalendar::currentYear(),
        ];

        $freezeResolver = new class extends CalculationMethodFreezeResolver
        {
            public function resolveForNewCombination(
                AdvertisingMedium $medium,
                ?string $requestedMethodKey,
            ): CalculationMethodFreezeDescriptor {
                $requested = $requestedMethodKey !== null ? trim($requestedMethodKey) : '';
                if ($requested === 'calendar') {
                    return new CalculationMethodFreezeDescriptor(
                        engineProfileKey: EngineProfileRegistry::PROFILE_SPOT_CLASSIC,
                        calculationMethodKey: 'calendar',
                        calculationMethodName: 'Kalenderplaner',
                        algorithmVersion: 'v1',
                    );
                }

                return parent::resolveForNewCombination($medium, $requestedMethodKey);
            }
        };

        $resolved = (new CatalogResolver($freezeResolver))->resolvePosition($payload, $position->fresh());

        $this->assertSame($pinnedId, (int) $resolved['priceList']->id);
        $this->assertSame($pinnedVersion, $resolved['priceList']->version);
        $this->assertNotSame($newer->id, $resolved['priceList']->id);
        $this->assertSame('calendar', $resolved['freeze']->calculationMethodKey);
        $this->assertSame(
            'released',
            EngineProfileRegistry::pairStatus(
                EngineProfileRegistry::PROFILE_SPOT_CLASSIC,
                'calendar',
            )->value,
        );
    }

    private function requireMysql(string $label): void
    {
        MysqlTestDatabaseGuard::assertSafeBeforeDestructiveTraits();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped($label.' erfordert MySQL (GitHub-Job mysql).');
        }
    }
}
