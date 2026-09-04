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
    /** @var list<string> */
    private const SUPPORTED_TYPES = ['boolean', 'period', 'short_text', 'long_text'];

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
                'values' => $positionValues,
            ];
        }

        $this->rules->validate($snapshot, $headerValues, $positionContexts);

        $this->persistHeaderValues($calculation, $snapshot, $headerValues);

        $calculation->loadMissing('positions');
        $positions = $calculation->positions->values();
        foreach ($positionContexts as $context) {
            $position = $positions->get($context['index']);
            if ($position === null) {
                continue;
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
            $typeValue = $def->field_type instanceof FieldType
                ? $def->field_type->value
                : (string) $def->field_type;

            if (! in_array($typeValue, self::SUPPORTED_TYPES, true)) {
                throw new \RuntimeException(
                    "Snapshot enthält nicht unterstützten Feldtyp „{$typeValue}“ ({$def->key}).",
                );
            }

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
                FieldType::ShortText, FieldType::LongText => $raw === null || $raw === '' ? null : (string) $raw,
                default => throw new \RuntimeException(
                    "Snapshot enthält nicht unterstützten Feldtyp „{$typeValue}“ ({$def->key}).",
                ),
            };
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $normalized;
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
            default => null,
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
        $row->value_period_start = $start ? Carbon::parse((string) $start)->toDateString() : null;
        $row->value_period_end = $end ? Carbon::parse((string) $end)->toDateString() : null;
    }

    private function emptyValue(SnapshotFieldDefinition $def): mixed
    {
        return match ($def->field_type) {
            FieldType::Boolean => $def->key === 'period_open' ? true : null,
            FieldType::Period => null,
            default => null,
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
            default => null,
        };
    }
}
