<?php

namespace Tests\Feature\Calculation;

use App\Enums\EngineCapabilityStatus;
use App\Enums\Role;
use App\Models\User;
use App\Support\Advertising\AdvertisingMediumLiveBookability;
use App\Support\Calculation\EngineProfileRegistry;
use App\Support\PriceList\PriceListCalendar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesAllongeAverageCatalog;
use Tests\Concerns\CreatesContestAverageCatalog;
use Tests\Concerns\CreatesPromoAverageCatalog;
use Tests\Concerns\CreatesTrailerAverageCatalog;
use Tests\TestCase;

/**
 * PO-SWF-CALENDAR-1: Medium-Contract-Tests je SWF-Profil (ohne vier Engine-Kopien).
 */
class SwfCalendarContractTest extends TestCase
{
    use CreatesAllongeAverageCatalog;
    use CreatesContestAverageCatalog;
    use CreatesPromoAverageCatalog;
    use CreatesTrailerAverageCatalog;
    use RefreshDatabase;

    #[DataProvider('releasedProfiles')]
    public function test_calendar_released_without_index_capability(string $profile): void
    {
        $this->assertSame(
            EngineCapabilityStatus::Released,
            EngineProfileRegistry::pairStatus($profile, 'calendar'),
        );
        $this->assertSame('v1', EngineProfileRegistry::currentReleasedVersion($profile, 'calendar'));
        $this->assertTrue(EngineProfileRegistry::usesLengthWithoutIndex($profile));
        $this->assertFalse(EngineProfileRegistry::hasMethodForProfile($profile, 'fixed_price'));
    }

    public function test_trailer_calendar_bookable_and_calculates(): void
    {
        $catalog = $this->createTrailerAverageCatalog();
        $bookability = (new AdvertisingMediumLiveBookability)->evaluate($catalog['trailer'], 'calendar');
        $this->assertTrue($bookability->isBookableForNewPositions);
        $this->assertSame('swf_trailer', $bookability->engineProfileKey);

        $year = PriceListCalendar::currentYear();
        $user = User::factory()->role(Role::Sales)->create();
        $payload = $this->trailerPayload([[
            'inventory_id' => $catalog['a']->id,
            'advertising_medium_id' => $catalog['trailer']->id,
            'spot_method' => 'calendar',
            'calculation_method_key' => 'calendar',
            'length_seconds' => 20,
            'position_discount_percent' => '0',
            'ae_percent' => '0',
            'planner_entries' => [['date' => sprintf('%04d-09-14', $year), 'hour' => 8, 'spot_count' => 10]],
        ]]);

        $preview = $this->actingAs($user)->postJson(route('calculations.preview'), $payload);
        $preview->assertOk();
        // 10 × 2 × 20 × 1,3 = 520
        $this->assertSame('520.00', $preview->json('totals.media_gross'));
        $this->assertSame(100, $preview->json('totals.positions.0.length_index'));
    }

    public function test_allonge_calendar_requires_confirm(): void
    {
        $catalog = $this->createAllongeAverageCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $year = PriceListCalendar::currentYear();

        $catalog['ruleA']->update(['swf_allonge_confirmed_at' => null]);
        $payload = $this->allongeCalendarPayload($catalog, $year);
        $this->actingAs($user)->postJson(route('calculations.preview'), $payload)->assertStatus(422);

        $catalog['ruleA']->update(['swf_allonge_confirmed_at' => now()]);
        $ok = $this->actingAs($user)->postJson(route('calculations.preview'), $payload);
        $ok->assertOk();
        // 10 × 2 × 10 × 1,3 = 260 (ruleA default 10/+30)
        $this->assertSame('260.00', $ok->json('totals.media_gross'));
        $this->assertSame(100, $ok->json('totals.positions.0.length_index'));
    }

    public function test_promo_calendar_uses_live_length_and_surcharge(): void
    {
        $catalog = $this->createPromoAverageCatalog();
        $bookability = (new AdvertisingMediumLiveBookability)->evaluate($catalog['promo'], 'calendar');
        $this->assertTrue($bookability->isBookableForNewPositions);

        $catalog['ruleA']->update(['default_length_seconds' => 15, 'surcharge_percent' => '40']);
        $user = User::factory()->role(Role::Sales)->create();
        $year = PriceListCalendar::currentYear();
        $payload = $this->promoPayload([[
            'inventory_id' => $catalog['a']->id,
            'advertising_medium_id' => $catalog['promo']->id,
            'spot_method' => 'calendar',
            'calculation_method_key' => 'calendar',
            'length_seconds' => 15,
            'position_discount_percent' => '0',
            'ae_percent' => '0',
            'planner_entries' => [['date' => sprintf('%04d-09-14', $year), 'hour' => 8, 'spot_count' => 10]],
        ]]);

        $preview = $this->actingAs($user)->postJson(route('calculations.preview'), $payload);
        $preview->assertOk();
        // 10 × 2 × 15 × 1,4 = 420
        $this->assertSame('420.00', $preview->json('totals.media_gross'));
    }

    public function test_contest_calendar_uses_live_length_and_surcharge(): void
    {
        $catalog = $this->createContestAverageCatalog();
        $bookability = (new AdvertisingMediumLiveBookability)->evaluate($catalog['contest'], 'calendar');
        $this->assertTrue($bookability->isBookableForNewPositions);

        $user = User::factory()->role(Role::Sales)->create();
        $year = PriceListCalendar::currentYear();
        $payload = $this->contestPayload([[
            'inventory_id' => $catalog['a']->id,
            'advertising_medium_id' => $catalog['contest']->id,
            'spot_method' => 'calendar',
            'calculation_method_key' => 'calendar',
            'length_seconds' => 25,
            'position_discount_percent' => '0',
            'ae_percent' => '0',
            'planner_entries' => [['date' => sprintf('%04d-09-14', $year), 'hour' => 8, 'spot_count' => 10]],
        ]]);

        $preview = $this->actingAs($user)->postJson(route('calculations.preview'), $payload);
        $preview->assertOk();
        // 10 × 2 × 25 × 1,5 = 750
        $this->assertSame('750.00', $preview->json('totals.media_gross'));
        $this->assertSame(100, $preview->json('totals.positions.0.length_index'));
    }

    /**
     * @return list<array{0: string}>
     */
    public static function releasedProfiles(): array
    {
        return [
            [EngineProfileRegistry::PROFILE_SWF_TRAILER],
            [EngineProfileRegistry::PROFILE_SWF_ALLONGE],
            [EngineProfileRegistry::PROFILE_SWF_PROMO],
            [EngineProfileRegistry::PROFILE_SWF_CONTEST],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function allongeCalendarPayload(array $catalog, int $year): array
    {
        return $this->allongePayload([[
            'inventory_id' => $catalog['a']->id,
            'advertising_medium_id' => $catalog['allonge']->id,
            'spot_method' => 'calendar',
            'calculation_method_key' => 'calendar',
            'length_seconds' => 10,
            'position_discount_percent' => '0',
            'ae_percent' => '0',
            'planner_entries' => [['date' => sprintf('%04d-09-14', $year), 'hour' => 8, 'spot_count' => 10]],
        ]]);
    }
}
