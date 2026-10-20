<?php

namespace Tests\Feature\Calculation;

use App\Enums\CalculationKind;
use App\Enums\Role;
use App\Models\Calculation;
use App\Models\DispoOrderPosition;
use App\Models\User;
use App\Services\Calculation\CalculationWriter;
use App\Services\DispoOrder\DispoOrderWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesContestAverageCatalog;
use Tests\TestCase;

/**
 * PO-SWF-CONTEST-1 / SWF-Medium Gewinnspiel/Pay-Off × Durchschnitt.
 *
 * Happy Path: 10 × 2,00 × 25 × 1,50 = 750,00.
 */
class SwfContestAverageAcceptanceTest extends TestCase
{
    use CreatesContestAverageCatalog;
    use RefreshDatabase;

    public function test_happy_path_preview_save_reload_dispo_with_25_and_50(): void
    {
        $catalog = $this->createContestAverageCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $payload = $this->contestPayload([
            $this->contestPosition($catalog['a'], $catalog['contest'], 10),
        ]);

        $preview = $this->actingAs($user)->postJson(route('calculations.preview'), $payload);
        $preview->assertOk();
        $this->assertSame('750.00', $preview->json('totals.media_gross'));
        $this->assertSame(100, $preview->json('totals.positions.0.length_index'));

        $this->actingAs($user)->post(route('calculations.store'), $payload)->assertRedirect();
        $calculation = Calculation::query()->firstOrFail()->load('positions');
        $this->assertSame('750.00', (string) $calculation->media_gross);
        $this->assertSame(CalculationKind::SwfContest, $calculation->positions[0]->kind);
        $this->assertSame('swf_contest', $calculation->positions[0]->engine_profile_key);
        $this->assertSame('average', $calculation->positions[0]->calculation_method_key);
        $this->assertSame(25, $calculation->positions[0]->length_seconds);
        $this->assertEqualsWithDelta(50.0, (float) $calculation->positions[0]->surcharge_percent, 0.00001);

        $this->actingAs($user)->get(route('calculations.edit', $calculation))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('calculation.positions.0.length_seconds', 25));

        $result = app(DispoOrderWriter::class)->createFromCalculation(
            $calculation,
            [$calculation->positions[0]->id],
            $user,
        );
        $dispo = $result->order->positions()->first();
        $this->assertInstanceOf(DispoOrderPosition::class, $dispo);
        $this->assertSame(25, $dispo->length_seconds);
        $this->assertSame('750.00', (string) $dispo->media_gross);
        $this->assertSame(CalculationKind::SwfContest, $dispo->kind);
        $this->assertEqualsWithDelta(50.0, (float) $dispo->surcharge_percent, 0.00001);
    }

    public function test_variable_length_uses_position_length_with_admin_surcharge(): void
    {
        $catalog = $this->createContestAverageCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $payload = $this->contestPayload([
            $this->contestPosition($catalog['a'], $catalog['contest'], 10, [
                'length_seconds' => 30,
                'surcharge_percent' => '99',
            ]),
        ]);

        $preview = $this->actingAs($user)->postJson(route('calculations.preview'), $payload);
        $preview->assertOk();
        // 10 × 2,00 × 30 × 1,50 = 900,00; Client-Aufschlag wird ignoriert
        $this->assertSame('900.00', $preview->json('totals.media_gross'));
        $this->assertSame(100, $preview->json('totals.positions.0.length_index'));

        $this->actingAs($user)->post(route('calculations.store'), $payload)->assertRedirect();
        $position = Calculation::query()->firstOrFail()->positions()->firstOrFail();
        $this->assertSame(30, $position->length_seconds);
        $this->assertEqualsWithDelta(50.0, (float) $position->surcharge_percent, 0.00001);
    }

    public function test_admin_deviation_30_and_55_on_new_calculation(): void
    {
        $catalog = $this->createContestAverageCatalog();
        $catalog['ruleA']->update([
            'default_length_seconds' => 30,
            'surcharge_percent' => '55',
        ]);
        $user = User::factory()->role(Role::Sales)->create();
        $payload = $this->contestPayload([
            $this->contestPosition($catalog['a'], $catalog['contest'], 10),
        ]);

        $preview = $this->actingAs($user)->postJson(route('calculations.preview'), $payload);
        $preview->assertOk();
        // 10 × 2,00 × 30 × 1,55 = 930,00
        $this->assertSame('930.00', $preview->json('totals.media_gross'));
    }

    public function test_discount_and_ae_follow_swf_contract(): void
    {
        $catalog = $this->createContestAverageCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $payload = $this->contestPayload([
            $this->contestPosition($catalog['a'], $catalog['contest'], 10, [
                'position_discount_percent' => '10',
                'ae_percent' => '15',
            ]),
        ], ['order_discount_percent' => '5', 'ae_enabled' => true]);

        // Mediabrutto 750,00 → −10 % = 675,00 → −5 % = 641,25 → AE 15 % = 96,19 → N/N 545,06
        $preview = $this->actingAs($user)->postJson(route('calculations.preview'), $payload);
        $preview->assertOk();
        $this->assertSame('750.00', $preview->json('totals.media_gross'));
        $this->assertSame('641.25', $preview->json('totals.positions.0.after_order_discount'));
        $this->assertSame('96.19', $preview->json('totals.positions.0.ae_amount'));
        $this->assertSame('545.06', $preview->json('totals.positions.0.nn_invest'));
    }

    public function test_fail_closed_for_invalid_surcharge_length_calendar_settlement(): void
    {
        $catalog = $this->createContestAverageCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $base = $this->contestPosition($catalog['a'], $catalog['contest'], 10);

        foreach (['null', '0', '-10'] as $bad) {
            if ($bad === 'null') {
                $catalog['ruleA']->update(['surcharge_percent' => null]);
            } else {
                $catalog['ruleA']->update(['surcharge_percent' => $bad]);
            }
            $this->actingAs($user)->postJson(route('calculations.preview'), $this->contestPayload([$base]))
                ->assertStatus(422);
        }
        $catalog['ruleA']->update(['surcharge_percent' => '50']);

        $catalog['ruleA']->update(['default_length_seconds' => null]);
        $this->actingAs($user)->postJson(route('calculations.preview'), $this->contestPayload([
            $this->contestPosition($catalog['a'], $catalog['contest'], 10, ['length_seconds' => 25]),
        ]))->assertStatus(422);

        $catalog['ruleA']->update(['default_length_seconds' => 0]);
        $this->actingAs($user)->postJson(route('calculations.preview'), $this->contestPayload([
            $this->contestPosition($catalog['a'], $catalog['contest'], 10, ['length_seconds' => 25]),
        ]))->assertStatus(422);
        $catalog['ruleA']->update(['default_length_seconds' => 25]);

        $this->actingAs($user)->postJson(route('calculations.preview'), $this->contestPayload([
            $this->contestPosition($catalog['c'], $catalog['contest'], 10, ['length_seconds' => 25]),
        ]))->assertStatus(422);

        // PO-SWF-SETTLEMENT-1: Settlement freigegeben; Produktion/Komponenten bleiben OUT.
        $settled = $this->actingAs($user)->postJson(route('calculations.preview'), $this->contestPayload([
            array_merge($base, ['pricing_settlement_mode' => 'fixed_price', 'fixed_price_nn' => '500.00']),
        ]));
        $settled->assertOk();
        $this->assertSame('750.00', $settled->json('totals.media_gross'));
        $this->assertSame('500.00', $settled->json('totals.positions.0.nn_invest'));

        $this->actingAs($user)->postJson(route('calculations.preview'), $this->contestPayload([
            array_merge($base, ['production_lines' => [[
                'production_type' => 'spot_production',
                'quantity' => 1,
            ]]]),
        ]))->assertStatus(422);

        $this->actingAs($user)->postJson(route('calculations.preview'), $this->contestPayload([
            array_merge($base, ['components' => [
                ['role' => 'main_spot', 'label' => 'Hauptspot', 'length_seconds' => 15, 'sort' => 0],
                ['role' => 'allonge', 'label' => 'Allonge', 'length_seconds' => 5, 'sort' => 1],
            ]]),
        ]))->assertStatus(422);
    }

    public function test_snapshot_stays_when_live_config_changes(): void
    {
        $catalog = $this->createContestAverageCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $this->actingAs($user)->post(route('calculations.store'), $this->contestPayload([
            $this->contestPosition($catalog['a'], $catalog['contest'], 10),
        ]))->assertRedirect();

        $calculation = Calculation::query()->firstOrFail();
        $result = app(DispoOrderWriter::class)->createFromCalculation(
            $calculation,
            [$calculation->positions[0]->id],
            $user,
        );
        $dispo = $result->order->positions()->firstOrFail();

        $catalog['ruleA']->update([
            'default_length_seconds' => 30,
            'surcharge_percent' => '55',
        ]);

        $writerPayload = app(CalculationWriter::class)->payloadFromCalculation(
            $calculation->fresh(['positions.planRows', 'positions.timeRanges', 'positions.discounts', 'orderDiscounts', 'configurationSnapshot', 'fieldValues']),
        );
        $writerPayload['lock_version'] = $calculation->lock_version;
        $writerPayload['positions'][0]['length_seconds'] = 25;
        $this->actingAs($user)->put(route('calculations.update', $calculation), $writerPayload)->assertRedirect();

        $this->assertSame('750.00', (string) $dispo->fresh()->media_gross);
        $this->assertSame('750.00', (string) $calculation->fresh()->media_gross);
        $this->assertEqualsWithDelta(50.0, (float) $calculation->fresh()->positions()->first()->surcharge_percent, 0.00001);

        $again = $this->actingAs($user)->postJson(route('calculations.preview'), $this->contestPayload([
            $this->contestPosition($catalog['a'], $catalog['contest'], 10),
        ]));
        $again->assertOk();
        // 10 × 2,00 × 30 × 1,55 = 930,00
        $this->assertSame('930.00', $again->json('totals.media_gross'));
    }

    public function test_missing_price_list_is_fail_closed(): void
    {
        $catalog = $this->createContestAverageCatalog();
        $catalog['listA']->delete();
        $user = User::factory()->role(Role::Sales)->create();

        $this->actingAs($user)->postJson(route('calculations.preview'), $this->contestPayload([
            $this->contestPosition($catalog['a'], $catalog['contest'], 10),
        ]))->assertStatus(422);
    }
}
