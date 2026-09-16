<?php

namespace Tests\Feature\Calculation;

use App\Enums\Role;
use App\Models\AdvertisingMedium;
use App\Models\AuditEvent;
use App\Models\Calculation;
use App\Models\InventoryMediumRule;
use App\Models\PriceListItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesSpotClassicCatalog;
use Tests\TestCase;

class CalculationSnapshotBlockerTest extends TestCase
{
    use CreatesSpotClassicCatalog;
    use RefreshDatabase;

    public function test_spt_003_average_prices_remain_stable_after_resave(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $list = $catalog['hamburg']->priceLists()->firstOrFail();
        PriceListItem::query()->where('price_list_id', $list->id)->where('hour', 8)->update(['second_price' => '1.0000']);
        PriceListItem::query()->where('price_list_id', $list->id)->where('hour', 10)->update(['second_price' => '3.0000']);

        $user = User::factory()->role(Role::Sales)->create();
        $payload = $this->payload($catalog, hours: [8, 10], totalSpots: 10, length: 30);

        $this->actingAs($user)->post(route('calculations.store'), $payload);
        $calculation = Calculation::query()->firstOrFail()->load('positions.planRows');
        $position = $calculation->positions->firstOrFail();

        $this->assertSame('2.0000', (string) $position->average_second_price);
        $prices = $position->planRows->pluck('second_price')->map(fn ($p) => (string) $p)->sort()->values()->all();
        $this->assertSame(['1.0000', '3.0000'], $prices);

        $nnBefore = (string) $calculation->nn_invest;
        $mediaBefore = (string) $calculation->media_gross;

        $this->actingAs($user)->put(route('calculations.update', $calculation), [
            ...$payload,
            'lock_version' => $calculation->lock_version,
            'briefing' => 'Nur Briefing',
            'positions' => $this->positionsFromCalculation($calculation),
        ]);

        $calculation->refresh()->load('positions.planRows');
        $position = $calculation->positions->firstOrFail();
        $this->assertSame('2.0000', (string) $position->average_second_price);
        $this->assertSame($nnBefore, (string) $calculation->nn_invest);
        $this->assertSame($mediaBefore, (string) $calculation->media_gross);
        $this->assertSame(['1.0000', '3.0000'], $position->planRows->pluck('second_price')->map(fn ($p) => (string) $p)->sort()->values()->all());
    }

    public function test_pri_004_browser_price_manipulation_is_ignored(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $payload = $this->payload($catalog, hours: [8], totalSpots: 10, length: 30);
        $payload['positions'][0]['plan_rows'][0]['second_price'] = '0.0100';

        $this->actingAs($user)->post(route('calculations.store'), $payload);
        $calculation = Calculation::query()->firstOrFail()->load('positions.planRows');

        $this->assertSame('1.0000', (string) $calculation->positions->first()?->planRows->first()?->second_price);
        $this->assertSame('300.00', (string) $calculation->media_gross);

        $audit = AuditEvent::query()->where('action', 'calculation.created')->firstOrFail();
        $price = $audit->new_values['positions'][0]['plan_rows'][0]['second_price'] ?? null;
        $this->assertSame('1.0000', $price);
    }

    public function test_ver_002_inactive_rule_keeps_snapshot_on_unchanged_position(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $payload = $this->payload($catalog, hours: [8], totalSpots: 5, length: 30);

        $this->actingAs($user)->post(route('calculations.store'), $payload);
        $calculation = Calculation::query()->firstOrFail();
        $position = $calculation->positions()->firstOrFail();
        $surchargeBefore = (string) $position->surcharge_percent;

        InventoryMediumRule::query()
            ->where('inventory_id', $catalog['hamburg']->id)
            ->update([
                'is_active' => false,
                'surcharge_percent' => 99,
                'is_discountable' => false,
                'is_ae_eligible' => false,
            ]);

        $this->actingAs($user)->put(route('calculations.update', $calculation), [
            ...$payload,
            'lock_version' => $calculation->lock_version,
            'campaign' => 'Kopf',
            'positions' => $this->positionsFromCalculation($calculation->fresh(['positions.planRows'])),
        ]);

        $position->refresh();
        $this->assertSame($surchargeBefore, (string) $position->surcharge_percent);
        $this->assertTrue($position->is_discountable);
    }

    public function test_inventory_change_uses_new_price_list(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $payload = $this->payload($catalog, hours: [8], totalSpots: 5, length: 30, inventoryId: $catalog['hamburg']->id);

        $this->actingAs($user)->post(route('calculations.store'), $payload);
        $calculation = Calculation::query()->firstOrFail()->load('positions.planRows');
        $hamburgListId = $calculation->positions->first()?->price_list_id;

        $positions = $this->positionsFromCalculation($calculation);
        $positions[0]['inventory_id'] = $catalog['rock']->id;

        $this->actingAs($user)->put(route('calculations.update', $calculation), [
            ...$payload,
            'lock_version' => $calculation->lock_version,
            'positions' => $positions,
        ]);

        $calculation->refresh()->load('positions.planRows');
        $position = $calculation->positions->firstOrFail();
        $this->assertNotSame($hamburgListId, $position->price_list_id);
        $this->assertSame('2026-RAH', $position->price_list_version);
        $this->assertSame('0.8000', (string) $position->planRows->first()?->second_price);
    }

    public function test_audit_contains_persisted_positions_after_create_and_update(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $payload = $this->payload($catalog, hours: [8], totalSpots: 1, length: 30);

        $this->actingAs($user)->post(route('calculations.store'), $payload);
        $calculation = Calculation::query()->firstOrFail();
        $created = AuditEvent::query()->where('action', 'calculation.created')->firstOrFail();
        $this->assertCount(1, $created->new_values['positions']);

        $positions = $this->positionsFromCalculation($calculation->fresh(['positions.planRows']));
        $positions[] = [
            'client_key' => '550e8400-e29b-41d4-a716-446655440001',
            'inventory_id' => $catalog['rock']->id,
            'advertising_medium_id' => $catalog['medium']->id,
            'schema_fingerprint' => $this->positionSchemaFingerprintFor($calculation, (int) $catalog['medium']->id),
            'spot_method' => 'average',
            'length_seconds' => 30,
            'total_spot_count' => 2,
            'position_discount_percent' => '0',
            'ae_percent' => '0',
            'plan_rows' => [['hour' => 10, 'day_group' => 'mo_fr']],
        ];

        $this->actingAs($user)->put(route('calculations.update', $calculation), [
            ...$payload,
            'lock_version' => $calculation->lock_version,
            'positions' => $positions,
        ]);

        $updated = AuditEvent::query()->where('action', 'calculation.updated')->latest('id')->firstOrFail();
        $this->assertCount(2, $updated->new_values['positions']);
    }

    public function test_bud_008_budget_proposal_only_changes_total_spot_count(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $payload = $this->twoPositionPayload($catalog);

        $this->actingAs($user)->post(route('calculations.store'), $payload);
        $calculation = Calculation::query()->firstOrFail()->load('positions.planRows');

        $propose = $this->actingAs($user)->postJson(route('calculations.budget-propose'), [
            ...$this->budgetSpotProposalPayload($catalog, $calculation),
        ]);
        $proposalId = $propose->json('proposal.id');
        $this->assertNotNull($proposalId);

        $this->actingAs($user)->post(route('calculations.budget-apply', [
            'calculation' => $calculation,
            'proposal' => $proposalId,
        ]));

        $calculation->refresh()->load('positions.timeRanges');
        $this->assertGreaterThan(2, (int) $calculation->positions->sum('total_spot_count'));
        $this->assertTrue($calculation->positions->every(fn ($position) => $position->timeRanges->isNotEmpty()));
    }

    public function test_gen_001_sequential_create_assigns_unique_numbers(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $payload = $this->payload($catalog, hours: [8], totalSpots: 1, length: 30);

        $this->actingAs($user)->post(route('calculations.store'), $payload);
        $this->actingAs($user)->post(route('calculations.store'), $payload);

        $numbers = Calculation::query()->pluck('number')->all();
        $this->assertCount(2, $numbers);
        $this->assertSame(2, count(array_unique($numbers)));
    }

    public function test_ver_002_inactive_inventory_unchanged_position_remains_saveable(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $payload = $this->payload($catalog, hours: [8], totalSpots: 5, length: 30);

        $this->actingAs($user)->post(route('calculations.store'), $payload);
        $calculation = Calculation::query()->firstOrFail();
        $nnBefore = (string) $calculation->nn_invest;

        $catalog['hamburg']->update(['is_active' => false]);

        $this->actingAs($user)->put(route('calculations.update', $calculation), [
            ...$payload,
            'lock_version' => $calculation->lock_version,
            'briefing' => 'Historisch',
            'positions' => $this->positionsFromCalculation($calculation->fresh(['positions.planRows'])),
        ])->assertRedirect();

        $calculation->refresh();
        $this->assertSame($nnBefore, (string) $calculation->nn_invest);
    }

    public function test_ver_002_inactive_medium_unchanged_position_remains_stable(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $payload = $this->payload($catalog, hours: [8], totalSpots: 5, length: 30);

        $this->actingAs($user)->post(route('calculations.store'), $payload);
        $calculation = Calculation::query()->firstOrFail();
        $mediaBefore = (string) $calculation->media_gross;

        $catalog['medium']->update(['is_active' => false]);

        $this->actingAs($user)->put(route('calculations.update', $calculation), [
            ...$payload,
            'lock_version' => $calculation->lock_version,
            'campaign' => 'Historisch',
            'positions' => $this->positionsFromCalculation($calculation->fresh(['positions.planRows'])),
        ])->assertRedirect();

        $calculation->refresh();
        $this->assertSame($mediaBefore, (string) $calculation->media_gross);
    }

    public function test_pri_004_new_position_rejects_inactive_inventory(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $catalog['hamburg']->update(['is_active' => false]);
        $user = User::factory()->role(Role::Sales)->create();

        $this->actingAs($user)->post(route('calculations.store'), $this->payload($catalog, hours: [8], totalSpots: 1, length: 30))
            ->assertSessionHasErrors('positions');
    }

    public function test_pri_004_change_to_inactive_inventory_is_rejected(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $payload = $this->payload($catalog, hours: [8], totalSpots: 5, length: 30, inventoryId: $catalog['hamburg']->id);

        $this->actingAs($user)->post(route('calculations.store'), $payload);
        $calculation = Calculation::query()->firstOrFail();

        $catalog['rock']->update(['is_active' => false]);
        $positions = $this->positionsFromCalculation($calculation);
        $positions[0]['inventory_id'] = $catalog['rock']->id;

        $this->actingAs($user)->put(route('calculations.update', $calculation), [
            ...$payload,
            'lock_version' => $calculation->lock_version,
            'positions' => $positions,
        ])->assertSessionHasErrors('positions');
    }

    public function test_pri_004_medium_change_resolves_active_catalog_rules(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $mediumB = AdvertisingMedium::factory()->create([
            'code' => 'spot_classic_alt',
            'is_active' => true,
            'default_length_seconds' => 30,
        ]);
        InventoryMediumRule::factory()->create([
            'inventory_id' => $catalog['hamburg']->id,
            'advertising_medium_id' => $mediumB->id,
            'surcharge_percent' => 50,
        ]);

        $user = User::factory()->role(Role::Sales)->create();
        $payload = $this->payload($catalog, hours: [8], totalSpots: 5, length: 30);

        $this->actingAs($user)->post(route('calculations.store'), $payload);
        $calculation = Calculation::query()->firstOrFail();
        $this->assertSame('0.0000', (string) $calculation->positions()->first()?->surcharge_percent);

        $positions = $this->positionsFromCalculation($calculation);
        $positions[0]['advertising_medium_id'] = $mediumB->id;
        $positions = $this->withPositionSchemaFingerprints($calculation, $positions);

        $this->actingAs($user)->put(route('calculations.update', $calculation), [
            ...$payload,
            'lock_version' => $calculation->lock_version,
            'positions' => $positions,
        ])->assertRedirect();

        $calculation->refresh();
        $this->assertSame('50.0000', (string) $calculation->positions()->first()?->surcharge_percent);
    }

    public function test_spt_009_recalculates_length_index_when_length_changes(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $payload = $this->payload($catalog, hours: [8], totalSpots: 5, length: 30);

        $this->actingAs($user)->post(route('calculations.store'), $payload);
        $calculation = Calculation::query()->firstOrFail();
        $position = $calculation->positions()->firstOrFail();
        $this->assertSame(100, $position->length_index);

        $positions = $this->positionsFromCalculation($calculation);
        $positions[0]['length_seconds'] = 20;

        $this->actingAs($user)->put(route('calculations.update', $calculation), [
            ...$payload,
            'lock_version' => $calculation->lock_version,
            'positions' => $positions,
        ]);

        $position->refresh();
        $this->assertSame(105, $position->length_index);
    }

    public function test_spt_009_uses_stored_length_index_when_length_unchanged(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $payload = $this->payload($catalog, hours: [8], totalSpots: 5, length: 30);

        $this->actingAs($user)->post(route('calculations.store'), $payload);
        $calculation = Calculation::query()->firstOrFail();
        $position = $calculation->positions()->firstOrFail();
        $position->update(['length_index' => 110]);

        $this->actingAs($user)->put(route('calculations.update', $calculation), [
            ...$payload,
            'lock_version' => $calculation->lock_version,
            'positions' => $this->positionsFromCalculation($calculation->fresh(['positions.planRows'])),
        ]);
        $calculation->refresh();
        $nnBefore = (string) $calculation->nn_invest;

        $this->actingAs($user)->put(route('calculations.update', $calculation), [
            ...$payload,
            'lock_version' => $calculation->lock_version,
            'briefing' => 'Index unverändert',
            'positions' => $this->positionsFromCalculation($calculation->fresh(['positions.planRows'])),
        ]);

        $calculation->refresh();
        $position->refresh();
        $this->assertSame(110, $position->length_index);
        $this->assertSame($nnBefore, (string) $calculation->nn_invest);
    }

    public function test_bud_008_same_proposal_cannot_be_applied_twice(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $payload = $this->twoPositionPayload($catalog);

        $this->actingAs($user)->post(route('calculations.store'), $payload);
        $calculation = Calculation::query()->firstOrFail();

        $propose = $this->actingAs($user)->postJson(route('calculations.budget-propose'), [
            ...$this->budgetSpotProposalPayload($catalog, $calculation),
        ]);
        $proposalId = $propose->json('proposal.id');
        $this->assertNotNull($proposalId);

        $this->actingAs($user)->post(route('calculations.budget-apply', [
            'calculation' => $calculation,
            'proposal' => $proposalId,
        ]))->assertRedirect()->assertSessionHas('success');

        $this->actingAs($user)->post(route('calculations.budget-apply', [
            'calculation' => $calculation->fresh(),
            'proposal' => $proposalId,
        ]))->assertStatus(422);
    }

    public function test_pri_004_new_position_rejects_inactive_medium(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $catalog['medium']->update(['is_active' => false]);
        $user = User::factory()->role(Role::Sales)->create();

        $this->actingAs($user)->post(route('calculations.store'), $this->payload($catalog, hours: [8], totalSpots: 1, length: 30))
            ->assertSessionHasErrors('positions');
    }

    public function test_pri_004_new_position_rejects_inactive_rule(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        InventoryMediumRule::query()
            ->where('inventory_id', $catalog['hamburg']->id)
            ->update(['is_active' => false]);
        $user = User::factory()->role(Role::Sales)->create();

        $this->actingAs($user)->post(route('calculations.store'), $this->payload($catalog, hours: [8], totalSpots: 1, length: 30))
            ->assertSessionHasErrors('positions');
    }

    public function test_pri_004_change_to_inactive_medium_is_rejected(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $mediumB = AdvertisingMedium::factory()->create([
            'code' => 'spot_classic_alt',
            'is_active' => false,
            'default_length_seconds' => 30,
        ]);
        InventoryMediumRule::factory()->create([
            'inventory_id' => $catalog['hamburg']->id,
            'advertising_medium_id' => $mediumB->id,
            'is_active' => true,
        ]);

        $user = User::factory()->role(Role::Sales)->create();
        $payload = $this->payload($catalog, hours: [8], totalSpots: 5, length: 30);

        $this->actingAs($user)->post(route('calculations.store'), $payload);
        $calculation = Calculation::query()->firstOrFail();

        $positions = $this->positionsFromCalculation($calculation);
        $positions[0]['advertising_medium_id'] = $mediumB->id;
        $positions = $this->withPositionSchemaFingerprints($calculation, $positions);

        $this->actingAs($user)->put(route('calculations.update', $calculation), [
            ...$payload,
            'lock_version' => $calculation->lock_version,
            'positions' => $positions,
        ])->assertSessionHasErrors('positions');
    }

    public function test_pri_004_change_to_inactive_rule_combination_is_rejected(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $mediumB = AdvertisingMedium::factory()->create([
            'code' => 'spot_classic_alt',
            'is_active' => true,
            'default_length_seconds' => 30,
        ]);
        InventoryMediumRule::factory()->create([
            'inventory_id' => $catalog['hamburg']->id,
            'advertising_medium_id' => $mediumB->id,
            'is_active' => false,
        ]);

        $user = User::factory()->role(Role::Sales)->create();
        $payload = $this->payload($catalog, hours: [8], totalSpots: 5, length: 30);

        $this->actingAs($user)->post(route('calculations.store'), $payload);
        $calculation = Calculation::query()->firstOrFail();

        $positions = $this->positionsFromCalculation($calculation);
        $positions[0]['advertising_medium_id'] = $mediumB->id;
        $positions = $this->withPositionSchemaFingerprints($calculation, $positions);

        $this->actingAs($user)->put(route('calculations.update', $calculation), [
            ...$payload,
            'lock_version' => $calculation->lock_version,
            'positions' => $positions,
        ])->assertSessionHasErrors('positions');
    }

    public function test_ver_002_deactivated_rule_keeps_snapshot_on_unchanged_position(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $payload = $this->payload($catalog, hours: [8], totalSpots: 5, length: 30);

        $this->actingAs($user)->post(route('calculations.store'), $payload);
        $calculation = Calculation::query()->firstOrFail();
        $position = $calculation->positions()->firstOrFail();
        $surchargeBefore = (string) $position->surcharge_percent;

        InventoryMediumRule::query()
            ->where('inventory_id', $catalog['hamburg']->id)
            ->update(['is_active' => false]);

        $this->actingAs($user)->put(route('calculations.update', $calculation), [
            ...$payload,
            'lock_version' => $calculation->lock_version,
            'campaign' => 'Historisch',
            'positions' => $this->positionsFromCalculation($calculation->fresh(['positions.planRows'])),
        ])->assertRedirect();

        $position->refresh();
        $this->assertSame($surchargeBefore, (string) $position->surcharge_percent);
    }

    public function test_historical_wizard_includes_inactive_inventory_for_display(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $payload = $this->payload($catalog, hours: [8], totalSpots: 5, length: 30);

        $this->actingAs($user)->post(route('calculations.store'), $payload);
        $calculation = Calculation::query()->firstOrFail();

        $catalog['hamburg']->update(['is_active' => false]);
        $catalog['medium']->update(['is_active' => false]);
        InventoryMediumRule::query()
            ->where('inventory_id', $catalog['hamburg']->id)
            ->update(['is_active' => false]);

        $this->actingAs($user)->get(route('calculations.edit', $calculation))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('calculations/wizard')
                ->where('catalog.inventories', function ($inventories): bool {
                    $hamburg = collect($inventories)->firstWhere('name', 'Radio Hamburg');

                    return $hamburg !== null
                        && $hamburg['is_active'] === false;
                })
                ->where('catalog.media', function ($media): bool {
                    $classic = collect($media)->firstWhere('code', 'spot_classic');

                    return $classic !== null
                        && $classic['is_active'] === false;
                }));
    }

    public function test_bud_proposal_and_apply_use_snapshot_length_index(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $payload = $this->payload($catalog, hours: [8], totalSpots: 1, length: 30);

        $this->actingAs($user)->post(route('calculations.store'), $payload);
        $calculation = Calculation::query()->firstOrFail();
        $position = $calculation->positions()->firstOrFail();
        $position->update(['length_index' => 110]);

        $propose = $this->actingAs($user)->postJson(route('calculations.budget-propose'), [
            ...$this->budgetSpotProposalPayload($catalog, $calculation),
        ]);

        $usedNnSnapshot = $propose->json('proposal.used_nn');
        $proposalId = $propose->json('proposal.id');
        $this->assertNotNull($proposalId);

        $position->update(['length_index' => 100]);

        $this->actingAs($user)->post(route('calculations.budget-apply', [
            'calculation' => $calculation,
            'proposal' => $proposalId,
        ]))->assertRedirect();

        $calculation->refresh();
        $position->refresh();
        $this->assertSame($usedNnSnapshot, (string) $calculation->nn_invest);
        $this->assertGreaterThan(0, (int) $position->total_spot_count);
    }

    public function test_unimplemented_spot_method_change_is_not_treated_as_header_only(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $payload = $this->payload($catalog, hours: [8], totalSpots: 5, length: 30);

        $this->actingAs($user)->post(route('calculations.store'), $payload);
        $calculation = Calculation::query()->firstOrFail();
        $mediaBefore = (string) $calculation->media_gross;

        $positions = $this->positionsFromCalculation($calculation);
        // BL-P4-02b: calendar ist freigegeben; ungeplante/nicht implementierte Methode = fixed_price.
        $positions[0]['spot_method'] = 'fixed_price';
        $positions[0]['calculation_method_key'] = 'fixed_price';

        $this->actingAs($user)->put(route('calculations.update', $calculation), [
            ...$payload,
            'lock_version' => $calculation->lock_version,
            'briefing' => 'Nur Kopf',
            'positions' => $positions,
        ])->assertSessionHasErrors('positions');

        $calculation->refresh();
        $this->assertSame($mediaBefore, (string) $calculation->media_gross);
    }

    /**
     * @param  array{hamburg: mixed, rock: mixed, medium: mixed}  $catalog
     * @param  list<int>  $hours
     * @return array<string, mixed>
     */
    private function payload(array $catalog, array $hours, int $totalSpots, int $length, ?int $inventoryId = null): array
    {
        return $this->withLiveSchemaFingerprint([
            'planning_mode' => 'manual',
            'order_discount_percent' => '0',
            'schema_fingerprint' => $this->liveSchemaFingerprint(),
            'positions' => [[
                'inventory_id' => $inventoryId ?? $catalog['hamburg']->id,
                'advertising_medium_id' => $catalog['medium']->id,
                'spot_method' => 'average',
                'length_seconds' => $length,
                'total_spot_count' => $totalSpots,
                'position_discount_percent' => '0',
                'ae_percent' => '0',
                'plan_rows' => array_map(
                    fn (int $hour): array => ['hour' => $hour, 'day_group' => 'mo_fr'],
                    $hours,
                ),
            ]],
        ]);
    }

    /**
     * @param  array{hamburg: mixed, rock: mixed, medium: mixed}  $catalog
     * @return array<string, mixed>
     */
    private function twoPositionPayload(array $catalog): array
    {
        return $this->withLiveSchemaFingerprint([
            'planning_mode' => 'manual',
            'order_discount_percent' => '0',
            'positions' => [
                [
                    'inventory_id' => $catalog['hamburg']->id,
                    'advertising_medium_id' => $catalog['medium']->id,
                    'spot_method' => 'average',
                    'length_seconds' => 30,
                    'total_spot_count' => 1,
                    'position_discount_percent' => '0',
                    'ae_percent' => '0',
                    'plan_rows' => [['hour' => 8, 'day_group' => 'mo_fr']],
                ],
                [
                    'inventory_id' => $catalog['rock']->id,
                    'advertising_medium_id' => $catalog['medium']->id,
                    'spot_method' => 'average',
                    'length_seconds' => 30,
                    'total_spot_count' => 1,
                    'position_discount_percent' => '0',
                    'ae_percent' => '0',
                    'plan_rows' => [['hour' => 8, 'day_group' => 'mo_fr']],
                ],
            ],
        ]);
    }

    /**
     * @param  array{hamburg: mixed, rock: mixed, medium: mixed}  $catalog
     * @return array<string, mixed>
     */
    private function budgetSpotProposalPayload(array $catalog, Calculation $calculation, string $budget = '500'): array
    {
        $inventoryIds = $calculation->positions->pluck('inventory_id')->unique()->values()->all();

        return [
            'planning_mode' => 'budget',
            'target_budget_nn' => $budget,
            'budget_wish_inventory_ids' => $inventoryIds !== [] ? $inventoryIds : [$catalog['hamburg']->id],
            'budget_spot_length_seconds' => 30,
            'budget_distribution_ranges' => [
                [
                    'start_hour' => 8,
                    'end_hour_exclusive' => 12,
                    'day_group' => 'mo_fr',
                ],
            ],
            'order_discount_percent' => '0',
            'order_discounts' => [],
            'ae_enabled' => false,
            'positions' => [],
            'calculation_id' => $calculation->id,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function positionsFromCalculation(Calculation $calculation): array
    {
        $calculation->load('positions.planRows');

        return $this->withPositionSchemaFingerprints($calculation, $calculation->positions->map(fn ($position): array => [
            'id' => $position->id,
            'client_key' => $position->client_key,
            'inventory_id' => $position->inventory_id,
            'advertising_medium_id' => $position->advertising_medium_id,
            'spot_method' => $position->spot_method->value,
            'length_seconds' => $position->length_seconds,
            'total_spot_count' => $position->total_spot_count,
            'position_discount_percent' => (string) $position->position_discount_percent,
            'ae_percent' => (string) $position->ae_percent,
            'plan_rows' => $position->planRows->map(fn ($row): array => [
                'hour' => $row->hour,
                'day_group' => $row->day_group->value,
            ])->all(),
        ])->all());
    }
}
