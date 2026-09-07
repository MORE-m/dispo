<?php

namespace App\Services\DynamicField;

use App\Enums\DispoOrderStatus;
use App\Enums\FieldScope;
use App\Enums\FieldType;
use App\Exceptions\DispoOrderConflictException;
use App\Models\Calculation;
use App\Models\ConfigurationSnapshot;
use App\Models\DispoOrder;
use App\Models\DispoOrderFieldValue;
use App\Models\DispoOrderPosition;
use App\Models\DispoOrderPositionFieldValue;
use App\Models\FieldDefinition;
use App\Models\SnapshotFieldDefinition;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * DF-2: Persistenz und Validierung dynamischer Dispo-Feldwerte.
 */
final class DispoOrderDynamicFieldWriter
{
    public function __construct(
        private readonly SnapshotFieldRuleEvaluator $rules,
        private readonly CalculationDynamicFieldWriter $calculationFields,
        private readonly ConfigurationSnapshotFreezeService $freeze,
        private readonly ConfigurationSnapshotCloneService $clone,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Freeze der Dispo-Konfiguration; setzt configuration_snapshot_id vor dem
     * ersten Save.
     */
    public function assignComposedSnapshot(DispoOrder $order, Calculation $calculation): void
    {
        $calculation->loadMissing([
            'configurationSnapshot.fieldDefinitions',
            'configurationSnapshot.rules',
        ]);

        $calcSnapshot = $calculation->configurationSnapshot;
        if ($calcSnapshot === null) {
            throw ValidationException::withMessages([
                'calculation' => 'Die Kalkulation besitzt keinen Konfigurationssnapshot.',
            ]);
        }

        if ((int) $calcSnapshot->id !== (int) $calculation->configuration_snapshot_id) {
            throw new RuntimeException(
                'Quellsnapshot stimmt nicht mit der configuration_snapshot_id der Kalkulation überein.',
            );
        }

        $snapshot = $this->freeze->freezeDispoV2($calcSnapshot);
        $order->forceFill([
            'configuration_snapshot_id' => $snapshot->id,
        ]);
    }

    /**
     * Nachbesserung: historisch eingefrorene Konfiguration wird geklont, nicht
     * neu aufgelöst.
     */
    public function assignClonedSnapshot(DispoOrder $order, DispoOrder $predecessor): void
    {
        $predecessor->loadMissing([
            'configurationSnapshot.fieldDefinitions',
            'configurationSnapshot.rules',
        ]);

        // dispo_orders.configuration_snapshot_id ist NOT NULL und restrictOnDelete.
        $snapshot = $this->clone->cloneForDispoRevision($predecessor->configurationSnapshot);
        $order->forceFill([
            'configuration_snapshot_id' => $snapshot->id,
        ]);
    }

    /**
     * @param  list<int>  $selectedCalculationPositionIds
     */
    public function persistCopiedValues(
        DispoOrder $order,
        Calculation $calculation,
        array $selectedCalculationPositionIds,
        ?DispoOrder $predecessor = null,
    ): void {
        $snapshot = $this->requireSnapshot($order);
        $calculation->loadMissing([
            'configurationSnapshot.fieldDefinitions',
            'fieldValues.snapshotFieldDefinition',
            'positions.fieldValues.snapshotFieldDefinition',
        ]);

        $calcSnapshot = $calculation->configurationSnapshot;
        if ($calcSnapshot === null) {
            throw ValidationException::withMessages([
                'calculation' => 'Die Kalkulation besitzt keinen Konfigurationssnapshot.',
            ]);
        }

        $this->assertSelectedCalcPositionsReadyForDispo($calculation, $selectedCalculationPositionIds);

        $headerFromCalc = $this->calculationFields->headerValuesForPayload($calculation);
        $calcSnapshot = $calculation->configurationSnapshot;
        $this->persistHeaderPeriod(
            $order,
            $snapshot,
            'campaign_period',
            $headerFromCalc['campaign_period'] ?? null,
        );
        $this->captureCalcOriginHeaderTexts($order, $snapshot, $calcSnapshot, $headerFromCalc);

        $order->loadMissing('positions');
        $positionsByCalcId = $order->positions->keyBy('calculation_position_id');
        $calcPositions = $calculation->positions->keyBy('id');

        $positionContexts = [];
        $index = 0;
        foreach ($selectedCalculationPositionIds as $calcPositionId) {
            $dispoPosition = $positionsByCalcId->get($calcPositionId);
            $calcPosition = $calcPositions->get($calcPositionId);
            if ($dispoPosition === null || $calcPosition === null) {
                throw ValidationException::withMessages([
                    'position_ids' => 'Positionszuordnung für dynamische Felder fehlgeschlagen.',
                ]);
            }

            $values = $this->calculationFields->positionValuesForPayload($calcPosition, $calcSnapshot);
            $this->persistPositionValues($dispoPosition, $snapshot, $values, $calcSnapshot);
            $positionContexts[] = [
                'index' => $index,
                'values' => $values,
            ];
            $index++;
        }

        if ($predecessor !== null) {
            $this->copyTextFieldsFromPredecessor($order, $snapshot, $predecessor);
        }

        $headerValues = $this->headerValuesForValidation($order, $snapshot);
        $this->rules->validate($snapshot, $headerValues, $positionContexts);
    }

    /**
     * @deprecated Use assignComposedSnapshot + persistCopiedValues
     *
     * @param  list<int>  $selectedCalculationPositionIds
     */
    public function attachOnCreate(
        DispoOrder $order,
        Calculation $calculation,
        array $selectedCalculationPositionIds,
        ?DispoOrder $predecessor = null,
    ): void {
        $this->persistCopiedValues($order, $calculation, $selectedCalculationPositionIds, $predecessor);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function updateDraftTexts(
        DispoOrder $order,
        User $user,
        int $expectedLockVersion,
        array $input,
    ): DispoOrder {
        return DB::transaction(function () use ($order, $user, $expectedLockVersion, $input): DispoOrder {
            $locked = DispoOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($locked->lock_version !== $expectedLockVersion) {
                throw new DispoOrderConflictException(
                    'Der Dispoauftrag wurde zwischenzeitlich geändert. Bitte die Seite neu laden.',
                );
            }

            if ($locked->status !== DispoOrderStatus::Draft) {
                throw ValidationException::withMessages([
                    'status' => 'Nur Entwürfe können bearbeitet werden.',
                ]);
            }

            $snapshot = $this->requireSnapshot($locked);
            $before = $this->textValuesForAudit($locked, $snapshot);

            $normalized = $this->normalizeTextInput($snapshot, $input);
            $changed = false;

            foreach ($normalized as $key => $newValue) {
                $oldValue = $before[$key] ?? null;
                if ($oldValue === $newValue) {
                    continue;
                }
                $this->upsertHeaderTextValue($locked, $snapshot, $key, $newValue);
                $changed = true;
            }

            if (! $changed) {
                return $this->reloadOrder($locked);
            }

            $locked->lock_version = $locked->lock_version + 1;
            $locked->save();

            $fresh = $this->reloadOrder($locked);
            $after = $this->textValuesForAudit($fresh, $snapshot);

            $this->audit->record(
                $fresh,
                'dispo_order.updated',
                $user,
                [
                    'lock_version' => $expectedLockVersion,
                    'dynamic_field_values' => $before,
                ],
                [
                    'lock_version' => $fresh->lock_version,
                    'dynamic_field_values' => $after,
                ],
            );

            return $fresh;
        });
    }

    /**
     * Atomare Teilspeicherung nativer Positions-Custom-Textfelder (PO-32b-2).
     *
     * @param  array<int|string, array<string, mixed>>  $byPositionId
     */
    public function updateDraftPositionCustoms(
        DispoOrder $order,
        User $user,
        int $expectedLockVersion,
        array $byPositionId,
    ): DispoOrder {
        return DB::transaction(function () use ($order, $user, $expectedLockVersion, $byPositionId): DispoOrder {
            $locked = DispoOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($locked->lock_version !== $expectedLockVersion) {
                throw new DispoOrderConflictException(
                    'Der Dispoauftrag wurde zwischenzeitlich geändert. Bitte die Seite neu laden.',
                );
            }

            if ($locked->status !== DispoOrderStatus::Draft) {
                throw ValidationException::withMessages([
                    'status' => 'Nur Entwürfe können bearbeitet werden.',
                ]);
            }

            $snapshot = $this->requireSnapshot($locked);
            $locked->loadMissing(['positions.fieldValues.snapshotFieldDefinition']);
            $positionsById = $locked->positions->keyBy('id');
            $before = $this->positionCustomValuesForAudit($locked, $snapshot);

            $errors = [];
            $changed = false;

            foreach ($byPositionId as $positionId => $values) {
                $positionId = (int) $positionId;
                $position = $positionsById->get($positionId);
                if ($position === null) {
                    $errors["position_dynamic_field_values.{$positionId}"] = 'Unbekannte Position.';

                    continue;
                }

                $editableKeys = [];
                foreach ($snapshot->fieldDefinitions->where('scope', FieldScope::Position) as $def) {
                    if ($this->isNativeEditablePositionText($snapshot, $def)) {
                        $editableKeys[$def->key] = $def;
                    }
                }

                foreach (array_keys($values) as $key) {
                    $key = (string) $key;
                    if (! isset($editableKeys[$key])) {
                        if ($this->isCalcOriginKey($snapshot, $key)) {
                            $errors["position_dynamic_field_values.{$positionId}.{$key}"] =
                                'Dieses Feld ist im Dispoauftrag nicht bearbeitbar.';
                        } elseif ($snapshot->fieldDefinitions->firstWhere('key', $key) !== null) {
                            $errors["position_dynamic_field_values.{$positionId}.{$key}"] =
                                'Dieses Feld ist im Dispoauftrag nicht bearbeitbar.';
                        } else {
                            $errors["position_dynamic_field_values.{$positionId}.{$key}"] =
                                'Unbekanntes dynamisches Feld.';
                        }
                    }
                }

                foreach ($editableKeys as $key => $def) {
                    if (! array_key_exists($key, $values)) {
                        continue;
                    }
                    $raw = $values[$key];
                    if ($raw !== null && ! is_string($raw) && ! is_numeric($raw)) {
                        $errors["position_dynamic_field_values.{$positionId}.{$key}"] =
                            $def->label.' muss Text sein.';

                        continue;
                    }
                    $value = $raw === null ? null : trim((string) $raw);
                    if ($value === '') {
                        $value = null;
                    }
                    $maxLength = $this->maxLengthForDefinition($def);
                    if ($value !== null && mb_strlen($value) > $maxLength) {
                        $errors["position_dynamic_field_values.{$positionId}.{$key}"] =
                            $def->label." darf höchstens {$maxLength} Zeichen haben.";

                        continue;
                    }

                    $old = $this->readPositionValue($position, $def);
                    if ($old === $value) {
                        continue;
                    }
                    $this->upsertPositionTextValue($position, $snapshot, $key, $value);
                    $changed = true;
                }
            }

            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }

            if (! $changed) {
                return $this->reloadOrder($locked);
            }

            $locked->lock_version = $locked->lock_version + 1;
            $locked->save();

            $fresh = $this->reloadOrder($locked);
            $after = $this->positionCustomValuesForAudit($fresh, $snapshot);

            $this->audit->record(
                $fresh,
                'dispo_order.updated',
                $user,
                [
                    'lock_version' => $expectedLockVersion,
                    'position_dynamic_field_values' => $before,
                ],
                [
                    'lock_version' => $fresh->lock_version,
                    'position_dynamic_field_values' => $after,
                ],
            );

            return $fresh;
        });
    }

    public function syncMissingCalculationFields(
        DispoOrder $order,
        User $user,
        int $expectedLockVersion,
    ): DispoOrder {
        return DB::transaction(function () use ($order, $user, $expectedLockVersion): DispoOrder {
            $locked = DispoOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($locked->lock_version !== $expectedLockVersion) {
                throw new DispoOrderConflictException(
                    'Der Dispoauftrag wurde zwischenzeitlich geändert. Bitte die Seite neu laden.',
                );
            }

            if ($locked->status !== DispoOrderStatus::Draft) {
                throw ValidationException::withMessages([
                    'status' => 'Nur Entwürfe können synchronisiert werden.',
                ]);
            }

            $snapshot = $this->requireSnapshot($locked);
            $calculation = $locked->calculation()->with([
                'configurationSnapshot.fieldDefinitions',
                'configurationSnapshot.rules',
                'fieldValues.snapshotFieldDefinition',
                'positions.fieldValues.snapshotFieldDefinition',
            ])->first();

            if ($calculation === null || $calculation->configurationSnapshot === null) {
                throw ValidationException::withMessages([
                    'calculation' => 'Die verknüpfte Kalkulation oder ihr Snapshot fehlt.',
                ]);
            }

            $before = $this->calcOriginValuesForAudit($locked, $snapshot);
            $changed = false;

            $headerFromCalc = $this->calculationFields->headerValuesForPayload($calculation);
            if (! $this->hasHeaderValue($locked, $snapshot, 'campaign_period')) {
                $this->persistHeaderPeriod(
                    $locked,
                    $snapshot,
                    'campaign_period',
                    $headerFromCalc['campaign_period'] ?? null,
                );
                $changed = true;
            }

            $locked->load('positions');
            $calcPositions = $calculation->positions->keyBy('id');
            $calcSnapshot = $calculation->configurationSnapshot;

            foreach ($locked->positions as $dispoPosition) {
                $calcPosition = $calcPositions->get($dispoPosition->calculation_position_id);
                if ($calcPosition === null) {
                    continue;
                }
                $values = $this->calculationFields->positionValuesForPayload($calcPosition, $calcSnapshot);
                foreach ($snapshot->fieldDefinitions->where('scope', FieldScope::Position) as $def) {
                    if (! $this->isCalcOriginKey($snapshot, $def->key, $calcSnapshot)) {
                        continue;
                    }
                    if ($this->hasPositionValue($dispoPosition, $snapshot, $def->key)) {
                        continue;
                    }
                    $this->persistPositionFieldValue(
                        $dispoPosition,
                        $snapshot,
                        $def->key,
                        $values[$def->key] ?? null,
                    );
                    $changed = true;
                }
            }

            if (! $changed) {
                return $this->reloadOrder($locked);
            }

            $locked->unsetRelation('positions');
            $locked->unsetRelation('fieldValues');
            $locked->load(['positions.fieldValues.snapshotFieldDefinition', 'fieldValues.snapshotFieldDefinition']);
            $this->assertReadyForRules($locked, $snapshot);

            $locked->lock_version = $locked->lock_version + 1;
            $locked->save();
            $fresh = $this->reloadOrder($locked);
            $after = $this->calcOriginValuesForAudit($fresh, $snapshot);

            $this->audit->record(
                $fresh,
                'dispo_order.calculation_dynamic_fields_synced',
                $user,
                [
                    'lock_version' => $expectedLockVersion,
                    'note' => 'Draft-Vervollständigung aus aktuellem Kalkulationsstand, kein historischer Erstellungsstand.',
                    'dynamic_field_values' => $before,
                ],
                [
                    'lock_version' => $fresh->lock_version,
                    'dynamic_field_values' => $after,
                ],
            );

            return $fresh;
        });
    }

    public function assertReadyForSubmit(DispoOrder $order): void
    {
        $snapshot = $this->requireSnapshot($order);
        $order->loadMissing(['positions.fieldValues.snapshotFieldDefinition', 'fieldValues.snapshotFieldDefinition']);

        $gaps = $this->calcOriginCaptureGaps($order, $snapshot);
        if ($gaps !== []) {
            throw ValidationException::withMessages([
                'dynamic_field_values' => 'Dynamische Zeitraumfelder fehlen. Bitte „Dynamische Zeitraumfelder aus Kalkulation übernehmen“ ausführen oder einen neuen Dispoauftrag anlegen.',
            ]);
        }

        $errors = [];
        foreach ($snapshot->fieldDefinitions->where('scope', FieldScope::Header) as $def) {
            if (! $def->required || ! $def->visible) {
                continue;
            }
            if (! in_array($def->field_type, [FieldType::ShortText, FieldType::LongText], true)) {
                continue;
            }
            if ($this->isCalcOriginKey($snapshot, $def->key)) {
                continue;
            }

            $raw = $this->readHeaderValue($order, $def);
            if ($raw === null || $raw === '') {
                $errors["dynamic_field_values.{$def->key}"] = $def->label.' ist erforderlich.';
            }
        }
        foreach ($order->positions as $position) {
            foreach ($snapshot->fieldDefinitions->where('scope', FieldScope::Position) as $def) {
                if (! $def->required || ! $def->visible) {
                    continue;
                }
                if (! in_array($def->field_type, [FieldType::ShortText, FieldType::LongText], true)) {
                    continue;
                }
                if ($this->isCalcOriginKey($snapshot, $def->key)) {
                    continue;
                }

                $raw = $this->readPositionValue($position, $def);
                if ($raw === null || $raw === '') {
                    $errors["position_dynamic_field_values.{$position->id}.{$def->key}"] =
                        $def->label.' ist erforderlich.';
                }
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $headerValues = $this->headerValuesForValidation($order, $snapshot);
        $positionContexts = [];
        foreach ($order->positions->values() as $index => $position) {
            $positionContexts[] = [
                'index' => $index,
                'values' => $this->positionValuesForValidation($position, $snapshot),
            ];
        }

        $this->rules->validate($snapshot, $headerValues, $positionContexts);
    }

    /**
     * @return array{fields: list<array<string, mixed>>, rules: list<array<string, mixed>>}
     */
    public function fieldSchemaProp(DispoOrder $order): array
    {
        $snapshot = $order->configurationSnapshot;
        $snapshot->loadMissing(['fieldDefinitions', 'rules']);

        $systemByDefinitionId = FieldDefinition::query()
            ->whereIn('id', $snapshot->fieldDefinitions->pluck('field_definition_id')->unique()->all())
            ->pluck('is_system', 'id');

        $fields = array_values($snapshot->fieldDefinitions->map(fn (SnapshotFieldDefinition $def): array => [
            'key' => $def->key,
            'label' => $def->label,
            'help_text' => $def->help_text,
            'field_type' => $def->field_type->value,
            'scope' => $def->scope->value,
            'sort' => $def->sort,
            'group_key' => $def->group_key,
            'applies_to' => $def->applies_to->value,
            'is_system' => (bool) ($systemByDefinitionId[$def->field_definition_id] ?? false),
            'required' => (bool) $def->required,
            'visible' => (bool) $def->visible,
            'editable' => $def->scope === FieldScope::Header
                ? $this->isNativeEditableHeaderText($snapshot, $def)
                : $this->isNativeEditablePositionText($snapshot, $def),
            'calc_origin' => $this->isCalcOriginKey($snapshot, $def->key),
            'max_length' => $this->maxLengthForDefinition($def),
            'validation_json' => $def->validation_json,
        ])->all());

        $editableCustom = array_values(array_filter(
            $fields,
            fn (array $field): bool => ! $field['is_system']
                && $field['editable'] === true
                && $field['visible'] === true
                && $field['scope'] === FieldScope::Header->value
                && in_array($field['field_type'], [FieldType::ShortText->value, FieldType::LongText->value], true),
        ));
        $calcOriginCustom = array_values(array_filter(
            $fields,
            fn (array $field): bool => ! $field['is_system']
                && $field['calc_origin'] === true
                && $field['visible'] === true
                && $field['scope'] === FieldScope::Header->value
                && in_array($field['field_type'], [FieldType::ShortText->value, FieldType::LongText->value], true),
        ));
        $editableCustomPosition = array_values(array_filter(
            $fields,
            fn (array $field): bool => ! $field['is_system']
                && $field['editable'] === true
                && $field['visible'] === true
                && $field['scope'] === FieldScope::Position->value
                && in_array($field['field_type'], [FieldType::ShortText->value, FieldType::LongText->value], true),
        ));
        $calcOriginCustomPosition = array_values(array_filter(
            $fields,
            fn (array $field): bool => ! $field['is_system']
                && $field['calc_origin'] === true
                && $field['visible'] === true
                && $field['scope'] === FieldScope::Position->value
                && in_array($field['field_type'], [FieldType::ShortText->value, FieldType::LongText->value], true),
        ));

        return [
            'fields' => $fields,
            'rules' => array_values($snapshot->rules->map(fn ($rule): array => [
                'sort' => $rule->sort,
                'condition' => $rule->condition_json,
                'action' => $rule->action_json,
            ])->all()),
            'editable_custom_header_fields' => $editableCustom,
            'calc_origin_custom_header_fields' => $calcOriginCustom,
            'editable_custom_position_fields' => $editableCustomPosition,
            'calc_origin_custom_position_fields' => $calcOriginCustomPosition,
        ];
    }

    /**
     * @return array{
     *     header: array<string, mixed>,
     *     positions: array<int, array<string, mixed>>,
     *     header_captured: array<string, bool>,
     *     positions_captured: array<int, array<string, bool>>,
     *     missing_calc_origin_keys: list<string>,
     *     historically_uncaptured: bool
     * }
     */
    public function valuesProp(DispoOrder $order): array
    {
        $snapshot = $order->configurationSnapshot;

        $order->loadMissing([
            'fieldValues.snapshotFieldDefinition',
            'positions.fieldValues.snapshotFieldDefinition',
            'configurationSnapshot.fieldDefinitions',
        ]);

        $header = [];
        $headerCaptured = [];
        foreach ($snapshot->fieldDefinitions->where('scope', FieldScope::Header) as $def) {
            $headerCaptured[$def->key] = $this->hasHeaderValue($order, $snapshot, $def->key);
            $header[$def->key] = $this->readHeaderValue($order, $def);
        }

        $positions = [];
        $positionsCaptured = [];
        foreach ($order->positions as $position) {
            $values = [];
            $captured = [];
            foreach ($snapshot->fieldDefinitions->where('scope', FieldScope::Position) as $def) {
                $captured[$def->key] = $this->hasPositionValue($position, $snapshot, $def->key);
                $values[$def->key] = $this->readPositionValue($position, $def);
            }
            $positions[(int) $position->id] = $values;
            $positionsCaptured[(int) $position->id] = $captured;
        }

        $missing = $this->missingCalcOriginKeys($order, $snapshot);

        return [
            'header' => $header,
            'positions' => $positions,
            'header_captured' => $headerCaptured,
            'positions_captured' => $positionsCaptured,
            'missing_calc_origin_keys' => $missing,
            'historically_uncaptured' => $missing !== [] && $order->status !== DispoOrderStatus::Draft
                ? true
                : ($missing !== [] && ! $this->hasAnyCalcOriginValue($order, $snapshot)),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function dynamicSnapshotForAudit(DispoOrder $order): array
    {
        $values = $this->valuesProp($order);

        return [
            'configuration_snapshot_id' => $order->configuration_snapshot_id,
            'dynamic_field_values' => $values['header'],
            'position_dynamic_field_values' => $values['positions'],
        ];
    }

    private function copyTextFieldsFromPredecessor(
        DispoOrder $order,
        ConfigurationSnapshot $snapshot,
        DispoOrder $predecessor,
    ): void {
        $predecessor->loadMissing([
            'fieldValues.snapshotFieldDefinition',
            'positions.fieldValues.snapshotFieldDefinition',
            'configurationSnapshot.fieldDefinitions',
        ]);
        $predSnapshot = $predecessor->configurationSnapshot;

        foreach ($snapshot->fieldDefinitions->where('scope', FieldScope::Header) as $newDef) {
            if (! $this->isNativeEditableHeaderText($snapshot, $newDef)) {
                continue;
            }

            $predDef = $predSnapshot->fieldDefinitions->firstWhere('key', $newDef->key);
            if ($predDef === null
                || ! in_array($predDef->field_type, [FieldType::ShortText, FieldType::LongText], true)) {
                continue;
            }

            $predValue = $predecessor->fieldValues
                ->firstWhere('snapshot_field_definition_id', $predDef->id);
            $text = $predDef->field_type === FieldType::ShortText
                ? $predValue?->value_string
                : $predValue?->value_text;
            if ($text === null || trim($text) === '') {
                continue;
            }

            $this->upsertHeaderTextValue($order, $snapshot, $newDef->key, $text);
        }

        $order->loadMissing('positions');
        $predByCalcId = $predecessor->positions->keyBy('calculation_position_id');

        foreach ($order->positions as $dispoPosition) {
            $calcPositionId = $dispoPosition->calculation_position_id;
            if ($calcPositionId === null) {
                continue;
            }
            $predPosition = $predByCalcId->get($calcPositionId);
            if ($predPosition === null) {
                continue;
            }

            foreach ($snapshot->fieldDefinitions->where('scope', FieldScope::Position) as $newDef) {
                if (! $this->isNativeEditablePositionText($snapshot, $newDef)) {
                    continue;
                }

                $predDef = $predSnapshot->fieldDefinitions->firstWhere('key', $newDef->key);
                if ($predDef === null
                    || ! in_array($predDef->field_type, [FieldType::ShortText, FieldType::LongText], true)) {
                    continue;
                }

                $predValue = $predPosition->fieldValues
                    ->firstWhere('snapshot_field_definition_id', $predDef->id);
                $text = $predDef->field_type === FieldType::ShortText
                    ? $predValue?->value_string
                    : $predValue?->value_text;
                if ($text === null || trim($text) === '') {
                    continue;
                }

                $this->upsertPositionTextValue($dispoPosition, $snapshot, $newDef->key, $text);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $headerFromCalc
     */
    private function captureCalcOriginHeaderTexts(
        DispoOrder $order,
        ConfigurationSnapshot $snapshot,
        ConfigurationSnapshot $calcSnapshot,
        array $headerFromCalc,
    ): void {
        foreach ($snapshot->fieldDefinitions->where('scope', FieldScope::Header) as $def) {
            if ($def->key === 'campaign_period') {
                continue;
            }
            if (! in_array($def->field_type, [FieldType::ShortText, FieldType::LongText], true)) {
                continue;
            }
            if (! $this->isCalcOriginKey($snapshot, $def->key, $calcSnapshot)) {
                continue;
            }

            $raw = $headerFromCalc[$def->key] ?? null;
            $value = null;
            if (is_string($raw)) {
                $trimmed = trim($raw);
                $value = $trimmed === '' ? null : $trimmed;
            }

            $this->upsertHeaderTextValue($order, $snapshot, $def->key, $value);
        }
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, string|null>
     */
    private function normalizeTextInput(ConfigurationSnapshot $snapshot, array $input): array
    {
        $editableKeys = [];
        foreach ($snapshot->fieldDefinitions->where('scope', FieldScope::Header) as $def) {
            if ($this->isNativeEditableHeaderText($snapshot, $def)) {
                $editableKeys[$def->key] = $def;
            }
        }

        $errors = [];

        foreach (array_keys($input) as $key) {
            $key = (string) $key;
            if (! isset($editableKeys[$key])) {
                if ($snapshot->fieldDefinitions->firstWhere('key', $key) !== null) {
                    $errors["dynamic_field_values.{$key}"] = 'Dieses Feld ist im Dispoauftrag nicht bearbeitbar.';
                } else {
                    $errors["dynamic_field_values.{$key}"] = 'Unbekanntes dynamisches Feld.';
                }
            }
        }

        $normalized = [];
        foreach ($editableKeys as $key => $def) {
            if (! array_key_exists($key, $input)) {
                continue;
            }
            $raw = $input[$key];
            if ($raw !== null && ! is_string($raw)) {
                $errors["dynamic_field_values.{$key}"] = $def->label.' muss Text sein.';

                continue;
            }
            $value = $raw === null ? null : trim($raw);
            if ($value === '') {
                $value = null;
            }
            $maxLength = $this->maxLengthForDefinition($def);
            if ($value !== null && mb_strlen($value) > $maxLength) {
                $errors["dynamic_field_values.{$key}"] =
                    $def->label." darf höchstens {$maxLength} Zeichen haben.";

                continue;
            }
            $normalized[$key] = $value;
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $normalized;
    }

    private function upsertHeaderTextValue(
        DispoOrder $order,
        ConfigurationSnapshot $snapshot,
        string $key,
        ?string $value,
    ): void {
        $def = $snapshot->fieldDefinitions->firstWhere('key', $key);
        if ($def === null) {
            throw new RuntimeException("Snapshot-Definition {$key} fehlt.");
        }

        $row = DispoOrderFieldValue::query()->firstOrNew([
            'dispo_order_id' => $order->id,
            'snapshot_field_definition_id' => $def->id,
        ]);
        $row->value_string = $def->field_type === FieldType::ShortText ? $value : null;
        $row->value_text = $def->field_type === FieldType::LongText ? $value : null;
        $row->value_period_start = null;
        $row->value_period_end = null;
        $row->save();
    }

    private function isNativeEditableHeaderText(ConfigurationSnapshot $snapshot, SnapshotFieldDefinition $def): bool
    {
        if ($def->scope !== FieldScope::Header) {
            return false;
        }
        if (! in_array($def->field_type, [FieldType::ShortText, FieldType::LongText], true)) {
            return false;
        }

        return ! $this->isCalcOriginKey($snapshot, $def->key);
    }

    private function isNativeEditablePositionText(ConfigurationSnapshot $snapshot, SnapshotFieldDefinition $def): bool
    {
        if ($def->scope !== FieldScope::Position) {
            return false;
        }
        if (! $def->visible) {
            return false;
        }
        if (! in_array($def->field_type, [FieldType::ShortText, FieldType::LongText], true)) {
            return false;
        }

        return ! $this->isCalcOriginKey($snapshot, $def->key);
    }

    /**
     * @param  list<int>  $selectedCalculationPositionIds
     */
    private function assertSelectedCalcPositionsReadyForDispo(
        Calculation $calculation,
        array $selectedCalculationPositionIds,
    ): void {
        $calcSnapshot = $calculation->configurationSnapshot;
        if ($calcSnapshot === null) {
            return;
        }
        $calcSnapshot->loadMissing('fieldDefinitions');
        $calcPositions = $calculation->positions->keyBy('id');

        $requiredDefs = $calcSnapshot->fieldDefinitions
            ->where('scope', FieldScope::Position)
            ->filter(fn (SnapshotFieldDefinition $def): bool => $def->required
                && $def->visible
                && in_array($def->field_type, [FieldType::ShortText, FieldType::LongText], true));

        if ($requiredDefs->isEmpty()) {
            return;
        }

        $errors = [];
        foreach ($selectedCalculationPositionIds as $index => $calcPositionId) {
            $calcPosition = $calcPositions->get($calcPositionId);
            if ($calcPosition === null) {
                continue;
            }
            $values = $this->calculationFields->positionValuesForPayload($calcPosition, $calcSnapshot);
            foreach ($requiredDefs as $def) {
                $raw = $values[$def->key] ?? null;
                if ($raw === null || $raw === '') {
                    $errors["positions.{$index}.dynamic_field_values.{$def->key}"] =
                        $def->label.' ist erforderlich.';
                }
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function isCalcOriginKey(
        ConfigurationSnapshot $dispoSnapshot,
        string $key,
        ?ConfigurationSnapshot $calcSnapshot = null,
    ): bool {
        if (in_array($key, DispoConfigurationSnapshotComposer::CALC_ORIGIN_KEYS, true)) {
            return true;
        }

        if ($calcSnapshot === null) {
            $sourceId = $dispoSnapshot->source_configuration_snapshot_id;
            if ($sourceId === null) {
                return false;
            }
            $calcSnapshot = ConfigurationSnapshot::query()
                ->with('fieldDefinitions')
                ->find($sourceId);
            if ($calcSnapshot === null) {
                return false;
            }
        } else {
            $calcSnapshot->loadMissing('fieldDefinitions');
        }

        return $calcSnapshot->fieldDefinitions->contains('key', $key);
    }

    private function maxLengthForDefinition(SnapshotFieldDefinition $def): int
    {
        $fromJson = is_array($def->validation_json) ? ($def->validation_json['max_length'] ?? null) : null;
        if (is_numeric($fromJson)) {
            return (int) $fromJson;
        }

        return $def->field_type === FieldType::ShortText ? 255 : 20000;
    }

    private function persistHeaderPeriod(
        DispoOrder $order,
        ConfigurationSnapshot $snapshot,
        string $key,
        mixed $period,
    ): void {
        $def = $snapshot->fieldDefinitions->firstWhere('key', $key);
        if ($def === null) {
            throw new RuntimeException("Snapshot-Definition {$key} fehlt.");
        }

        $start = null;
        $end = null;
        if (is_array($period)) {
            $start = $period['start'] ?? $period['period_start'] ?? null;
            $end = $period['end'] ?? $period['period_end'] ?? null;
            $start = $start === '' ? null : $start;
            $end = $end === '' ? null : $end;
        }

        $row = DispoOrderFieldValue::query()->firstOrNew([
            'dispo_order_id' => $order->id,
            'snapshot_field_definition_id' => $def->id,
        ]);
        $row->value_text = null;
        $row->value_period_start = $start !== null ? Carbon::parse((string) $start)->toDateString() : null;
        $row->value_period_end = $end !== null ? Carbon::parse((string) $end)->toDateString() : null;
        $row->save();
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function persistPositionValues(
        DispoOrderPosition $position,
        ConfigurationSnapshot $snapshot,
        array $values,
        ?ConfigurationSnapshot $calcSnapshot = null,
    ): void {
        foreach ($snapshot->fieldDefinitions->where('scope', FieldScope::Position) as $def) {
            if (! $this->isCalcOriginKey($snapshot, $def->key, $calcSnapshot)) {
                continue;
            }
            $this->persistPositionFieldValue($position, $snapshot, $def->key, $values[$def->key] ?? null);
        }
    }

    private function persistPositionFieldValue(
        DispoOrderPosition $position,
        ConfigurationSnapshot $snapshot,
        string $key,
        mixed $raw,
    ): void {
        $def = $snapshot->fieldDefinitions->firstWhere('key', $key);
        if ($def === null) {
            throw new RuntimeException("Snapshot-Definition {$key} fehlt.");
        }

        if ($def->field_type === FieldType::Boolean || $key === 'period_open') {
            if ($key === 'period_open' && ! is_bool($raw)) {
                throw ValidationException::withMessages([
                    'dynamic_field_values.period_open' => 'Zeitraum offen muss gesetzt sein.',
                ]);
            }
            if ($raw !== null && ! is_bool($raw)) {
                throw ValidationException::withMessages([
                    "dynamic_field_values.{$key}" => 'Boolean-Wert ungültig.',
                ]);
            }
            $row = DispoOrderPositionFieldValue::query()->firstOrNew([
                'dispo_order_position_id' => $position->id,
                'snapshot_field_definition_id' => $def->id,
            ]);
            $row->value_boolean = is_bool($raw) ? $raw : null;
            $row->value_string = null;
            $row->value_text = null;
            $row->value_period_start = null;
            $row->value_period_end = null;
            $row->save();

            return;
        }

        if (in_array($def->field_type, [FieldType::ShortText, FieldType::LongText], true)) {
            $value = null;
            if (is_string($raw) || is_numeric($raw)) {
                $trimmed = trim((string) $raw);
                $value = $trimmed === '' ? null : $trimmed;
            }
            $this->upsertPositionTextValue($position, $snapshot, $key, $value);

            return;
        }

        $start = null;
        $end = null;
        if ($raw !== null && $raw !== '') {
            if (! is_array($raw)) {
                throw ValidationException::withMessages([
                    "dynamic_field_values.{$key}" => 'Zeitraum muss Start und Ende enthalten.',
                ]);
            }
            $start = $raw['start'] ?? $raw['period_start'] ?? null;
            $end = $raw['end'] ?? $raw['period_end'] ?? null;
            $start = $start === '' ? null : $start;
            $end = $end === '' ? null : $end;
            if (($start === null) !== ($end === null)) {
                throw ValidationException::withMessages([
                    "dynamic_field_values.{$key}" => 'Zeitraum muss vollständig mit Beginn und Ende angegeben werden.',
                ]);
            }
            if ($start !== null && $end !== null && (string) $start > (string) $end) {
                throw ValidationException::withMessages([
                    "dynamic_field_values.{$key}" => 'Zeitraum-Beginn darf nicht nach dem Ende liegen.',
                ]);
            }
        }

        $row = DispoOrderPositionFieldValue::query()->firstOrNew([
            'dispo_order_position_id' => $position->id,
            'snapshot_field_definition_id' => $def->id,
        ]);
        $row->value_boolean = null;
        $row->value_string = null;
        $row->value_text = null;
        $row->value_period_start = $start !== null ? Carbon::parse((string) $start)->toDateString() : null;
        $row->value_period_end = $end !== null ? Carbon::parse((string) $end)->toDateString() : null;
        $row->save();
    }

    private function upsertPositionTextValue(
        DispoOrderPosition $position,
        ConfigurationSnapshot $snapshot,
        string $key,
        ?string $value,
    ): void {
        $def = $snapshot->fieldDefinitions->firstWhere('key', $key);
        if ($def === null) {
            throw new RuntimeException("Snapshot-Definition {$key} fehlt.");
        }

        $row = DispoOrderPositionFieldValue::query()->firstOrNew([
            'dispo_order_position_id' => $position->id,
            'snapshot_field_definition_id' => $def->id,
        ]);
        $row->value_string = $def->field_type === FieldType::ShortText ? $value : null;
        $row->value_text = $def->field_type === FieldType::LongText ? $value : null;
        $row->value_boolean = null;
        $row->value_period_start = null;
        $row->value_period_end = null;
        $row->save();
    }

    private function requireSnapshot(DispoOrder $order): ConfigurationSnapshot
    {
        $order->loadMissing('configurationSnapshot.fieldDefinitions', 'configurationSnapshot.rules');

        return $order->configurationSnapshot;
    }

    private function assertReadyForRules(DispoOrder $order, ConfigurationSnapshot $snapshot): void
    {
        $order->loadMissing(['positions.fieldValues', 'fieldValues']);
        $headerValues = $this->headerValuesForValidation($order, $snapshot);
        $positionContexts = [];
        foreach ($order->positions->values() as $index => $position) {
            $positionContexts[] = [
                'index' => $index,
                'values' => $this->positionValuesForValidation($position, $snapshot),
            ];
        }
        $this->rules->validate($snapshot, $headerValues, $positionContexts);
    }

    /**
     * @return array<string, mixed>
     */
    private function headerValuesForValidation(DispoOrder $order, ConfigurationSnapshot $snapshot): array
    {
        $order->loadMissing('fieldValues.snapshotFieldDefinition');
        $values = [];
        foreach ($snapshot->fieldDefinitions->where('scope', FieldScope::Header) as $def) {
            $values[$def->key] = $this->readHeaderValue($order, $def);
        }

        return $values;
    }

    /**
     * @return array<string, mixed>
     */
    private function positionValuesForValidation(DispoOrderPosition $position, ConfigurationSnapshot $snapshot): array
    {
        $position->loadMissing('fieldValues.snapshotFieldDefinition');
        $values = [];
        foreach ($snapshot->fieldDefinitions->where('scope', FieldScope::Position) as $def) {
            $values[$def->key] = $this->readPositionValue($position, $def);
        }

        return $values;
    }

    private function readHeaderValue(DispoOrder $order, SnapshotFieldDefinition $def): mixed
    {
        $row = $order->fieldValues->firstWhere('snapshot_field_definition_id', $def->id);
        if ($row === null) {
            return null;
        }
        if ($def->field_type === FieldType::Period) {
            if ($row->value_period_start === null && $row->value_period_end === null) {
                return null;
            }

            return [
                'start' => $this->dateToString($row->value_period_start),
                'end' => $this->dateToString($row->value_period_end),
            ];
        }

        if ($def->field_type === FieldType::ShortText) {
            return $row->value_string;
        }

        if ($def->field_type === FieldType::LongText) {
            return $row->value_text;
        }

        return null;
    }

    private function readPositionValue(DispoOrderPosition $position, SnapshotFieldDefinition $def): mixed
    {
        $row = $position->fieldValues->firstWhere('snapshot_field_definition_id', $def->id);
        if ($row === null) {
            return null;
        }

        return match ($def->field_type) {
            FieldType::Boolean => $row->value_boolean,
            FieldType::Period => ($row->value_period_start === null && $row->value_period_end === null)
                ? null
                : [
                    'start' => $this->dateToString($row->value_period_start),
                    'end' => $this->dateToString($row->value_period_end),
                ],
            FieldType::ShortText => $row->value_string,
            FieldType::LongText => $row->value_text,
        };
    }

    private function dateToString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if ($value instanceof CarbonInterface) {
            return $value->toDateString();
        }

        return Carbon::parse((string) $value)->toDateString();
    }

    private function hasHeaderValue(DispoOrder $order, ConfigurationSnapshot $snapshot, string $key): bool
    {
        $def = $snapshot->fieldDefinitions->firstWhere('key', $key);
        if ($def === null) {
            return false;
        }
        $order->loadMissing('fieldValues');

        return $order->fieldValues->contains('snapshot_field_definition_id', $def->id);
    }

    private function hasPositionValue(DispoOrderPosition $position, ConfigurationSnapshot $snapshot, string $key): bool
    {
        $def = $snapshot->fieldDefinitions->firstWhere('key', $key);
        if ($def === null) {
            return false;
        }
        $position->loadMissing('fieldValues');

        return $position->fieldValues->contains('snapshot_field_definition_id', $def->id);
    }

    /**
     * @return list<string>
     */
    private function missingCalcOriginKeys(DispoOrder $order, ConfigurationSnapshot $snapshot): array
    {
        $gaps = $this->calcOriginCaptureGaps($order, $snapshot);
        $keys = [];
        foreach ($gaps as $gap) {
            $keys[] = $gap['key'];
        }

        return array_values(array_unique($keys));
    }

    /**
     * @return list<array{key: string, position_id: int|null, reason: string}>
     */
    private function calcOriginCaptureGaps(DispoOrder $order, ConfigurationSnapshot $snapshot): array
    {
        $gaps = [];
        $order->loadMissing(['fieldValues', 'positions.fieldValues']);

        if (! $this->hasHeaderValue($order, $snapshot, 'campaign_period')) {
            $gaps[] = [
                'key' => 'campaign_period',
                'position_id' => null,
                'reason' => 'missing_row',
            ];
        }

        foreach ($order->positions as $position) {
            $positionId = (int) $position->id;
            if (! $this->hasPositionValue($position, $snapshot, 'period_open')) {
                $gaps[] = [
                    'key' => 'period_open',
                    'position_id' => $positionId,
                    'reason' => 'missing_row',
                ];
            } else {
                $periodOpenDef = $snapshot->fieldDefinitions->firstWhere('key', 'period_open');
                $periodOpen = $periodOpenDef === null
                    ? null
                    : $this->readPositionValue($position, $periodOpenDef);
                if (! is_bool($periodOpen)) {
                    $gaps[] = [
                        'key' => 'period_open',
                        'position_id' => $positionId,
                        'reason' => 'missing_boolean',
                    ];
                }
            }

            if (! $this->hasPositionValue($position, $snapshot, 'position_flight_period')) {
                $gaps[] = [
                    'key' => 'position_flight_period',
                    'position_id' => $positionId,
                    'reason' => 'missing_row',
                ];
            }
        }

        return $gaps;
    }

    private function hasAnyCalcOriginValue(DispoOrder $order, ConfigurationSnapshot $snapshot): bool
    {
        if ($this->hasHeaderValue($order, $snapshot, 'campaign_period')) {
            return true;
        }
        foreach ($order->positions as $position) {
            if ($this->hasPositionValue($position, $snapshot, 'period_open')
                || $this->hasPositionValue($position, $snapshot, 'position_flight_period')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, string|null>
     */
    private function textValuesForAudit(DispoOrder $order, ConfigurationSnapshot $snapshot): array
    {
        $order->loadMissing('fieldValues.snapshotFieldDefinition');
        $out = [];
        foreach ($snapshot->fieldDefinitions->where('scope', FieldScope::Header) as $def) {
            if (! in_array($def->field_type, [FieldType::ShortText, FieldType::LongText], true)) {
                continue;
            }
            if (! $this->isNativeEditableHeaderText($snapshot, $def)
                && ! $this->isCalcOriginKey($snapshot, $def->key)) {
                continue;
            }
            $out[$def->key] = $this->readHeaderValue($order, $def);
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function calcOriginValuesForAudit(DispoOrder $order, ConfigurationSnapshot $snapshot): array
    {
        $order->loadMissing(['fieldValues', 'positions.fieldValues']);
        $header = [];
        foreach (['campaign_period'] as $key) {
            $def = $snapshot->fieldDefinitions->firstWhere('key', $key);
            $header[$key] = $def === null ? null : $this->readHeaderValue($order, $def);
        }
        $positions = [];
        foreach ($order->positions as $position) {
            $values = [];
            foreach (['period_open', 'position_flight_period'] as $key) {
                $def = $snapshot->fieldDefinitions->firstWhere('key', $key);
                $values[$key] = $def === null ? null : $this->readPositionValue($position, $def);
            }
            $positions[(int) $position->id] = $values;
        }

        return ['header' => $header, 'positions' => $positions];
    }

    /**
     * @return array<int, array<string, string|null>>
     */
    private function positionCustomValuesForAudit(DispoOrder $order, ConfigurationSnapshot $snapshot): array
    {
        $order->loadMissing('positions.fieldValues.snapshotFieldDefinition');
        $out = [];
        foreach ($order->positions as $position) {
            $values = [];
            foreach ($snapshot->fieldDefinitions->where('scope', FieldScope::Position) as $def) {
                if (! in_array($def->field_type, [FieldType::ShortText, FieldType::LongText], true)) {
                    continue;
                }
                if (! $this->isNativeEditablePositionText($snapshot, $def)
                    && ! $this->isCalcOriginKey($snapshot, $def->key)) {
                    continue;
                }
                $raw = $this->readPositionValue($position, $def);
                $values[$def->key] = is_string($raw) || $raw === null ? $raw : (string) $raw;
            }
            $out[(int) $position->id] = $values;
        }

        return $out;
    }

    private function reloadOrder(DispoOrder $order): DispoOrder
    {
        return DispoOrder::query()
            ->with([
                'positions.fieldValues.snapshotFieldDefinition',
                'fieldValues.snapshotFieldDefinition',
                'configurationSnapshot.fieldDefinitions',
                'configurationSnapshot.rules',
                'creator',
                'advisor',
                'calculation',
                'revises',
                'revision',
            ])
            ->whereKey($order->id)
            ->firstOrFail();
    }
}
