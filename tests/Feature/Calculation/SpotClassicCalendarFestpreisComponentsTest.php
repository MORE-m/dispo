<?php

namespace Tests\Feature\Calculation;

use App\Enums\ComponentCalculationStrategy;
use App\Enums\PriceListStatus;
use App\Enums\Role;
use App\Models\InventoryMediumRule;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\User;
use App\Services\Calculation\CalculationWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesSpotClassicCatalog;
use Tests\TestCase;

/**
 * BL-P4-03j: Calc-Parity Calendar × fixed_price × Hauptspot+Allonge (beide Strategien).
 */
class SpotClassicCalendarFestpreisComponentsTest extends TestCase
{
    use CreatesSpotClassicCatalog;
    use RefreshDatabase;

    public function test_calendar_fixed_price_shared_total_length_create_and_reload(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $this->setRuleStrategy($catalog, ComponentCalculationStrategy::SharedTotalLength);
        $this->setSecondPrice($catalog, '2.0000');

        $payload = $this->calendarFestpreisComponentsPayload(
            $catalog,
            ComponentCalculationStrategy::SharedTotalLength,
            '500.00',
        );

        $writer = app(CalculationWriter::class);
        $calc = $writer->create($payload, $user);
        $position = $calc->positions->firstOrFail();

        $this->assertSame('600.00', (string) $calc->media_gross);
        $this->assertSame('500.00', (string) $calc->nn_invest);
        $this->assertSame('fixed_price', $position->pricing_settlement_mode->value);
        $this->assertSame('500.00', (string) $position->fixed_price_nn);
        $this->assertSame('shared_total_length', $position->component_calculation_strategy);
        $this->assertCount(2, $position->components);
        $this->assertSame(
            ['', ''],
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
        $this->assertSame('calendar', $reloaded['positions'][0]['spot_method']);
        $this->assertSame('fixed_price', $reloaded['positions'][0]['pricing_settlement_mode']);
        $this->assertSame('500.00', (string) $reloaded['positions'][0]['fixed_price_nn']);
        $this->assertSame('shared_total_length', $reloaded['positions'][0]['component_calculation_strategy']);
        $this->assertCount(2, $reloaded['positions'][0]['components']);
        $this->assertSame('600.00', (string) $calc->fresh()->media_gross);
        $this->assertSame('500.00', (string) $calc->fresh()->nn_invest);
    }

    public function test_calendar_fixed_price_individual_create_and_reload(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $this->setRuleStrategy($catalog, ComponentCalculationStrategy::Individual);
        $this->setSecondPrice($catalog, '2.0000');

        $payload = $this->calendarFestpreisComponentsPayload(
            $catalog,
            ComponentCalculationStrategy::Individual,
            '520.00',
        );

        $writer = app(CalculationWriter::class);
        $calc = $writer->create($payload, $user);
        $position = $calc->positions->firstOrFail();

        $this->assertSame('640.00', (string) $calc->media_gross);
        $this->assertSame('520.00', (string) $calc->nn_invest);
        $this->assertSame('fixed_price', $position->pricing_settlement_mode->value);
        $this->assertSame('520.00', (string) $position->fixed_price_nn);
        $this->assertSame('individual', $position->component_calculation_strategy);
        $this->assertCount(2, $position->components);
        $this->assertSame(
            ['420.00', '220.00'],
            $position->components->sortBy('sort')->values()->map(fn ($c) => (string) $c->media_gross)->all(),
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
        $this->assertSame('calendar', $reloaded['positions'][0]['spot_method']);
        $this->assertSame('fixed_price', $reloaded['positions'][0]['pricing_settlement_mode']);
        $this->assertSame('520.00', (string) $reloaded['positions'][0]['fixed_price_nn']);
        $this->assertSame('individual', $reloaded['positions'][0]['component_calculation_strategy']);
        $this->assertCount(2, $reloaded['positions'][0]['components']);
        $this->assertSame('640.00', (string) $calc->fresh()->media_gross);
        $this->assertSame('520.00', (string) $calc->fresh()->nn_invest);
        $freshComponents = $calc->fresh(['positions.components'])->positions->first()->components->sortBy('sort')->values();
        $this->assertSame('420.00', (string) $freshComponents[0]->media_gross);
        $this->assertSame('220.00', (string) $freshComponents[1]->media_gross);
    }

    /**
     * @param  array{hamburg: mixed, medium: mixed}  $catalog
     * @return array<string, mixed>
     */
    private function calendarFestpreisComponentsPayload(
        array $catalog,
        ComponentCalculationStrategy $strategy,
        string $fixedPriceNn,
    ): array {
        return $this->withLiveSchemaFingerprint([
            'planning_mode' => 'manual',
            'customer_name' => 'Calc 03j',
            'order_discount_percent' => '0',
            'ae_enabled' => false,
            'order_discounts' => [],
            'dynamic_field_values' => [],
            'positions' => [[
                'client_key' => 'cal-fp-comp',
                'inventory_id' => $catalog['hamburg']->id,
                'advertising_medium_id' => $catalog['medium']->id,
                'spot_method' => 'calendar',
                'calculation_method_key' => 'calendar',
                'length_seconds' => 30,
                'position_discount_percent' => '0',
                'ae_percent' => '0',
                'pricing_settlement_mode' => 'fixed_price',
                'fixed_price_nn' => $fixedPriceNn,
                'component_calculation_strategy' => $strategy->value,
                'components' => [
                    ['role' => 'main_spot', 'label' => 'Hauptspot', 'length_seconds' => 20, 'sort' => 0],
                    ['role' => 'allonge', 'label' => 'Allonge', 'length_seconds' => 10, 'sort' => 1],
                ],
                'planner_entries' => [
                    ['date' => '2026-03-02', 'hour' => 8, 'spot_count' => 10],
                ],
                'plan_rows' => [],
                'time_ranges' => [],
                'position_discounts' => [],
                'dynamic_field_values' => ['period_open' => true],
            ]],
        ]);
    }

    /**
     * @param  array{hamburg: mixed, medium: mixed}  $catalog
     */
    private function setRuleStrategy(array $catalog, ComponentCalculationStrategy $strategy): void
    {
        InventoryMediumRule::query()
            ->where('inventory_id', $catalog['hamburg']->id)
            ->where('advertising_medium_id', $catalog['medium']->id)
            ->update(['component_calculation_strategy' => $strategy->value]);
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

        PriceListItem::query()
            ->where('price_list_id', $list->id)
            ->update(['second_price' => $price]);
    }
}
