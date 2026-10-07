<?php

namespace Tests\Feature\Calculation;

use App\Enums\ComponentCalculationStrategy;
use App\Enums\PriceListStatus;
use App\Enums\Role;
use App\Models\AdvertisingMedium;
use App\Models\InventoryMediumRule;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\User;
use App\Services\Calculation\CalculationWriter;
use App\Services\DynamicField\ConfigurationSnapshotFreezeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesSpotClassicCatalog;
use Tests\TestCase;

/**
 * BL-P4-03k: Calc-Parity Calendar × Tandem/Tridem × normal/fixed_price.
 */
class SpotClassicCalendarTandemTridemTest extends TestCase
{
    use CreatesSpotClassicCatalog;
    use RefreshDatabase;

    /** @var array{tandem: AdvertisingMedium, tridem: AdvertisingMedium} */
    private array $profileMedia = [];

    /** @var array{hamburg: mixed, medium: mixed} */
    private array $catalog = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->catalog = $this->createSpotClassicCatalog();
        $this->profileMedia['tandem'] = $this->attachProfileMedium(
            $this->catalog,
            AdvertisingMedium::factory()->tandem()->create(['code' => 'tandem_cal_'.uniqid()]),
        );
        $this->profileMedia['tridem'] = $this->attachProfileMedium(
            $this->catalog,
            AdvertisingMedium::factory()->tridem()->create(['code' => 'tridem_cal_'.uniqid()]),
        );
        $this->setSecondPrice($this->catalog, '2.0000');
    }

    public function test_calendar_tandem_normal_create_preview_and_reload(): void
    {
        $this->assertCalendarProfileRoundtrip(
            'tandem',
            'normal',
            null,
            '600.00',
            '600.00',
            ['', ''],
        );
    }

    public function test_calendar_tandem_fixed_price_create_preview_and_reload(): void
    {
        $this->assertCalendarProfileRoundtrip(
            'tandem',
            'fixed_price',
            '888.50',
            '600.00',
            '888.50',
            ['', ''],
        );
    }

    public function test_calendar_tridem_normal_create_preview_and_reload(): void
    {
        $this->assertCalendarProfileRoundtrip(
            'tridem',
            'normal',
            null,
            '760.00',
            '760.00',
            ['', '', ''],
        );
    }

    public function test_calendar_tridem_fixed_price_create_preview_and_reload(): void
    {
        $this->assertCalendarProfileRoundtrip(
            'tridem',
            'fixed_price',
            '1200.00',
            '760.00',
            '1200.00',
            ['', '', ''],
        );
    }

    /**
     * @param  list<string>  $expectedComponentGrosses
     */
    private function assertCalendarProfileRoundtrip(
        string $profile,
        string $mode,
        ?string $fixedNn,
        string $expectedMediaGross,
        string $expectedNnInvest,
        array $expectedComponentGrosses,
    ): void {
        $user = User::factory()->role(Role::Sales)->create();
        $writer = app(CalculationWriter::class);
        $medium = $this->profileMedia[$profile];
        $components = $profile === 'tandem' ? $this->tandemComponents() : $this->tridemComponents();
        $length = $profile === 'tandem' ? 30 : 40;

        $payload = $this->calendarProfilePayload($medium, $profile, $components, $length, $mode, $fixedNn, [
            ['date' => '2026-03-02', 'hour' => 8, 'spot_count' => 10],
        ]);

        $preview = $writer->preview($payload, $user)->toArray();
        $this->assertSame($expectedMediaGross, $preview['media_gross']);
        $this->assertSame($expectedNnInvest, $preview['nn_invest']);

        $calc = $writer->create($payload, $user);
        $position = $calc->positions->firstOrFail();
        $this->assertSame($expectedMediaGross, (string) $calc->media_gross);
        $this->assertSame($expectedNnInvest, (string) $calc->nn_invest);
        $this->assertSame($profile, $position->component_profile?->value);
        $this->assertSame('shared_total_length', $position->component_calculation_strategy);
        $this->assertSame($mode, $position->pricing_settlement_mode->value);
        if ($fixedNn !== null) {
            $this->assertSame($fixedNn, (string) $position->fixed_price_nn);
        }
        $this->assertCount(count($expectedComponentGrosses), $position->components);
        $this->assertSame(
            $expectedComponentGrosses,
            $position->components->sortBy('sort')->values()->map(fn ($c) => (string) ($c->media_gross ?? ''))->all(),
        );

        $reloaded = $writer->payloadFromCalculation($calc->fresh([
            'positions',
            'positions.components',
            'positions.plannerEntries',
            'positions.planRows',
            'positions.timeRanges',
            'positions.discounts',
            'orderDiscounts',
        ]));
        $reloadPreview = $writer->preview($reloaded, $user)->toArray();
        $this->assertSame($expectedMediaGross, $reloadPreview['media_gross']);
        $this->assertSame($expectedNnInvest, $reloadPreview['nn_invest']);
        $this->assertSame('calendar', $reloaded['positions'][0]['spot_method']);
        $this->assertSame($profile, $reloaded['positions'][0]['component_profile'] ?? null);
        $this->assertSame($mode, $reloaded['positions'][0]['pricing_settlement_mode']);
    }

    /**
     * @param  array{hamburg: mixed}  $catalog
     */
    private function attachProfileMedium(array $catalog, AdvertisingMedium $medium): AdvertisingMedium
    {
        InventoryMediumRule::factory()->create([
            'inventory_id' => $catalog['hamburg']->id,
            'advertising_medium_id' => $medium->id,
            'component_calculation_strategy' => ComponentCalculationStrategy::SharedTotalLength,
        ]);

        return $medium;
    }

    /**
     * @param  list<array{role: string, label: string, length_seconds: int, sort: int}>  $components
     * @param  list<array{date: string, hour: int, spot_count: int}>  $entries
     * @return array<string, mixed>
     */
    private function calendarProfilePayload(
        AdvertisingMedium $medium,
        string $profile,
        array $components,
        int $lengthSeconds,
        string $mode,
        ?string $fixedNn,
        array $entries,
    ): array {
        $position = [
            'inventory_id' => $this->catalog['hamburg']->id,
            'advertising_medium_id' => $medium->id,
            'schema_fingerprint' => app(ConfigurationSnapshotFreezeService::class)
                ->resolveLivePositionSchema((int) $medium->id)['schema_fingerprint'],
            'spot_method' => 'calendar',
            'calculation_method_key' => 'calendar',
            'length_seconds' => $lengthSeconds,
            'position_discount_percent' => '0',
            'ae_percent' => '0',
            'pricing_settlement_mode' => $mode,
            'component_profile' => $profile,
            'component_calculation_strategy' => ComponentCalculationStrategy::SharedTotalLength->value,
            'components' => $components,
            'planner_entries' => $entries,
            'plan_rows' => [],
            'time_ranges' => [],
            'position_discounts' => [],
            'dynamic_field_values' => ['period_open' => true],
        ];
        if ($fixedNn !== null) {
            $position['fixed_price_nn'] = $fixedNn;
        }

        return [
            'planning_mode' => 'manual',
            'customer_name' => 'Calendar Profile GmbH',
            'schema_fingerprint' => app(ConfigurationSnapshotFreezeService::class)
                ->resolveLiveSchemaForCalculationV3()['schema_fingerprint'],
            'order_discount_percent' => '0',
            'ae_enabled' => false,
            'order_discounts' => [],
            'dynamic_field_values' => [],
            'positions' => [$position],
        ];
    }

    /**
     * @return list<array{role: string, label: string, length_seconds: int, sort: int}>
     */
    private function tandemComponents(): array
    {
        return [
            ['role' => 'main_spot', 'label' => 'Hauptspot', 'length_seconds' => 20, 'sort' => 1],
            ['role' => 'reminder', 'label' => 'Reminder', 'length_seconds' => 10, 'sort' => 2],
        ];
    }

    /**
     * @return list<array{role: string, label: string, length_seconds: int, sort: int}>
     */
    private function tridemComponents(): array
    {
        return [
            ['role' => 'main_spot', 'label' => 'Hauptspot', 'length_seconds' => 20, 'sort' => 1],
            ['role' => 'reminder', 'label' => 'Reminder', 'length_seconds' => 10, 'sort' => 2],
            ['role' => 'reminder', 'label' => 'Reminder', 'length_seconds' => 10, 'sort' => 3],
        ];
    }

    /**
     * @param  array{hamburg: mixed}  $catalog
     */
    private function setSecondPrice(array $catalog, string $price): void
    {
        $list = PriceList::query()
            ->where('inventory_id', $catalog['hamburg']->id)
            ->where('status', PriceListStatus::Active)
            ->where('year', 2026)
            ->firstOrFail();
        PriceListItem::query()->where('price_list_id', $list->id)->update(['second_price' => $price]);
    }
}
