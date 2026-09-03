<?php

namespace Tests\Feature\DispoOrder;

use App\Enums\DispoOrderStatus;
use App\Enums\Role;
use App\Models\AdvertisingMedium;
use App\Models\AuditEvent;
use App\Models\Calculation;
use App\Models\DispoOrder;
use App\Models\DispoOrderNumberSequence;
use App\Models\DispoOrderPosition;
use App\Models\Inventory;
use App\Models\User;
use App\Services\DispoOrder\DispoOrderWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesSavedCalculation;
use Tests\Concerns\CreatesSpotClassicCatalog;
use Tests\TestCase;

class CreateDispoOrderFromCalculationTest extends TestCase
{
    use CreatesSavedCalculation;
    use CreatesSpotClassicCatalog;
    use RefreshDatabase;

    /**
     * @return array{calculation: Calculation, catalog: array{hamburg: Inventory, rock: Inventory, medium: AdvertisingMedium}}
     */
    private function setupCalculation(): array
    {
        $catalog = $this->createSpotClassicCatalog();
        $calculation = $this->createSavedCalculation($catalog, [
            ['inventory_id' => $catalog['hamburg']->id],
            ['inventory_id' => $catalog['rock']->id, 'total_spot_count' => 5, 'hour' => 10],
        ]);

        return compact('calculation', 'catalog');
    }

    public function test_sales_can_create_dispo_order_from_calculation(): void
    {
        ['calculation' => $calculation] = $this->setupCalculation();
        $user = User::factory()->role(Role::Sales)->create();
        $positionIds = $calculation->positions()->pluck('id')->all();

        $response = $this->actingAs($user)->post(route('dispo-orders.store', $calculation), [
            'position_ids' => $positionIds,
        ]);

        $order = DispoOrder::query()->firstOrFail();
        $response->assertRedirect(route('dispo-orders.show', $order));
        $this->assertSame(DispoOrderStatus::Draft, $order->status);
    }

    public function test_admin_can_create_dispo_order(): void
    {
        ['calculation' => $calculation] = $this->setupCalculation();
        $user = User::factory()->role(Role::Admin)->create();

        $this->actingAs($user)->post(route('dispo-orders.store', $calculation), [
            'position_ids' => [$calculation->positions()->first()->id],
        ])->assertRedirect();

        $this->assertSame(1, DispoOrder::query()->count());
    }

    public function test_management_can_create_dispo_order(): void
    {
        ['calculation' => $calculation] = $this->setupCalculation();
        $user = User::factory()->role(Role::Management)->create();

        $this->actingAs($user)->post(route('dispo-orders.store', $calculation), [
            'position_ids' => [$calculation->positions()->first()->id],
        ])->assertRedirect();

        $this->assertSame(1, DispoOrder::query()->count());
    }

    public function test_disposition_can_read_but_not_create(): void
    {
        ['calculation' => $calculation] = $this->setupCalculation();
        $sales = User::factory()->role(Role::Sales)->create();
        $disposition = User::factory()->role(Role::Disposition)->create();

        $this->actingAs($sales)->post(route('dispo-orders.store', $calculation), [
            'position_ids' => [$calculation->positions()->first()->id],
        ])->assertRedirect();

        $order = DispoOrder::query()->firstOrFail();

        $this->actingAs($disposition)->get(route('dispo-orders.index'))->assertOk();
        $this->actingAs($disposition)->get(route('dispo-orders.show', $order))->assertOk();
        $this->actingAs($disposition)->post(route('dispo-orders.store', $calculation), [
            'position_ids' => [$calculation->positions()->first()->id],
        ])->assertForbidden();
    }

    public function test_product_management_is_denied(): void
    {
        ['calculation' => $calculation] = $this->setupCalculation();
        $user = User::factory()->role(Role::ProductManagement)->create();

        $this->actingAs($user)->get(route('dispo-orders.index'))->assertForbidden();
        $this->actingAs($user)->post(route('dispo-orders.store', $calculation), [
            'position_ids' => [$calculation->positions()->first()->id],
        ])->assertForbidden();
    }

    public function test_foreign_position_ids_are_rejected(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $calculation = $this->createSavedCalculation($catalog, [
            ['inventory_id' => $catalog['hamburg']->id],
        ]);
        $other = $this->createSavedCalculation($catalog, [
            ['inventory_id' => $catalog['rock']->id],
        ]);
        $user = User::factory()->role(Role::Sales)->create();

        $this->actingAs($user)->post(route('dispo-orders.store', $calculation), [
            'position_ids' => [$other->positions()->first()->id],
        ])->assertSessionHasErrors('position_ids');

        $this->assertSame(0, DispoOrder::query()->count());
    }

    public function test_at_least_one_position_is_required(): void
    {
        ['calculation' => $calculation] = $this->setupCalculation();
        $user = User::factory()->role(Role::Sales)->create();

        $this->actingAs($user)->post(route('dispo-orders.store', $calculation), [
            'position_ids' => [],
        ])->assertSessionHasErrors('position_ids');
    }

    public function test_only_selected_positions_are_copied(): void
    {
        ['calculation' => $calculation] = $this->setupCalculation();
        $user = User::factory()->role(Role::Sales)->create();
        $firstId = $calculation->positions()->orderBy('sort')->first()->id;

        $this->actingAs($user)->post(route('dispo-orders.store', $calculation), [
            'position_ids' => [$firstId],
        ])->assertRedirect();

        $order = DispoOrder::query()->with('positions')->firstOrFail();
        $this->assertCount(1, $order->positions);
        $this->assertSame($firstId, $order->positions->first()->calculation_position_id);
    }

    public function test_header_and_position_snapshots_are_persisted(): void
    {
        ['calculation' => $calculation] = $this->setupCalculation();
        $user = User::factory()->role(Role::Sales)->create();
        $position = $calculation->positions()->first();
        $position->load('inventory', 'advertisingMedium');

        $this->actingAs($user)->post(route('dispo-orders.store', $calculation), [
            'position_ids' => [$position->id],
        ])->assertRedirect();

        $order = DispoOrder::query()->with('positions')->firstOrFail();

        $this->assertSame($calculation->number, $order->source_calculation_number);
        $this->assertSame('Testkunde GmbH', $order->customer_name);
        $this->assertSame('Frühjahr 2026', $order->campaign);
        $this->assertSame((string) $position->nn_invest, (string) $order->nn_invest);
        $this->assertSame(
            (string) $calculation->nn_invest,
            $order->source_calculation_totals_snapshot['nn_invest'],
        );

        $snapshot = $order->positions->first();
        $this->assertSame($position->inventory->name, $snapshot->inventory_name);
        $this->assertSame($position->advertisingMedium->name, $snapshot->advertising_medium_name);
        $this->assertSame((string) $position->nn_invest, (string) $snapshot->nn_invest);
        $this->assertNotEmpty($snapshot->time_ranges_snapshot);
    }

    public function test_dispo_order_is_unchanged_after_calculation_update(): void
    {
        ['calculation' => $calculation, 'catalog' => $catalog] = $this->setupCalculation();
        $user = User::factory()->role(Role::Sales)->create();
        $positionId = $calculation->positions()->first()->id;

        $this->actingAs($user)->post(route('dispo-orders.store', $calculation), [
            'position_ids' => [$positionId],
        ]);

        $order = DispoOrder::query()->firstOrFail();
        $originalNn = (string) $order->nn_invest;
        $originalCustomer = $order->customer_name;

        $calculation->update([
            'customer_name' => 'Geändert',
            'nn_invest' => '9999.99',
        ]);
        $calculation->positions()->first()->update(['nn_invest' => '9999.99']);

        $order->refresh();
        $this->assertSame($originalCustomer, $order->customer_name);
        $this->assertSame($originalNn, (string) $order->nn_invest);
    }

    public function test_dispo_order_is_unchanged_after_master_data_update(): void
    {
        ['calculation' => $calculation, 'catalog' => $catalog] = $this->setupCalculation();
        $user = User::factory()->role(Role::Sales)->create();
        $positionId = $calculation->positions()->first()->id;

        $this->actingAs($user)->post(route('dispo-orders.store', $calculation), [
            'position_ids' => [$positionId],
        ]);

        $order = DispoOrder::query()->with('positions')->firstOrFail();
        $originalInventoryName = $order->positions->first()->inventory_name;

        $catalog['hamburg']->update(['name' => 'Neuer Sendername']);

        $order->refresh()->load('positions');
        $this->assertSame($originalInventoryName, $order->positions->first()->inventory_name);
    }

    public function test_multiple_orders_from_same_calculation_are_allowed(): void
    {
        ['calculation' => $calculation] = $this->setupCalculation();
        $user = User::factory()->role(Role::Sales)->create();
        $positionId = $calculation->positions()->first()->id;

        $this->actingAs($user)->post(route('dispo-orders.store', $calculation), [
            'position_ids' => [$positionId],
        ]);
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

        $this->assertCount(3, $orders);
        $this->assertSame(1, $orders->pluck('number_org_seq')->unique()->count());
        $this->assertSame([1, 2, 3], $orders->pluck('number_calc_seq')->all());

        $stem = sprintf('DA-%d-%06d', $orders[0]->number_year, $orders[0]->number_org_seq);
        $this->assertSame("{$stem}-01", $orders[0]->number);
        $this->assertSame("{$stem}-02", $orders[1]->number);
        $this->assertSame("{$stem}-03", $orders[2]->number);
    }

    public function test_adopted_positions_are_reported(): void
    {
        ['calculation' => $calculation] = $this->setupCalculation();
        $user = User::factory()->role(Role::Sales)->create();
        $positionId = $calculation->positions()->first()->id;

        $this->actingAs($user)->post(route('dispo-orders.store', $calculation), [
            'position_ids' => [$positionId],
        ]);

        $order = DispoOrder::query()->firstOrFail();

        $response = $this->actingAs($user)->getJson(route('dispo-orders.positions', $calculation));
        $response->assertOk();
        $payload = collect($response->json('positions'))->firstWhere('id', $positionId);
        $this->assertTrue($payload['already_adopted']);
        $this->assertSame($order->number, $payload['adoptions'][0]['dispo_order_number']);
    }

    public function test_numbers_are_unique_and_match_format(): void
    {
        ['calculation' => $calculation] = $this->setupCalculation();
        $user = User::factory()->role(Role::Sales)->create();
        $positionId = $calculation->positions()->first()->id;
        $year = (int) now('Europe/Berlin')->format('Y');

        $this->actingAs($user)->post(route('dispo-orders.store', $calculation), [
            'position_ids' => [$positionId],
        ]);

        $order = DispoOrder::query()->firstOrFail();
        $this->assertMatchesRegularExpression('/^DA-\d{4}-\d{6}-\d{2}$/', $order->number);
        $this->assertSame(sprintf('DA-%d-%06d-%02d', $year, 1, 1), $order->number);
    }

    public function test_audit_event_is_written(): void
    {
        ['calculation' => $calculation] = $this->setupCalculation();
        $user = User::factory()->role(Role::Sales)->create();
        $positionId = $calculation->positions()->first()->id;

        $this->actingAs($user)->post(route('dispo-orders.store', $calculation), [
            'position_ids' => [$positionId],
        ]);

        $order = DispoOrder::query()->firstOrFail();
        $event = AuditEvent::query()->where('action', 'dispo_order.created')->firstOrFail();

        $this->assertSame(DispoOrder::class, $event->auditable_type);
        $this->assertSame($order->id, $event->auditable_id);
        $this->assertSame($calculation->id, $event->new_values['calculation_id']);
        $this->assertSame($order->number, $event->new_values['number']);
        $this->assertSame([$positionId], $event->new_values['position_ids']);
    }

    public function test_failed_create_rolls_back_transaction(): void
    {
        ['calculation' => $calculation] = $this->setupCalculation();
        $user = User::factory()->role(Role::Sales)->create();
        $year = (int) now('Europe/Berlin')->format('Y');
        $seqBefore = DispoOrderNumberSequence::query()->where('year', $year)->value('last_seq') ?? 0;

        $this->actingAs($user)->post(route('dispo-orders.store', $calculation), [
            'position_ids' => [999999],
        ])->assertSessionHasErrors('position_ids');

        $seqAfter = DispoOrderNumberSequence::query()->where('year', $year)->value('last_seq') ?? 0;
        $this->assertSame($seqBefore, $seqAfter);
        $this->assertSame(0, DispoOrder::query()->count());
        $this->assertSame(0, DispoOrderPosition::query()->count());
    }

    public function test_index_and_show_respect_authorization(): void
    {
        ['calculation' => $calculation] = $this->setupCalculation();
        $sales = User::factory()->role(Role::Sales)->create();

        $this->actingAs($sales)->post(route('dispo-orders.store', $calculation), [
            'position_ids' => $calculation->positions()->pluck('id')->all(),
        ]);

        $order = DispoOrder::query()->firstOrFail();

        $this->actingAs($sales)
            ->get(route('dispo-orders.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('dispo-orders/index')
                ->has('orders', 1));

        $this->actingAs($sales)
            ->get(route('dispo-orders.show', $order))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('dispo-orders/show')
                ->where('order.number', $order->number));

        $this->actingAs(User::factory()->role(Role::ProductManagement)->create())
            ->get(route('dispo-orders.show', $order))
            ->assertForbidden();
    }

    public function test_writer_service_can_be_used_directly(): void
    {
        ['calculation' => $calculation] = $this->setupCalculation();
        $user = User::factory()->role(Role::Sales)->create();
        $positionIds = $calculation->positions()->pluck('id')->all();

        $result = app(DispoOrderWriter::class)->createFromCalculation($calculation, $positionIds, $user);

        $this->assertSame(DispoOrderStatus::Draft, $result->order->status);
        $this->assertCount(2, $result->order->positions);
    }
}
