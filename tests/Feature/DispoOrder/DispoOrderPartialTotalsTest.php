<?php

namespace Tests\Feature\DispoOrder;

use App\Enums\Role;
use App\Models\Calculation;
use App\Models\CalculationPosition;
use App\Models\DispoOrder;
use App\Models\User;
use App\Services\Calculation\StoredPositionTotals;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesSavedCalculation;
use Tests\Concerns\CreatesSpotClassicCatalog;
use Tests\TestCase;

class DispoOrderPartialTotalsTest extends TestCase
{
    use CreatesSavedCalculation;
    use CreatesSpotClassicCatalog;
    use RefreshDatabase;

    /**
     * @return array{calculation: Calculation, first: CalculationPosition, second: CalculationPosition}
     */
    private function twoPositionCalculation(array $options = []): array
    {
        $catalog = $this->createSpotClassicCatalog();
        $calculation = $this->createSavedCalculation($catalog, [
            [
                'inventory_id' => $catalog['hamburg']->id,
                'total_spot_count' => $options['first_spots'] ?? 10,
            ],
            [
                'inventory_id' => $catalog['rock']->id,
                'total_spot_count' => $options['second_spots'] ?? 5,
                'hour' => 10,
            ],
        ], $options['user'] ?? null, $options);

        $positions = $calculation->positions()->orderBy('sort')->get();

        return [
            'calculation' => $calculation,
            'first' => $positions[0],
            'second' => $positions[1],
        ];
    }

    private function createOrder(Calculation $calculation, array $positionIds, ?User $user = null): DispoOrder
    {
        $user ??= User::factory()->role(Role::Sales)->create();

        $this->actingAs($user)->post(route('dispo-orders.store', $calculation), [
            'position_ids' => $positionIds,
        ])->assertRedirect();

        return DispoOrder::query()->latest('id')->firstOrFail();
    }

    private function assertOrderTotalsMatchPositions(DispoOrder $order, CalculationPosition ...$positions): void
    {
        $expected = StoredPositionTotals::sum(collect($positions));

        $this->assertSame($expected['media_gross'], (string) $order->media_gross);
        $this->assertSame($expected['position_discount_total'], (string) $order->position_discount_total);
        $this->assertSame($expected['order_discount_total'], (string) $order->order_discount_total);
        $this->assertSame($expected['ae_total'], (string) $order->ae_total);
        $this->assertSame($expected['nn_invest'], (string) $order->nn_invest);
    }

    public function test_first_position_only_does_not_include_second_position_amounts(): void
    {
        ['calculation' => $calculation, 'first' => $first, 'second' => $second] = $this->twoPositionCalculation();

        $order = $this->createOrder($calculation, [$first->id]);

        $this->assertOrderTotalsMatchPositions($order, $first);
        $this->assertSame((string) $first->nn_invest, (string) $order->nn_invest);
        $this->assertNotSame((string) $calculation->nn_invest, (string) $order->nn_invest);
        $this->assertSame(
            (string) $calculation->nn_invest,
            $order->source_calculation_totals_snapshot['nn_invest'],
        );
    }

    public function test_second_position_only_does_not_include_first_position_amounts(): void
    {
        ['calculation' => $calculation, 'first' => $first, 'second' => $second] = $this->twoPositionCalculation();

        $order = $this->createOrder($calculation, [$second->id]);

        $this->assertOrderTotalsMatchPositions($order, $second);
        $this->assertNotSame((string) $first->nn_invest, (string) $order->nn_invest);
        $this->assertSame((string) $second->nn_invest, (string) $order->nn_invest);
    }

    public function test_all_positions_selected_matches_calculation_totals_exactly(): void
    {
        ['calculation' => $calculation, 'first' => $first, 'second' => $second] = $this->twoPositionCalculation();
        $order = $this->createOrder($calculation, [
            $first->id,
            $second->id,
        ]);

        $this->assertSame((string) $calculation->nn_invest, (string) $order->nn_invest);
        $this->assertSame((string) $calculation->media_gross, (string) $order->media_gross);
        $this->assertSame((string) $calculation->order_discount_total, (string) $order->order_discount_total);
        $this->assertSame((string) $calculation->ae_total, (string) $order->ae_total);
    }

    public function test_partial_adoption_with_position_discount(): void
    {
        ['calculation' => $calculation, 'first' => $first] = $this->twoPositionCalculation([
            'first_position_discount_percent' => '10',
        ]);

        $order = $this->createOrder($calculation, [$first->id]);

        $this->assertOrderTotalsMatchPositions($order, $first);
        $this->assertNotSame('0.00', (string) $order->position_discount_total);
    }

    public function test_partial_adoption_with_order_discount(): void
    {
        ['calculation' => $calculation, 'first' => $first] = $this->twoPositionCalculation([
            'order_discount_percent' => '10',
        ]);

        $order = $this->createOrder($calculation, [$first->id]);

        $this->assertOrderTotalsMatchPositions($order, $first);
        $this->assertNotSame('0.00', (string) $order->order_discount_total);
        $this->assertNotSame((string) $calculation->order_discount_total, (string) $order->order_discount_total);
    }

    public function test_partial_adoption_with_ae_enabled(): void
    {
        ['calculation' => $calculation, 'first' => $first] = $this->twoPositionCalculation([
            'ae_enabled' => true,
        ]);

        $order = $this->createOrder($calculation, [$first->id]);

        $this->assertTrue($order->ae_enabled);
        $this->assertOrderTotalsMatchPositions($order, $first);
        $this->assertNotSame('0.00', (string) $order->ae_total);
        $this->assertNotSame((string) $calculation->ae_total, (string) $order->ae_total);
    }

    public function test_partial_adoption_with_position_order_and_ae_discounts(): void
    {
        ['calculation' => $calculation] = $this->twoPositionCalculation([
            'first_position_discount_percent' => '10',
            'order_discount_percent' => '5',
            'ae_enabled' => true,
        ]);
        $calculation->refresh()->load('positions');
        $first = $calculation->positions()->orderBy('sort')->firstOrFail();

        $order = $this->createOrder($calculation, [$first->id]);

        $this->assertOrderTotalsMatchPositions($order, $first);
    }

    public function test_cent_precision_with_multiple_selected_positions(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $calculation = $this->createSavedCalculation($catalog, [
            ['inventory_id' => $catalog['hamburg']->id, 'total_spot_count' => 7],
            ['inventory_id' => $catalog['rock']->id, 'total_spot_count' => 3, 'hour' => 9],
        ], null, [
            'order_discount_percent' => '7.5',
            'ae_enabled' => true,
        ]);
        $calculation->load('positions');
        $selected = $calculation->positions->all();

        $order = $this->createOrder($calculation, collect($selected)->pluck('id')->all());

        $this->assertOrderTotalsMatchPositions($order, ...$selected);
        $this->assertMatchesRegularExpression('/^\d+\.\d{2}$/', (string) $order->nn_invest);
    }

    public function test_calculation_change_does_not_alter_dispo_order_totals_or_positions(): void
    {
        ['calculation' => $calculation, 'first' => $first] = $this->twoPositionCalculation();
        $order = $this->createOrder($calculation, [$first->id]);
        $before = [
            'nn' => (string) $order->nn_invest,
            'position_nn' => (string) $order->positions->first()->nn_invest,
        ];

        $calculation->update(['nn_invest' => '9999.99', 'media_gross' => '9999.99']);
        $calculation->positions()->first()->update(['nn_invest' => '9999.99']);

        $order->refresh()->load('positions');

        $this->assertSame($before['nn'], (string) $order->nn_invest);
        $this->assertSame($before['position_nn'], (string) $order->positions->first()->nn_invest);
    }

    public function test_show_page_exposes_correct_order_and_source_totals(): void
    {
        ['calculation' => $calculation, 'first' => $first] = $this->twoPositionCalculation();
        $user = User::factory()->role(Role::Sales)->create();
        $order = $this->createOrder($calculation, [$first->id], $user);

        $this->actingAs($user)
            ->get(route('dispo-orders.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('dispo-orders/index')
                ->where('orders.0.nn_invest', (string) $first->nn_invest)
                ->where('orders.0.positions_count', 1));

        $this->actingAs($user)
            ->get(route('dispo-orders.show', $order))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('dispo-orders/show')
                ->where('order.nn_invest', (string) $first->nn_invest)
                ->where('order.source_calculation_totals.nn_invest', (string) $calculation->nn_invest)
                ->where('order.media_gross', (string) $first->media_gross));
    }
}
