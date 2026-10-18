<?php

namespace Tests\Feature\Calculation;

use App\Enums\Role;
use App\Models\Calculation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesProductionPriceLists;
use Tests\Concerns\CreatesSpotSingleCatalog;
use Tests\TestCase;

/**
 * PO-SPOT-SINGLE-1: Produktion bleibt OUT für Single (und Erst/Letzt-Leak schließen).
 *
 * Finding aus SURCHARGE-1: kind=spot_classic allein würde Erst/Letzt/Single für Produktion
 * freischalten. Guard: mediumCode === spot_classic.
 */
class SpotSingleProductionOutTest extends TestCase
{
    use CreatesProductionPriceLists;
    use CreatesSpotSingleCatalog;
    use RefreshDatabase;

    /**
     * @param  list<array<string, mixed>>  $positions
     * @return array<string, mixed>
     */
    private function payload(array $positions): array
    {
        return $this->withLiveSchemaFingerprint([
            'planning_mode' => 'manual',
            'order_discount_percent' => '0',
            'positions' => $positions,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function line(): array
    {
        return [
            'client_key' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
            'production_type' => 'spot_production',
            'label' => 'Spotproduktion',
            'quantity' => '1',
            'remark' => null,
            'sort' => 0,
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function averageWithProduction(int $inventoryId, int $mediumId, array $overrides = []): array
    {
        return array_merge([
            'inventory_id' => $inventoryId,
            'advertising_medium_id' => $mediumId,
            'spot_method' => 'average',
            'length_seconds' => 30,
            'position_discount_percent' => '0',
            'ae_percent' => '0',
            'production_lines' => [$this->line()],
            'time_ranges' => [[
                'start_hour' => 8,
                'end_hour_exclusive' => 9,
                'day_group' => 'mo_fr',
                'spot_count' => 10,
                'sort' => 0,
            ]],
        ], $overrides);
    }

    public function test_single_rejects_production_lines(): void
    {
        $catalog = $this->createSpotSingleCatalog();
        $this->createActiveProductionPriceList($catalog['hamburg'], '150.00');
        $user = User::factory()->role(Role::Sales)->create();
        $payload = $this->payload([
            $this->averageWithProduction($catalog['hamburg']->id, $catalog['single']->id),
        ]);

        $this->actingAs($user)->postJson(route('calculations.preview'), $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['positions.0.production_lines']);
        $this->actingAs($user)->post(route('calculations.store'), $payload)
            ->assertSessionHasErrors('positions.0.production_lines');
        $this->assertSame(0, Calculation::query()->count());
    }

    public function test_first_and_last_also_reject_production_lines_finding(): void
    {
        $catalog = $this->createSpotSingleCatalog();
        $this->createActiveProductionPriceList($catalog['hamburg'], '150.00');
        $user = User::factory()->role(Role::Sales)->create();

        foreach ([$catalog['first'], $catalog['last']] as $medium) {
            $payload = $this->payload([
                $this->averageWithProduction($catalog['hamburg']->id, $medium->id),
            ]);
            $this->actingAs($user)->postJson(route('calculations.preview'), $payload)
                ->assertStatus(422)
                ->assertJsonValidationErrors(['positions.0.production_lines']);
        }
    }

    public function test_classic_production_still_works(): void
    {
        $catalog = $this->createSpotSingleCatalog();
        $this->createActiveProductionPriceList($catalog['hamburg'], '150.00');
        $user = User::factory()->role(Role::Sales)->create();
        $payload = $this->payload([
            $this->averageWithProduction($catalog['hamburg']->id, $catalog['classic']->id),
        ]);

        $preview = $this->actingAs($user)->postJson(route('calculations.preview'), $payload);
        $preview->assertOk();
        $this->assertSame('150.00', $preview->json('totals.positions.0.production_gross'));
        $this->assertSame('300.00', $preview->json('totals.positions.0.media_gross'));

        $this->actingAs($user)->post(route('calculations.store'), $payload)->assertRedirect();
        $calculation = Calculation::query()->firstOrFail()->load('positions.productionLines');
        $this->assertCount(1, $calculation->positions[0]->productionLines);
        $this->assertSame('150.00', (string) $calculation->positions[0]->production_gross);
    }
}
