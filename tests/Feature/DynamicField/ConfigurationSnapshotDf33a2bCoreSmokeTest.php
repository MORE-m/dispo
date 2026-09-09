<?php

namespace Tests\Feature\DynamicField;

use App\Enums\FieldAppliesTo;
use App\Enums\FieldScope;
use App\Enums\FieldType;
use App\Enums\Role;
use App\Models\AdvertisingCategory;
use App\Models\AdvertisingMedium;
use App\Models\CalculationPosition;
use App\Models\CalculationPositionFieldValue;
use App\Models\ConfigurationSnapshot;
use App\Models\ConfigurationSnapshotSource;
use App\Models\FieldSet;
use App\Models\FieldSetVersion;
use App\Models\User;
use App\Services\DynamicField\Admin\FieldDefinitionCustomWriter;
use App\Services\DynamicField\Assignment\FieldSetAssignmentAdminWriter;
use App\Services\DynamicField\ConfigurationSnapshotFreezeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreatesSavedCalculation;
use Tests\Concerns\CreatesSpotClassicCatalog;
use Tests\TestCase;

/**
 * DF-3.3a2β / VER-003: Happy-Path des Generation-3-Kerns – Basis-Freeze,
 * Positions-Effektiv, Werbemittelwechsel mit Werteübernahme und Dispo-Freeze.
 */
class ConfigurationSnapshotDf33a2bCoreSmokeTest extends TestCase
{
    use CreatesSavedCalculation;
    use CreatesSpotClassicCatalog;
    use RefreshDatabase;

    public function test_generation_three_freeze_flow(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $admin = User::factory()->role(Role::Admin)->create();
        $medium = $catalog['medium'];

        $otherCategory = AdvertisingCategory::query()->where('id', '!=', $medium->category_id)->firstOrFail();
        $otherMedium = AdvertisingMedium::factory()->create([
            'category_id' => $otherCategory->id,
            'code' => 'other_medium',
            'name' => 'Anderes Werbemittel',
        ]);

        $this->activatePositionAssignment($admin, 'advertising_medium', ['advertising_medium_id' => $medium->id]);
        $this->activatePositionAssignment($admin, 'advertising_category', ['advertising_category_id' => $medium->category_id]);

        $freeze = app(ConfigurationSnapshotFreezeService::class);

        $live = $freeze->resolveLiveSchemaForCalculationV3();
        $this->assertFalse($live['has_blocking_conflicts']);

        $positionSchema = $freeze->resolveLivePositionSchema((int) $medium->id);
        $this->assertFalse($positionSchema['has_blocking_conflicts']);

        $result = $freeze->freezeCalculationV3($live['schema_fingerprint'], [
            [
                'client_key' => 'p1',
                'advertising_medium_id' => (int) $medium->id,
                'schema_fingerprint' => $positionSchema['schema_fingerprint'],
            ],
        ]);

        $base = $result['base'];
        $effective = $result['effectives_by_client_key']['p1'];

        $this->assertSame(ConfigurationSnapshot::FORMAT_VERSION_CONTEXTUAL_FREEZE, (int) $base->format_version);
        $this->assertNull($base->parent_configuration_snapshot_id);
        $this->assertNull($base->context_advertising_medium_id);
        $this->assertTrue($base->fieldDefinitions->every(
            fn ($def): bool => $def->scope === FieldScope::Header,
        ), 'Basis enthält nur Headerfelder');

        // Universum: Kategorie- und Werbemittelquellen liegen im Basisgraph.
        $categorySource = ConfigurationSnapshotSource::query()
            ->where('configuration_snapshot_id', $base->id)
            ->where('layer', 'advertising_category')
            ->firstOrFail();
        $this->assertSame((int) $medium->category_id, (int) $categorySource->target_id);
        $this->assertNotNull($categorySource->target_key);
        $this->assertNotNull($categorySource->target_name);

        $mediumSource = ConfigurationSnapshotSource::query()
            ->where('configuration_snapshot_id', $base->id)
            ->where('layer', 'advertising_medium')
            ->firstOrFail();
        $this->assertSame((int) $medium->id, (int) $mediumSource->target_id);
        $this->assertSame($medium->code, $mediumSource->target_key);

        // Effektiv: Positionsfelder, eingefrorener Kontext, Parent.
        $this->assertSame((int) $base->id, (int) $effective->parent_configuration_snapshot_id);
        $this->assertSame((int) $medium->id, (int) $effective->context_advertising_medium_id);
        $this->assertSame((int) $medium->category_id, (int) $effective->context_advertising_category_id);
        $this->assertTrue($effective->fieldDefinitions->every(
            fn ($def): bool => $def->scope === FieldScope::Position,
        ), 'Effektiv enthält nur Positionsfelder');
        $this->assertTrue(
            $effective->fieldDefinitions->contains('key', $this->positionFieldKeys[0]),
            'Werbemittelfeld liegt im Effektiv-Snapshot',
        );

        // Fremdes Werbemittel darf die Kategorie-/Werbemittelquelle nicht sehen.
        $otherSchema = $freeze->resolveLivePositionSchema((int) $otherMedium->id);
        $this->assertNotSame($positionSchema['schema_fingerprint'], $otherSchema['schema_fingerprint']);

        // Ownership: erst nach Bindung lesbar.
        $calculation = $this->createSavedCalculation($catalog, [
            ['inventory_id' => $catalog['hamburg']->id],
            ['inventory_id' => $catalog['rock']->id],
        ]);
        $positions = CalculationPosition::query()
            ->where('calculation_id', $calculation->id)
            ->orderBy('id')
            ->get();
        $position = $positions->first();
        $secondPosition = $positions->last();

        $position->effective_configuration_snapshot_id = $effective->id;
        $position->save();

        $effective->refresh()->assertReadable();

        // Neue Position aus der eingefrorenen Basis (nie live).
        $fromBase = $freeze->resolvePositionSchemaFromBase($base, (int) $medium->id);
        $second = $freeze->freezePositionEffectiveFromBase(
            $base,
            (int) $medium->id,
            $fromBase['schema_fingerprint'],
        );
        $this->assertSame((int) $base->id, (int) $second->parent_configuration_snapshot_id);

        $secondPosition->effective_configuration_snapshot_id = $second->id;
        $secondPosition->save();

        // Werte der Position zeigen auf den Effektiv-Snapshot.
        CalculationPositionFieldValue::query()
            ->where('calculation_position_id', $secondPosition->id)
            ->delete();

        $periodOpenDef = $second->fieldDefinitions->firstWhere('key', 'period_open');
        $mediumOnlyDef = $second->fieldDefinitions->firstWhere('key', $this->positionFieldKeys[0]);
        $this->assertNotNull($periodOpenDef);
        $this->assertNotNull($mediumOnlyDef);

        CalculationPositionFieldValue::query()->create([
            'calculation_position_id' => $secondPosition->id,
            'snapshot_field_definition_id' => $periodOpenDef->id,
            'value_boolean' => true,
        ]);
        $mediumOnlyValue = CalculationPositionFieldValue::query()->create([
            'calculation_position_id' => $secondPosition->id,
            'snapshot_field_definition_id' => $mediumOnlyDef->id,
            'value_string' => 'Wert',
        ]);

        $replacementSchema = $freeze->resolvePositionSchemaFromBase($base, (int) $otherMedium->id);

        // Werbemittelwechsel blockiert, solange ein entfallendes Feld einen Wert trägt.
        try {
            $freeze->replacePositionEffective(
                $secondPosition->fresh(),
                $base,
                (int) $otherMedium->id,
                $replacementSchema['schema_fingerprint'],
            );
            $this->fail('Erwartete ValidationException für entfallendes Feld mit Wert.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey(
                "positions.{$secondPosition->id}.dynamic_field_values.{$mediumOnlyDef->key}",
                $exception->errors(),
            );
        }

        $mediumOnlyValue->delete();

        $replaced = $freeze->replacePositionEffective(
            $secondPosition->fresh(),
            $base,
            (int) $otherMedium->id,
            $replacementSchema['schema_fingerprint'],
        );

        $this->assertSame((int) $otherMedium->id, (int) $replaced->context_advertising_medium_id);
        $this->assertNull(ConfigurationSnapshot::query()->find($second->id), 'Alter Effektiv-Snapshot entfernt');
        $this->assertSame(
            (int) $replaced->fieldDefinitions->firstWhere('key', 'period_open')->id,
            (int) CalculationPositionFieldValue::query()
                ->where('calculation_position_id', $secondPosition->id)
                ->firstOrFail()
                ->snapshot_field_definition_id,
            'Wert folgt der neuen Definition',
        );

        // Dispo-Freeze der Generation 3.
        $position->refresh();
        $dispoBase = $freeze->freezeDispoV3($base);
        $this->assertSame(ConfigurationSnapshot::FORMAT_VERSION_CONTEXTUAL_FREEZE, (int) $dispoBase->format_version);
        $this->assertSame((int) $base->id, (int) $dispoBase->source_configuration_snapshot_id);

        $dispoEffectives = ConfigurationSnapshot::query()
            ->where('parent_configuration_snapshot_id', $dispoBase->id)
            ->get();
        $this->assertCount(2, $dispoEffectives, 'Je Kalkulations-Effektiv ein Dispo-Effektiv');

        foreach ($dispoEffectives as $dispoEffective) {
            $this->assertNotNull($dispoEffective->context_advertising_medium_id);
            $this->assertTrue(
                $dispoEffective->fieldDefinitions->contains('key', 'period_open'),
                'Calc-Origin-Positionsfeld übernommen',
            );
        }
    }

    /** @var list<string> */
    private array $positionFieldKeys = [];

    /**
     * @param  array<string, mixed>  $target
     */
    private function activatePositionAssignment(User $admin, string $targetLayer, array $target): void
    {
        $key = 'df33a2b_'.bin2hex(random_bytes(3));

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
            'label' => 'Positionsfeld '.$key,
            'field_type' => FieldType::ShortText,
            'scope' => FieldScope::Position,
            'applies_to' => FieldAppliesTo::Both,
            'max_length' => 120,
        ], $admin);

        $this->positionFieldKeys[] = $definition->key;

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
        $assignment = $writer->create(array_merge([
            'field_set_id' => $fieldSet->id,
            'target_layer' => $targetLayer,
            'applies_to_process' => FieldAppliesTo::Calculation->value,
        ], $target), $admin);

        $writer->activate($assignment, [
            'lock_version' => $assignment->lock_version,
            'fingerprint' => $writer->canonicalActivationFingerprint(
                $writer->previewAffectedContexts($assignment, asCandidate: true),
            ),
        ], $admin);
    }
}
