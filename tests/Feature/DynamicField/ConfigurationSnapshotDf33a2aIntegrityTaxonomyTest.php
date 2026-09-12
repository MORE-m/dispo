<?php

namespace Tests\Feature\DynamicField;

use App\Enums\FieldAppliesTo;
use App\Enums\FieldScope;
use App\Enums\FieldType;
use App\Enums\Role;
use App\Models\ConfigurationSnapshot;
use App\Models\ConfigurationSnapshotSource;
use App\Models\ConfigurationSnapshotSourceRule;
use App\Models\FieldDefinition;
use App\Models\FieldSet;
use App\Models\FieldSetVersion;
use App\Models\SnapshotFieldRule;
use App\Models\User;
use App\Services\DispoOrder\DispoOrderWriter;
use App\Services\DynamicField\Admin\FieldDefinitionCustomWriter;
use App\Services\DynamicField\Assignment\FieldSetAssignmentAdminWriter;
use App\Services\DynamicField\Assignment\FieldSetAssignmentMergeResolver;
use App\Services\DynamicField\ConfigurationSnapshotCloneService;
use App\Services\DynamicField\ConfigurationSnapshotFreezeService;
use App\Services\DynamicField\ConfigurationSnapshotMaterializer;
use App\Services\DynamicField\SnapshotFieldRuleDedupeKey;
use App\Support\DynamicField\FieldRuleContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Concerns\CreatesSavedCalculation;
use Tests\Concerns\CreatesSpotClassicCatalog;
use Tests\TestCase;

/**
 * DF-3.3a2α Nachhärtung: Source-Taxonomie und eindeutige Rule-Provenance.
 */
class ConfigurationSnapshotDf33a2aIntegrityTaxonomyTest extends TestCase
{
    use CreatesSavedCalculation;
    use CreatesSpotClassicCatalog;
    use RefreshDatabase;

    public function test_unknown_source_role_is_rejected(): void
    {
        $snapshot = $this->calcSnapshotWithGlobalAssignment();
        $sourceId = (int) ConfigurationSnapshotSource::query()
            ->where('configuration_snapshot_id', $snapshot->id)
            ->where('role', ConfigurationSnapshotSource::ROLE_CORE)
            ->value('id');

        DB::table('configuration_snapshot_sources')
            ->where('id', $sourceId)
            ->update(['role' => 'ghost']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/unbekannte Source-Rolle/');
        $snapshot->fresh(['sources'])->assertReadable();
    }

    public function test_assignment_with_category_or_medium_target_is_rejected(): void
    {
        $snapshot = $this->calcSnapshotWithGlobalAssignment();
        $assignment = $this->firstAssignmentSource($snapshot);

        DB::table('configuration_snapshot_sources')
            ->where('id', $assignment->id)
            ->update([
                'target_layer' => FieldSetAssignmentMergeResolver::LAYER_ADVERTISING_CATEGORY,
                'target_identity' => 'c:1',
                'target_id' => 1,
            ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/target_layer=global|Kategorie-|passt nicht zu target_layer/');
        $snapshot->fresh(['sources'])->assertReadable();
    }

    public function test_assignment_with_wrong_target_identity_is_rejected(): void
    {
        $snapshot = $this->calcSnapshotWithGlobalAssignment();
        $assignment = $this->firstAssignmentSource($snapshot);

        DB::table('configuration_snapshot_sources')
            ->where('id', $assignment->id)
            ->update(['target_identity' => 'c:99']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/target_identity/');
        $snapshot->fresh(['sources'])->assertReadable();
    }

    public function test_inactive_frozen_assignment_is_rejected(): void
    {
        $snapshot = $this->calcSnapshotWithGlobalAssignment();
        $assignment = $this->firstAssignmentSource($snapshot);

        DB::table('configuration_snapshot_sources')
            ->where('id', $assignment->id)
            ->update(['assignment_is_active' => 0]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/assignment_is_active=true/');
        $snapshot->fresh(['sources'])->assertReadable();
    }

    public function test_process_incompatible_assignment_is_rejected(): void
    {
        $snapshot = $this->calcSnapshotWithGlobalAssignment();
        $assignment = $this->firstAssignmentSource($snapshot);

        DB::table('configuration_snapshot_sources')
            ->where('id', $assignment->id)
            ->update(['assignment_applies_to_process' => FieldAppliesTo::DispoOrder->value]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/prozessfremd/');
        $snapshot->fresh(['sources'])->assertReadable();
    }

    public function test_illegal_additional_source_on_calculation_is_rejected(): void
    {
        $snapshot = $this->calcSnapshotWithGlobalAssignment();
        $core = ConfigurationSnapshotSource::query()
            ->where('configuration_snapshot_id', $snapshot->id)
            ->where('role', ConfigurationSnapshotSource::ROLE_CORE)
            ->firstOrFail();

        DB::table('configuration_snapshot_sources')->insert([
            'configuration_snapshot_id' => $snapshot->id,
            'merge_order' => 99,
            'layer' => FieldSetAssignmentMergeResolver::LAYER_PRIMARY_CORE,
            'role' => ConfigurationSnapshotSource::ROLE_ADDITIONAL,
            'field_set_id' => $core->field_set_id,
            'field_set_key' => $core->field_set_key,
            'field_set_name' => $core->field_set_name,
            'field_set_version_id' => $core->field_set_version_id,
            'field_set_version_number' => $core->field_set_version_number,
            'target_layer' => FieldSetAssignmentMergeResolver::LAYER_GLOBAL,
            'target_identity' => ConfigurationSnapshotSource::TARGET_IDENTITY_CALC_ORIGIN,
            'target_name' => 'Calc-Origin',
            'created_at' => now(),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/additional-Source|Calc-v2 darf keine/');
        $snapshot->fresh(['sources'])->assertReadable();
    }

    public function test_manipulated_snapshot_rule_dedupe_key_is_rejected(): void
    {
        $snapshot = $this->freshV2CalcSnapshot();
        $rule = SnapshotFieldRule::query()
            ->where('configuration_snapshot_id', $snapshot->id)
            ->firstOrFail();

        DB::table('snapshot_field_rules')
            ->where('id', $rule->id)
            ->update(['dedupe_key' => str_repeat('b', 64)]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/dedupe_key stimmt nicht mit condition\/action/');
        $snapshot->fresh(['fieldDefinitions', 'rules', 'sources.rules'])->assertReadable();
    }

    public function test_manipulated_source_rule_dedupe_key_is_rejected(): void
    {
        $snapshot = $this->freshV2CalcSnapshot();
        $sourceRule = ConfigurationSnapshotSourceRule::query()
            ->whereIn(
                'configuration_snapshot_source_id',
                ConfigurationSnapshotSource::query()
                    ->where('configuration_snapshot_id', $snapshot->id)
                    ->pluck('id'),
            )
            ->firstOrFail();

        DB::table('configuration_snapshot_source_rules')
            ->where('id', $sourceRule->id)
            ->update(['dedupe_key' => str_repeat('c', 64)]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Source-Rule .*dedupe_key stimmt nicht/');
        $snapshot->fresh(['fieldDefinitions', 'rules', 'sources.rules'])->assertReadable();
    }

    public function test_same_rule_id_with_divergent_dedupe_or_content_is_rejected(): void
    {
        $snapshot = $this->freshV2CalcSnapshot();
        $rule = SnapshotFieldRule::query()
            ->where('configuration_snapshot_id', $snapshot->id)
            ->firstOrFail();

        // Semantik ändern (nicht unbekannte Keys anhängen) – Kanonisierung
        // behält nur op/field_key/value; unbekannte Attribute werden verworfen.
        $tamperedCondition = $rule->condition_json;
        $tamperedCondition['value'] = true;
        $rule->condition_json = $tamperedCondition;
        $rule->save();
        $rule->refresh();
        $rule->dedupe_key = SnapshotFieldRuleDedupeKey::from($rule->condition_json, $rule->action_json);
        $rule->save();

        $this->assertNotSame(
            $rule->dedupe_key,
            ConfigurationSnapshotSourceRule::query()
                ->where('configuration_snapshot_source_id', $rule->provenance_source_id)
                ->where('source_field_rule_id', $rule->source_field_rule_id)
                ->value('dedupe_key'),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/passende Source-Rule \(Rule-ID und Dedupe-Key\)/');
        $snapshot->fresh(['fieldDefinitions', 'rules', 'sources.rules'])->assertReadable();
    }

    public function test_same_dedupe_key_with_wrong_rule_id_is_rejected(): void
    {
        $snapshot = $this->freshV2CalcSnapshot();
        $rule = SnapshotFieldRule::query()
            ->where('configuration_snapshot_id', $snapshot->id)
            ->firstOrFail();

        $sourceRule = ConfigurationSnapshotSourceRule::query()
            ->where('configuration_snapshot_source_id', $rule->provenance_source_id)
            ->where('dedupe_key', $rule->dedupe_key)
            ->firstOrFail();

        DB::table('configuration_snapshot_source_rules')
            ->where('id', $sourceRule->id)
            ->update(['source_field_rule_id' => ((int) $sourceRule->source_field_rule_id) + 9_000_001]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/passende Source-Rule \(Rule-ID und Dedupe-Key\)/');
        $snapshot->fresh(['fieldDefinitions', 'rules', 'sources.rules'])->assertReadable();
    }

    public function test_valid_calc_and_dispo_v2_snapshots_remain_readable(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $calculation = $this->createSavedCalculation($catalog, [
            ['inventory_id' => $catalog['hamburg']->id],
        ]);
        $calcSnapshot = ConfigurationSnapshot::query()
            ->with(['fieldDefinitions', 'rules', 'sources.fields', 'sources.rules'])
            ->findOrFail($calculation->configuration_snapshot_id);
        $calcSnapshot->assertReadable();

        $user = User::factory()->role(Role::Sales)->create();
        $order = app(DispoOrderWriter::class)
            ->createFromCalculation($calculation, $calculation->positions()->pluck('id')->all(), $user)
            ->order;
        $dispoSnapshot = ConfigurationSnapshot::query()
            ->with(['fieldDefinitions', 'rules', 'sources.fields', 'sources.rules'])
            ->findOrFail($order->configuration_snapshot_id);
        $dispoSnapshot->assertReadable();

        $seedKey = FieldRuleContract::SEED_RULE_DEDUPE_SHA256;
        $positionSnapshotIds = $order->positions()
            ->whereNotNull('effective_configuration_snapshot_id')
            ->pluck('effective_configuration_snapshot_id');
        $seedExists = SnapshotFieldRule::query()
            ->whereIn('configuration_snapshot_id', $positionSnapshotIds->push($dispoSnapshot->id)->all())
            ->where('dedupe_key', $seedKey)
            ->exists();
        $this->assertTrue(
            $seedExists,
            'DF-1-Seed-Regel muss in Dispo-Basis oder Positions-Effektiv lesbar bleiben.',
        );
    }

    public function test_extra_condition_property_with_recomputed_dedupe_is_rejected(): void
    {
        $snapshot = $this->freshV2CalcSnapshot();
        $rule = SnapshotFieldRule::query()
            ->where('configuration_snapshot_id', $snapshot->id)
            ->firstOrFail();

        $tampered = $rule->condition_json;
        $tampered['extra'] = 'x';
        $rule->condition_json = $tampered;
        $rule->dedupe_key = SnapshotFieldRuleDedupeKey::from($rule->condition_json, $rule->action_json);
        $rule->save();

        DB::table('configuration_snapshot_source_rules')
            ->where('configuration_snapshot_source_id', $rule->provenance_source_id)
            ->where('source_field_rule_id', $rule->source_field_rule_id)
            ->update([
                'condition_json' => json_encode($rule->condition_json, JSON_THROW_ON_ERROR),
                'dedupe_key' => $rule->dedupe_key,
            ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/unzulässiges Attribut|unerwartetes Attribut|Bedingung/');
        $snapshot->fresh(['fieldDefinitions', 'rules', 'sources.rules', 'sources'])->assertReadable();
    }

    public function test_extra_action_property_with_recomputed_dedupe_is_rejected(): void
    {
        $snapshot = $this->freshV2CalcSnapshot();
        $rule = SnapshotFieldRule::query()
            ->where('configuration_snapshot_id', $snapshot->id)
            ->firstOrFail();

        $tampered = $rule->action_json;
        $tampered['extra'] = true;
        $rule->action_json = $tampered;
        $rule->dedupe_key = SnapshotFieldRuleDedupeKey::from($rule->condition_json, $rule->action_json);
        $rule->save();

        DB::table('configuration_snapshot_source_rules')
            ->where('configuration_snapshot_source_id', $rule->provenance_source_id)
            ->where('source_field_rule_id', $rule->source_field_rule_id)
            ->update([
                'action_json' => json_encode($rule->action_json, JSON_THROW_ON_ERROR),
                'dedupe_key' => $rule->dedupe_key,
            ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/unzulässiges Attribut|unerwartetes Attribut|Aktion/');
        $snapshot->fresh(['fieldDefinitions', 'rules', 'sources.rules', 'sources'])->assertReadable();
    }

    public function test_nested_group_rule_with_recomputed_dedupe_is_rejected(): void
    {
        $snapshot = $this->freshV2CalcSnapshot();
        $rule = SnapshotFieldRule::query()
            ->where('configuration_snapshot_id', $snapshot->id)
            ->firstOrFail();

        $nested = [
            'op' => 'all',
            'conditions' => [
                ['op' => 'field_equals', 'field_key' => 'period_open', 'value' => false],
                [
                    'op' => 'any',
                    'conditions' => [
                        ['op' => 'field_empty', 'field_key' => 'period_open'],
                        ['op' => 'field_not_empty', 'field_key' => 'period_open'],
                    ],
                ],
            ],
        ];
        $action = ['op' => 'require_field', 'field_key' => 'position_flight_period'];
        $rawDedupe = hash('sha256', json_encode([
            'condition' => $nested,
            'action' => $action,
        ], JSON_THROW_ON_ERROR));
        $rule->condition_json = $nested;
        $rule->action_json = $action;
        $rule->dedupe_key = $rawDedupe;
        $rule->save();

        DB::table('configuration_snapshot_source_rules')
            ->where('configuration_snapshot_source_id', $rule->provenance_source_id)
            ->where('source_field_rule_id', $rule->source_field_rule_id)
            ->update([
                'condition_json' => json_encode($nested, JSON_THROW_ON_ERROR),
                'action_json' => json_encode($action, JSON_THROW_ON_ERROR),
                'dedupe_key' => $rawDedupe,
            ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Verschachtelte/');
        $snapshot->fresh(['fieldDefinitions', 'rules', 'sources.rules', 'sources'])->assertReadable();
    }

    public function test_generation_one_remains_readable_without_source_graph(): void
    {
        $legacy = app(ConfigurationSnapshotMaterializer::class)->materializeFromActiveSet();
        $this->assertSame(ConfigurationSnapshot::FORMAT_VERSION_LEGACY, (int) $legacy->format_version);
        $legacy->assertReadable();
    }

    public function test_freeze_and_revision_clone_remain_integrity_valid(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $this->activateGlobalCalculationField(User::factory()->role(Role::Admin)->create());
        $calculation = $this->createSavedCalculation($catalog, [
            ['inventory_id' => $catalog['hamburg']->id],
        ]);
        $calcSnapshot = ConfigurationSnapshot::query()->findOrFail($calculation->configuration_snapshot_id);
        $calcSnapshot->assertReadable();
        $this->assertTrue(
            ConfigurationSnapshotSource::query()
                ->where('configuration_snapshot_id', $calcSnapshot->id)
                ->where('role', ConfigurationSnapshotSource::ROLE_ASSIGNMENT)
                ->exists(),
        );

        $user = User::factory()->role(Role::Sales)->create();
        $order = app(DispoOrderWriter::class)
            ->createFromCalculation($calculation, $calculation->positions()->pluck('id')->all(), $user)
            ->order;
        $dispoSnapshot = ConfigurationSnapshot::query()->findOrFail($order->configuration_snapshot_id);
        $dispoSnapshot->assertReadable();

        $clone = app(ConfigurationSnapshotCloneService::class)->cloneForDispoRevision($dispoSnapshot);
        $clone->assertReadable();
        $this->assertNotSame((int) $dispoSnapshot->id, (int) $clone->id);
    }

    private function calcSnapshotWithGlobalAssignment(): ConfigurationSnapshot
    {
        $this->activateGlobalCalculationField(User::factory()->role(Role::Admin)->create());

        $fingerprint = app(ConfigurationSnapshotFreezeService::class)
            ->resolveLiveSchemaForCalculation()['schema_fingerprint'];

        return app(ConfigurationSnapshotFreezeService::class)
            ->freezeCalculationV2($fingerprint)
            ->load(['fieldDefinitions', 'rules', 'sources.fields', 'sources.rules']);
    }

    private function freshV2CalcSnapshot(): ConfigurationSnapshot
    {
        $fingerprint = app(ConfigurationSnapshotFreezeService::class)
            ->resolveLiveSchemaForCalculation()['schema_fingerprint'];

        return app(ConfigurationSnapshotFreezeService::class)
            ->freezeCalculationV2($fingerprint)
            ->load(['fieldDefinitions', 'rules', 'sources.fields', 'sources.rules']);
    }

    private function firstAssignmentSource(ConfigurationSnapshot $snapshot): ConfigurationSnapshotSource
    {
        return ConfigurationSnapshotSource::query()
            ->where('configuration_snapshot_id', $snapshot->id)
            ->where('role', ConfigurationSnapshotSource::ROLE_ASSIGNMENT)
            ->firstOrFail();
    }

    private function activateGlobalCalculationField(User $admin): FieldDefinition
    {
        $key = 'df33a2a_tax_'.bin2hex(random_bytes(3));

        $this->actingAs($admin)
            ->post(route('administration.dynamic-fields.field-sets.store'), [
                'name' => 'Freies Set '.$key,
                'key' => $key,
                'applies_to' => FieldAppliesTo::Both->value,
            ])
            ->assertRedirect();

        $fieldSet = FieldSet::query()->where('key', $key)->firstOrFail();
        $draft = FieldSetVersion::query()
            ->where('field_set_id', $fieldSet->id)
            ->where('status', 'draft')
            ->firstOrFail();

        $definition = app(FieldDefinitionCustomWriter::class)->create([
            'label' => 'Globales Feld '.$key,
            'field_type' => FieldType::ShortText,
            'scope' => FieldScope::Header,
            'applies_to' => FieldAppliesTo::Both,
            'max_length' => 120,
        ], $admin);

        $this->actingAs($admin)
            ->post(route('administration.dynamic-fields.field-sets.versions.memberships.store', [$fieldSet, $draft]), [
                'lock_version' => $fieldSet->fresh()->lock_version,
                'field_definition_id' => $definition->id,
                'field_definition_revision_id' => $definition->current_revision_id,
                'sort' => 20,
            ])
            ->assertRedirect();

        $this->actingAs($admin)
            ->post(route('administration.dynamic-fields.field-sets.versions.activate', [$fieldSet, $draft]), [
                'lock_version' => $fieldSet->fresh()->lock_version,
            ])
            ->assertRedirect();

        $writer = app(FieldSetAssignmentAdminWriter::class);
        $assignment = $writer->create([
            'field_set_id' => $fieldSet->id,
            'target_layer' => 'global',
            'applies_to_process' => FieldAppliesTo::Calculation->value,
        ], $admin);

        $writer->activate($assignment, [
            'lock_version' => $assignment->lock_version,
            'fingerprint' => $writer->canonicalActivationFingerprint(
                $writer->previewAffectedContexts($assignment, asCandidate: true),
            ),
        ], $admin);

        return $definition;
    }
}
