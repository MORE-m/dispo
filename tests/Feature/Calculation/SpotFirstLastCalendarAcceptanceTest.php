<?php

namespace Tests\Feature\Calculation;

use App\Enums\Role;
use App\Models\Calculation;
use App\Models\User;
use App\Services\Calculation\CalculationWriter;
use App\Services\DispoOrder\DispoOrderWriter;
use App\Services\DynamicField\ConfigurationSnapshotFreezeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesSpotFirstLastCatalog;
use Tests\TestCase;

/**
 * PO-SPOT-SURCHARGE-1: Erst/Letzt × Calendar auf spot_classic (+30 %, Snapshot).
 */
class SpotFirstLastCalendarAcceptanceTest extends TestCase
{
    use CreatesSpotFirstLastCatalog;
    use RefreshDatabase;

    /**
     * @param  list<array{date: string, hour: int, spot_count: int}>  $entries
     * @return array<string, mixed>
     */
    private function calendarPayload(
        array $catalog,
        int $mediumId,
        array $entries,
        int $lengthSeconds = 30,
    ): array {
        $fingerprint = app(ConfigurationSnapshotFreezeService::class)
            ->resolveLiveSchemaForCalculationV3()['schema_fingerprint'];
        $positionFingerprint = app(ConfigurationSnapshotFreezeService::class)
            ->resolveLivePositionSchema($mediumId)['schema_fingerprint'];

        return [
            'planning_mode' => 'manual',
            'order_discount_percent' => '0',
            'schema_fingerprint' => $fingerprint,
            'positions' => [[
                'inventory_id' => $catalog['hamburg']->id,
                'advertising_medium_id' => $mediumId,
                'schema_fingerprint' => $positionFingerprint,
                'spot_method' => 'calendar',
                'calculation_method_key' => 'calendar',
                'length_seconds' => $lengthSeconds,
                'position_discount_percent' => '0',
                'ae_percent' => '0',
                'planner_entries' => $entries,
            ]],
        ];
    }

    public function test_first_calendar_preview_matches_store_with_thirty_percent(): void
    {
        $catalog = $this->createSpotFirstLastCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $payload = $this->calendarPayload($catalog, $catalog['first']->id, [
            ['date' => '2026-09-14', 'hour' => 8, 'spot_count' => 10],
            ['date' => '2026-09-14', 'hour' => 14, 'spot_count' => 5],
        ]);

        $writer = app(CalculationWriter::class);
        $preview = $writer->preview($payload, $user)->toArray();
        $calculation = $writer->create($payload, $user);
        $position = $calculation->positions()->firstOrFail();

        $this->assertSame('calendar', $position->calculation_method_key);
        $this->assertSame(15, $position->total_spot_count);
        $this->assertEqualsWithDelta(30.0, (float) $position->surcharge_percent, 0.00001);
        $this->assertSame($preview['media_gross'], (string) $calculation->media_gross);
        $this->assertSame($preview['positions'][0]['media_gross'], (string) $position->media_gross);
        // 15 × 1,00 × 30 × 1,00 × 1,30 = 585,00 (beide Stunden gleicher Preis)
        $this->assertSame('585.00', (string) $calculation->media_gross);
        $this->assertCount(2, $position->plannerEntries);
    }

    public function test_last_calendar_applies_thirty_percent_and_index(): void
    {
        $catalog = $this->createSpotFirstLastCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $payload = $this->calendarPayload(
            $catalog,
            $catalog['last']->id,
            [['date' => '2026-09-14', 'hour' => 8, 'spot_count' => 10]],
            lengthSeconds: 20,
        );

        $writer = app(CalculationWriter::class);
        $preview = $writer->preview($payload, $user)->toArray();
        $calculation = $writer->create($payload, $user);

        // 10 × 1,00 × 20 × 1,05 × 1,30 = 273,00
        $this->assertSame('273.00', $preview['media_gross']);
        $this->assertSame('273.00', (string) $calculation->media_gross);
        $this->assertEqualsWithDelta(30.0, (float) $calculation->positions()->firstOrFail()->surcharge_percent, 0.00001);
        $this->assertSame(105, $calculation->positions()->firstOrFail()->length_index);
    }

    public function test_calendar_reload_and_dispo_snapshot_retain_surcharge(): void
    {
        $catalog = $this->createSpotFirstLastCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $payload = $this->calendarPayload($catalog, $catalog['first']->id, [
            ['date' => '2026-09-19', 'hour' => 9, 'spot_count' => 3],
        ]);

        $calculation = app(CalculationWriter::class)->create($payload, $user);
        $position = $calculation->positions()->firstOrFail();
        $this->assertEqualsWithDelta(30.0, (float) $position->surcharge_percent, 0.00001);

        $roundtrip = app(CalculationWriter::class)->payloadFromCalculation(
            $calculation->fresh([
                'positions.plannerEntries',
                'positions.planRows',
                'positions.timeRanges',
                'positions.discounts',
                'orderDiscounts',
                'configurationSnapshot',
                'fieldValues',
            ]),
        );
        $this->assertSame('calendar', $roundtrip['positions'][0]['spot_method']);

        $result = app(DispoOrderWriter::class)->createFromCalculation(
            $calculation,
            [$position->id],
            $user,
        );
        $dispo = $result->order->positions()->firstOrFail();
        $this->assertEqualsWithDelta(30.0, (float) $dispo->surcharge_percent, 0.00001);

        $catalog['ruleFirstHamburg']->update(['surcharge_percent' => '80']);
        $this->assertEqualsWithDelta(30.0, (float) $dispo->fresh()->surcharge_percent, 0.00001);
        $this->assertEqualsWithDelta(
            30.0,
            (float) Calculation::query()->firstOrFail()->positions()->firstOrFail()->surcharge_percent,
            0.00001,
        );
    }
}
