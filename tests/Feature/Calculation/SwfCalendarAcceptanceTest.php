<?php

namespace Tests\Feature\Calculation;

use App\Enums\CalculationKind;
use App\Enums\Role;
use App\Models\Calculation;
use App\Models\DispoOrderPosition;
use App\Models\User;
use App\Services\Calculation\CalculationWriter;
use App\Services\DispoOrder\DispoOrderWriter;
use App\Support\PriceList\PriceListCalendar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesPromoAverageCatalog;
use Tests\TestCase;

/**
 * PO-SWF-CALENDAR-1: Familien-Acceptance für SWF × Calendar (repräsentativ Promo).
 *
 * Happy Path: 10 × 2,00 × 20 × 1,50 = 600,00 ohne Spotlängenindex.
 */
class SwfCalendarAcceptanceTest extends TestCase
{
    use CreatesPromoAverageCatalog;
    use RefreshDatabase;

    public function test_promo_calendar_preview_save_reload_dispo_snapshot(): void
    {
        $catalog = $this->createPromoAverageCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $year = PriceListCalendar::currentYear();
        $date = sprintf('%04d-09-14', $year);
        $payload = $this->promoCalendarPayload($catalog, [
            ['date' => $date, 'hour' => 8, 'spot_count' => 10],
        ]);

        $preview = $this->actingAs($user)->postJson(route('calculations.preview'), $payload);
        $preview->assertOk();
        $this->assertSame('600.00', $preview->json('totals.media_gross'));
        $this->assertSame(100, $preview->json('totals.positions.0.length_index'));
        $this->assertSame(10, $preview->json('totals.positions.0.spot_count'));

        $this->actingAs($user)->post(route('calculations.store'), $payload)->assertRedirect();
        $calculation = Calculation::query()->firstOrFail()->load(['positions.plannerEntries']);
        $this->assertSame('600.00', (string) $calculation->media_gross);
        $position = $calculation->positions[0];
        $this->assertSame(CalculationKind::SwfPromo, $position->kind);
        $this->assertSame('swf_promo', $position->engine_profile_key);
        $this->assertSame('calendar', $position->calculation_method_key);
        $this->assertSame(20, $position->length_seconds);
        $this->assertEqualsWithDelta(50.0, (float) $position->surcharge_percent, 0.00001);
        $this->assertSame(100, $position->length_index);
        $this->assertCount(1, $position->plannerEntries);
        $this->assertSame($date, $position->plannerEntries->first()->dateIso());

        $this->actingAs($user)->get(route('calculations.edit', $calculation))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('calculation.positions.0.calculation_method_key', 'calendar')
                ->where('calculation.positions.0.length_seconds', 20));

        $result = app(DispoOrderWriter::class)->createFromCalculation(
            $calculation,
            [$position->id],
            $user,
        );
        $dispo = $result->order->positions()->first();
        $this->assertInstanceOf(DispoOrderPosition::class, $dispo);
        $this->assertSame('calendar', $dispo->calculation_method_key);
        $this->assertSame(20, $dispo->length_seconds);
        $this->assertSame('600.00', (string) $dispo->media_gross);
        $this->assertSame(CalculationKind::SwfPromo, $dispo->kind);
        $this->assertStringContainsString($date, (string) json_encode($dispo->planner_entries_snapshot));
    }

    public function test_promo_calendar_multi_slot_aggregation(): void
    {
        $catalog = $this->createPromoAverageCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $year = PriceListCalendar::currentYear();
        $payload = $this->promoCalendarPayload($catalog, [
            ['date' => sprintf('%04d-09-14', $year), 'hour' => 8, 'spot_count' => 10],
            ['date' => sprintf('%04d-09-14', $year), 'hour' => 14, 'spot_count' => 5],
        ], lengthSeconds: 20);

        // listA = 2,00 für alle Stunden → 10×2×20×1,5 + 5×2×20×1,5 = 600 + 300 = 900
        $preview = $this->actingAs($user)->postJson(route('calculations.preview'), $payload);
        $preview->assertOk();
        $this->assertSame('900.00', $preview->json('totals.media_gross'));
        $this->assertSame(15, $preview->json('totals.positions.0.spot_count'));
    }

    public function test_snapshot_frozen_when_live_rule_changes(): void
    {
        $catalog = $this->createPromoAverageCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $year = PriceListCalendar::currentYear();
        $date = sprintf('%04d-09-14', $year);
        $payload = $this->promoCalendarPayload($catalog, [
            ['date' => $date, 'hour' => 8, 'spot_count' => 10],
        ]);

        $this->actingAs($user)->post(route('calculations.store'), $payload)->assertRedirect();
        $calculation = Calculation::query()->firstOrFail();
        $result = app(DispoOrderWriter::class)->createFromCalculation(
            $calculation,
            [$calculation->positions[0]->id],
            $user,
        );
        $dispo = $result->order->positions()->firstOrFail();

        $catalog['ruleA']->update([
            'default_length_seconds' => 25,
            'surcharge_percent' => '55',
        ]);

        $writerPayload = app(CalculationWriter::class)->payloadFromCalculation(
            $calculation->fresh(['positions.plannerEntries', 'positions.planRows', 'positions.timeRanges', 'positions.discounts', 'orderDiscounts', 'configurationSnapshot', 'fieldValues']),
        );
        $writerPayload['lock_version'] = $calculation->lock_version;
        $this->actingAs($user)->put(route('calculations.update', $calculation), $writerPayload)->assertRedirect();

        $this->assertSame('600.00', (string) $dispo->fresh()->media_gross);
        $this->assertSame('600.00', (string) $calculation->fresh()->media_gross);
        $this->assertEqualsWithDelta(50.0, (float) $calculation->fresh()->positions()->first()->surcharge_percent, 0.00001);

        $again = $this->actingAs($user)->postJson(route('calculations.preview'), $this->promoCalendarPayload($catalog, [
            ['date' => $date, 'hour' => 8, 'spot_count' => 10],
        ], lengthSeconds: 25));
        $again->assertOk();
        // 10 × 2 × 25 × 1,55 = 775
        $this->assertSame('775.00', $again->json('totals.media_gross'));
    }

    public function test_settlement_still_rejected_for_promo_calendar(): void
    {
        $catalog = $this->createPromoAverageCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $year = PriceListCalendar::currentYear();
        $payload = $this->promoCalendarPayload($catalog, [
            ['date' => sprintf('%04d-09-14', $year), 'hour' => 8, 'spot_count' => 2],
        ]);
        $payload['positions'][0]['pricing_settlement_mode'] = 'fixed_price';
        $payload['positions'][0]['fixed_price_nn'] = '100.00';

        $this->actingAs($user)->postJson(route('calculations.preview'), $payload)->assertStatus(422);
    }

    public function test_year_crossing_fail_closed(): void
    {
        $catalog = $this->createPromoAverageCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $year = PriceListCalendar::currentYear();
        $next = $year + 1;

        $this->actingAs($user)->postJson(route('calculations.preview'), $this->promoCalendarPayload($catalog, [
            ['date' => sprintf('%04d-09-14', $year), 'hour' => 8, 'spot_count' => 1],
            ['date' => sprintf('%04d-01-10', $next), 'hour' => 9, 'spot_count' => 1],
        ]))->assertStatus(422);
    }

    /**
     * @param  list<array{date: string, hour: int, spot_count: int}>  $entries
     * @return array<string, mixed>
     */
    private function promoCalendarPayload(array $catalog, array $entries, int $lengthSeconds = 20): array
    {
        return $this->promoPayload([
            [
                'inventory_id' => $catalog['a']->id,
                'advertising_medium_id' => $catalog['promo']->id,
                'spot_method' => 'calendar',
                'calculation_method_key' => 'calendar',
                'length_seconds' => $lengthSeconds,
                'position_discount_percent' => '0',
                'ae_percent' => '0',
                'planner_entries' => $entries,
            ],
        ]);
    }
}
