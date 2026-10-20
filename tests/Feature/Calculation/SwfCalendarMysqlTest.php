<?php

namespace Tests\Feature\Calculation;

use App\Enums\CalculationKind;
use App\Enums\Role;
use App\Models\AdvertisingCategoryCalculationMethod;
use App\Models\AdvertisingMedium;
use App\Models\AdvertisingMediumCalculationMethod;
use App\Models\Calculation;
use App\Models\CalculationMethod;
use App\Models\User;
use App\Services\DispoOrder\DispoOrderWriter;
use App\Services\InventoryMediumRule\Catalog\InitialCatalogBootstrapper;
use App\Support\Advertising\AdvertisingMediumLiveBookability;
use App\Support\Advertising\CanonicalAdvertisingCategories;
use App\Support\Calculation\EngineProfileRegistry;
use App\Support\PriceList\PriceListCalendar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesPromoAverageCatalog;
use Tests\Support\MysqlTestDatabaseGuard;
use Tests\TestCase;

/**
 * PO-SWF-CALENDAR-1: MySQL – Registry-Zuordnungen, Promo-Calendar Persistenz, Planner-Snapshot.
 */
class SwfCalendarMysqlTest extends TestCase
{
    use CreatesPromoAverageCatalog;
    use RefreshDatabase;

    public function test_mysql_bootstrap_assigns_calendar_for_swf_profiles(): void
    {
        $this->requireMysql('PO-SWF-CALENDAR-1 catalog');

        app(InitialCatalogBootstrapper::class)->bootstrap();

        $categoryId = AdvertisingMedium::query()
            ->where('code', 'trailer_station_voice')
            ->value('category_id');
        $this->assertNotNull($categoryId);

        $categoryKeys = AdvertisingCategoryCalculationMethod::query()
            ->where('advertising_category_id', $categoryId)
            ->with('calculationMethod')
            ->orderBy('sort')
            ->get()
            ->pluck('calculationMethod.key')
            ->all();
        $this->assertSame(['average', 'calendar'], $categoryKeys);

        foreach ([
            'allonge' => EngineProfileRegistry::PROFILE_SWF_ALLONGE,
            'promo_moderation' => EngineProfileRegistry::PROFILE_SWF_PROMO,
            'contest_payoff' => EngineProfileRegistry::PROFILE_SWF_CONTEST,
        ] as $code => $profile) {
            $medium = AdvertisingMedium::query()->where('code', $code)->firstOrFail();
            $keys = AdvertisingMediumCalculationMethod::query()
                ->where('advertising_medium_id', $medium->id)
                ->with('calculationMethod')
                ->orderBy('sort')
                ->get()
                ->pluck('calculationMethod.key')
                ->all();
            $this->assertSame(['average', 'calendar'], $keys, $code);
            $this->assertTrue(
                AdvertisingMediumCalculationMethod::query()
                    ->where('advertising_medium_id', $medium->id)
                    ->where('engine_profile_key', $profile)
                    ->where('calculation_method_id', CalculationMethod::query()->where('key', 'calendar')->value('id'))
                    ->exists(),
            );
        }

        $bookability = app(AdvertisingMediumLiveBookability::class);
        foreach (['trailer_station_voice', 'allonge', 'promo_moderation', 'contest_payoff'] as $code) {
            $medium = AdvertisingMedium::query()->where('code', $code)->firstOrFail();
            $this->assertTrue($bookability->evaluate($medium, 'calendar')->isBookableForNewPositions, $code);
            $this->assertTrue($bookability->evaluate($medium, 'average')->isBookableForNewPositions, $code);
        }

        $this->assertSame(
            CanonicalAdvertisingCategories::SPECIAL_ADVERTISING_FORMATS,
            AdvertisingMedium::query()->where('code', 'promo_moderation')->firstOrFail()->category?->key,
        );
    }

    public function test_mysql_promo_calendar_save_planner_snapshot_and_dispo(): void
    {
        $this->requireMysql('PO-SWF-CALENDAR-1 snapshot');

        $catalog = $this->createPromoAverageCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $year = PriceListCalendar::currentYear();
        $date = sprintf('%04d-09-14', $year);
        $payload = $this->promoPayload([[
            'inventory_id' => $catalog['a']->id,
            'advertising_medium_id' => $catalog['promo']->id,
            'spot_method' => 'calendar',
            'calculation_method_key' => 'calendar',
            'length_seconds' => 20,
            'position_discount_percent' => '0',
            'ae_percent' => '0',
            'planner_entries' => [
                ['date' => $date, 'hour' => 8, 'spot_count' => 10],
                ['date' => $date, 'hour' => 14, 'spot_count' => 5],
            ],
        ]]);

        $this->actingAs($user)->post(route('calculations.store'), $payload)->assertRedirect();
        $calculation = Calculation::query()->firstOrFail()->load('positions.plannerEntries');
        $position = $calculation->positions[0];

        $this->assertSame(CalculationKind::SwfPromo, $position->kind);
        $this->assertSame('calendar', $position->calculation_method_key);
        $this->assertSame('swf_promo', $position->engine_profile_key);
        $this->assertSame(100, $position->length_index);
        $this->assertCount(2, $position->plannerEntries);
        $this->assertSame('900.00', (string) $calculation->media_gross);

        $dispo = app(DispoOrderWriter::class)
            ->createFromCalculation($calculation, [$position->id], $user)
            ->order
            ->positions()
            ->firstOrFail();

        $this->assertSame('calendar', $dispo->calculation_method_key);
        $this->assertSame('900.00', (string) $dispo->media_gross);
        $this->assertIsArray($dispo->planner_entries_snapshot);
        $this->assertCount(2, $dispo->planner_entries_snapshot);
        $this->assertSame($date, $dispo->planner_entries_snapshot[0]['date'] ?? null);
    }

    private function requireMysql(string $label): void
    {
        MysqlTestDatabaseGuard::assertSafeBeforeDestructiveTraits();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped($label.' erfordert MySQL (GitHub-Job mysql).');
        }
    }
}
