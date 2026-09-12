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
use App\Support\DynamicField\ChoiceFieldValueContract;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Persistiert typisierte Dyn-Werte gegen Snapshot-Definitionen.
 *
 * DF-3.3a2β / VER-003: Ab Generation 3 trägt der Kalkulationssnapshot nur noch
 * Headerfelder; die Positionsfelder stammen je Position aus dem eigenen
 * Effektiv-Snapshot.
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
        $calculation->loadMissing(['fieldValues.snapshotFieldDefinition', 'positions']);

        $headerInput = is_array($payload['dynamic_field_values'] ?? null)
            ? $payload['dynamic_field_values']
            : [];

        $this->rejectUnknownKeys(
            $snapshot,
            FieldScope::Header,
            $headerInput,
            'dynamic_field_values',
        );

        $previousHeader = $this->storedChoiceValuesByKey(
            $snapshot,
            FieldScope::Header,
            $calculation->fieldValues,
        );
        [$headerValues, $headerChoiceDirty] = $this->normalizeScopeValues(
            $snapshot,
            FieldScope::Header,
            $headerInput,
            'dynamic_field_values',
            $previousHeader,
        );

        // Die Position muss vor der Normalisierung feststehen: ab Generation 3
        // bestimmt ihr Effektiv-Snapshot die erlaubten Keys und Regeln.
        $byId = $calculation->positions->keyBy('id');
        $byClient = $calculation->positions->keyBy('client_key');

        $positionContexts = [];
        foreach (array_values($payload['positions'] ?? []) as $index => $positionPayload) {
            $position = $this->matchPosition($byId, $byClient, $positionPayload, $index);
            $position->loadMissing('fieldValues.snapshotFieldDefinition');
            $scopeSnapshot = $this->positionScopeSnapshot($position, $snapshot);

            $input = is_array($positionPayload['dynamic_field_values'] ?? null)
                ? $positionPayload['dynamic_field_values']
                : [];
            if (! array_key_exists('period_open', $input)) {
                $input['period_open'] = true;
            }
            $prefix = "positions.{$index}.dynamic_field_values";
            $this->rejectUnknownKeys($scopeSnapshot, FieldScope::Position, $input, $prefix);
            $previousPosition = $this->storedChoiceValuesByKey(
                $scopeSnapshot,
                FieldScope::Position,
                $position->fieldValues,
            );
            [$values, $choiceDirty] = $this->normalizeScopeValues(
                $scopeSnapshot,
                FieldScope::Position,
                $input,
                $prefix,
                $previousPosition,
            );
            $positionContexts[] = [
                'index' => $index,
                'position' => $position,
                'snapshot' => $scopeSnapshot,
                'values' => $values,
                'choice_dirty' => $choiceDirty,
            ];
        }

        $this->validateRules($snapshot, $headerValues, $positionContexts);

        $this->persistHeaderValues($calculation, $snapshot, $headerValues, $headerChoiceDirty);

        foreach ($positionContexts as $context) {
            $this->persistPositionValues(
                $context['position'],
                $context['snapshot'],
                $context['values'],
                $context['choice_dirty'],
            );
        }
    }

    /**
     * Positionsscharfer Bewertungsrahmen: ab Generation 3 der Effektiv-Snapshot
     * der Position, davor der Kalkulationssnapshot selbst.
     */
    public function positionScopeSnapshot(
        CalculationPosition $position,
        ConfigurationSnapshot $snapshot,
    ): ConfigurationSnapshot {
        if ((int) $snapshot->format_version !== ConfigurationSnapshot::FORMAT_VERSION_CONTEXTUAL_FREEZE) {
            return $snapshot;
        }

        $position->loadMissing('effectiveConfigurationSnapshot');
        $effective = $position->effectiveConfigurationSnapshot;

        if ($effective === null) {
            throw new RuntimeException(
                "Kalkulationsposition {$position->id} hat keinen Effektiv-Snapshot.",
            );
        }

        $effective->assertReadable();
        $effective->loadMissing(['fieldDefinitions', 'rules']);

        return $effective;
    }

    /**
     * Headerregeln liegen im Basissnapshot, Positionsregeln je Effektiv-Snapshot.
     *
     * @param  array<string, mixed>  $headerValues
     * @param  list<array{index: int, position: CalculationPosition, snapshot: ConfigurationSnapshot, values: array<string, mixed>}>  $positionContexts
     */
    private function validateRules(
        ConfigurationSnapshot $snapshot,
        array $headerValues,
        array $positionContexts,
    ): void {
        $flattened = array_map(
            static fn (array $context): array => [
                'index' => $context['index'],
                'values' => $context['values'],
            ],
            $positionContexts,
        );

        $this->rules->validate($snapshot, $headerValues, $flattened);

        if ((int) $snapshot->format_version !== ConfigurationSnapshot::FORMAT_VERSION_CONTEXTUAL_FREEZE) {
            return;
        }

        foreach ($positionContexts as $context) {
            $this->rules->validate($context['snapshot'], $headerValues, [[
                'index' => $context['index'],
                'values' => $context['values'],
            ]]);
        }
    }

    /**
     * @param  Collection<int, CalculationPosition>  $byId
     * @param  Collection<string, CalculationPosition>  $byClient
     * @param  array<string, mixed>  $positionPayload
     */
    private function matchPosition($byId, $byClient, array $positionPayload, int $index): CalculationPosition
    {
        $position = null;

        if (isset($positionPayload['id'])) {
            $position = $byId->get((int) $positionPayload['id']);
        }

        $clientKey = isset($positionPayload['client_key']) ? (string) $positionPayload['client_key'] : '';
        if ($position === null && $clientKey !== '') {
            $position = $byClient->get($clientKey);
        }

        if ($position === null) {
            throw ValidationException::withMessages([
                "positions.{$index}" => 'Positionszuordnung für dynamische Felder fehlgeschlagen.',
            ]);
        }

        return $position;
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
        $snapshot = $this->positionScopeSnapshot($position, $snapshot);
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
     * @param  array<string, string|list<string>|null>  $previousChoice
     * @return array{0: array<string, mixed>, 1: array<string, true>}
     */
    private function normalizeScopeValues(
        ConfigurationSnapshot $snapshot,
        FieldScope $scope,
        array $input,
        string $errorPrefix,
        array $previousChoice = [],
    ): array {
        $normalized = [];
        $choiceDirty = [];
        $errors = [];

        foreach ($snapshot->fieldDefinitions->where('scope', $scope) as $def) {
            if ($def->field_type->isChoice()) {
                if (! array_key_exists($def->key, $input)) {
                    $normalized[$def->key] = array_key_exists($def->key, $previousChoice)
                        ? $previousChoice[$def->key]
                        : ChoiceFieldValueContract::emptyValue($def->field_type);

                    continue;
                }

                try {
                    $normalized[$def->key] = ChoiceFieldValueContract::normalizeIncoming(
                        $def->field_type,
                        is_array($def->options_json) ? $def->options_json : null,
                        $input[$def->key],
                        $previousChoice[$def->key] ?? null,
                        "{$errorPrefix}.{$def->key}",
                        $def->label,
                    );
                    $choiceDirty[$def->key] = true;
                } catch (ValidationException $exception) {
                    foreach ($exception->errors() as $key => $messages) {
                        $errors[$key] = $messages[0] ?? ($def->label.' ist ungültig.');
                    }
                }

                continue;
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
                FieldType::ShortText, FieldType::LongText => $this->normalizeText($raw, $def, $errorPrefix, $errors),
                FieldType::Select, FieldType::MultiSelect => null,
            };
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return [$normalized, $choiceDirty];
    }

    /**
     * @param  iterable<int, CalculationFieldValue|CalculationPositionFieldValue>  $rows
     * @return array<string, string|list<string>|null>
     */
    private function storedChoiceValuesByKey(
        ConfigurationSnapshot $snapshot,
        FieldScope $scope,
        iterable $rows,
    ): array {
        $byDefinitionId = [];
        foreach ($rows as $row) {
            $byDefinitionId[(int) $row->snapshot_field_definition_id] = $row;
        }

        $out = [];
        foreach ($snapshot->fieldDefinitions->where('scope', $scope) as $def) {
            if (! $def->field_type->isChoice()) {
                continue;
            }
            $row = $byDefinitionId[(int) $def->id] ?? null;
            if ($row === null) {
                $out[$def->key] = ChoiceFieldValueContract::emptyValue($def->field_type);

                continue;
            }
            $out[$def->key] = ChoiceFieldValueContract::readStored($def, $row);
        }

        return $out;
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
     * @param  array<string, true>  $choiceDirty
     */
    private function persistHeaderValues(
        Calculation $calculation,
        ConfigurationSnapshot $snapshot,
        array $values,
        array $choiceDirty = [],
    ): void {
        foreach ($snapshot->fieldDefinitions->where('scope', FieldScope::Header) as $def) {
            if ($def->field_type->isChoice() && ! isset($choiceDirty[$def->key])) {
                continue;
            }
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
     * @param  array<string, true>  $choiceDirty
     */
    private function persistPositionValues(
        CalculationPosition $position,
        ConfigurationSnapshot $snapshot,
        array $values,
        array $choiceDirty = [],
    ): void {
        foreach ($snapshot->fieldDefinitions->where('scope', FieldScope::Position) as $def) {
            if ($def->field_type->isChoice() && ! isset($choiceDirty[$def->key])) {
                continue;
            }
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
        if ($def->field_type->isChoice()) {
            ChoiceFieldValueContract::writeStored(
                $def,
                $row,
                ChoiceFieldValueContract::assertNormalizedStoredValue($def->field_type, $value),
            );

            return;
        }

        ChoiceFieldValueContract::clearChoiceChannel($row);
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
            FieldType::Select, FieldType::MultiSelect => throw new RuntimeException(
                'Unerreichbarer Choice-Zweig in fillRow.',
            ),
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
            FieldType::Select, FieldType::MultiSelect => ChoiceFieldValueContract::emptyValue($def->field_type),
        };
    }

    private function exportValue(
        SnapshotFieldDefinition $def,
        CalculationFieldValue|CalculationPositionFieldValue $value,
    ): mixed {
        if ($def->field_type->isChoice()) {
            return ChoiceFieldValueContract::readStored($def, $value);
        }

        ChoiceFieldValueContract::assertChoiceChannelExclusive($def, $value);

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
            FieldType::Select, FieldType::MultiSelect => throw new RuntimeException(
                'Unerreichbarer Choice-Zweig in exportValue.',
            ),
        };
    }
}
