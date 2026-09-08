<?php

namespace App\Services\DynamicField;

use App\Enums\FieldScope;
use App\Enums\FieldType;
use App\Models\Calculation;
use App\Models\CalculationFieldValue;
use App\Models\CalculationPosition;
use App\Models\CalculationPositionFieldValue;
use App\Models\ConfigurationSnapshot;
use App\Models\SnapshotFieldDefinition;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Persistiert typisierte Dyn-Werte gegen Snapshot-Definitionen.
 */
final class CalculationDynamicFieldWriter
{
    public function __construct(
        private readonly SnapshotFieldRuleEvaluator $rules,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function syncFromPayload(Calculation $calculation, array $payload): void
    {
        $snapshot = $calculation->configurationSnapshot;
        if ($snapshot === null) {
            throw ValidationException::withMessages([
                'configuration_snapshot_id' => 'Konfigurationssnapshot fehlt.',
            ]);
        }

        $snapshot->assertReadable();
        $snapshot->loadMissing(['fieldDefinitions', 'rules']);
        $headerInput = is_array($payload['dynamic_field_values'] ?? null)
            ? $payload['dynamic_field_values']
            : [];

        $this->rejectUnknownKeys(
            $snapshot,
            FieldScope::Header,
            $headerInput,
            'dynamic_field_values',
        );
        $headerValues = $this->normalizeScopeValues(
            $snapshot,
            FieldScope::Header,
            $headerInput,
            'dynamic_field_values',
        );

        $positionContexts = [];
        foreach (array_values($payload['positions'] ?? []) as $index => $positionPayload) {
            $input = is_array($positionPayload['dynamic_field_values'] ?? null)
                ? $positionPayload['dynamic_field_values']
                : [];
            if (! array_key_exists('period_open', $input)) {
                $input['period_open'] = true;
            }
            $prefix = "positions.{$index}.dynamic_field_values";
            $this->rejectUnknownKeys($snapshot, FieldScope::Position, $input, $prefix);
            $positionValues = $this->normalizeScopeValues($snapshot, FieldScope::Position, $input, $prefix);
            $positionContexts[] = [
                'index' => $index,
                'id' => isset($positionPayload['id']) ? (int) $positionPayload['id'] : null,
                'client_key' => isset($positionPayload['client_key'])
                    ? (string) $positionPayload['client_key']
                    : null,
                'values' => $positionValues,
            ];
        }

        $this->rules->validate($snapshot, $headerValues, $positionContexts);

        $this->persistHeaderValues($calculation, $snapshot, $headerValues);

        $calculation->loadMissing('positions');
        $byId = $calculation->positions->keyBy('id');
        $byClient = $calculation->positions->keyBy('client_key');
        foreach ($positionContexts as $context) {
            $position = null;
            if ($context['id'] !== null) {
                $position = $byId->get($context['id']);
            }
            if ($position === null && $context['client_key'] !== null && $context['client_key'] !== '') {
                $position = $byClient->get($context['client_key']);
            }
            if ($position === null) {
                throw ValidationException::withMessages([
                    "positions.{$context['index']}" => 'Positionszuordnung für dynamische Felder fehlgeschlagen.',
                ]);
            }
            $this->persistPositionValues($position, $snapshot, $context['values']);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function headerValuesForPayload(Calculation $calculation): array
    {
        $calculation->loadMissing(['configurationSnapshot.fieldDefinitions', 'fieldValues.snapshotFieldDefinition']);
        $snapshot = $calculation->configurationSnapshot;
        if ($snapshot === null) {
            return [];
        }

        $byKey = [];
        foreach ($snapshot->fieldDefinitions->where('scope', FieldScope::Header) as $def) {
            $byKey[$def->key] = $this->emptyValue($def);
        }

        foreach ($calculation->fieldValues as $value) {
            $def = $value->snapshotFieldDefinition;
            if ($def === null || $def->scope !== FieldScope::Header) {
                continue;
            }
            $byKey[$def->key] = $this->exportValue($def, $value);
        }

        return $byKey;
    }

    /**
     * @return array<string, mixed>
     */
    public function positionValuesForPayload(CalculationPosition $position, ConfigurationSnapshot $snapshot): array
    {
        $position->loadMissing('fieldValues.snapshotFieldDefinition');
        $byKey = [];
        foreach ($snapshot->fieldDefinitions->where('scope', FieldScope::Position) as $def) {
            $byKey[$def->key] = $def->key === 'period_open' ? true : $this->emptyValue($def);
        }

        foreach ($position->fieldValues as $value) {
            $def = $value->snapshotFieldDefinition;
            if ($def === null || $def->scope !== FieldScope::Position) {
                continue;
            }
            $byKey[$def->key] = $this->exportValue($def, $value);
        }

        if (! array_key_exists('period_open', $byKey) || $byKey['period_open'] === null) {
            $byKey['period_open'] = true;
        }

        return $byKey;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function rejectUnknownKeys(
        ConfigurationSnapshot $snapshot,
        FieldScope $scope,
        array $input,
        string $errorPrefix,
    ): void {
        $allowed = $snapshot->fieldDefinitions
            ->where('scope', $scope)
            ->pluck('key')
            ->all();
        $allowedLookup = array_fill_keys($allowed, true);
        $errors = [];

        foreach (array_keys($input) as $key) {
            $key = (string) $key;
            if (! isset($allowedLookup[$key])) {
                $foreign = $snapshot->fieldDefinitions->firstWhere('key', $key);
                if ($foreign !== null) {
                    $errors["{$errorPrefix}.{$key}"] = 'Dieses Feld gehört nicht in diesen Bereich.';
                } else {
                    $errors["{$errorPrefix}.{$key}"] = 'Unbekanntes dynamisches Feld.';
                }
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function normalizeScopeValues(
        ConfigurationSnapshot $snapshot,
        FieldScope $scope,
        array $input,
        string $errorPrefix,
    ): array {
        $normalized = [];
        $errors = [];

        foreach ($snapshot->fieldDefinitions->where('scope', $scope) as $def) {
            $raw = $input[$def->key] ?? null;

            if ($def->field_type === FieldType::Period) {
                $periodError = $this->periodRawError($raw);
                if ($periodError !== null) {
                    $errors["{$errorPrefix}.{$def->key}"] = $periodError;

                    continue;
                }
            }

            $normalized[$def->key] = match ($def->field_type) {
                FieldType::Boolean => $this->normalizeBoolean($raw, $def->key === 'period_open'),
                FieldType::Period => $this->normalizePeriod($raw),
                FieldType::ShortText, FieldType::LongText => $this->normalizeText($raw, $def, $errorPrefix, $errors),
            };
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $normalized;
    }

    /**
     * @param  array<string, string>  $errors
     */
    private function normalizeText(
        mixed $raw,
        SnapshotFieldDefinition $def,
        string $errorPrefix,
        array &$errors,
    ): ?string {
        if ($raw === null || $raw === '') {
            return null;
        }
        if (! is_string($raw) && ! is_numeric($raw)) {
            $errors["{$errorPrefix}.{$def->key}"] = $def->label.' muss Text sein.';

            return null;
        }

        $value = trim((string) $raw);
        if ($value === '') {
            return null;
        }

        $maxLength = $this->maxLengthForDefinition($def);
        if (mb_strlen($value) > $maxLength) {
            $errors["{$errorPrefix}.{$def->key}"] = $def->label." darf höchstens {$maxLength} Zeichen haben.";

            return null;
        }

        return $value;
    }

    private function maxLengthForDefinition(SnapshotFieldDefinition $def): int
    {
        $fromJson = is_array($def->validation_json) ? ($def->validation_json['max_length'] ?? null) : null;
        if (is_numeric($fromJson)) {
            return (int) $fromJson;
        }

        return $def->field_type === FieldType::ShortText ? 255 : 20000;
    }

    private function periodRawError(mixed $raw): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        if (! is_array($raw)) {
            return 'Zeitraum muss Start und Ende enthalten.';
        }

        $start = $raw['start'] ?? $raw['period_start'] ?? null;
        $end = $raw['end'] ?? $raw['period_end'] ?? null;
        $start = $start === '' ? null : $start;
        $end = $end === '' ? null : $end;

        if ($start === null && $end === null) {
            return null;
        }

        if ($start === null || $end === null) {
            return 'Zeitraum muss vollständig mit Beginn und Ende angegeben werden.';
        }

        try {
            $startDate = Carbon::parse((string) $start)->startOfDay();
            $endDate = Carbon::parse((string) $end)->startOfDay();
        } catch (\Throwable) {
            return 'Zeitraum enthält ungültige Datumsangaben.';
        }

        if ($startDate->greaterThan($endDate)) {
            return 'Der Zeitraumbeginn darf nicht nach dem Ende liegen.';
        }

        return null;
    }

    private function normalizeBoolean(mixed $raw, bool $defaultTrue): bool
    {
        if ($raw === null) {
            return $defaultTrue;
        }

        if (is_bool($raw)) {
            return $raw;
        }

        if ($raw === 0 || $raw === '0' || $raw === 'false') {
            return false;
        }

        if ($raw === 1 || $raw === '1' || $raw === 'true') {
            return true;
        }

        return $defaultTrue;
    }

    /**
     * @return array{start: string, end: string}|null
     */
    private function normalizePeriod(mixed $raw): ?array
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        if (! is_array($raw)) {
            return null;
        }

        $start = $raw['start'] ?? $raw['period_start'] ?? null;
        $end = $raw['end'] ?? $raw['period_end'] ?? null;
        $start = $start === '' ? null : $start;
        $end = $end === '' ? null : $end;

        if ($start === null && $end === null) {
            return null;
        }

        return [
            'start' => (string) $start,
            'end' => (string) $end,
        ];
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function persistHeaderValues(
        Calculation $calculation,
        ConfigurationSnapshot $snapshot,
        array $values,
    ): void {
        foreach ($snapshot->fieldDefinitions->where('scope', FieldScope::Header) as $def) {
            $row = CalculationFieldValue::query()->firstOrNew([
                'calculation_id' => $calculation->id,
                'snapshot_field_definition_id' => $def->id,
            ]);
            $this->fillRow($row, $def, $values[$def->key] ?? null);
            $row->save();
        }
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function persistPositionValues(
        CalculationPosition $position,
        ConfigurationSnapshot $snapshot,
        array $values,
    ): void {
        foreach ($snapshot->fieldDefinitions->where('scope', FieldScope::Position) as $def) {
            $row = CalculationPositionFieldValue::query()->firstOrNew([
                'calculation_position_id' => $position->id,
                'snapshot_field_definition_id' => $def->id,
            ]);
            $this->fillRow($row, $def, $values[$def->key] ?? null);
            $row->save();
        }
    }

    private function fillRow(
        CalculationFieldValue|CalculationPositionFieldValue $row,
        SnapshotFieldDefinition $def,
        mixed $value,
    ): void {
        $row->value_string = null;
        $row->value_text = null;
        $row->value_boolean = null;
        $row->value_period_start = null;
        $row->value_period_end = null;

        match ($def->field_type) {
            FieldType::Boolean => $row->value_boolean = is_bool($value) ? $value : null,
            FieldType::Period => $this->fillPeriod($row, is_array($value) ? $value : null),
            FieldType::ShortText => $row->value_string = $value === null ? null : (string) $value,
            FieldType::LongText => $row->value_text = $value === null ? null : (string) $value,
        };
    }

    /**
     * @param  array{start?: string|null, end?: string|null}|null  $period
     */
    private function fillPeriod(
        CalculationFieldValue|CalculationPositionFieldValue $row,
        ?array $period,
    ): void {
        if ($period === null) {
            return;
        }

        $start = $period['start'] ?? null;
        $end = $period['end'] ?? null;
        $row->value_period_start = $start ? Carbon::parse((string) $start) : null;
        $row->value_period_end = $end ? Carbon::parse((string) $end) : null;
    }

    private function emptyValue(SnapshotFieldDefinition $def): mixed
    {
        return match ($def->field_type) {
            FieldType::Boolean => $def->key === 'period_open' ? true : null,
            FieldType::Period => null,
            FieldType::ShortText, FieldType::LongText => null,
        };
    }

    private function exportValue(
        SnapshotFieldDefinition $def,
        CalculationFieldValue|CalculationPositionFieldValue $value,
    ): mixed {
        return match ($def->field_type) {
            FieldType::Boolean => $value->value_boolean,
            FieldType::Period => ($value->value_period_start === null && $value->value_period_end === null)
                ? null
                : [
                    'start' => $value->value_period_start?->toDateString(),
                    'end' => $value->value_period_end?->toDateString(),
                ],
            FieldType::ShortText => $value->value_string,
            FieldType::LongText => $value->value_text,
        };
    }
}
