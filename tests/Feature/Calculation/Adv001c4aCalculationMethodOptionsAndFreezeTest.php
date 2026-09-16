<?php

namespace Tests\Feature\Calculation;

use App\Enums\Role;
use App\Models\AdvertisingMedium;
use App\Models\CalculationMethod;
use App\Models\InventoryMediumRule;
use App\Models\User;
use App\Services\Calculation\CalculationWriter;
use App\Services\DynamicField\ConfigurationSnapshotFreezeService;
use App\Support\Advertising\AdvertisingMediumCalculationMethodOptionsResolver;
use App\Support\Calculation\CalculationMethodFreezeResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesSavedCalculation;
use Tests\Concerns\CreatesSpotClassicCatalog;
use Tests\TestCase;

/**
 * ADV-001c4a: Request-/Freeze-Semantik und Wizard-Props ohne sichtbare Methoden-UX.
 */
class Adv001c4aCalculationMethodOptionsAndFreezeTest extends TestCase
{
    use CreatesSavedCalculation;
    use CreatesSpotClassicCatalog;
    use RefreshDatabase;

    public function test_create_without_key_uses_executable_default_freeze(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $freeze = app(ConfigurationSnapshotFreezeService::class);

        $created = app(CalculationWriter::class)->create([
            'planning_mode' => 'manual',
            'order_discount_percent' => '0',
            'schema_fingerprint' => $freeze->resolveLiveSchemaForCalculationV3()['schema_fingerprint'],
            'positions' => [[
                'inventory_id' => $catalog['rock']->id,
                'advertising_medium_id' => $catalog['medium']->id,
                'schema_fingerprint' => $freeze->resolveLivePositionSchema((int) $catalog['medium']->id)['schema_fingerprint'],
                'length_seconds' => 30,
                'total_spot_count' => 4,
                'position_discount_percent' => '0',
                'ae_percent' => '0',
                'plan_rows' => [['hour' => 8, 'day_group' => 'mo_fr']],
            ]],
        ], $user);

        $position = $created->positions()->firstOrFail();
        $this->assertSame('average', $position->calculation_method_key);
        $this->assertSame('Durchschnitt', $position->calculation_method_name);
        $this->assertSame('spot_classic', $position->engine_profile_key);
        $this->assertSame('v1', $position->algorithm_version);
        $this->assertSame('average', $position->spot_method->value);
    }

    public function test_create_with_explicit_average_key_freezes_descriptor(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();

        $calculation = app(CalculationWriter::class)->create([
            'planning_mode' => 'manual',
            'order_discount_percent' => '0',
            'schema_fingerprint' => app(ConfigurationSnapshotFreezeService::class)
                ->resolveLiveSchemaForCalculationV3()['schema_fingerprint'],
            'positions' => [[
                'inventory_id' => $catalog['hamburg']->id,
                'advertising_medium_id' => $catalog['medium']->id,
                'schema_fingerprint' => app(ConfigurationSnapshotFreezeService::class)
                    ->resolveLivePositionSchema((int) $catalog['medium']->id)['schema_fingerprint'],
                'calculation_method_key' => 'average',
                'length_seconds' => 30,
                'total_spot_count' => 3,
                'position_discount_percent' => '0',
                'ae_percent' => '0',
                'plan_rows' => [['hour' => 9, 'day_group' => 'mo_fr']],
            ]],
        ], $user);

        $position = $calculation->positions()->firstOrFail();
        $this->assertSame(CalculationMethodFreezeResolver::LEGACY_SPOT_CLASSIC_AVERAGE, [
            'engine_profile_key' => $position->engine_profile_key,
            'calculation_method_key' => $position->calculation_method_key,
            'calculation_method_name' => $position->calculation_method_name,
            'algorithm_version' => $position->algorithm_version,
        ]);
    }

    public function test_create_with_empty_or_planned_key_is_rejected(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $fingerprint = app(ConfigurationSnapshotFreezeService::class)
            ->resolveLiveSchemaForCalculationV3()['schema_fingerprint'];
        $positionFingerprint = app(ConfigurationSnapshotFreezeService::class)
            ->resolveLivePositionSchema((int) $catalog['medium']->id)['schema_fingerprint'];

        $this->actingAs($user)->postJson(route('calculations.store'), [
            'planning_mode' => 'manual',
            'order_discount_percent' => '0',
            'schema_fingerprint' => $fingerprint,
            'positions' => [[
                'inventory_id' => $catalog['hamburg']->id,
                'advertising_medium_id' => $catalog['medium']->id,
                'schema_fingerprint' => $positionFingerprint,
                'calculation_method_key' => '',
                'length_seconds' => 30,
                'total_spot_count' => 1,
                'plan_rows' => [['hour' => 8, 'day_group' => 'mo_fr']],
            ]],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['positions.0.calculation_method_key']);

        try {
            app(CalculationWriter::class)->create([
                'planning_mode' => 'manual',
                'order_discount_percent' => '0',
                'schema_fingerprint' => $fingerprint,
                'positions' => [[
                    'inventory_id' => $catalog['hamburg']->id,
                    'advertising_medium_id' => $catalog['medium']->id,
                    'schema_fingerprint' => $positionFingerprint,
                    'calculation_method_key' => 'fixed_price',
                    'length_seconds' => 30,
                    'total_spot_count' => 1,
                    'plan_rows' => [['hour' => 8, 'day_group' => 'mo_fr']],
                ]],
            ], $user);
            $this->fail('Erwartete ValidationException für planned Key.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                ['Kalkulationsart Festpreis ist noch nicht freigegeben.'],
                $exception->errors()['positions'] ?? null,
            );
        }
    }

    public function test_conflicting_spot_method_and_calculation_method_key_rejected(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $fingerprint = app(ConfigurationSnapshotFreezeService::class)
            ->resolveLiveSchemaForCalculationV3()['schema_fingerprint'];
        $positionFingerprint = app(ConfigurationSnapshotFreezeService::class)
            ->resolveLivePositionSchema((int) $catalog['medium']->id)['schema_fingerprint'];

        $this->actingAs($user)->postJson(route('calculations.store'), [
            'planning_mode' => 'manual',
            'order_discount_percent' => '0',
            'schema_fingerprint' => $fingerprint,
            'positions' => [[
                'inventory_id' => $catalog['hamburg']->id,
                'advertising_medium_id' => $catalog['medium']->id,
                'schema_fingerprint' => $positionFingerprint,
                'calculation_method_key' => 'average',
                'spot_method' => 'calendar',
                'length_seconds' => 30,
                'total_spot_count' => 1,
                'plan_rows' => [['hour' => 8, 'day_group' => 'mo_fr']],
            ]],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['positions.0.calculation_method_key']);
    }

    public function test_update_without_key_keeps_freeze_byte_exact_after_admin_rename(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $calculation = $this->createSavedCalculation($catalog, [
            ['inventory_id' => $catalog['hamburg']->id],
        ], $user);
        $position = $calculation->positions()->firstOrFail();
        $before = [
            $position->engine_profile_key,
            $position->calculation_method_key,
            $position->calculation_method_name,
            $position->algorithm_version,
            $position->spot_method->value,
            $position->kind->value,
        ];

        CalculationMethod::query()->where('key', 'average')->update(['name' => 'Durchschnitt LIVE']);

        $writer = app(CalculationWriter::class);
        $payload = $writer->payloadFromCalculation($calculation->fresh([
            'positions.planRows',
            'positions.timeRanges',
            'positions.discounts',
            'orderDiscounts',
            'configurationSnapshot',
            'fieldValues',
        ]));
        unset($payload['positions'][0]['spot_method']);
        $payload['lock_version'] = $calculation->lock_version;
        $payload['campaign'] = 'Rename-safe';

        $updated = $writer->update($calculation->fresh(), $payload, $user);
        $fresh = $updated->positions()->firstOrFail();

        $this->assertSame($before, [
            $fresh->engine_profile_key,
            $fresh->calculation_method_key,
            $fresh->calculation_method_name,
            $fresh->algorithm_version,
            $fresh->spot_method->value,
            $fresh->kind->value,
        ]);
        $this->assertSame('Durchschnitt', $fresh->calculation_method_name);
    }

    public function test_update_with_same_explicit_key_keeps_freeze_byte_exact(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $calculation = $this->createSavedCalculation($catalog, [
            ['inventory_id' => $catalog['hamburg']->id],
        ], $user);
        $position = $calculation->positions()->firstOrFail();
        DB::table('calculation_positions')->where('id', $position->id)->update([
            'calculation_method_name' => 'Historischer Name',
            'algorithm_version' => 'v1',
        ]);
        $position->refresh();
        $before = [
            $position->engine_profile_key,
            $position->calculation_method_key,
            $position->calculation_method_name,
            $position->algorithm_version,
        ];

        $writer = app(CalculationWriter::class);
        $payload = $writer->payloadFromCalculation($calculation->fresh([
            'positions.planRows',
            'positions.timeRanges',
            'positions.discounts',
            'orderDiscounts',
            'configurationSnapshot',
            'fieldValues',
        ]));
        $payload['lock_version'] = $calculation->fresh()->lock_version;
        $payload['positions'][0]['calculation_method_key'] = 'average';
        $payload['positions'][0]['spot_method'] = 'average';
        $payload['positions'][0]['length_seconds'] = 20;

        $updated = $writer->update($calculation->fresh(), $payload, $user);
        $fresh = $updated->positions()->firstOrFail();

        $this->assertSame($before, [
            $fresh->engine_profile_key,
            $fresh->calculation_method_key,
            $fresh->calculation_method_name,
            $fresh->algorithm_version,
        ]);
        $this->assertSame('Historischer Name', $fresh->calculation_method_name);
        $this->assertSame(20, $fresh->length_seconds);
    }

    public function test_inventory_only_change_keeps_freeze_and_updates_price_list(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $calculation = $this->createSavedCalculation($catalog, [
            ['inventory_id' => $catalog['hamburg']->id],
        ], $user);
        $position = $calculation->positions()->firstOrFail();
        $beforeFreeze = [
            $position->engine_profile_key,
            $position->calculation_method_key,
            $position->calculation_method_name,
            $position->algorithm_version,
        ];
        $oldPriceListId = $position->price_list_id;

        DB::table('calculation_positions')->where('id', $position->id)->update([
            'calculation_method_name' => 'Freeze Inventar',
        ]);

        $writer = app(CalculationWriter::class);
        $payload = $writer->payloadFromCalculation($calculation->fresh([
            'positions.planRows',
            'positions.timeRanges',
            'positions.discounts',
            'orderDiscounts',
            'configurationSnapshot',
            'fieldValues',
        ]));
        $payload['lock_version'] = $calculation->fresh()->lock_version;
        $payload['positions'][0]['inventory_id'] = $catalog['rock']->id;
        $payload['positions'][0]['calculation_method_key'] = 'average';

        $updated = $writer->update($calculation->fresh(), $payload, $user);
        $fresh = $updated->positions()->firstOrFail();

        $this->assertSame($catalog['rock']->id, $fresh->inventory_id);
        $this->assertNotSame($oldPriceListId, $fresh->price_list_id);
        $this->assertSame([
            'spot_classic',
            'average',
            'Freeze Inventar',
            'v1',
        ], [
            $fresh->engine_profile_key,
            $fresh->calculation_method_key,
            $fresh->calculation_method_name,
            $fresh->algorithm_version,
        ]);
        $this->assertSame($beforeFreeze[0], $fresh->engine_profile_key);
        $this->assertSame($beforeFreeze[1], $fresh->calculation_method_key);
        $this->assertSame($beforeFreeze[3], $fresh->algorithm_version);
    }

    public function test_inventory_change_without_rule_is_rejected(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $calculation = $this->createSavedCalculation($catalog, [
            ['inventory_id' => $catalog['hamburg']->id],
        ], $user);

        InventoryMediumRule::query()
            ->where('inventory_id', $catalog['rock']->id)
            ->where('advertising_medium_id', $catalog['medium']->id)
            ->update(['is_active' => false]);

        $writer = app(CalculationWriter::class);
        $payload = $writer->payloadFromCalculation($calculation->fresh([
            'positions.planRows',
            'positions.timeRanges',
            'positions.discounts',
            'orderDiscounts',
            'configurationSnapshot',
            'fieldValues',
        ]));
        $payload['lock_version'] = $calculation->lock_version;
        $payload['positions'][0]['inventory_id'] = $catalog['rock']->id;

        try {
            $writer->update($calculation->fresh(), $payload, $user);
            $this->fail('Erwartete ValidationException ohne aktive Rule.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                ['Die Kombination Sender/Werbemittel ist nicht zulässig.'],
                $exception->errors()['positions'] ?? null,
            );
        }

        $fresh = $calculation->fresh()->positions()->firstOrFail();
        $this->assertSame($catalog['hamburg']->id, $fresh->inventory_id);
    }

    public function test_medium_change_without_key_uses_new_default_and_re_freezes(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $calculation = $this->createSavedCalculation($catalog, [
            ['inventory_id' => $catalog['hamburg']->id],
        ], $user);

        $other = AdvertisingMedium::factory()->create([
            'category_id' => $catalog['medium']->category_id,
            'code' => 'c4a_other_spot',
            'kind' => $catalog['medium']->kind,
            'is_active' => true,
        ]);
        InventoryMediumRule::factory()->create([
            'inventory_id' => $catalog['hamburg']->id,
            'advertising_medium_id' => $other->id,
        ]);

        CalculationMethod::query()->where('key', 'average')->update(['name' => 'Durchschnitt NEU']);

        $writer = app(CalculationWriter::class);
        $payload = $writer->payloadFromCalculation($calculation->fresh([
            'positions.planRows',
            'positions.timeRanges',
            'positions.discounts',
            'orderDiscounts',
            'configurationSnapshot',
            'fieldValues',
        ]));
        $payload['lock_version'] = $calculation->lock_version;
        $payload['positions'][0]['advertising_medium_id'] = $other->id;
        unset($payload['positions'][0]['spot_method']);
        $payload['positions'][0]['schema_fingerprint'] = app(ConfigurationSnapshotFreezeService::class)
            ->resolvePositionSchemaFromBase(
                $calculation->fresh()->configurationSnapshot,
                $other->id,
            )['schema_fingerprint'];

        $updated = $writer->update($calculation->fresh(), $payload, $user);
        $fresh = $updated->positions()->firstOrFail();

        $this->assertSame($other->id, $fresh->advertising_medium_id);
        $this->assertSame('average', $fresh->calculation_method_key);
        $this->assertSame('Durchschnitt NEU', $fresh->calculation_method_name);
        $this->assertSame('v1', $fresh->algorithm_version);
    }

    public function test_historical_inactive_method_still_allows_unchanged_update(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $calculation = $this->createSavedCalculation($catalog, [
            ['inventory_id' => $catalog['hamburg']->id, 'total_spot_count' => 5],
        ], $user);

        CalculationMethod::query()->where('key', 'average')->update(['is_active' => false]);

        $writer = app(CalculationWriter::class);
        $payload = $writer->payloadFromCalculation($calculation->fresh([
            'positions.planRows',
            'positions.timeRanges',
            'positions.discounts',
            'orderDiscounts',
            'configurationSnapshot',
            'fieldValues',
        ]));
        $payload['lock_version'] = $calculation->lock_version;
        $payload['campaign'] = 'Historisch inaktiv';

        $updated = $writer->update($calculation->fresh(), $payload, $user);
        $this->assertSame('Historisch inaktiv', $updated->campaign);
        $this->assertSame('average', $updated->positions()->first()?->calculation_method_key);
        $this->assertSame('v1', $updated->positions()->first()?->algorithm_version);
    }

    public function test_wizard_props_include_method_options_and_frozen_position_fields(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $calculation = $this->createSavedCalculation($catalog, [
            ['inventory_id' => $catalog['hamburg']->id],
        ], $user);

        $resolver = app(AdvertisingMediumCalculationMethodOptionsResolver::class);
        $expected = $resolver->resolve($catalog['medium']->fresh([
            'category.defaultCalculationMethod',
            'category.calculationMethodAssignments.calculationMethod',
            'defaultCalculationMethod',
            'calculationMethodAssignments.calculationMethod',
        ]))->toPayload();

        $this->actingAs($user)
            ->get(route('calculations.edit', $calculation))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('calculations/wizard')
                ->where('catalog.media', function ($media) use ($catalog, $expected): bool {
                    $row = collect($media)->firstWhere('id', $catalog['medium']->id);

                    return is_array($row)
                        && ($row['calculation_method_options'] ?? null) === $expected
                        && ! array_key_exists('engine_profile_key', $row['calculation_method_options'])
                        && ($row['calculation_method_options']['methods'][0]['key'] ?? null) === 'average';
                })
                ->where('calculation.positions.0.calculation_method_key', 'average')
                ->where('calculation.positions.0.calculation_method_name', 'Durchschnitt')
                ->missing('calculation.positions.0.engine_profile_key')
                ->missing('calculation.positions.0.algorithm_version'));
    }
}
