<?php

namespace Tests\Feature\InventoryMediumRule;

use App\Enums\Role;
use App\Models\AdvertisingMedium;
use App\Models\AuditEvent;
use App\Models\CalculationPosition;
use App\Models\InventoryMediumRule;
use App\Models\User;
use App\Services\DispoOrder\DispoOrderWriter;
use App\Support\InventoryMediumRule\InventoryMediumRuleOperativeContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesSavedCalculation;
use Tests\Concerns\CreatesSpotClassicCatalog;
use Tests\TestCase;

/**
 * BL-P2-02a / MAT-CORE-1: Kombinationstabellen-Admin + Freeze/Dispo (PO-MAT-BOOKING-VIS-1 A).
 */
class CombinationAdminMatCoreTest extends TestCase
{
    use CreatesSavedCalculation;
    use CreatesSpotClassicCatalog;
    use RefreshDatabase;

    public function test_admin_can_open_combination_module_sales_forbidden(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $sales = User::factory()->role(Role::Sales)->create();

        $this->actingAs($admin)
            ->get(route('administration.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('administration/index')
                ->where('modules', fn ($modules): bool => collect($modules)->contains(
                    fn (array $m): bool => $m['key'] === 'combinations' && $m['available'] === true,
                )));

        $this->actingAs($admin)
            ->get(route('administration.combinations.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('administration/combinations/index')
                ->has('rules')
                ->has('planningOptions'));

        $this->actingAs($sales)
            ->get(route('administration.combinations.index'))
            ->assertForbidden();
    }

    public function test_create_update_deactivate_reactivate_with_audit_and_locking(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $catalog = $this->createSpotClassicCatalog();

        // Factory already created rules for hamburg/rock – use a third inventory via new medium pair.
        // Deactivate existing hamburg rule to free uniqueness for recreate tests? Better: update existing.
        $existing = InventoryMediumRule::query()
            ->where('inventory_id', $catalog['hamburg']->id)
            ->where('advertising_medium_id', $catalog['medium']->id)
            ->firstOrFail();

        $this->actingAs($admin)
            ->put(route('administration.combinations.update', $existing), [
                'lock_version' => $existing->lock_version,
                'booking_code' => 'L',
                'planning_responsibility_key' => 'disposition',
                'hint_text' => 'TEST-FIXTURE Hinweis',
                'sort' => 10,
                'default_length_seconds' => 30,
                'surcharge_percent' => '0',
                'is_discountable' => true,
                'is_ae_eligible' => true,
                'component_calculation_strategy' => 'shared_total_length',
            ])
            ->assertRedirect(route('administration.combinations.show', $existing));

        $existing->refresh();
        $this->assertSame('L', $existing->booking_code);
        $this->assertSame('disposition', $existing->planning_responsibility_key);
        $this->assertSame('TEST-FIXTURE Hinweis', $existing->hint_text);
        $this->assertSame(2, (int) $existing->lock_version);

        $this->assertTrue(
            AuditEvent::query()
                ->where('action', 'inventory_medium_rule.updated')
                ->where('auditable_id', $existing->id)
                ->exists(),
        );

        $this->actingAs($admin)
            ->put(route('administration.combinations.update', $existing), [
                'lock_version' => 1, // stale
                'booking_code' => 'X',
                'planning_responsibility_key' => 'disposition',
            ])
            ->assertRedirect(route('administration.combinations.show', $existing))
            ->assertSessionHas('error');

        $existing->refresh();
        $this->assertSame('L', $existing->booking_code);

        $this->actingAs($admin)
            ->post(route('administration.combinations.deactivate', $existing), [
                'lock_version' => $existing->lock_version,
            ])
            ->assertRedirect();

        $existing->refresh();
        $this->assertFalse($existing->is_active);

        $this->actingAs($admin)
            ->post(route('administration.combinations.reactivate', $existing), [
                'lock_version' => $existing->lock_version,
            ])
            ->assertRedirect();

        $existing->refresh();
        $this->assertTrue($existing->is_active);
    }

    public function test_active_create_requires_booking_and_planning(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $catalog = $this->createSpotClassicCatalog();

        // Use rock inventory but a new medium to avoid unique collision.
        $medium = AdvertisingMedium::factory()->create([
            'code' => 'test_fixture_spot_b',
            'name' => 'TEST-FIXTURE Spot B',
            'category_id' => $catalog['medium']->category_id,
            'kind' => $catalog['medium']->kind,
            'is_active' => true,
        ]);

        $this->actingAs($admin)
            ->post(route('administration.combinations.store'), [
                'inventory_id' => $catalog['rock']->id,
                'advertising_medium_id' => $medium->id,
                'is_active' => true,
                'booking_code' => '',
                'planning_responsibility_key' => '',
            ])
            ->assertSessionHasErrors(['booking_code', 'planning_responsibility_key']);
    }

    public function test_filters_by_inventory_booking_and_planning(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $catalog = $this->createSpotClassicCatalog();

        $hamburgRule = InventoryMediumRule::query()
            ->where('inventory_id', $catalog['hamburg']->id)
            ->firstOrFail();
        $hamburgRule->booking_code = 'L';
        $hamburgRule->planning_responsibility_key = 'disposition';
        $hamburgRule->save();

        $rockRule = InventoryMediumRule::query()
            ->where('inventory_id', $catalog['rock']->id)
            ->firstOrFail();
        $rockRule->booking_code = 'S';
        $rockRule->planning_responsibility_key = 'oap';
        $rockRule->save();

        $this->actingAs($admin)
            ->get(route('administration.combinations.index', [
                'inventory_id' => $catalog['hamburg']->id,
                'booking_code' => 'L',
                'planning_responsibility_key' => 'disposition',
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('administration/combinations/index')
                ->has('rules', 1)
                ->where('rules.0.id', $hamburgRule->id));
    }

    public function test_calc_freezes_combination_fields_and_admin_change_does_not_mutate_history(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $rule = InventoryMediumRule::query()
            ->where('inventory_id', $catalog['hamburg']->id)
            ->firstOrFail();
        $rule->booking_code = 'L';
        $rule->planning_responsibility_key = 'disposition';
        $rule->hint_text = 'Freeze-A';
        $rule->save();

        $calculation = $this->createSavedCalculation($catalog, [
            ['inventory_id' => $catalog['hamburg']->id],
        ]);

        /** @var CalculationPosition $position */
        $position = $calculation->positions()->firstOrFail();
        $this->assertSame('L', $position->booking_code);
        $this->assertSame('disposition', $position->planning_responsibility_key);
        $this->assertSame('Disposition', $position->planning_responsibility_label);
        $this->assertSame('Freeze-A', $position->combination_hint_text);

        $rule->booking_code = 'K';
        $rule->planning_responsibility_key = 'oap';
        $rule->hint_text = 'Freeze-B';
        $rule->save();

        // Historische Position liest nicht aus Live-Regel nach (kein Backfill).
        $position->refresh();
        $this->assertSame('L', $position->booking_code);
        $this->assertSame('disposition', $position->planning_responsibility_key);
        $this->assertSame('Freeze-A', $position->combination_hint_text);
        $this->assertSame('K', $rule->fresh()->booking_code);
    }

    public function test_dispo_snapshot_copies_frozen_combination_fields_read_only(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $rule = InventoryMediumRule::query()
            ->where('inventory_id', $catalog['hamburg']->id)
            ->firstOrFail();
        $rule->booking_code = 'L';
        $rule->planning_responsibility_key = 'disposition';
        $rule->hint_text = 'Dispo-Hinweis';
        $rule->save();

        $user = User::factory()->role(Role::Sales)->create();
        $calculation = $this->createSavedCalculation($catalog, [
            ['inventory_id' => $catalog['hamburg']->id],
        ], $user);

        $order = app(DispoOrderWriter::class)->createFromCalculation(
            $calculation,
            $calculation->positions->pluck('id')->all(),
            $user,
        )->order;
        $dispoPosition = $order->positions()->firstOrFail();
        $this->assertSame('L', $dispoPosition->booking_code);
        $this->assertSame('disposition', $dispoPosition->planning_responsibility_key);
        $this->assertSame('Disposition', $dispoPosition->planning_responsibility_label);
        $this->assertSame('Dispo-Hinweis', $dispoPosition->combination_hint_text);

        $rule->booking_code = 'UA';
        $rule->save();
        $dispoPosition->refresh();
        $this->assertSame('L', $dispoPosition->booking_code);

        $this->actingAs($user)
            ->get(route('dispo-orders.show', $order))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('dispo-orders/show')
                ->where('order.positions.0.booking_code', 'L')
                ->where('order.positions.0.planning_responsibility_label', 'Disposition')
                ->where('order.positions.0.combination_hint_text', 'Dispo-Hinweis'));
    }

    public function test_incomplete_active_rule_blocks_new_calc_position(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $rule = InventoryMediumRule::query()
            ->where('inventory_id', $catalog['hamburg']->id)
            ->firstOrFail();
        $rule->booking_code = null;
        $rule->planning_responsibility_key = null;
        $rule->save();

        $user = User::factory()->role(Role::Sales)->create();

        try {
            $this->createSavedCalculation($catalog, [
                ['inventory_id' => $catalog['hamburg']->id],
            ], $user);
            $this->fail('Expected validation for incomplete combination.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString(
                'unvollständig',
                $exception->errors()['positions'][0] ?? '',
            );
        }
    }

    public function test_must_not_plan_blocks_new_calc_position(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $rule = InventoryMediumRule::query()
            ->where('inventory_id', $catalog['hamburg']->id)
            ->firstOrFail();
        $rule->booking_code = 'L';
        $rule->planning_responsibility_key = InventoryMediumRuleOperativeContract::PLANNING_MUST_NOT_PLAN;
        $rule->save();

        $user = User::factory()->role(Role::Sales)->create();

        try {
            $this->createSavedCalculation($catalog, [
                ['inventory_id' => $catalog['hamburg']->id],
            ], $user);
            $this->fail('Expected validation for must-not-plan.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString(
                'nicht geplant',
                $exception->errors()['positions'][0] ?? '',
            );
        }
    }

    public function test_legacy_position_without_freeze_remains_readable_on_dispo(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $calculation = $this->createSavedCalculation($catalog, [
            ['inventory_id' => $catalog['hamburg']->id],
        ], $user);

        /** @var CalculationPosition $position */
        $position = $calculation->positions()->firstOrFail();
        // Simuliere Legacy vor MAT-CORE: Freeze-Felder geleert, ohne Live-Backfill.
        $position->booking_code = null;
        $position->planning_responsibility_key = null;
        $position->planning_responsibility_label = null;
        $position->combination_hint_text = null;
        $position->save();

        $order = app(DispoOrderWriter::class)->createFromCalculation(
            $calculation->fresh(['positions']),
            [$position->id],
            $user,
        )->order;

        $dispoPosition = $order->positions()->firstOrFail();
        $this->assertNull($dispoPosition->booking_code);
        $this->assertNull($dispoPosition->planning_responsibility_key);
    }
}
