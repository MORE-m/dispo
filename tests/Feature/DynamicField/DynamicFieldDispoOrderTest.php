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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

        foreach ($order->positions as $position) {
            $this->assertTrue(
                $position->fieldValues()
                    ->whereHas('snapshotFieldDefinition', fn ($q) => $q->where('key', 'period_open'))
                    ->exists(),
            );
        }
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

        $orderId = DB::table('dispo_orders')->insertGetId([
            'calculation_id' => $calculation->id,
            'number' => 'DA-2026-00999-01',
            'number_year' => 2026,
            'number_org_seq' => 999,
            'number_calc_seq' => 1,
            'status' => DispoOrderStatus::AtDisposition->value,
            'created_by_id' => $user->id,
            'source_calculation_number' => $calculation->number,
            'customer_name' => 'Legacy',
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
            'configuration_snapshot_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        /** @var object $migration */
        $migration = require database_path('migrations/2026_09_04_140000_create_dynamic_field_dispo_tables.php');
        $method = new \ReflectionMethod($migration, 'backfillExistingDispoOrders');
        $method->setAccessible(true);
        $method->invoke($migration);

        $order = DispoOrder::query()->findOrFail($orderId);
        $this->assertNotNull($order->configuration_snapshot_id);
        $this->assertSame(
            ConfigurationSnapshotSource::DispoOrderLegacyBackfill,
            $order->configurationSnapshot->source,
        );
        $this->assertSame(
            (int) $calculation->configuration_snapshot_id,
            (int) $order->configurationSnapshot->source_configuration_snapshot_id,
        );
        $this->assertSame(DispoOrderStatus::AtDisposition, $order->status);
        $this->assertSame(0, $order->fieldValues()->count());
        $this->assertSame(
            5,
            SnapshotFieldDefinition::query()
                ->where('configuration_snapshot_id', $order->configuration_snapshot_id)
                ->count(),
        );

        // Idempotent: zweiter Lauf ändert nichts
        $snapId = $order->configuration_snapshot_id;
        $method->invoke($migration);
        $order->refresh();
        $this->assertSame((int) $snapId, (int) $order->configuration_snapshot_id);
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
}
