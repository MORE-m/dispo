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
        private readonly DispoConfigurationSnapshotComposer $composer,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Compose Snapshot und setzt configuration_snapshot_id vor dem ersten Save.
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

        $snapshot = $this->composer->composeFromCalculationSnapshot($calcSnapshot);
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

        $headerFromCalc = $this->calculationFields->headerValuesForPayload($calculation);
        $this->persistHeaderPeriod(
            $order,
            $snapshot,
            'campaign_period',
            $headerFromCalc['campaign_period'] ?? null,
        );

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
            $this->persistPositionValues($dispoPosition, $snapshot, $values);
            $positionContexts[] = [
                'index' => $index,
                'values' => [
                    'period_open' => $values['period_open'] ?? null,
                    'position_flight_period' => $values['position_flight_period'] ?? null,
                ],
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
        if ($order->configuration_snapshot_id === null) {
            $this->assignComposedSnapshot($order, $calculation);
            $order->save();
        }
        $this->persistCopiedValues($order, $calculation, $selectedCalculationPositionIds, $predecessor);
    }

    /**
     * @param  array{billing_special_features?: mixed, disposition_notes?: mixed}  $input
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

            foreach (DispoConfigurationSnapshotComposer::DISPO_TEXT_KEYS as $key) {
                if (! array_key_exists($key, $normalized)) {
                    continue;
                }
                $newValue = $normalized[$key];
                $oldValue = $before[$key] ?? null;
                if ($oldValue === $newValue) {
                    continue;
                }
                $this->upsertTextValue($locked, $snapshot, $key, $newValue);
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
                'configurationSnapshot',
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
                foreach (['period_open', 'position_flight_period'] as $key) {
                    if ($this->hasPositionValue($dispoPosition, $snapshot, $key)) {
                        continue;
                    }
                    $this->persistSinglePositionValue($dispoPosition, $snapshot, $key, $values[$key] ?? null);
                    $changed = true;
                }
            }

            if (! $changed) {
                return $this->reloadOrder($locked);
            }

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

        $missing = $this->missingCalcOriginKeys($order, $snapshot);
        if ($missing !== []) {
            throw ValidationException::withMessages([
                'dynamic_field_values' => 'Dynamische Zeitraumfelder fehlen. Bitte „Dynamische Zeitraumfelder aus Kalkulation übernehmen“ ausführen oder einen neuen Dispoauftrag anlegen.',
            ]);
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
        if ($snapshot === null) {
            return ['fields' => [], 'rules' => []];
        }

        $snapshot->loadMissing(['fieldDefinitions', 'rules']);

        return [
            'fields' => array_values($snapshot->fieldDefinitions->map(fn (SnapshotFieldDefinition $def): array => [
                'key' => $def->key,
                'label' => $def->label,
                'help_text' => $def->help_text,
                'field_type' => $def->field_type->value,
                'scope' => $def->scope->value,
                'sort' => $def->sort,
                'group_key' => $def->group_key,
            ])->all()),
            'rules' => array_values($snapshot->rules->map(fn ($rule): array => [
                'sort' => $rule->sort,
                'condition' => $rule->condition_json,
                'action' => $rule->action_json,
            ])->all()),
        ];
    }

    /**
     * @return array{header: array<string, mixed>, positions: array<int, array<string, mixed>>, missing_calc_origin_keys: list<string>, historically_uncaptured: bool}
     */
    public function valuesProp(DispoOrder $order): array
    {
        $snapshot = $order->configurationSnapshot;
        if ($snapshot === null) {
            return [
                'header' => [],
                'positions' => [],
                'missing_calc_origin_keys' => [],
                'historically_uncaptured' => true,
            ];
        }

        $order->loadMissing([
            'fieldValues.snapshotFieldDefinition',
            'positions.fieldValues.snapshotFieldDefinition',
            'configurationSnapshot.fieldDefinitions',
        ]);

        $header = [];
        foreach ($snapshot->fieldDefinitions->where('scope', FieldScope::Header) as $def) {
            $header[$def->key] = $this->readHeaderValue($order, $def);
        }

        $positions = [];
        foreach ($order->positions as $position) {
            $values = [];
            foreach ($snapshot->fieldDefinitions->where('scope', FieldScope::Position) as $def) {
                $values[$def->key] = $this->readPositionValue($position, $def);
            }
            $positions[(int) $position->id] = $values;
        }

        $missing = $this->missingCalcOriginKeys($order, $snapshot);

        return [
            'header' => $header,
            'positions' => $positions,
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
        $predecessor->loadMissing(['fieldValues.snapshotFieldDefinition', 'configurationSnapshot.fieldDefinitions']);
        $predSnapshot = $predecessor->configurationSnapshot;
        if ($predSnapshot === null) {
            throw new RuntimeException('Vorgänger besitzt keinen Konfigurationssnapshot.');
        }

        foreach (DispoConfigurationSnapshotComposer::DISPO_TEXT_KEYS as $key) {
            $newDef = $snapshot->fieldDefinitions->firstWhere('key', $key);
            if ($newDef === null || $newDef->field_type !== FieldType::LongText) {
                throw new RuntimeException(
                    "Neuer Snapshot fehlt kompatibles Textfeld „{$key}“.",
                );
            }

            $predDef = $predSnapshot->fieldDefinitions->firstWhere('key', $key);
            if ($predDef === null || $predDef->field_type !== FieldType::LongText) {
                throw new RuntimeException(
                    "Vorgänger-Snapshot fehlt kompatibles Textfeld „{$key}“.",
                );
            }

            $predValue = $predecessor->fieldValues
                ->firstWhere('snapshot_field_definition_id', $predDef->id);
            $text = $predValue?->value_text;
            if ($text === null || trim($text) === '') {
                continue;
            }

            $this->upsertTextValue($order, $snapshot, $key, $text);
        }
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, string|null>
     */
    private function normalizeTextInput(ConfigurationSnapshot $snapshot, array $input): array
    {
        $allowed = array_fill_keys(DispoConfigurationSnapshotComposer::DISPO_TEXT_KEYS, true);
        $errors = [];

        foreach (array_keys($input) as $key) {
            $key = (string) $key;
            if (! isset($allowed[$key])) {
                if ($snapshot->fieldDefinitions->firstWhere('key', $key) !== null) {
                    $errors["dynamic_field_values.{$key}"] = 'Dieses Feld ist im Dispoauftrag nicht bearbeitbar.';
                } else {
                    $errors["dynamic_field_values.{$key}"] = 'Unbekanntes dynamisches Feld.';
                }
            }
        }

        $normalized = [];
        foreach (DispoConfigurationSnapshotComposer::DISPO_TEXT_KEYS as $key) {
            if (! array_key_exists($key, $input)) {
                continue;
            }
            $def = $snapshot->fieldDefinitions->firstWhere('key', $key);
            if ($def === null) {
                $errors["dynamic_field_values.{$key}"] = 'Snapshot-Definition fehlt.';

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
            if ($value !== null && mb_strlen($value) > 20000) {
                $errors["dynamic_field_values.{$key}"] =
                    $def->label.' darf höchstens 20000 Zeichen haben.';

                continue;
            }
            $normalized[$key] = $value;
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $normalized;
    }

    private function upsertTextValue(
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
        $row->value_text = $value;
        $row->value_period_start = null;
        $row->value_period_end = null;
        $row->save();
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

        if ($start === null && $end === null) {
            return;
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
    ): void {
        foreach (['period_open', 'position_flight_period'] as $key) {
            $this->persistSinglePositionValue($position, $snapshot, $key, $values[$key] ?? null);
        }
    }

    private function persistSinglePositionValue(
        DispoOrderPosition $position,
        ConfigurationSnapshot $snapshot,
        string $key,
        mixed $raw,
    ): void {
        $def = $snapshot->fieldDefinitions->firstWhere('key', $key);
        if ($def === null) {
            throw new RuntimeException("Snapshot-Definition {$key} fehlt.");
        }

        if ($key === 'period_open') {
            if (! is_bool($raw)) {
                throw ValidationException::withMessages([
                    'dynamic_field_values.period_open' => 'Zeitraum offen muss gesetzt sein.',
                ]);
            }
            $row = DispoOrderPositionFieldValue::query()->firstOrNew([
                'dispo_order_position_id' => $position->id,
                'snapshot_field_definition_id' => $def->id,
            ]);
            $row->value_boolean = $raw;
            $row->value_period_start = null;
            $row->value_period_end = null;
            $row->save();

            return;
        }

        if ($raw === null || $raw === '') {
            return;
        }
        if (! is_array($raw)) {
            throw ValidationException::withMessages([
                "dynamic_field_values.{$key}" => 'Zeitraum muss Start und Ende enthalten.',
            ]);
        }
        $start = $raw['start'] ?? $raw['period_start'] ?? null;
        $end = $raw['end'] ?? $raw['period_end'] ?? null;
        $start = $start === '' ? null : $start;
        $end = $end === '' ? null : $end;
        if ($start === null && $end === null) {
            return;
        }
        if ($start === null || $end === null) {
            throw ValidationException::withMessages([
                "dynamic_field_values.{$key}" => 'Zeitraum muss vollständig mit Beginn und Ende angegeben werden.',
            ]);
        }

        $row = DispoOrderPositionFieldValue::query()->firstOrNew([
            'dispo_order_position_id' => $position->id,
            'snapshot_field_definition_id' => $def->id,
        ]);
        $row->value_boolean = null;
        $row->value_period_start = Carbon::parse((string) $start)->toDateString();
        $row->value_period_end = Carbon::parse((string) $end)->toDateString();
        $row->save();
    }

    private function requireSnapshot(DispoOrder $order): ConfigurationSnapshot
    {
        $order->loadMissing('configurationSnapshot.fieldDefinitions', 'configurationSnapshot.rules');
        $snapshot = $order->configurationSnapshot;
        if ($snapshot === null) {
            throw ValidationException::withMessages([
                'configuration_snapshot_id' => 'Konfigurationssnapshot fehlt.',
            ]);
        }

        return $snapshot;
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

        return $row->value_text;
    }

    private function readPositionValue(DispoOrderPosition $position, SnapshotFieldDefinition $def): mixed
    {
        $row = $position->fieldValues->firstWhere('snapshot_field_definition_id', $def->id);
        if ($row === null) {
            return null;
        }
        if ($def->field_type === FieldType::Boolean) {
            return $row->value_boolean;
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

        return null;
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
        $missing = [];
        $order->loadMissing(['fieldValues', 'positions.fieldValues']);

        if (! $this->hasHeaderValue($order, $snapshot, 'campaign_period')) {
            // campaign_period is optional; absence of row is OK if never set.
            // Only period_open is required for submit readiness of positions.
        }

        foreach ($order->positions as $position) {
            if (! $this->hasPositionValue($position, $snapshot, 'period_open')) {
                $missing[] = 'period_open';
            }
        }

        return array_values(array_unique($missing));
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
        foreach (DispoConfigurationSnapshotComposer::DISPO_TEXT_KEYS as $key) {
            $def = $snapshot->fieldDefinitions->firstWhere('key', $key);
            $out[$key] = $def === null ? null : $this->readHeaderValue($order, $def);
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
