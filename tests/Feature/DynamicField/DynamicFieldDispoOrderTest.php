<?php

namespace Tests\Feature\DynamicField;

use App\Enums\ConfigurationSnapshotSource;
use App\Enums\DispoOrderStatus;
use App\Enums\Role;
use App\Models\AuditEvent;
use App\Models\Calculation;
use App\Models\ConfigurationSnapshot;
use App\Models\DispoOrder;
use App\Models\DispoOrderFieldValue;
use App\Models\FieldSet;
use App\Models\SnapshotFieldDefinition;
use App\Models\User;
use App\Services\DispoOrder\DispoOrderApprovalService;
use App\Services\DispoOrder\DispoOrderWriter;
use App\Services\DynamicField\DispoConfigurationSnapshotComposer;
use App\Services\DynamicField\DispoOrderDynamicFieldWriter;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\Concerns\CreatesSavedCalculation;
use Tests\Concerns\CreatesSpotClassicCatalog;
use Tests\TestCase;

class DynamicFieldDispoOrderTest extends TestCase
{
    use CreatesSavedCalculation;
    use CreatesSpotClassicCatalog;
    use RefreshDatabase;

    private function savedCalculation(): Calculation
    {
        $catalog = $this->createSpotClassicCatalog();

        return $this->createSavedCalculation($catalog, [
            ['inventory_id' => $catalog['hamburg']->id],
        ]);
    }

    public function test_create_composes_dispo_snapshot_with_five_definitions(): void
    {
        $calculation = $this->savedCalculation();
        $user = User::factory()->role(Role::Sales)->create();
        $positionIds = $calculation->positions()->pluck('id')->all();

        $order = app(DispoOrderWriter::class)
            ->createFromCalculation($calculation, $positionIds, $user)
            ->order;

        $order->refresh();
        $this->assertNotNull($order->configuration_snapshot_id);
        $snapshot = ConfigurationSnapshot::query()->findOrFail($order->configuration_snapshot_id);
        $this->assertSame(ConfigurationSnapshotSource::DispoOrderCreate, $snapshot->source);
        $this->assertSame(
            (int) $calculation->configuration_snapshot_id,
            (int) $snapshot->source_configuration_snapshot_id,
        );
        $this->assertSame(
            'system_dispo_order_core',
            FieldSet::query()->findOrFail($snapshot->field_set_id)->key,
        );

        $keys = SnapshotFieldDefinition::query()
            ->where('configuration_snapshot_id', $snapshot->id)
            ->orderBy('key')
            ->pluck('key')
            ->all();
        $this->assertSame([
            'billing_special_features',
            'campaign_period',
            'disposition_notes',
            'period_open',
            'position_flight_period',
        ], $keys);

        $this->assertSame(0, DispoOrderFieldValue::query()->where('dispo_order_id', $order->id)->whereHas(
            'snapshotFieldDefinition',
            fn ($q) => $q->whereIn('key', ['billing_special_features', 'disposition_notes']),
        )->count());

        $this->assertTrue(
            DispoOrderFieldValue::query()->where('dispo_order_id', $order->id)->whereHas(
                'snapshotFieldDefinition',
                fn ($q) => $q->where('key', 'campaign_period'),
            )->exists(),
        );

        foreach ($order->positions as $position) {
            $this->assertTrue(
                $position->fieldValues()
                    ->whereHas('snapshotFieldDefinition', fn ($q) => $q->where('key', 'period_open'))
                    ->exists(),
            );
            $this->assertTrue(
                $position->fieldValues()
                    ->whereHas('snapshotFieldDefinition', fn ($q) => $q->where('key', 'position_flight_period'))
                    ->exists(),
            );
        }

        $values = app(DispoOrderDynamicFieldWriter::class)->valuesProp($order);
        $this->assertTrue($values['header_captured']['campaign_period'] ?? false);
        $this->assertSame([], $values['missing_calc_origin_keys']);
    }

    public function test_draft_update_saves_texts_and_audit_and_noop_skips_audit(): void
    {
        $calculation = $this->savedCalculation();
        $user = User::factory()->role(Role::Sales)->create();
        $order = app(DispoOrderWriter::class)
            ->createFromCalculation($calculation, $calculation->positions()->pluck('id')->all(), $user)
            ->order;

        $this->actingAs($user)->patch(route('dispo-orders.update', $order), [
            'lock_version' => $order->lock_version,
            'dynamic_field_values' => [
                'billing_special_features' => 'Rechnungshinweis',
                'disposition_notes' => 'Dispohinweis',
            ],
        ])->assertRedirect(route('dispo-orders.show', $order));

        $order->refresh();
        $this->assertSame(2, $order->lock_version);
        $texts = $order->fieldValues()->with('snapshotFieldDefinition')->get()
            ->mapWithKeys(fn ($row) => [$row->snapshotFieldDefinition->key => $row->value_text])
            ->all();
        $this->assertSame('Rechnungshinweis', $texts['billing_special_features']);
        $this->assertSame('Dispohinweis', $texts['disposition_notes']);

        $this->assertTrue(
            AuditEvent::query()
                ->where('auditable_type', DispoOrder::class)
                ->where('auditable_id', $order->id)
                ->where('action', 'dispo_order.updated')
                ->exists(),
        );

        $auditCount = AuditEvent::query()->where('action', 'dispo_order.updated')->count();
        $this->actingAs($user)->patch(route('dispo-orders.update', $order), [
            'lock_version' => $order->lock_version,
            'dynamic_field_values' => [
                'billing_special_features' => 'Rechnungshinweis',
                'disposition_notes' => 'Dispohinweis',
            ],
        ])->assertRedirect();
        $order->refresh();
        $this->assertSame(2, $order->lock_version);
        $this->assertSame($auditCount, AuditEvent::query()->where('action', 'dispo_order.updated')->count());
    }

    public function test_disposition_cannot_update_draft_texts(): void
    {
        $calculation = $this->savedCalculation();
        $sales = User::factory()->role(Role::Sales)->create();
        $disposition = User::factory()->role(Role::Disposition)->create();
        $order = app(DispoOrderWriter::class)
            ->createFromCalculation($calculation, $calculation->positions()->pluck('id')->all(), $sales)
            ->order;

        $this->actingAs($disposition)->patch(route('dispo-orders.update', $order), [
            'lock_version' => $order->lock_version,
            'dynamic_field_values' => [
                'billing_special_features' => 'x',
                'disposition_notes' => null,
            ],
        ])->assertForbidden();
    }

    public function test_unknown_and_calc_origin_keys_are_rejected(): void
    {
        $calculation = $this->savedCalculation();
        $user = User::factory()->role(Role::Sales)->create();
        $order = app(DispoOrderWriter::class)
            ->createFromCalculation($calculation, $calculation->positions()->pluck('id')->all(), $user)
            ->order;

        $this->actingAs($user)->patch(route('dispo-orders.update', $order), [
            'lock_version' => $order->lock_version,
            'dynamic_field_values' => [
                'billing_special_features' => null,
                'disposition_notes' => null,
                'campaign_period' => ['start' => '2026-01-01', 'end' => '2026-01-31'],
            ],
        ])->assertSessionHasErrors('dynamic_field_values.campaign_period');

        $this->actingAs($user)->patch(route('dispo-orders.update', $order), [
            'lock_version' => $order->lock_version,
            'dynamic_field_values' => [
                'billing_special_features' => null,
                'disposition_notes' => null,
                'unknown_field' => 'x',
            ],
        ])->assertSessionHasErrors('dynamic_field_values.unknown_field');
    }

    public function test_text_max_20000_and_multibyte_boundary(): void
    {
        $calculation = $this->savedCalculation();
        $user = User::factory()->role(Role::Sales)->create();
        $order = app(DispoOrderWriter::class)
            ->createFromCalculation($calculation, $calculation->positions()->pluck('id')->all(), $user)
            ->order;

        $ok = str_repeat('ä', 20000);
        $this->actingAs($user)->patch(route('dispo-orders.update', $order), [
            'lock_version' => $order->lock_version,
            'dynamic_field_values' => [
                'billing_special_features' => $ok,
                'disposition_notes' => null,
            ],
        ])->assertRedirect();

        $order->refresh();
        $this->actingAs($user)->patch(route('dispo-orders.update', $order), [
            'lock_version' => $order->lock_version,
            'dynamic_field_values' => [
                'billing_special_features' => $ok.'x',
                'disposition_notes' => null,
            ],
        ])->assertSessionHasErrors('dynamic_field_values.billing_special_features');
    }

    public function test_revision_copies_texts_by_key_with_new_definition_ids(): void
    {
        $calculation = $this->savedCalculation();
        $creator = User::factory()->role(Role::Sales)->create();
        $approver = User::factory()->role(Role::Sales)->create();
        $writer = app(DispoOrderWriter::class);
        $approvals = app(DispoOrderApprovalService::class);

        $predecessor = $writer->createFromCalculation(
            $calculation,
            $calculation->positions()->pluck('id')->all(),
            $creator,
        )->order;

        $this->actingAs($creator)->patch(route('dispo-orders.update', $predecessor), [
            'lock_version' => $predecessor->lock_version,
            'dynamic_field_values' => [
                'billing_special_features' => 'Alt-Rechnung',
                'disposition_notes' => 'Alt-Dispo',
            ],
        ])->assertRedirect();
        $predecessor->refresh();

        $approvals->submit($predecessor, $creator, $predecessor->lock_version);
        $predecessor->refresh();
        $approvals->reject($predecessor, $approver, $predecessor->lock_version, 'Bitte nachbessern');
        $predecessor->refresh();

        $oldDefIds = SnapshotFieldDefinition::query()
            ->where('configuration_snapshot_id', $predecessor->configuration_snapshot_id)
            ->whereIn('key', ['billing_special_features', 'disposition_notes'])
            ->pluck('id', 'key');

        $successor = $writer->createRevision(
            $predecessor,
            $calculation->fresh(['positions', 'configurationSnapshot']),
            $calculation->positions()->pluck('id')->all(),
            $creator,
        )->order;

        $newDefIds = SnapshotFieldDefinition::query()
            ->where('configuration_snapshot_id', $successor->configuration_snapshot_id)
            ->whereIn('key', ['billing_special_features', 'disposition_notes'])
            ->pluck('id', 'key');

        $this->assertNotSame((int) $oldDefIds['billing_special_features'], (int) $newDefIds['billing_special_features']);
        $this->assertNotSame((int) $predecessor->configuration_snapshot_id, (int) $successor->configuration_snapshot_id);

        $texts = $successor->fieldValues()->with('snapshotFieldDefinition')->get()
            ->mapWithKeys(fn ($row) => [$row->snapshotFieldDefinition->key => $row->value_text])
            ->all();
        $this->assertSame('Alt-Rechnung', $texts['billing_special_features']);
        $this->assertSame('Alt-Dispo', $texts['disposition_notes']);

        $predTexts = $predecessor->fresh()->fieldValues()->with('snapshotFieldDefinition')->get()
            ->mapWithKeys(fn ($row) => [$row->snapshotFieldDefinition->key => $row->value_text])
            ->all();
        $this->assertSame('Alt-Rechnung', $predTexts['billing_special_features']);
    }

    public function test_legacy_upgrade_backfills_snapshot_without_inventing_values(): void
    {
        $calculation = $this->savedCalculation();
        $user = User::factory()->role(Role::Sales)->create();

        /** @var object $migration */
        $migration = require database_path('migrations/2026_09_04_140000_create_dynamic_field_dispo_tables.php');
        $dispoSet = DB::table('field_sets')->where('key', 'system_dispo_order_core')->first();
        $this->assertNotNull($dispoSet);

        $compose = new \ReflectionMethod($migration, 'composeSnapshot');
        $compose->setAccessible(true);
        $snapshotId = $compose->invoke(
            $migration,
            (int) $dispoSet->id,
            (int) $dispoSet->active_version_id,
            (int) $calculation->configuration_snapshot_id,
            'dispo_order_legacy_backfill',
        );

        $this->assertSame(
            'dispo_order_legacy_backfill',
            DB::table('configuration_snapshots')->where('id', $snapshotId)->value('source'),
        );
        $this->assertSame(
            (int) $calculation->configuration_snapshot_id,
            (int) DB::table('configuration_snapshots')->where('id', $snapshotId)->value('source_configuration_snapshot_id'),
        );
        $this->assertSame(
            5,
            DB::table('snapshot_field_definitions')->where('configuration_snapshot_id', $snapshotId)->count(),
        );
        $this->assertSame(
            0,
            DB::table('dispo_order_field_values')->count(),
        );
        $this->assertSame(
            0,
            DB::table('dispo_order_position_field_values')->count(),
        );

        $order = DispoOrder::query()->create([
            'calculation_id' => $calculation->id,
            'number' => 'DA-2026-00999-01',
            'number_year' => 2026,
            'number_org_seq' => 999,
            'number_calc_seq' => 1,
            'status' => DispoOrderStatus::AtDisposition,
            'created_by_id' => $user->id,
            'source_calculation_number' => $calculation->number,
            'customer_name' => 'Legacy',
            'lock_version' => 1,
            'configuration_snapshot_id' => $snapshotId,
            'approval_kind' => 'regular',
            'order_discounts_snapshot' => [],
            'source_calculation_totals_snapshot' => [],
        ]);

        $values = app(DispoOrderDynamicFieldWriter::class)->valuesProp($order);
        $this->assertFalse($values['header_captured']['campaign_period'] ?? true);
        $this->assertContains('campaign_period', $values['missing_calc_origin_keys']);
        $this->assertSame(0, $order->fieldValues()->count());

        $backfill = new \ReflectionMethod($migration, 'backfillExistingDispoOrders');
        $backfill->setAccessible(true);
        $before = ConfigurationSnapshot::query()
            ->where('source', ConfigurationSnapshotSource::DispoOrderLegacyBackfill)
            ->count();
        $backfill->invoke($migration);
        $this->assertSame(
            $before,
            ConfigurationSnapshot::query()
                ->where('source', ConfigurationSnapshotSource::DispoOrderLegacyBackfill)
                ->count(),
        );
        $order->refresh();
        $this->assertSame((int) $snapshotId, (int) $order->configuration_snapshot_id);
    }

    public function test_insert_without_configuration_snapshot_id_fails(): void
    {
        $calculation = $this->savedCalculation();
        $user = User::factory()->role(Role::Sales)->create();

        $this->expectException(QueryException::class);

        DB::table('dispo_orders')->insert([
            'calculation_id' => $calculation->id,
            'number' => 'DA-2026-00998-01',
            'number_year' => 2026,
            'number_org_seq' => 998,
            'number_calc_seq' => 1,
            'status' => DispoOrderStatus::Draft->value,
            'created_by_id' => $user->id,
            'source_calculation_number' => $calculation->number,
            'customer_name' => 'Ohne Snapshot',
            'agency_name' => null,
            'campaign' => null,
            'product_title' => null,
            'briefing' => null,
            'advisor_id' => null,
            'advisor_name' => null,
            'order_discount_percent' => 0,
            'ae_enabled' => false,
            'target_budget_nn' => null,
            'media_gross' => 0,
            'position_discount_total' => 0,
            'order_discount_total' => 0,
            'ae_total' => 0,
            'nn_invest' => 0,
            'requires_special_approval' => false,
            'approval_kind' => 'regular',
            'special_approval_reasons' => null,
            'order_discounts_snapshot' => json_encode([]),
            'source_calculation_totals_snapshot' => json_encode([]),
            'lock_version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_calc_change_does_not_mutate_existing_dispo_dynamic_values(): void
    {
        $calculation = $this->savedCalculation();
        $user = User::factory()->role(Role::Sales)->create();
        $order = app(DispoOrderWriter::class)
            ->createFromCalculation($calculation, $calculation->positions()->pluck('id')->all(), $user)
            ->order;

        $before = $order->positions()->with('fieldValues.snapshotFieldDefinition')->get()
            ->map(fn ($p) => $p->fieldValues->mapWithKeys(
                fn ($v) => [$v->snapshotFieldDefinition->key => $v->value_boolean],
            )->all())
            ->all();

        $calculation->customer_name = 'Geändert nach DA';
        $calculation->save();

        $order->refresh()->load('positions.fieldValues.snapshotFieldDefinition');
        $after = $order->positions->map(fn ($p) => $p->fieldValues->mapWithKeys(
            fn ($v) => [$v->snapshotFieldDefinition->key => $v->value_boolean],
        )->all())->all();

        $this->assertSame($before, $after);
    }

    public function test_legacy_draft_submit_blocked_until_sync(): void
    {
        $calculation = $this->savedCalculation();
        $user = User::factory()->role(Role::Sales)->create();
        $writer = app(DispoOrderWriter::class);
        $order = $writer->createFromCalculation(
            $calculation,
            $calculation->positions()->pluck('id')->all(),
            $user,
        )->order;

        // Simuliere Legacy: Capture-Zeilen entfernen, Snapshot behalten.
        DispoOrderFieldValue::query()->where('dispo_order_id', $order->id)->delete();
        foreach ($order->positions as $position) {
            $position->fieldValues()->delete();
        }

        $this->actingAs($user)->post(route('dispo-orders.submit', $order), [
            'lock_version' => $order->lock_version,
        ])->assertSessionHasErrors('dynamic_field_values');

        $order->refresh();
        $this->assertSame(DispoOrderStatus::Draft, $order->status);
        $this->assertSame(1, $order->lock_version);
        $this->assertFalse(
            AuditEvent::query()
                ->where('auditable_type', DispoOrder::class)
                ->where('auditable_id', $order->id)
                ->where('action', 'dispo_order.submitted_for_approval')
                ->exists(),
        );

        $this->actingAs($user)->post(route('dispo-orders.sync-calculation-dynamic-fields', $order), [
            'lock_version' => $order->lock_version,
        ])->assertRedirect(route('dispo-orders.show', $order));

        $order->refresh();
        $this->assertSame(2, $order->lock_version);
        $this->assertTrue(
            AuditEvent::query()
                ->where('action', 'dispo_order.calculation_dynamic_fields_synced')
                ->where('auditable_id', $order->id)
                ->exists(),
        );

        $values = app(DispoOrderDynamicFieldWriter::class)->valuesProp($order);
        $this->assertSame([], $values['missing_calc_origin_keys']);
        $this->assertTrue($values['header_captured']['campaign_period']);

        $this->actingAs($user)->post(route('dispo-orders.submit', $order), [
            'lock_version' => $order->lock_version,
        ])->assertRedirect();

        $order->refresh();
        $this->assertSame(DispoOrderStatus::AwaitingSalesApproval, $order->status);
    }

    public function test_sync_is_noop_when_complete_and_rejects_stale_lock_and_disposition(): void
    {
        $calculation = $this->savedCalculation();
        $user = User::factory()->role(Role::Sales)->create();
        $disposition = User::factory()->role(Role::Disposition)->create();
        $order = app(DispoOrderWriter::class)
            ->createFromCalculation($calculation, $calculation->positions()->pluck('id')->all(), $user)
            ->order;

        $auditCount = AuditEvent::query()
            ->where('action', 'dispo_order.calculation_dynamic_fields_synced')
            ->count();

        $this->actingAs($user)->post(route('dispo-orders.sync-calculation-dynamic-fields', $order), [
            'lock_version' => $order->lock_version,
        ])->assertRedirect();
        $order->refresh();
        $this->assertSame(1, $order->lock_version);
        $this->assertSame(
            $auditCount,
            AuditEvent::query()->where('action', 'dispo_order.calculation_dynamic_fields_synced')->count(),
        );

        $this->actingAs($user)->post(route('dispo-orders.sync-calculation-dynamic-fields', $order), [
            'lock_version' => 999,
        ])->assertStatus(409);

        $this->actingAs($disposition)->post(route('dispo-orders.sync-calculation-dynamic-fields', $order), [
            'lock_version' => $order->lock_version,
        ])->assertForbidden();
    }

    public function test_sync_does_not_overwrite_existing_values_or_texts(): void
    {
        $calculation = $this->savedCalculation();
        $user = User::factory()->role(Role::Sales)->create();
        $order = app(DispoOrderWriter::class)
            ->createFromCalculation($calculation, $calculation->positions()->pluck('id')->all(), $user)
            ->order;

        $this->actingAs($user)->patch(route('dispo-orders.update', $order), [
            'lock_version' => $order->lock_version,
            'dynamic_field_values' => [
                'billing_special_features' => 'Bleibt',
                'disposition_notes' => 'Auch',
            ],
        ])->assertRedirect();
        $order->refresh();

        $campaignDefId = SnapshotFieldDefinition::query()
            ->where('configuration_snapshot_id', $order->configuration_snapshot_id)
            ->where('key', 'campaign_period')
            ->value('id');
        DispoOrderFieldValue::query()
            ->where('dispo_order_id', $order->id)
            ->where('snapshot_field_definition_id', $campaignDefId)
            ->delete();

        $this->actingAs($user)->post(route('dispo-orders.sync-calculation-dynamic-fields', $order), [
            'lock_version' => $order->lock_version,
        ])->assertRedirect();

        $order->refresh();
        $texts = $order->fieldValues()->with('snapshotFieldDefinition')->get()
            ->mapWithKeys(fn ($row) => [$row->snapshotFieldDefinition->key => $row->value_text])
            ->all();
        $this->assertSame('Bleibt', $texts['billing_special_features']);
        $this->assertSame('Auch', $texts['disposition_notes']);
        $this->assertTrue(
            DispoOrderFieldValue::query()
                ->where('dispo_order_id', $order->id)
                ->where('snapshot_field_definition_id', $campaignDefId)
                ->exists(),
        );
    }

    public function test_draft_update_stale_lock_returns_409(): void
    {
        $calculation = $this->savedCalculation();
        $user = User::factory()->role(Role::Sales)->create();
        $order = app(DispoOrderWriter::class)
            ->createFromCalculation($calculation, $calculation->positions()->pluck('id')->all(), $user)
            ->order;

        $this->actingAs($user)->patch(route('dispo-orders.update', $order), [
            'lock_version' => 999,
            'dynamic_field_values' => [
                'billing_special_features' => 'x',
                'disposition_notes' => null,
            ],
        ])->assertStatus(409);
    }

    public function test_composer_rejects_dispo_snapshot_as_source(): void
    {
        $calculation = $this->savedCalculation();
        $user = User::factory()->role(Role::Sales)->create();
        $order = app(DispoOrderWriter::class)
            ->createFromCalculation($calculation, $calculation->positions()->pluck('id')->all(), $user)
            ->order;

        $dispoSnapshot = ConfigurationSnapshot::query()->findOrFail($order->configuration_snapshot_id);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('bereits ein Dispo-Snapshot');

        app(DispoConfigurationSnapshotComposer::class)
            ->composeFromCalculationSnapshot($dispoSnapshot);
    }

    public function test_failed_create_does_not_leave_orphan_dispo_snapshot(): void
    {
        $calculation = $this->savedCalculation();
        $user = User::factory()->role(Role::Sales)->create();
        $calcSnapshotId = (int) $calculation->configuration_snapshot_id;

        $periodOpenDefId = SnapshotFieldDefinition::query()
            ->where('configuration_snapshot_id', $calcSnapshotId)
            ->where('key', 'period_open')
            ->value('id');
        $flightDefId = SnapshotFieldDefinition::query()
            ->where('configuration_snapshot_id', $calcSnapshotId)
            ->where('key', 'position_flight_period')
            ->value('id');

        foreach ($calculation->positions as $position) {
            DB::table('calculation_position_field_values')
                ->where('calculation_position_id', $position->id)
                ->where('snapshot_field_definition_id', $periodOpenDefId)
                ->update(['value_boolean' => false]);
            DB::table('calculation_position_field_values')
                ->where('calculation_position_id', $position->id)
                ->where('snapshot_field_definition_id', $flightDefId)
                ->delete();
        }

        $before = ConfigurationSnapshot::query()
            ->where('source', ConfigurationSnapshotSource::DispoOrderCreate)
            ->count();

        try {
            app(DispoOrderWriter::class)->createFromCalculation(
                $calculation->fresh(['positions', 'configurationSnapshot']),
                $calculation->positions()->pluck('id')->all(),
                $user,
            );
            $this->fail('Expected validation exception');
        } catch (ValidationException) {
            // expected: period_open=false ohne Flugzeitraum
        }

        $this->assertSame(
            $before,
            ConfigurationSnapshot::query()
                ->where('source', ConfigurationSnapshotSource::DispoOrderCreate)
                ->count(),
        );
        $this->assertSame(0, DispoOrder::query()->where('calculation_id', $calculation->id)->count());
    }

    public function test_revision_allows_missing_optional_text_value_row(): void
    {
        $calculation = $this->savedCalculation();
        $creator = User::factory()->role(Role::Sales)->create();
        $approver = User::factory()->role(Role::Sales)->create();
        $writer = app(DispoOrderWriter::class);
        $approvals = app(DispoOrderApprovalService::class);

        $predecessor = $writer->createFromCalculation(
            $calculation,
            $calculation->positions()->pluck('id')->all(),
            $creator,
        )->order;

        $approvals->submit($predecessor, $creator, $predecessor->lock_version);
        $predecessor->refresh();
        $approvals->reject($predecessor, $approver, $predecessor->lock_version, 'Bitte nachbessern');
        $predecessor->refresh();

        $successor = $writer->createRevision(
            $predecessor,
            $calculation->fresh(['positions', 'configurationSnapshot']),
            $calculation->positions()->pluck('id')->all(),
            $creator,
        )->order;

        $this->assertSame(
            0,
            $successor->fieldValues()->whereHas(
                'snapshotFieldDefinition',
                fn ($q) => $q->whereIn('key', ['billing_special_features', 'disposition_notes']),
            )->count(),
        );
    }

    public function test_product_management_cannot_update_or_view_manage_paths(): void
    {
        $calculation = $this->savedCalculation();
        $sales = User::factory()->role(Role::Sales)->create();
        $pm = User::factory()->role(Role::ProductManagement)->create();
        $order = app(DispoOrderWriter::class)
            ->createFromCalculation($calculation, $calculation->positions()->pluck('id')->all(), $sales)
            ->order;

        $this->actingAs($pm)->get(route('dispo-orders.show', $order))->assertForbidden();
        $this->actingAs($pm)->patch(route('dispo-orders.update', $order), [
            'lock_version' => $order->lock_version,
            'dynamic_field_values' => [
                'billing_special_features' => 'x',
                'disposition_notes' => null,
            ],
        ])->assertForbidden();
    }
}
