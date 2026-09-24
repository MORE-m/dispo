<?php

namespace App\Services\DynamicField;

use App\Enums\ConfigurationSnapshotSource as ConfigurationSnapshotSourceEnum;
use App\Enums\DispoOrderStatus;
use App\Enums\FieldScope;
use App\Enums\FieldType;
use App\Exceptions\DispoOrderConflictException;
use App\Models\Calculation;
use App\Models\CalculationPosition;
use App\Models\ConfigurationSnapshot;
use App\Models\DispoOrder;
use App\Models\DispoOrderFieldValue;
use App\Models\DispoOrderPosition;
use App\Models\DispoOrderPositionFieldValue;
use App\Models\FieldDefinition;
use App\Models\SnapshotFieldDefinition;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\DynamicField\ChoiceFieldValueContract;
use App\Support\DynamicField\FieldRuleDefinitionContext;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * DF-2: Persistenz und Validierung dynamischer Dispo-Feldwerte.
 */
final class DispoOrderDynamicFieldWriter
{
    private const RULESET_INTEGRITY_CLIENT_MESSAGE =
        'Die Feldregeln dieses Vorgangs sind ungültig. Speichern ist nicht möglich.';

    public function __construct(
        private readonly SnapshotFieldRuleEvaluator $rules,
        private readonly CalculationDynamicFieldWriter $calculationFields,
        private readonly ConfigurationSnapshotFreezeService $freeze,
        private readonly ConfigurationSnapshotCloneService $clone,
        private readonly ConfigurationSnapshotIntegrity $integrity,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Freeze der Dispo-Konfiguration; setzt configuration_snapshot_id vor dem
     * ersten Save.
     *
     * @param  list<int>  $selectedCalculationPositionIds
     */
    public function assignComposedSnapshot(
        DispoOrder $order,
        Calculation $calculation,
        array $selectedCalculationPositionIds,
    ): void {
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

        $calcSnapshot->assertReadable();

        if ((int) $calcSnapshot->id !== (int) $calculation->configuration_snapshot_id) {
            throw new RuntimeException(
                'Quellsnapshot stimmt nicht mit der configuration_snapshot_id der Kalkulation überein.',
            );
        }

        // DF-3.3a2β: Kalkulationen der Generation 3 frieren Dispo-Basis plus
        // Effektiv-Snapshots nur für die ausgewählten Positionen ein.
        $snapshot = $this->isContextualFreeze($calcSnapshot)
            ? $this->freeze->freezeDispoV3($calcSnapshot, $selectedCalculationPositionIds)
            : $this->freeze->freezeDispoV2($calcSnapshot);

        $order->forceFill([
            'configuration_snapshot_id' => $snapshot->id,
        ]);
    }

    /**
     * DF-3.3a2β / VER-003: bindet je Dispoposition den Effektiv-Snapshot und
     * übernimmt den historischen Werbemittel-/Kategoriekontext.
     *
     * Neuanlage nutzt die beim Dispo-Freeze entstandenen Effektiv-Snapshots,
     * die Nachbesserung klont die des Vorgängers.
     */
    public function attachPositionEffectives(
        DispoOrder $order,
        Calculation $calculation,
        ?DispoOrder $predecessor = null,
    ): void {
        $order->loadMissing('configurationSnapshot');
        $base = $order->configurationSnapshot;

        if (! $this->isContextualFreeze($base)) {
            return;
        }

        $order->load('positions');

        $predecessorByCalcId = collect();
        if ($predecessor !== null) {
            $predecessor->loadMissing('positions.effectiveConfigurationSnapshot');
            $predecessorByCalcId = $predecessor->positions->keyBy('calculation_position_id');
        }

        $calculation->loadMissing('positions.effectiveConfigurationSnapshot');
        $calcPositions = $calculation->positions->keyBy('id');

        foreach ($order->positions as $position) {
            $calcPositionId = (int) $position->calculation_position_id;

            $effective = $predecessor !== null
                ? $this->clonedPredecessorEffective($predecessorByCalcId->get($calcPositionId), $base, $calcPositionId)
                : $this->frozenDispoEffective($calcPositions->get($calcPositionId), $base, $calcPositionId);

            $position->forceFill([
                'advertising_medium_code' => $effective->context_advertising_medium_code,
                'advertising_medium_name' => $effective->context_advertising_medium_name,
                'advertising_category_id' => (int) $effective->context_advertising_category_id,
                'advertising_category_key' => $effective->context_advertising_category_key,
                'advertising_category_name' => $effective->context_advertising_category_name,
                'effective_configuration_snapshot_id' => (int) $effective->id,
            ]);
            $position->save();
            $position->setRelation('effectiveConfigurationSnapshot', $effective);

            $this->integrity->assertOwnership($effective);
        }

        if ($predecessor === null) {
            $createdEffectives = ConfigurationSnapshot::query()
                ->where('parent_configuration_snapshot_id', $base->id)
                ->where('source', ConfigurationSnapshotSourceEnum::DispoOrderPositionEffective->value)
                ->count();
            if ($createdEffectives !== $order->positions->count()) {
                throw new RuntimeException(
                    "Dispo-Freeze erzeugte {$createdEffectives} Effektiv-Snapshots, "
                    ."aber {$order->positions->count()} Positionen wurden gebunden.",
                );
            }
        }
    }

    private function frozenDispoEffective(
        ?CalculationPosition $calcPosition,
        ConfigurationSnapshot $base,
        int $calcPositionId,
    ): ConfigurationSnapshot {
        $calcEffective = $calcPosition?->effectiveConfigurationSnapshot;
        if ($calcEffective === null) {
            throw new RuntimeException(
                "Kalkulationsposition {$calcPositionId} hat keinen Effektiv-Snapshot.",
            );
        }

        $effective = $this->freeze->findDispoPositionEffective($base, $calcEffective);
        if ($effective === null) {
            throw new RuntimeException(
                "Dispo-Freeze ohne Effektiv-Snapshot zu Kalkulationsposition {$calcPositionId}.",
            );
        }

        return $effective;
    }

    private function clonedPredecessorEffective(
        ?DispoOrderPosition $predecessorPosition,
        ConfigurationSnapshot $base,
        int $calcPositionId,
    ): ConfigurationSnapshot {
        $predecessorEffective = $predecessorPosition?->effectiveConfigurationSnapshot;
        if ($predecessorEffective === null) {
            throw new RuntimeException(
                "Nachbesserung ohne Effektiv-Snapshot zu Kalkulationsposition {$calcPositionId}.",
            );
        }

        return $this->clone->cloneEffectiveForDispoRevision($predecessorEffective, $base);
    }

    private function isContextualFreeze(?ConfigurationSnapshot $snapshot): bool
    {
        return $snapshot !== null
            && (int) $snapshot->format_version === ConfigurationSnapshot::FORMAT_VERSION_CONTEXTUAL_FREEZE;
    }

    /**
     * Positionsscharfer Bewertungsrahmen: ab Generation 3 der Effektiv-Snapshot
     * der Dispoposition, davor der Dispo-Basissnapshot selbst.
     */
    private function positionSnapshot(
        ConfigurationSnapshot $snapshot,
        DispoOrderPosition $position,
    ): ConfigurationSnapshot {
        if (! $this->isContextualFreeze($snapshot)) {
            return $snapshot;
        }

        $position->loadMissing('effectiveConfigurationSnapshot');
        $effective = $position->effectiveConfigurationSnapshot;

        if ($effective === null) {
            throw new RuntimeException("Dispoposition {$position->id} hat keinen Effektiv-Snapshot.");
        }

        try {
            $effective->assertReadable();
        } catch (Throwable $exception) {
            // RULE-B Show/Payload: soft-fail. Mutationen bleiben über
            // assertSnapshotRulesetIntegrity / requireSnapshot fail-closed.
            Log::warning('Dispo position snapshot integrity failed', [
                'position_id' => $position->id,
                'snapshot_id' => $effective->id,
                'exception' => $exception->getMessage(),
            ]);
        }
        $effective->loadMissing(['fieldDefinitions', 'rules']);

        return $effective;
    }

    /**
     * @return Collection<int, SnapshotFieldDefinition>
     */
    private function positionDefinitions(ConfigurationSnapshot $snapshot, DispoOrderPosition $position)
    {
        return $this->positionSnapshot($snapshot, $position)
            ->fieldDefinitions
            ->where('scope', FieldScope::Position);
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
            $this->persistPositionValues(
                $dispoPosition,
                $this->positionSnapshot($snapshot, $dispoPosition),
                $values,
                $this->calculationFields->positionScopeSnapshot($calcPosition, $calcSnapshot),
            );
            $positionContexts[] = [
                'index' => $index,
                'position' => $dispoPosition,
                'values' => $values,
            ];
            $index++;
        }

        if ($predecessor !== null) {
            $this->copyTextFieldsFromPredecessor($order, $snapshot, $predecessor);
        }

        $headerValues = $this->headerValuesForValidation($order, $snapshot);
        $this->validateRules($snapshot, $headerValues, $positionContexts);
    }

    /**
     * Headerregeln liegen im Dispo-Basissnapshot, Positionsregeln je Effektiv-Snapshot.
     *
     * @param  array<string, mixed>  $headerValues
     * @param  list<array{index: int, position: DispoOrderPosition, values: array<string, mixed>}>  $positionContexts
     */
    private function validateRules(
        ConfigurationSnapshot $snapshot,
        array $headerValues,
        array $positionContexts,
    ): void {
        $this->rules->validate($snapshot, $headerValues, array_map(
            static fn (array $context): array => [
                'index' => $context['index'],
                'values' => $context['values'],
            ],
            $positionContexts,
        ));

        if (! $this->isContextualFreeze($snapshot)) {
            return;
        }

        foreach ($positionContexts as $context) {
            $this->rules->validate(
                $this->positionSnapshot($snapshot, $context['position']),
                $headerValues,
                [['index' => $context['index'], 'values' => $context['values']]],
            );
        }
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
            $this->assertSnapshotRulesetIntegrity($snapshot);
            $locked->loadMissing('fieldValues.snapshotFieldDefinition');
            $before = $this->textValuesForAudit($locked, $snapshot);

            $normalized = $this->normalizeTextInput($snapshot, $input, $locked);
            $changed = false;

            foreach ($normalized as $key => $newValue) {
                $oldValue = $before[$key] ?? null;
                if ($oldValue === $newValue) {
                    continue;
                }
                $def = $snapshot->fieldDefinitions->firstWhere('key', $key);
                if ($def !== null && $def->field_type->isChoice()) {
                    $this->upsertHeaderChoiceValue(
                        $locked,
                        $snapshot,
                        $key,
                        ChoiceFieldValueContract::assertNormalizedStoredValue($def->field_type, $newValue),
                    );
                } else {
                    $this->upsertHeaderTextValue(
                        $locked,
                        $snapshot,
                        $key,
                        $newValue === null ? null : (string) $newValue,
                    );
                }
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
            $this->assertSnapshotRulesetIntegrity($snapshot);
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

                $positionSnapshot = $this->positionSnapshot($snapshot, $position);
                $this->assertSnapshotRulesetIntegrity($snapshot, $positionSnapshot);
                $editableKeys = [];
                foreach ($this->positionDefinitions($snapshot, $position) as $def) {
                    if ($this->isNativeEditablePositionText($positionSnapshot, $def)) {
                        $editableKeys[$def->key] = $def;
                    }
                }

                foreach (array_keys($values) as $key) {
                    $key = (string) $key;
                    if (! isset($editableKeys[$key])) {
                        if ($this->isCalcOriginKey($positionSnapshot, $key)) {
                            $errors["position_dynamic_field_values.{$positionId}.{$key}"] =
                                'Dieses Feld ist im Dispoauftrag nicht bearbeitbar.';
                        } elseif ($positionSnapshot->fieldDefinitions->firstWhere('key', $key) !== null) {
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

                    if ($def->field_type->isChoice()) {
                        $previous = $this->readPositionValue($position, $def);
                        try {
                            $value = ChoiceFieldValueContract::normalizeIncoming(
                                $def->field_type,
                                is_array($def->options_json) ? $def->options_json : null,
                                $raw,
                                $previous,
                                "position_dynamic_field_values.{$positionId}.{$key}",
                                $def->label,
                            );
                        } catch (ValidationException $exception) {
                            foreach ($exception->errors() as $errorKey => $messages) {
                                $errors[$errorKey] = $messages[0] ?? ($def->label.' ist ungültig.');
                            }

                            continue;
                        }
                        if ($def->required
                            && ChoiceFieldValueContract::isEmpty($def->field_type, $value)) {
                            $headerOverlay = $this->headerValuesForValidation($locked, $snapshot);
                            $positionOverlay = array_replace(
                                $this->positionValuesForValidation($position, $snapshot),
                                [$key => $value],
                            );
                            if ($this->rules->isEffectivelyVisible(
                                $snapshot,
                                $def,
                                $headerOverlay,
                                $positionOverlay,
                                $positionSnapshot,
                            )) {
                                $errors["position_dynamic_field_values.{$positionId}.{$key}"] =
                                    $def->label.' ist erforderlich.';

                                continue;
                            }
                        }
                        $old = $previous;
                        if ($old === $value) {
                            continue;
                        }
                        $this->upsertPositionChoiceValue($position, $positionSnapshot, $key, $value);
                        $changed = true;

                        continue;
                    }

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
                    $this->upsertPositionTextValue($position, $positionSnapshot, $key, $value);
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
            $this->assertSnapshotRulesetIntegrity($snapshot);
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
                $positionSnapshot = $this->positionSnapshot($snapshot, $dispoPosition);
                $calcPositionSnapshot = $this->calculationFields->positionScopeSnapshot($calcPosition, $calcSnapshot);
                foreach ($this->positionDefinitions($snapshot, $dispoPosition) as $def) {
                    if (! $this->isCalcOriginKey($positionSnapshot, $def->key, $calcPositionSnapshot)) {
                        continue;
                    }
                    if ($this->hasPositionValue($dispoPosition, $positionSnapshot, $def->key)) {
                        continue;
                    }
                    $this->persistPositionFieldValue(
                        $dispoPosition,
                        $positionSnapshot,
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
        $missing = $this->collectMissingRequiredFields($order);
        if ($missing['errors'] !== []) {
            throw ValidationException::withMessages($missing['errors']);
        }

        $snapshot = $this->requireSnapshot($order);
        $order->loadMissing(['positions.fieldValues.snapshotFieldDefinition', 'fieldValues.snapshotFieldDefinition']);
        $headerValues = $this->headerValuesForValidation($order, $snapshot);

        $positionContexts = [];
        foreach ($order->positions->values() as $index => $position) {
            $positionContexts[] = [
                'index' => $index,
                'position' => $position,
                'values' => $this->positionValuesForValidation($position, $snapshot),
            ];
        }

        $this->validateRules($snapshot, $headerValues, $positionContexts);
    }

    /**
     * Sichtbare Pflichtfelder laut Frozen Snapshot (Completion / Submit).
     *
     * @return array{
     *     errors: array<string, string>,
     *     violations: list<array{message: string, field_label: string, scope: string}>
     * }
     */
    public function collectMissingRequiredFields(DispoOrder $order): array
    {
        $snapshot = $this->requireSnapshot($order);
        $order->loadMissing(['positions.fieldValues.snapshotFieldDefinition', 'fieldValues.snapshotFieldDefinition']);

        $gaps = $this->calcOriginCaptureGaps($order, $snapshot);
        if ($gaps !== []) {
            $message = 'Dynamische Zeitraumfelder fehlen. Bitte „Dynamische Zeitraumfelder aus Kalkulation übernehmen“ ausführen oder einen neuen Dispoauftrag anlegen.';

            return [
                'errors' => [
                    'dynamic_field_values' => $message,
                ],
                'violations' => [[
                    'message' => $message,
                    'field_label' => 'Dynamische Zeitraumfelder',
                    'scope' => 'header',
                ]],
            ];
        }

        $errors = [];
        $violations = [];
        $headerValues = $this->headerValuesForValidation($order, $snapshot);
        foreach ($snapshot->fieldDefinitions->where('scope', FieldScope::Header) as $def) {
            if (! $def->required) {
                continue;
            }
            if (! $this->isTextOrChoiceFieldType($def->field_type)) {
                continue;
            }
            if ($this->isCalcOriginKey($snapshot, $def->key)) {
                continue;
            }
            if (! $this->rules->isEffectivelyVisible($snapshot, $def, $headerValues, [])) {
                continue;
            }

            $raw = $this->readHeaderValue($order, $def);
            if ($this->isRequiredCustomEmpty($def, $raw)) {
                $message = $def->label.' ist erforderlich.';
                $errors["dynamic_field_values.{$def->key}"] = $message;
                $violations[] = [
                    'message' => $message,
                    'field_label' => $def->label,
                    'scope' => 'header',
                ];
            }
        }
        foreach ($order->positions as $position) {
            $positionSnapshot = $this->positionSnapshot($snapshot, $position);
            $positionValues = $this->positionValuesForValidation($position, $snapshot);
            foreach ($this->positionDefinitions($snapshot, $position) as $def) {
                if (! $def->required) {
                    continue;
                }
                if (! $this->isTextOrChoiceFieldType($def->field_type)) {
                    continue;
                }
                if ($this->isCalcOriginKey($positionSnapshot, $def->key)) {
                    continue;
                }
                if (! $this->rules->isEffectivelyVisible(
                    $snapshot,
                    $def,
                    $headerValues,
                    $positionValues,
                    $positionSnapshot,
                )) {
                    continue;
                }

                $raw = $this->readPositionValue($position, $def);
                if ($this->isRequiredCustomEmpty($def, $raw)) {
                    $message = $def->label.' ist erforderlich.';
                    $errors["position_dynamic_field_values.{$position->id}.{$def->key}"] = $message;
                    $violations[] = [
                        'message' => $message,
                        'field_label' => $def->label,
                        'scope' => 'position',
                    ];
                }
            }
        }

        return [
            'errors' => $errors,
            'violations' => $violations,
        ];
    }

    /**
     * @return array{
     *     fields: list<array<string, mixed>>,
     *     rules: list<array<string, mixed>>,
     *     editable_custom_header_fields: list<array<string, mixed>>,
     *     calc_origin_custom_header_fields: list<array<string, mixed>>,
     *     editable_custom_position_fields: list<array<string, mixed>>,
     *     calc_origin_custom_position_fields: list<array<string, mixed>>,
     *     position_field_schemas: array<int, array{
     *         editable_custom_fields: list<array<string, mixed>>,
     *         calc_origin_custom_fields: list<array<string, mixed>>
     *     }>
     * }
     */
    public function fieldSchemaProp(DispoOrder $order): array
    {
        $snapshot = $order->configurationSnapshot;
        $rulesIntegrityError = null;
        try {
            $snapshot->assertReadable();
        } catch (Throwable $exception) {
            Log::warning('Dispo field schema snapshot integrity failed', [
                'dispo_order_id' => $order->id,
                'snapshot_id' => $snapshot->id,
                'exception' => $exception->getMessage(),
            ]);
            $rulesIntegrityError = self::RULESET_INTEGRITY_CLIENT_MESSAGE;
        }
        $snapshot->loadMissing(['fieldDefinitions', 'rules']);
        $order->loadMissing('positions.effectiveConfigurationSnapshot.fieldDefinitions');

        $entries = $this->schemaDefinitions($order, $snapshot);

        $definitionIds = array_values(array_unique(array_map(
            static fn (array $entry): int => (int) $entry['def']->field_definition_id,
            $entries,
        )));
        foreach ($order->positions as $position) {
            try {
                foreach ($this->positionDefinitions($snapshot, $position) as $def) {
                    $definitionIds[] = (int) $def->field_definition_id;
                }
            } catch (Throwable) {
                $rulesIntegrityError = self::RULESET_INTEGRITY_CLIENT_MESSAGE;
            }
        }
        $definitionIds = array_values(array_unique($definitionIds));

        $systemByDefinitionId = FieldDefinition::query()
            ->whereIn('id', $definitionIds)
            ->pluck('is_system', 'id');

        $fields = array_map(
            fn (array $entry): array => $this->mapSchemaFieldProp(
                $entry['def'],
                $entry['snapshot'],
                $systemByDefinitionId,
            ),
            $entries,
        );

        $customSchemaTypes = [
            FieldType::ShortText->value,
            FieldType::LongText->value,
            FieldType::Select->value,
            FieldType::MultiSelect->value,
        ];

        $editableCustom = $this->filterCustomSchemaFields(
            $fields,
            FieldScope::Header,
            editable: true,
            calcOrigin: false,
            customSchemaTypes: $customSchemaTypes,
        );
        $calcOriginCustom = $this->filterCustomSchemaFields(
            $fields,
            FieldScope::Header,
            editable: false,
            calcOrigin: true,
            customSchemaTypes: $customSchemaTypes,
        );
        $editableCustomPosition = $this->filterCustomSchemaFields(
            $fields,
            FieldScope::Position,
            editable: true,
            calcOrigin: false,
            customSchemaTypes: $customSchemaTypes,
        );
        $calcOriginCustomPosition = $this->filterCustomSchemaFields(
            $fields,
            FieldScope::Position,
            editable: false,
            calcOrigin: true,
            customSchemaTypes: $customSchemaTypes,
        );

        /** @var array<int, array{editable_custom_fields: list<array<string, mixed>>, calc_origin_custom_fields: list<array<string, mixed>>}> $positionFieldSchemas */
        $positionFieldSchemas = [];
        foreach ($order->positions as $position) {
            try {
                $positionSnapshot = $this->positionSnapshot($snapshot, $position);
            } catch (Throwable $exception) {
                Log::warning('Dispo position field schema integrity failed', [
                    'dispo_order_id' => $order->id,
                    'position_id' => $position->id,
                    'exception' => $exception->getMessage(),
                ]);
                $rulesIntegrityError = self::RULESET_INTEGRITY_CLIENT_MESSAGE;
                $positionSnapshot = $snapshot;
                $positionSnapshot->loadMissing(['fieldDefinitions', 'rules']);
            }
            $positionFields = [];
            foreach ($positionSnapshot->fieldDefinitions->where('scope', FieldScope::Position) as $def) {
                $positionFields[] = $this->mapSchemaFieldProp(
                    $def,
                    $positionSnapshot,
                    $systemByDefinitionId,
                );
            }

            $positionFieldSchemas[(int) $position->id] = [
                'fields' => $positionFields,
                'rules' => $positionSnapshot->rules->map(fn ($rule): array => [
                    'sort' => $rule->sort,
                    'condition' => $rule->condition_json,
                    'action' => $rule->action_json,
                ])->values()->all(),
                'editable_custom_fields' => $this->filterCustomSchemaFields(
                    $positionFields,
                    FieldScope::Position,
                    editable: true,
                    calcOrigin: false,
                    customSchemaTypes: $customSchemaTypes,
                ),
                'calc_origin_custom_fields' => $this->filterCustomSchemaFields(
                    $positionFields,
                    FieldScope::Position,
                    editable: false,
                    calcOrigin: true,
                    customSchemaTypes: $customSchemaTypes,
                ),
            ];
        }

        return [
            'fields' => $fields,
            // Header-/Basisregeln nur – Positionsregeln liegen kontexttreu in position_field_schemas.
            'rules' => $this->baseSchemaRules($snapshot),
            'editable_custom_header_fields' => $editableCustom,
            'calc_origin_custom_header_fields' => $calcOriginCustom,
            'editable_custom_position_fields' => $editableCustomPosition,
            'calc_origin_custom_position_fields' => $calcOriginCustomPosition,
            'position_field_schemas' => $positionFieldSchemas,
            'rules_integrity_error' => $rulesIntegrityError,
        ];
    }

    /**
     * @param  Collection<int|string, mixed>  $systemByDefinitionId
     * @return array<string, mixed>
     */
    private function mapSchemaFieldProp(
        SnapshotFieldDefinition $def,
        ConfigurationSnapshot $owner,
        $systemByDefinitionId,
    ): array {
        $calcOrigin = $this->isCalcOriginKey($owner, $def->key);

        return [
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
                ? $this->isNativeEditableHeaderText($owner, $def)
                : $this->isNativeEditablePositionText($owner, $def),
            'calc_origin' => $calcOrigin,
            'action_target_readonly' => $calcOrigin,
            'max_length' => $this->maxLengthForDefinition($def),
            'validation_json' => $def->validation_json,
            'options_json' => ChoiceFieldValueContract::optionsForSchemaProp(
                is_array($def->options_json) ? $def->options_json : null,
                $def->field_type,
            ),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $fields
     * @param  list<string>  $customSchemaTypes
     * @return list<array<string, mixed>>
     */
    private function filterCustomSchemaFields(
        array $fields,
        FieldScope $scope,
        bool $editable,
        bool $calcOrigin,
        array $customSchemaTypes,
    ): array {
        return array_values(array_filter(
            $fields,
            function (array $field) use ($scope, $editable, $calcOrigin, $customSchemaTypes): bool {
                if (($field['is_system'] ?? false) === true) {
                    return false;
                }
                if (($field['scope'] ?? null) !== $scope->value) {
                    return false;
                }
                if (! in_array($field['field_type'] ?? null, $customSchemaTypes, true)) {
                    return false;
                }
                if ($editable && ($field['editable'] ?? false) !== true) {
                    return false;
                }
                if ($calcOrigin && ($field['calc_origin'] ?? false) !== true) {
                    return false;
                }
                if (! $editable && ! $calcOrigin) {
                    return false;
                }
                if ($editable && ($field['calc_origin'] ?? false) === true) {
                    return false;
                }

                return true;
            },
        ));
    }

    /**
     * DF-3.3a2β: Ab Generation 3 liegen die Positionsfelder in den Effektiv-
     * Snapshots. Für die Oberfläche werden sie über alle Positionen vereinigt;
     * jeder Key erscheint genau einmal mit seinem Herkunftssnapshot.
     *
     * @return list<array{def: SnapshotFieldDefinition, snapshot: ConfigurationSnapshot}>
     */
    private function schemaDefinitions(DispoOrder $order, ConfigurationSnapshot $snapshot): array
    {
        /** @var list<array{def: SnapshotFieldDefinition, snapshot: ConfigurationSnapshot}> $entries */
        $entries = [];
        /** @var array<string, true> $seen */
        $seen = [];

        foreach ($snapshot->fieldDefinitions as $def) {
            $seen[$def->key] = true;
            $entries[] = ['def' => $def, 'snapshot' => $snapshot];
        }

        if (! $this->isContextualFreeze($snapshot)) {
            return $entries;
        }

        $order->loadMissing('positions.effectiveConfigurationSnapshot');

        foreach ($order->positions as $position) {
            $positionSnapshot = $this->positionSnapshot($snapshot, $position);
            foreach ($positionSnapshot->fieldDefinitions as $def) {
                if (isset($seen[$def->key])) {
                    continue;
                }
                $seen[$def->key] = true;
                $entries[] = ['def' => $def, 'snapshot' => $positionSnapshot];
            }
        }

        return $entries;
    }

    /**
     * Basis-Snapshot-Regeln für Header-Runtime (keine vereinigte Positionsmenge).
     *
     * @return list<array{sort: int, condition: mixed, action: mixed}>
     */
    private function baseSchemaRules(ConfigurationSnapshot $snapshot): array
    {
        $snapshot->loadMissing('rules');

        /** @var list<array{sort: int, condition: mixed, action: mixed}> $rules */
        $rules = [];
        foreach ($snapshot->rules as $rule) {
            $rules[] = [
                'sort' => (int) $rule->sort,
                'condition' => $rule->condition_json,
                'action' => $rule->action_json,
            ];
        }

        return $rules;
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
            $positionSnapshot = $this->positionSnapshot($snapshot, $position);
            $values = [];
            $captured = [];
            foreach ($this->positionDefinitions($snapshot, $position) as $def) {
                $captured[$def->key] = $this->hasPositionValue($position, $positionSnapshot, $def->key);
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
            if ($predDef === null || $predDef->field_type !== $newDef->field_type) {
                continue;
            }

            if ($newDef->field_type->isChoice()) {
                $predRow = $predecessor->fieldValues
                    ->firstWhere('snapshot_field_definition_id', $predDef->id);
                if ($predRow === null) {
                    continue;
                }
                $stored = ChoiceFieldValueContract::readStored($predDef, $predRow);
                if (ChoiceFieldValueContract::isEmpty($newDef->field_type, $stored)) {
                    continue;
                }
                $normalized = ChoiceFieldValueContract::normalizeIncoming(
                    $newDef->field_type,
                    is_array($newDef->options_json) ? $newDef->options_json : null,
                    $stored,
                    $stored,
                    "dynamic_field_values.{$newDef->key}",
                    $newDef->label,
                );
                $this->upsertHeaderChoiceValue($order, $snapshot, $newDef->key, $normalized);

                continue;
            }

            if (! in_array($predDef->field_type, [FieldType::ShortText, FieldType::LongText], true)) {
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

            $positionSnapshot = $this->positionSnapshot($snapshot, $dispoPosition);
            $predPositionSnapshot = $this->positionSnapshot($predSnapshot, $predPosition);

            foreach ($this->positionDefinitions($snapshot, $dispoPosition) as $newDef) {
                if (! $this->isNativeEditablePositionText($positionSnapshot, $newDef)) {
                    continue;
                }

                $predDef = $predPositionSnapshot->fieldDefinitions->firstWhere('key', $newDef->key);
                if ($predDef === null || $predDef->field_type !== $newDef->field_type) {
                    continue;
                }

                if ($newDef->field_type->isChoice()) {
                    $predValue = $predPosition->fieldValues
                        ->firstWhere('snapshot_field_definition_id', $predDef->id);
                    if ($predValue === null) {
                        continue;
                    }
                    $stored = ChoiceFieldValueContract::readStored($predDef, $predValue);
                    if (ChoiceFieldValueContract::isEmpty($newDef->field_type, $stored)) {
                        continue;
                    }
                    $normalized = ChoiceFieldValueContract::normalizeIncoming(
                        $newDef->field_type,
                        is_array($newDef->options_json) ? $newDef->options_json : null,
                        $stored,
                        $stored,
                        "position_dynamic_field_values.{$dispoPosition->id}.{$newDef->key}",
                        $newDef->label,
                    );
                    $this->upsertPositionChoiceValue(
                        $dispoPosition,
                        $positionSnapshot,
                        $newDef->key,
                        $normalized,
                    );

                    continue;
                }

                if (! in_array($predDef->field_type, [FieldType::ShortText, FieldType::LongText], true)) {
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

                $this->upsertPositionTextValue($dispoPosition, $positionSnapshot, $newDef->key, $text);
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
            if (! $this->isTextOrChoiceFieldType($def->field_type)) {
                continue;
            }
            if (! $this->isCalcOriginKey($snapshot, $def->key, $calcSnapshot)) {
                continue;
            }

            $raw = $headerFromCalc[$def->key] ?? null;

            if ($def->field_type->isChoice()) {
                $normalized = ChoiceFieldValueContract::normalizeIncoming(
                    $def->field_type,
                    is_array($def->options_json) ? $def->options_json : null,
                    $raw,
                    $raw,
                    "dynamic_field_values.{$def->key}",
                    $def->label,
                );
                $this->upsertHeaderChoiceValue($order, $snapshot, $def->key, $normalized);

                continue;
            }

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
     * @return array<string, mixed>
     */
    private function normalizeTextInput(
        ConfigurationSnapshot $snapshot,
        array $input,
        DispoOrder $order,
    ): array {
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

            if ($def->field_type->isChoice()) {
                $previous = $this->readHeaderValue($order, $def);
                try {
                    $value = ChoiceFieldValueContract::normalizeIncoming(
                        $def->field_type,
                        is_array($def->options_json) ? $def->options_json : null,
                        $raw,
                        $previous,
                        "dynamic_field_values.{$key}",
                        $def->label,
                    );
                } catch (ValidationException $exception) {
                    foreach ($exception->errors() as $errorKey => $messages) {
                        $errors[$errorKey] = $messages[0] ?? ($def->label.' ist ungültig.');
                    }

                    continue;
                }
                if ($def->required
                    && ChoiceFieldValueContract::isEmpty($def->field_type, $value)) {
                    $headerOverlay = array_replace(
                        $this->headerValuesForValidation($order, $snapshot),
                        [$key => $value],
                    );
                    if ($this->rules->isEffectivelyVisible($snapshot, $def, $headerOverlay, [])) {
                        $errors["dynamic_field_values.{$key}"] = $def->label.' ist erforderlich.';

                        continue;
                    }
                }
                $normalized[$key] = $value;

                continue;
            }

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
        ChoiceFieldValueContract::clearChoiceChannel($row);
        $row->value_string = $def->field_type === FieldType::ShortText ? $value : null;
        $row->value_text = $def->field_type === FieldType::LongText ? $value : null;
        $row->value_period_start = null;
        $row->value_period_end = null;
        $row->save();
    }

    /**
     * @param  string|list<string>|null  $value
     */
    private function upsertHeaderChoiceValue(
        DispoOrder $order,
        ConfigurationSnapshot $snapshot,
        string $key,
        string|array|null $value,
    ): void {
        $def = $snapshot->fieldDefinitions->firstWhere('key', $key);
        if ($def === null) {
            throw new RuntimeException("Snapshot-Definition {$key} fehlt.");
        }

        $row = DispoOrderFieldValue::query()->firstOrNew([
            'dispo_order_id' => $order->id,
            'snapshot_field_definition_id' => $def->id,
        ]);
        ChoiceFieldValueContract::writeStored($def, $row, $value);
        $row->save();
    }

    /**
     * Native bearbeitbare Header-Customs: Short/Long-Text sowie Select/MultiSelect
     * (nicht calc-origin).
     */
    private function isNativeEditableHeaderText(ConfigurationSnapshot $snapshot, SnapshotFieldDefinition $def): bool
    {
        if ($def->scope !== FieldScope::Header) {
            return false;
        }
        if (! $this->isTextOrChoiceFieldType($def->field_type)) {
            return false;
        }

        return ! $this->isCalcOriginKey($snapshot, $def->key);
    }

    /**
     * Native bearbeitbare Positions-Customs: Short/Long-Text sowie Select/MultiSelect
     * (nicht calc-origin). Basis-visible steuert nicht die Schreibbarkeit – RULE-B
     * blendet über Effective Visible; Server akzeptiert native Keys inkl. basis_visible=false.
     */
    private function isNativeEditablePositionText(ConfigurationSnapshot $snapshot, SnapshotFieldDefinition $def): bool
    {
        if ($def->scope !== FieldScope::Position) {
            return false;
        }
        if (! $this->isTextOrChoiceFieldType($def->field_type)) {
            return false;
        }

        return ! $this->isCalcOriginKey($snapshot, $def->key);
    }

    private function isTextOrChoiceFieldType(FieldType $fieldType): bool
    {
        return in_array($fieldType, [
            FieldType::ShortText,
            FieldType::LongText,
            FieldType::Select,
            FieldType::MultiSelect,
        ], true);
    }

    private function isRequiredCustomEmpty(SnapshotFieldDefinition $def, mixed $raw): bool
    {
        if ($def->field_type->isChoice()) {
            return ChoiceFieldValueContract::isEmpty($def->field_type, $raw);
        }

        return $raw === null || $raw === '';
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
        $calcSnapshot->assertReadable();
        $calcSnapshot->loadMissing('fieldDefinitions');
        $calculation->loadMissing('positions.effectiveConfigurationSnapshot.fieldDefinitions');
        $calcPositions = $calculation->positions->keyBy('id');

        $errors = [];

        // PO-32b-1 / RULE-B: effektiv sichtbare Custom-Pflichtfelder der Calc blockieren Dispo-Create.
        $headerValues = $this->calculationFields->headerValuesForPayload($calculation);
        foreach ($calcSnapshot->fieldDefinitions->where('scope', FieldScope::Header) as $def) {
            if (! $def->required) {
                continue;
            }
            if (! $this->isTextOrChoiceFieldType($def->field_type)) {
                continue;
            }
            if (! $this->calculationFields->isEffectivelyVisible(
                $calcSnapshot,
                $def,
                $headerValues,
            )) {
                continue;
            }

            $raw = $headerValues[$def->key] ?? null;
            if ($this->isRequiredCustomEmpty($def, $raw)) {
                $errors["dynamic_field_values.{$def->key}"] = $def->label.' ist erforderlich.';
            }
        }

        foreach ($selectedCalculationPositionIds as $index => $calcPositionId) {
            $calcPosition = $calcPositions->get($calcPositionId);
            if ($calcPosition === null) {
                continue;
            }

            $scopeSnapshot = $this->calculationFields->positionScopeSnapshot($calcPosition, $calcSnapshot);
            $values = $this->calculationFields->positionValuesForPayload($calcPosition, $calcSnapshot);
            $requiredDefs = $scopeSnapshot->fieldDefinitions
                ->where('scope', FieldScope::Position)
                ->filter(fn (SnapshotFieldDefinition $def): bool => $def->required
                    && $this->isTextOrChoiceFieldType($def->field_type)
                    && $this->calculationFields->isEffectivelyVisible(
                        $calcSnapshot,
                        $def,
                        $headerValues,
                        $values,
                        $scopeSnapshot,
                    ));

            if ($requiredDefs->isEmpty()) {
                continue;
            }

            foreach ($requiredDefs as $def) {
                $raw = $values[$def->key] ?? null;
                if ($this->isRequiredCustomEmpty($def, $raw)) {
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

        if ($calcSnapshot->fieldDefinitions->contains('key', $key)) {
            return true;
        }

        // Generation 3: Positions-Calc-Origin liegt am Effektiv, nicht an der Basis.
        if ((int) $calcSnapshot->format_version !== ConfigurationSnapshot::FORMAT_VERSION_CONTEXTUAL_FREEZE) {
            return false;
        }

        if ($calcSnapshot->parent_configuration_snapshot_id !== null) {
            // Quelle ist bereits ein Calc-Effektiv.
            return false;
        }

        return SnapshotFieldDefinition::query()
            ->where('key', $key)
            ->whereIn(
                'configuration_snapshot_id',
                ConfigurationSnapshot::query()
                    ->where('parent_configuration_snapshot_id', $calcSnapshot->id)
                    ->select('id'),
            )
            ->exists();
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
        ChoiceFieldValueContract::clearChoiceChannel($row);
        $row->value_string = null;
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

        if ($def->field_type->isChoice()) {
            $normalized = ChoiceFieldValueContract::normalizeIncoming(
                $def->field_type,
                is_array($def->options_json) ? $def->options_json : null,
                $raw,
                $raw,
                "dynamic_field_values.{$key}",
                $def->label,
            );
            $this->upsertPositionChoiceValue($position, $snapshot, $key, $normalized);

            return;
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
            ChoiceFieldValueContract::clearChoiceChannel($row);
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
        ChoiceFieldValueContract::clearChoiceChannel($row);
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
        ChoiceFieldValueContract::clearChoiceChannel($row);
        $row->value_string = $def->field_type === FieldType::ShortText ? $value : null;
        $row->value_text = $def->field_type === FieldType::LongText ? $value : null;
        $row->value_boolean = null;
        $row->value_period_start = null;
        $row->value_period_end = null;
        $row->save();
    }

    /**
     * @param  string|list<string>|null  $value
     */
    private function upsertPositionChoiceValue(
        DispoOrderPosition $position,
        ConfigurationSnapshot $snapshot,
        string $key,
        string|array|null $value,
    ): void {
        $def = $snapshot->fieldDefinitions->firstWhere('key', $key);
        if ($def === null) {
            throw new RuntimeException("Snapshot-Definition {$key} fehlt.");
        }

        $row = DispoOrderPositionFieldValue::query()->firstOrNew([
            'dispo_order_position_id' => $position->id,
            'snapshot_field_definition_id' => $def->id,
        ]);
        ChoiceFieldValueContract::writeStored($def, $row, $value);
        $row->save();
    }

    private function requireSnapshot(DispoOrder $order): ConfigurationSnapshot
    {
        $order->loadMissing('configurationSnapshot.fieldDefinitions', 'configurationSnapshot.rules');

        $snapshot = $order->configurationSnapshot;

        try {
            $snapshot->assertReadable();
        } catch (Throwable $exception) {
            Log::warning('Dispo dynamic-field snapshot readability failed', [
                'snapshot_id' => $snapshot->id,
                'schema_fingerprint' => $snapshot->schema_fingerprint,
                'exception' => $exception->getMessage(),
            ]);

            throw ValidationException::withMessages([
                'dynamic_field_values' => self::RULESET_INTEGRITY_CLIENT_MESSAGE,
            ]);
        }

        return $snapshot;
    }

    /**
     * RULE-B fail-closed: beschädigtes Ruleset blockiert Partial-Save vor jeder Mutation
     * (kein Wert-, Audit- oder Lock-Write). Getrennt von Required-Enforcement.
     */
    private function assertSnapshotRulesetIntegrity(
        ConfigurationSnapshot $snapshot,
        ?ConfigurationSnapshot $positionSnapshot = null,
    ): void {
        try {
            $snapshot->loadMissing(['fieldDefinitions', 'rules', 'sources']);
            $this->rules->assertRulesCompatibleWithDefinitions(
                FieldRuleDefinitionContext::fromSnapshot($snapshot),
                $snapshot->rules,
                requireActiveOptionKeys: false,
            );

            if ($positionSnapshot !== null && $positionSnapshot->id !== $snapshot->id) {
                $positionSnapshot->loadMissing(['fieldDefinitions', 'rules', 'sources']);
                $this->rules->assertRulesCompatibleWithDefinitions(
                    FieldRuleDefinitionContext::fromSnapshot($positionSnapshot),
                    $positionSnapshot->rules,
                    requireActiveOptionKeys: false,
                );
            }
        } catch (Throwable $exception) {
            Log::warning('Dispo dynamic-field ruleset integrity failed', [
                'snapshot_id' => $snapshot->id,
                'schema_fingerprint' => $snapshot->schema_fingerprint,
                'position_snapshot_id' => $positionSnapshot?->id,
                'exception' => $exception->getMessage(),
            ]);

            throw ValidationException::withMessages([
                'dynamic_field_values' => self::RULESET_INTEGRITY_CLIENT_MESSAGE,
            ]);
        }
    }

    private function assertReadyForRules(DispoOrder $order, ConfigurationSnapshot $snapshot): void
    {
        $order->loadMissing(['positions.fieldValues', 'fieldValues']);
        $headerValues = $this->headerValuesForValidation($order, $snapshot);
        $positionContexts = [];
        foreach ($order->positions->values() as $index => $position) {
            $positionContexts[] = [
                'index' => $index,
                'position' => $position,
                'values' => $this->positionValuesForValidation($position, $snapshot),
            ];
        }
        $this->validateRules($snapshot, $headerValues, $positionContexts);
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
        foreach ($this->positionDefinitions($snapshot, $position) as $def) {
            $values[$def->key] = $this->readPositionValue($position, $def);
        }

        return $values;
    }

    private function readHeaderValue(DispoOrder $order, SnapshotFieldDefinition $def): mixed
    {
        $row = $order->fieldValues->firstWhere('snapshot_field_definition_id', $def->id);
        if ($row === null) {
            return $def->field_type->isChoice()
                ? ChoiceFieldValueContract::emptyValue($def->field_type)
                : null;
        }

        if ($def->field_type->isChoice()) {
            return ChoiceFieldValueContract::readStored($def, $row);
        }

        ChoiceFieldValueContract::assertChoiceChannelExclusive($def, $row);

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
            return $def->field_type->isChoice()
                ? ChoiceFieldValueContract::emptyValue($def->field_type)
                : null;
        }

        if ($def->field_type->isChoice()) {
            return ChoiceFieldValueContract::readStored($def, $row);
        }

        ChoiceFieldValueContract::assertChoiceChannelExclusive($def, $row);

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
            FieldType::Select, FieldType::MultiSelect => throw new RuntimeException(
                'Unerreichbarer Choice-Zweig in readPositionValue.',
            ),
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
        $def = $this->positionDefinition($snapshot, $position, $key);
        if ($def === null) {
            return false;
        }
        $position->loadMissing('fieldValues');

        return $position->fieldValues->contains('snapshot_field_definition_id', $def->id);
    }

    private function positionDefinition(
        ConfigurationSnapshot $snapshot,
        DispoOrderPosition $position,
        string $key,
    ): ?SnapshotFieldDefinition {
        return $this->positionSnapshot($snapshot, $position)->fieldDefinitions->firstWhere('key', $key);
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
                $periodOpenDef = $this->positionDefinition($snapshot, $position, 'period_open');
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
     * @return array<string, mixed>
     */
    private function textValuesForAudit(DispoOrder $order, ConfigurationSnapshot $snapshot): array
    {
        $order->loadMissing('fieldValues.snapshotFieldDefinition');
        $out = [];
        foreach ($snapshot->fieldDefinitions->where('scope', FieldScope::Header) as $def) {
            if (! $this->isTextOrChoiceFieldType($def->field_type)) {
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
                $def = $this->positionDefinition($snapshot, $position, $key);
                $values[$key] = $def === null ? null : $this->readPositionValue($position, $def);
            }
            $positions[(int) $position->id] = $values;
        }

        return ['header' => $header, 'positions' => $positions];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function positionCustomValuesForAudit(DispoOrder $order, ConfigurationSnapshot $snapshot): array
    {
        $order->loadMissing('positions.fieldValues.snapshotFieldDefinition');
        $out = [];
        foreach ($order->positions as $position) {
            $positionSnapshot = $this->positionSnapshot($snapshot, $position);
            $values = [];
            foreach ($this->positionDefinitions($snapshot, $position) as $def) {
                if (! $this->isTextOrChoiceFieldType($def->field_type)) {
                    continue;
                }
                if (! $this->isNativeEditablePositionText($positionSnapshot, $def)
                    && ! $this->isCalcOriginKey($positionSnapshot, $def->key)) {
                    continue;
                }
                $raw = $this->readPositionValue($position, $def);
                if ($def->field_type->isChoice()) {
                    $values[$def->key] = $raw;
                } else {
                    $values[$def->key] = is_string($raw) || $raw === null ? $raw : (string) $raw;
                }
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
