<?php

namespace Tests\Feature\Calculation;

use App\Enums\DispoOrderStatus;
use App\Enums\Role;
use App\Models\Calculation;
use App\Models\DispoOrder;
use App\Models\User;
use App\Services\Calculation\CalculationWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesSavedCalculation;
use Tests\Concerns\CreatesSpotClassicCatalog;
use Tests\TestCase;

/**
 * PO-CALC-DISPO-HINT-1 Folgeslice: Wizard-Isolationsbanner (DSP-003).
 */
class CalcDispoIsolationBannerTest extends TestCase
{
    use CreatesSavedCalculation;
    use CreatesSpotClassicCatalog;
    use RefreshDatabase;

    public function test_wizard_without_dispo_has_no_linked_orders_prop(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $calculation = $this->createSavedCalculation($catalog, [
            ['inventory_id' => $catalog['hamburg']->id],
        ]);
        $user = User::factory()->role(Role::Sales)->create();

        $this->actingAs($user)
            ->get(route('calculations.edit', $calculation))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('calculations/wizard')
                ->where('linkedDispoOrders', null));
    }

    public function test_wizard_with_one_dispo_exposes_singular_link_without_database_id(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $calculation = $this->createSavedCalculation($catalog, [
            ['inventory_id' => $catalog['hamburg']->id],
        ]);
        $user = User::factory()->role(Role::Sales)->create();
        $positionId = (int) $calculation->positions()->first()->id;

        $this->actingAs($user)->post(route('dispo-orders.store', $calculation), [
            'position_ids' => [$positionId],
        ])->assertRedirect();

        $order = DispoOrder::query()->firstOrFail();

        $this->actingAs($user)
            ->get(route('calculations.edit', $calculation))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('calculations/wizard')
                ->where('linkedDispoOrders.count', 1)
                ->has('linkedDispoOrders.orders', 1)
                ->where('linkedDispoOrders.orders.0.number', $order->number)
                ->where(
                    'linkedDispoOrders.orders.0.show_url',
                    route('dispo-orders.show', $order),
                )
                ->missing('linkedDispoOrders.orders.0.id'));
    }

    public function test_wizard_with_multiple_dispos_exposes_all_numbers_including_completed(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $calculation = $this->createSavedCalculation($catalog, [
            ['inventory_id' => $catalog['hamburg']->id],
        ]);
        $user = User::factory()->role(Role::Sales)->create();
        $positionId = (int) $calculation->positions()->first()->id;

        $this->actingAs($user)->post(route('dispo-orders.store', $calculation), [
            'position_ids' => [$positionId],
        ]);
        $this->actingAs($user)->post(route('dispo-orders.store', $calculation), [
            'position_ids' => [$positionId],
        ]);

        $orders = DispoOrder::query()
            ->where('calculation_id', $calculation->id)
            ->orderBy('number_calc_seq')
            ->get();
        $this->assertCount(2, $orders);

        $orders[1]->forceFill(['status' => DispoOrderStatus::Completed])->save();

        $this->actingAs($user)
            ->get(route('calculations.edit', $calculation))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('linkedDispoOrders.count', 2)
                ->has('linkedDispoOrders.orders', 2)
                ->where('linkedDispoOrders.orders.0.number', $orders[0]->number)
                ->where('linkedDispoOrders.orders.1.number', $orders[1]->number));
    }

    public function test_calc_update_after_dispo_keeps_order_snapshot_and_banner(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $calculation = $this->createSavedCalculation($catalog, [
            ['inventory_id' => $catalog['hamburg']->id],
        ]);
        $user = User::factory()->role(Role::Sales)->create();
        $positionId = (int) $calculation->positions()->first()->id;

        $this->actingAs($user)->post(route('dispo-orders.store', $calculation), [
            'position_ids' => [$positionId],
        ]);

        $order = DispoOrder::query()->firstOrFail();
        $originalNn = (string) $order->nn_invest;
        $originalCustomer = $order->customer_name;
        $orderNumber = $order->number;

        $payload = $this->writerPayloadFromCalculation($calculation);
        $payload['customer_name'] = 'Geändert Isolation Banner';
        $payload['customer_account_id'] = null;
        $payload['customer_create_provisional'] = true;
        $payload['lock_version'] = $calculation->lock_version;

        $this->actingAs($user)
            ->put(route('calculations.update', $calculation), $payload)
            ->assertRedirect(route('calculations.edit', $calculation));

        $order->refresh();
        $this->assertSame($originalCustomer, $order->customer_name);
        $this->assertSame($originalNn, (string) $order->nn_invest);

        $calculation->refresh();
        $this->assertSame('Geändert Isolation Banner', $calculation->customer_name);

        $this->actingAs($user)
            ->get(route('calculations.edit', $calculation))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('linkedDispoOrders.count', 1)
                ->where('linkedDispoOrders.orders.0.number', $orderNumber));
    }

    public function test_linked_orders_omit_links_when_dispo_view_denied(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $calculation = $this->createSavedCalculation($catalog, [
            ['inventory_id' => $catalog['hamburg']->id],
        ]);
        $sales = User::factory()->role(Role::Sales)->create();
        $positionId = (int) $calculation->positions()->first()->id;

        $this->actingAs($sales)->post(route('dispo-orders.store', $calculation), [
            'position_ids' => [$positionId],
        ]);

        Gate::before(function ($user, string $ability, array $arguments) {
            if ($ability === 'viewAny' && ($arguments[0] ?? null) === DispoOrder::class) {
                return false;
            }

            if ($ability === 'view' && ($arguments[0] ?? null) instanceof DispoOrder) {
                return false;
            }

            return null;
        });

        $this->actingAs($sales)
            ->get(route('calculations.edit', $calculation))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('linkedDispoOrders.count', 1)
                ->where('linkedDispoOrders.orders', []));
    }

    /**
     * @return array<string, mixed>
     */
    private function writerPayloadFromCalculation(Calculation $calculation): array
    {
        $calculation->loadMissing([
            'positions.planRows',
            'positions.timeRanges',
            'positions.plannerEntries',
            'positions.components',
            'positions.discounts',
            'positions.productionLines',
            'orderDiscounts',
        ]);

        return app(CalculationWriter::class)->payloadFromCalculation($calculation);
    }
}
