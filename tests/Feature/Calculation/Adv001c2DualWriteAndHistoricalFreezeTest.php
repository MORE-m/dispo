<?php

namespace Tests\Feature\Calculation;

use App\Enums\Role;
use App\Models\AdvertisingCategory;
use App\Models\AdvertisingCategoryCalculationMethod;
use App\Models\AdvertisingMedium;
use App\Models\CalculationMethod;
use App\Models\DispoOrder;
use App\Models\InventoryMediumRule;
use App\Models\User;
use App\Services\Calculation\CalculationWriter;
use App\Services\DispoOrder\DispoOrderSnapshotMapper;
use App\Services\DispoOrder\DispoOrderWriter;
use App\Support\Advertising\CanonicalAdvertisingCategories;
use App\Support\Calculation\CalculationMethodFreezeResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreatesSavedCalculation;
use Tests\Concerns\CreatesSpotClassicCatalog;
use Tests\Concerns\EnsuresCustomerConfirmationException;
use Tests\TestCase;

/**
 * ADV-001c2: Dual-Write, Client-Prohibition, historische Freeze-Stabilität.
 */
class Adv001c2DualWriteAndHistoricalFreezeTest extends TestCase
{
    use CreatesSavedCalculation;
    use CreatesSpotClassicCatalog;
    use EnsuresCustomerConfirmationException;
    use RefreshDatabase;

    public function test_create_writes_freeze_fields_matching_legacy(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $calculation = $this->createSavedCalculation($catalog, [
            ['inventory_id' => $catalog['hamburg']->id],
        ]);
        $position = $calculation->positions()->firstOrFail();

        $this->assertSame('spot_classic', $position->kind->value);
        $this->assertSame('average', $position->spot_method->value);
        $this->assertSame('spot_classic', $position->engine_profile_key);
        $this->assertSame('average', $position->calculation_method_key);
        $this->assertSame('Durchschnitt', $position->calculation_method_name);
        $this->assertSame('v1', $position->algorithm_version);
    }

    public function test_update_preserves_freeze_fields(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $calculation = $this->createSavedCalculation($catalog, [
            ['inventory_id' => $catalog['hamburg']->id],
        ], $user);
        $position = $calculation->positions()->firstOrFail();
        $freezeBefore = [
            $position->engine_profile_key,
            $position->calculation_method_key,
            $position->calculation_method_name,
            $position->algorithm_version,
        ];

        $writer = app(CalculationWriter::class);
        $payload = $writer->payloadFromCalculation($calculation);
        $payload['lock_version'] = $calculation->lock_version;
        $payload['campaign'] = 'Geändert';
        $payload['positions'][0]['length_seconds'] = 20;
        if (($payload['positions'][0]['time_ranges'][0] ?? null) !== null) {
            $payload['positions'][0]['time_ranges'][0]['spot_count'] = 12;
        } else {
            $payload['positions'][0]['total_spot_count'] = 12;
        }

        $updated = $writer->update($calculation, $payload, $user);
        $fresh = $updated->positions()->firstOrFail();

        $this->assertSame($freezeBefore, [
            $fresh->engine_profile_key,
            $fresh->calculation_method_key,
            $fresh->calculation_method_name,
            $fresh->algorithm_version,
        ]);
        $this->assertSame('Geändert', $updated->campaign);
        $this->assertSame(20, $fresh->length_seconds);
        $this->assertSame(12, $fresh->total_spot_count);
    }

    public function test_client_cannot_inject_freeze_fields(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();

        $this->actingAs($user)->postJson(route('calculations.store'), $this->withLiveSchemaFingerprint([
            'planning_mode' => 'manual',
            'order_discount_percent' => '0',
            'positions' => [[
                'inventory_id' => $catalog['hamburg']->id,
                'advertising_medium_id' => $catalog['medium']->id,
                'spot_method' => 'average',
                'engine_profile_key' => 'injected',
                'calculation_method_name' => 'Injected',
                'algorithm_version' => 'v9',
                'length_seconds' => 30,
                'total_spot_count' => 1,
                'position_discount_percent' => '0',
                'ae_percent' => '0',
                'plan_rows' => [['hour' => 8, 'day_group' => 'mo_fr']],
            ]],
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'positions.0.engine_profile_key',
                'positions.0.calculation_method_name',
                'positions.0.algorithm_version',
            ]);
    }

    public function test_changing_spot_method_on_existing_position_to_planned_is_rejected_in_german(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $calculation = $this->createSavedCalculation($catalog, [
            ['inventory_id' => $catalog['hamburg']->id],
        ], $user);

        $writer = app(CalculationWriter::class);
        $payload = $writer->payloadFromCalculation($calculation);
        $payload['lock_version'] = $calculation->lock_version;
        // BL-P4-02b: calendar ist released; geplante Ablehnung über fixed_price.
        $payload['positions'][0]['spot_method'] = 'fixed_price';
        $payload['positions'][0]['calculation_method_key'] = 'fixed_price';

        try {
            $writer->update($calculation, $payload, $user);
            $this->fail('Erwartete ValidationException bei Methodenwechsel.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                ['Die Berechnungsmethode ist für dieses Werbemittel nicht mehr verfügbar. Bitte Auswahl aktualisieren.'],
                $exception->errors()['positions'] ?? null,
            );
        }
    }

    public function test_historical_position_remains_calculable_after_medium_and_category_deactivated(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $calculation = $this->createSavedCalculation($catalog, [
            ['inventory_id' => $catalog['hamburg']->id, 'total_spot_count' => 5],
        ], $user);
        $nnBefore = (string) $calculation->nn_invest;

        DB::table('advertising_media')->where('id', $catalog['medium']->id)->update(['is_active' => false]);
        DB::table('advertising_categories')
            ->where('id', $catalog['medium']->category_id)
            ->update(['is_active' => false]);

        $writer = app(CalculationWriter::class);
        $payload = $writer->payloadFromCalculation($calculation->fresh(['positions.planRows', 'positions.timeRanges', 'positions.discounts', 'orderDiscounts', 'configurationSnapshot', 'fieldValues']));
        $payload['lock_version'] = $calculation->lock_version;
        $payload['campaign'] = 'Historisch weiter';
        if (($payload['positions'][0]['time_ranges'][0] ?? null) !== null) {
            $payload['positions'][0]['time_ranges'][0]['spot_count'] = 8;
        } else {
            $payload['positions'][0]['total_spot_count'] = 8;
        }

        $updated = $writer->update($calculation->fresh(), $payload, $user);
        $this->assertSame('Historisch weiter', $updated->campaign);
        $this->assertSame(8, $updated->positions()->first()?->total_spot_count);
        $this->assertNotSame($nnBefore, (string) $updated->nn_invest);
        $this->assertSame('average', $updated->positions()->first()?->calculation_method_key);
        $this->assertSame('v1', $updated->positions()->first()?->algorithm_version);
    }

    public function test_existing_position_keeps_average_v1_after_catalog_default_and_assignment_change(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $calculation = $this->createSavedCalculation($catalog, [
            ['inventory_id' => $catalog['hamburg']->id],
        ], $user);
        $position = $calculation->positions()->firstOrFail();

        $spots = AdvertisingCategory::query()
            ->where('key', CanonicalAdvertisingCategories::SPOTS)
            ->firstOrFail();
        $calendar = CalculationMethod::query()->where('key', 'calendar')->firstOrFail();
        $average = CalculationMethod::query()->where('key', 'average')->firstOrFail();

        DB::table('advertising_categories')->where('id', $spots->id)->update([
            'default_calculation_method_id' => $calendar->id,
        ]);
        AdvertisingCategoryCalculationMethod::query()
            ->where('advertising_category_id', $spots->id)
            ->where('calculation_method_id', $average->id)
            ->update(['is_active' => false]);

        $writer = app(CalculationWriter::class);
        $payload = $writer->payloadFromCalculation($calculation);
        $payload['lock_version'] = $calculation->lock_version;
        $payload['briefing'] = 'Katalog geändert';

        $updated = $writer->update($calculation, $payload, $user);
        $fresh = $updated->positions()->firstOrFail();

        $this->assertSame($position->id, $fresh->id);
        $this->assertSame('average', $fresh->calculation_method_key);
        $this->assertSame('v1', $fresh->algorithm_version);
        $this->assertSame('spot_classic', $fresh->engine_profile_key);
        $this->assertSame('Durchschnitt', $fresh->calculation_method_name);
    }

    public function test_existing_position_still_calculable_when_medium_kind_set_null(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $calculation = $this->createSavedCalculation($catalog, [
            ['inventory_id' => $catalog['hamburg']->id, 'total_spot_count' => 3],
        ], $user);

        DB::table('advertising_media')->where('id', $catalog['medium']->id)->update(['kind' => null]);
        $this->assertNull(DB::table('advertising_media')->where('id', $catalog['medium']->id)->value('kind'));

        $writer = app(CalculationWriter::class);
        $payload = $writer->payloadFromCalculation($calculation);
        $payload['lock_version'] = $calculation->lock_version;
        $payload['positions'][0]['length_seconds'] = 20;
        if (($payload['positions'][0]['time_ranges'][0] ?? null) !== null) {
            $payload['positions'][0]['time_ranges'][0]['spot_count'] = 4;
        } else {
            $payload['positions'][0]['total_spot_count'] = 4;
        }

        $updated = $writer->update($calculation, $payload, $user);
        $fresh = $updated->positions()->firstOrFail();

        $this->assertSame(4, $fresh->total_spot_count);
        $this->assertSame(20, $fresh->length_seconds);
        $this->assertSame('spot_classic', $fresh->kind->value);
        $this->assertSame('average', $fresh->calculation_method_key);
        $this->assertSame('v1', $fresh->algorithm_version);
    }

    public function test_changed_combination_uses_live_path(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $calculation = $this->createSavedCalculation($catalog, [
            ['inventory_id' => $catalog['hamburg']->id],
        ], $user);

        $newMedium = AdvertisingMedium::factory()->create([
            'code' => 'spot_classic_live_path_'.uniqid(),
            'name' => 'Spot Classic Live',
        ]);
        InventoryMediumRule::factory()->create([
            'inventory_id' => $catalog['hamburg']->id,
            'advertising_medium_id' => $newMedium->id,
        ]);

        $writer = app(CalculationWriter::class);
        $payload = $writer->payloadFromCalculation($calculation);
        $payload['lock_version'] = $calculation->lock_version;
        $payload['positions'][0]['advertising_medium_id'] = $newMedium->id;
        $payload['positions'][0]['schema_fingerprint'] = $this->positionSchemaFingerprintFor(
            $calculation,
            (int) $newMedium->id,
        );

        $updated = $writer->update($calculation, $payload, $user);
        $fresh = $updated->positions()->firstOrFail();

        $this->assertSame($newMedium->id, $fresh->advertising_medium_id);
        $this->assertSame('spot_classic', $fresh->engine_profile_key);
        $this->assertSame('average', $fresh->calculation_method_key);
        $this->assertSame('Durchschnitt', $fresh->calculation_method_name);
        $this->assertSame('v1', $fresh->algorithm_version);
    }

    public function test_dispo_from_calculation_copies_all_four_freeze_fields(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $calculation = $this->createSavedCalculation($catalog, [
            ['inventory_id' => $catalog['hamburg']->id],
            ['inventory_id' => $catalog['rock']->id, 'total_spot_count' => 5, 'hour' => 10],
        ], $user);
        $calcPositions = $calculation->positions()->orderBy('sort')->get();

        $this->actingAs($user)->post(route('dispo-orders.store', $calculation), [
            'position_ids' => $calcPositions->pluck('id')->all(),
        ])->assertRedirect();

        $order = DispoOrder::query()->with('positions')->firstOrFail();
        $this->assertCount(2, $order->positions);

        foreach ($order->positions as $dispoPosition) {
            $source = $calcPositions->firstWhere('id', $dispoPosition->calculation_position_id);
            $this->assertNotNull($source);
            $this->assertSame($source->engine_profile_key, $dispoPosition->engine_profile_key);
            $this->assertSame($source->calculation_method_key, $dispoPosition->calculation_method_key);
            $this->assertSame($source->calculation_method_name, $dispoPosition->calculation_method_name);
            $this->assertSame($source->algorithm_version, $dispoPosition->algorithm_version);
            $this->assertSame(
                CalculationMethodFreezeResolver::LEGACY_SPOT_CLASSIC_AVERAGE,
                [
                    'engine_profile_key' => $dispoPosition->engine_profile_key,
                    'calculation_method_key' => $dispoPosition->calculation_method_key,
                    'calculation_method_name' => $dispoPosition->calculation_method_name,
                    'algorithm_version' => $dispoPosition->algorithm_version,
                ],
            );
        }
    }

    public function test_revision_keeps_freeze_fields(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $creator = User::factory()->role(Role::Sales)->create();
        $calculation = $this->createSavedCalculation($catalog, [
            ['inventory_id' => $catalog['hamburg']->id],
        ], $creator);
        $freeze = [
            'engine_profile_key' => $calculation->positions()->first()->engine_profile_key,
            'calculation_method_key' => $calculation->positions()->first()->calculation_method_key,
            'calculation_method_name' => $calculation->positions()->first()->calculation_method_name,
            'algorithm_version' => $calculation->positions()->first()->algorithm_version,
        ];

        $this->actingAs($creator)->post(route('dispo-orders.store', $calculation), [
            'position_ids' => $calculation->positions()->pluck('id')->all(),
        ])->assertRedirect();

        $order = DispoOrder::query()->latest('id')->firstOrFail();
        $order = $this->seedCustomerConfirmationException($order, $creator);
        $this->actingAs($creator)->postJson(route('dispo-orders.submit', $order), [
            'lock_version' => $order->lock_version,
        ])->assertOk();
        $order->refresh();

        $decider = User::factory()->role(Role::Sales)->create();
        $this->actingAs($decider)->postJson(route('dispo-orders.reject', $order), [
            'lock_version' => $order->lock_version,
            'reason' => 'Freeze-Revision prüfen',
        ])->assertOk();
        $order->refresh();

        $this->actingAs($creator)->post(route('dispo-orders.revise', $order))->assertRedirect();

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
        $payload['customer_name'] = 'Revision GmbH';
        $writer->update($calculation->fresh(), $payload, $creator);
        $calculation->refresh()->load('positions');

        $this->actingAs($creator)->post(route('dispo-orders.store', $calculation), [
            'position_ids' => $calculation->positions()->pluck('id')->all(),
            'revises_dispo_order_id' => $order->id,
        ])->assertRedirect();

        $revision = DispoOrder::query()->where('revises_dispo_order_id', $order->id)->with('positions')->firstOrFail();
        $revisionPosition = $revision->positions->firstOrFail();

        $this->assertSame($freeze['engine_profile_key'], $revisionPosition->engine_profile_key);
        $this->assertSame($freeze['calculation_method_key'], $revisionPosition->calculation_method_key);
        $this->assertSame($freeze['calculation_method_name'], $revisionPosition->calculation_method_name);
        $this->assertSame($freeze['algorithm_version'], $revisionPosition->algorithm_version);

        $calcPosition = $calculation->positions()->firstOrFail();
        $this->assertSame($freeze['engine_profile_key'], $calcPosition->engine_profile_key);
        $this->assertSame($freeze['calculation_method_key'], $calcPosition->calculation_method_key);
        $this->assertSame($freeze['calculation_method_name'], $calcPosition->calculation_method_name);
        $this->assertSame($freeze['algorithm_version'], $calcPosition->algorithm_version);
    }

    public function test_inactive_category_blocks_new_position(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();

        DB::table('advertising_categories')
            ->where('id', $catalog['medium']->category_id)
            ->update(['is_active' => false]);

        try {
            $this->createSavedCalculation($catalog, [
                ['inventory_id' => $catalog['hamburg']->id],
            ], $user);
            $this->fail('Erwartete ValidationException bei inaktiver Kategorie.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString(
                'Oberkategorie',
                implode(' ', $exception->errors()['positions'] ?? []),
            );
            $this->assertStringContainsString(
                'inaktiv',
                implode(' ', $exception->errors()['positions'] ?? []),
            );
        }
    }

    public function test_inactive_method_blocks_new_position(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();

        DB::table('calculation_methods')->where('key', 'average')->update(['is_active' => false]);

        try {
            $this->createSavedCalculation($catalog, [
                ['inventory_id' => $catalog['hamburg']->id],
            ], $user);
            $this->fail('Erwartete ValidationException bei inaktiver Methode.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                ['Die Berechnungsmethode ist unbekannt oder inaktiv.'],
                $exception->errors()['positions'] ?? null,
            );
        }
    }

    public function test_inactive_assignment_blocks_new_position(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $spots = AdvertisingCategory::query()
            ->where('key', CanonicalAdvertisingCategories::SPOTS)
            ->firstOrFail();
        $average = CalculationMethod::query()->where('key', 'average')->firstOrFail();

        AdvertisingCategoryCalculationMethod::query()
            ->where('advertising_category_id', $spots->id)
            ->where('calculation_method_id', $average->id)
            ->update(['is_active' => false]);

        try {
            $this->createSavedCalculation($catalog, [
                ['inventory_id' => $catalog['hamburg']->id],
            ], $user);
            $this->fail('Erwartete ValidationException bei inaktiver Zuordnung.');
        } catch (ValidationException $exception) {
            $message = implode(' ', $exception->errors()['positions'] ?? []);
            $this->assertTrue(
                str_contains($message, 'nicht aktiv zugeordnet')
                || str_contains($message, 'keine aktive Berechnungsmethoden-Zuordnung'),
                "Unerwartete Meldung: {$message}",
            );
        }
    }

    public function test_blank_whitespace_method_name_is_rejected_with_422_not_500(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        DB::table('calculation_methods')->where('key', 'average')->update(['name' => "  \t  "]);

        try {
            app(CalculationWriter::class)->create($this->withLiveSchemaFingerprint([
                'planning_mode' => 'manual',
                'customer_name' => 'Leerer Methodenname',
                'order_discount_percent' => '0',
                'ae_enabled' => false,
                'positions' => [[
                    'inventory_id' => $catalog['hamburg']->id,
                    'advertising_medium_id' => $catalog['medium']->id,
                    'spot_method' => 'average',
                    'length_seconds' => 30,
                    'total_spot_count' => 10,
                    'position_discount_percent' => '0',
                    'ae_percent' => '15',
                    'plan_rows' => [['hour' => 8, 'day_group' => 'mo_fr']],
                ]],
            ]), $user);
            $this->fail('Erwartete ValidationException bei Whitespace-Methodennamen.');
        } catch (ValidationException $exception) {
            $this->assertSame(422, $exception->status);
            $this->assertStringContainsString(
                'Methodenname',
                implode(' ', $exception->errors()['positions'] ?? []),
            );
        }
    }

    public function test_method_name_is_trimmed_when_frozen(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        DB::table('calculation_methods')->where('key', 'average')->update([
            'name' => '  Durchschnitt padded  ',
        ]);

        $calculation = app(CalculationWriter::class)->create($this->withLiveSchemaFingerprint([
            'planning_mode' => 'manual',
            'customer_name' => 'Trim Methodenname',
            'order_discount_percent' => '0',
            'ae_enabled' => false,
            'positions' => [[
                'inventory_id' => $catalog['hamburg']->id,
                'advertising_medium_id' => $catalog['medium']->id,
                'spot_method' => 'average',
                'length_seconds' => 30,
                'total_spot_count' => 10,
                'position_discount_percent' => '0',
                'ae_percent' => '15',
                'plan_rows' => [['hour' => 8, 'day_group' => 'mo_fr']],
            ]],
        ]), $user);

        $position = $calculation->positions()->firstOrFail();
        $this->assertSame('Durchschnitt padded', $position->calculation_method_name);
    }

    public function test_missing_default_blocks_when_no_spot_method_requested(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $spots = AdvertisingCategory::query()
            ->where('key', CanonicalAdvertisingCategories::SPOTS)
            ->firstOrFail();

        DB::table('advertising_categories')->where('id', $spots->id)->update([
            'default_calculation_method_id' => null,
        ]);

        $writer = app(CalculationWriter::class);
        $payload = $this->withLiveSchemaFingerprint([
            'planning_mode' => 'manual',
            'customer_name' => 'Ohne Default',
            'order_discount_percent' => '0',
            'ae_enabled' => false,
            'positions' => [[
                'inventory_id' => $catalog['hamburg']->id,
                'advertising_medium_id' => $catalog['medium']->id,
                'spot_method' => '',
                'length_seconds' => 30,
                'total_spot_count' => 10,
                'position_discount_percent' => '0',
                'ae_percent' => '15',
                'plan_rows' => [['hour' => 8, 'day_group' => 'mo_fr']],
            ]],
        ]);

        try {
            $writer->create($payload, $user);
            $this->fail('Erwartete ValidationException ohne Kategorie-Default.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                ['Für die Oberkategorie ist keine Standard-Berechnungsmethode hinterlegt.'],
                $exception->errors()['positions'] ?? null,
            );
        }
    }

    public function test_missing_assignment_is_not_replaced_by_spot_classic(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $spots = AdvertisingCategory::query()
            ->where('key', CanonicalAdvertisingCategories::SPOTS)
            ->firstOrFail();
        $average = CalculationMethod::query()->where('key', 'average')->firstOrFail();

        AdvertisingCategoryCalculationMethod::query()
            ->where('advertising_category_id', $spots->id)
            ->where('calculation_method_id', $average->id)
            ->delete();

        try {
            $this->createSavedCalculation($catalog, [
                ['inventory_id' => $catalog['hamburg']->id],
            ], $user);
            $this->fail('Erwartete ValidationException ohne Average-Zuordnung.');
        } catch (ValidationException $exception) {
            $message = implode(' ', $exception->errors()['positions'] ?? []);
            $this->assertTrue(
                str_contains($message, 'nicht aktiv zugeordnet')
                || str_contains($message, 'keine aktive Berechnungsmethoden-Zuordnung'),
                "Unerwartete Meldung: {$message}",
            );
            $this->assertStringNotContainsString('silent', strtolower($message));
        }
    }

    public function test_existing_frozen_position_still_works_after_live_catalog_blocks(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $calculation = $this->createSavedCalculation($catalog, [
            ['inventory_id' => $catalog['hamburg']->id, 'total_spot_count' => 4],
        ], $user);

        $spots = AdvertisingCategory::query()
            ->where('key', CanonicalAdvertisingCategories::SPOTS)
            ->firstOrFail();
        $average = CalculationMethod::query()->where('key', 'average')->firstOrFail();

        DB::table('advertising_categories')->where('id', $spots->id)->update(['is_active' => false]);
        DB::table('calculation_methods')->where('id', $average->id)->update(['is_active' => false]);
        AdvertisingCategoryCalculationMethod::query()
            ->where('advertising_category_id', $spots->id)
            ->where('calculation_method_id', $average->id)
            ->update(['is_active' => false]);
        DB::table('advertising_categories')->where('id', $spots->id)->update([
            'default_calculation_method_id' => null,
        ]);

        $writer = app(CalculationWriter::class);
        $payload = $writer->payloadFromCalculation($calculation);
        $payload['lock_version'] = $calculation->lock_version;
        $payload['campaign'] = 'Historisch trotz Katalog-Sperre';
        if (($payload['positions'][0]['time_ranges'][0] ?? null) !== null) {
            $payload['positions'][0]['time_ranges'][0]['spot_count'] = 6;
        } else {
            $payload['positions'][0]['total_spot_count'] = 6;
        }

        $updated = $writer->update($calculation, $payload, $user);
        $fresh = $updated->positions()->firstOrFail();

        $this->assertSame('Historisch trotz Katalog-Sperre', $updated->campaign);
        $this->assertSame(6, $fresh->total_spot_count);
        $this->assertSame('average', $fresh->calculation_method_key);
        $this->assertSame('v1', $fresh->algorithm_version);
        $this->assertSame('spot_classic', $fresh->engine_profile_key);
    }

    public function test_dispo_unknown_algorithm_version_blocks_with_validation_exception(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $calculation = $this->createSavedCalculation($catalog, [
            ['inventory_id' => $catalog['hamburg']->id],
        ], $user);
        $position = $calculation->positions()->firstOrFail();

        DB::table('calculation_positions')->where('id', $position->id)->update([
            'algorithm_version' => 'v999',
        ]);

        try {
            app(DispoOrderWriter::class)->createFromCalculation(
                $calculation->fresh(['positions']),
                [$position->id],
                $user,
            );
            $this->fail('Erwartete ValidationException bei unbekannter algorithm_version.');
        } catch (ValidationException $exception) {
            $this->assertSame(422, $exception->status);
            $this->assertStringContainsString(
                'unbekannt und kann nicht ausgeführt werden',
                implode(' ', $exception->errors()['positions'] ?? []),
            );
        }
    }

    public function test_dispo_planned_version_blocks_with_validation_exception(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $calculation = $this->createSavedCalculation($catalog, [
            ['inventory_id' => $catalog['hamburg']->id],
        ], $user);
        $position = $calculation->positions()->firstOrFail();

        // BL-P4-02b: calendar/v1 ist released; geplante Methode ohne Versionskatalog = fixed_price.
        DB::table('calculation_positions')->where('id', $position->id)->update([
            'kind' => 'spot_classic',
            'spot_method' => 'fixed_price',
            'engine_profile_key' => 'spot_classic',
            'calculation_method_key' => 'fixed_price',
            'calculation_method_name' => 'Festpreis',
            'algorithm_version' => 'v1',
        ]);

        try {
            app(DispoOrderWriter::class)->createFromCalculation(
                $calculation->fresh(['positions']),
                [$position->id],
                $user,
            );
            $this->fail('Erwartete ValidationException bei geplanter Version.');
        } catch (ValidationException $exception) {
            $this->assertSame(422, $exception->status);
            $this->assertStringContainsString(
                'unbekannt und kann nicht ausgeführt werden',
                implode(' ', $exception->errors()['positions'] ?? []),
            );
        }
    }

    public function test_dispo_unknown_profile_blocks_with_validation_exception(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $calculation = $this->createSavedCalculation($catalog, [
            ['inventory_id' => $catalog['hamburg']->id],
        ], $user);
        $position = $calculation->positions()->firstOrFail();

        DB::table('calculation_positions')->where('id', $position->id)->update([
            'engine_profile_key' => 'unknown_profile',
            'calculation_method_key' => 'average',
            'calculation_method_name' => 'Durchschnitt',
            'algorithm_version' => 'v1',
        ]);

        try {
            app(DispoOrderWriter::class)->createFromCalculation(
                $calculation->fresh(['positions']),
                [$position->id],
                $user,
            );
            $this->fail('Erwartete ValidationException bei unbekanntem Profil.');
        } catch (ValidationException $exception) {
            $this->assertSame(422, $exception->status);
            $message = implode(' ', $exception->errors()['positions'] ?? []);
            $this->assertTrue(
                str_contains($message, 'fachlich ungültig')
                || str_contains($message, 'unbekannt und kann nicht ausgeführt werden'),
                "Unerwartete Meldung: {$message}",
            );
        }
    }

    public function test_dispo_partial_freeze_blocks_without_500(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $calculation = $this->createSavedCalculation($catalog, [
            ['inventory_id' => $catalog['hamburg']->id],
        ], $user);
        $position = $calculation->positions()->firstOrFail();

        // Kein DDL unter RefreshDatabase (MySQL ALTER CHECK zerstört Savepoints).
        // Partielle Freeze-Daten in-memory am Modell setzen – gleicher Mapper-Pfad wie Writer.
        $attrs = $position->getAttributes();
        $attrs['calculation_method_name'] = null;
        $position->setRawAttributes($attrs, true);

        try {
            app(DispoOrderSnapshotMapper::class)
                ->positionFromCalculationPosition($position, 0);
            $this->fail('Erwartete ValidationException bei partiellem Freeze.');
        } catch (ValidationException $exception) {
            $this->assertSame(422, $exception->status);
            $this->assertSame(
                ['Die eingefrorenen Berechnungsdaten der Position sind unvollständig und können nicht verwendet werden.'],
                $exception->errors()['positions'] ?? null,
            );
        }
    }

    public function test_dispo_valid_freeze_still_copied_and_historical_read_works(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $calculation = $this->createSavedCalculation($catalog, [
            ['inventory_id' => $catalog['hamburg']->id],
        ], $user);
        $calcPosition = $calculation->positions()->firstOrFail();

        $order = app(DispoOrderWriter::class)
            ->createFromCalculation($calculation, [$calcPosition->id], $user)
            ->order;
        $dispoPosition = $order->positions()->firstOrFail();

        $this->assertSame($calcPosition->engine_profile_key, $dispoPosition->engine_profile_key);
        $this->assertSame($calcPosition->calculation_method_key, $dispoPosition->calculation_method_key);
        $this->assertSame($calcPosition->calculation_method_name, $dispoPosition->calculation_method_name);
        $this->assertSame($calcPosition->algorithm_version, $dispoPosition->algorithm_version);

        DB::table('dispo_order_positions')->where('id', $dispoPosition->id)->update([
            'algorithm_version' => 'v999',
        ]);
        $dispoPosition->refresh();

        $read = app(CalculationMethodFreezeResolver::class)
            ->resolveStoredPosition($dispoPosition, forExecution: false);
        $this->assertSame('v999', $read->algorithmVersion);
        $this->assertSame('average', $read->calculationMethodKey);
    }
}
