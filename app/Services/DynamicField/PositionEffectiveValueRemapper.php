<?php

namespace App\Services\DynamicField;

use App\Enums\FieldType;
use App\Models\CalculationPosition;
use App\Models\CalculationPositionFieldValue;
use App\Models\ConfigurationSnapshot;
use App\Models\DispoOrderPosition;
use App\Models\DispoOrderPositionFieldValue;
use App\Models\SnapshotFieldDefinition;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * DF-3.3a2β / VER-003: überträgt Positionswerte von einem Effektiv-Snapshot auf
 * den Nachfolger, wenn eine Position das Werbemittel wechselt.
 *
 * Zuordnung erfolgt ausschließlich über `field_definition_id`; der technische
 * Key ist bewusst kein Matchkriterium. Alles, was fachlich nicht verlustfrei
 * übernommen werden kann, blockiert fail-closed mit 422.
 */
final class PositionEffectiveValueRemapper
{
    public function remapCalculationPosition(
        CalculationPosition $position,
        ConfigurationSnapshot $from,
        ConfigurationSnapshot $to,
    ): void {
        $rows = array_values(CalculationPositionFieldValue::query()
            ->where('calculation_position_id', $position->id)
            ->orderBy('id')
            ->get()
            ->all());

        $this->remap($rows, $from, $to, "positions.{$position->id}.dynamic_field_values");
    }

    public function remapDispoOrderPosition(
        DispoOrderPosition $position,
        ConfigurationSnapshot $from,
        ConfigurationSnapshot $to,
    ): void {
        $rows = array_values(DispoOrderPositionFieldValue::query()
            ->where('dispo_order_position_id', $position->id)
            ->orderBy('id')
            ->get()
            ->all());

        $this->remap($rows, $from, $to, "position_dynamic_field_values.{$position->id}");
    }

    /**
     * @param  list<CalculationPositionFieldValue|DispoOrderPositionFieldValue>  $rows
     */
    private function remap(
        array $rows,
        ConfigurationSnapshot $from,
        ConfigurationSnapshot $to,
        string $errorPrefix,
    ): void {
        $from->loadMissing('fieldDefinitions');
        $to->loadMissing('fieldDefinitions');

        $previousById = $from->fieldDefinitions->keyBy('id');
        $nextByDefinitionId = $to->fieldDefinitions->keyBy('field_definition_id');
        $nextByKey = $to->fieldDefinitions->keyBy('key');

        /** @var array<string, string> $errors */
        $errors = [];
        /** @var list<array{0: CalculationPositionFieldValue|DispoOrderPositionFieldValue, 1: int}> $updates */
        $updates = [];
        /** @var list<CalculationPositionFieldValue|DispoOrderPositionFieldValue> $deletions */
        $deletions = [];

        foreach ($rows as $row) {
            /** @var SnapshotFieldDefinition|null $previous */
            $previous = $previousById->get((int) $row->snapshot_field_definition_id);
            if ($previous === null) {
                throw new RuntimeException(
                    "Positionswert {$row->id} gehört nicht zum bisherigen Effektiv-Snapshot {$from->id}.",
                );
            }

            /** @var SnapshotFieldDefinition|null $next */
            $next = $nextByDefinitionId->get((int) $previous->field_definition_id);
            $hasValue = ! $this->isEmpty($previous, $row);
            $errorKey = "{$errorPrefix}.{$previous->key}";

            if ($next === null) {
                if (! $hasValue) {
                    $deletions[] = $row;

                    continue;
                }

                $errors[$errorKey] = $nextByKey->get($previous->key) !== null
                    ? "„{$previous->label}“ verweist im neuen Werbemittelkontext auf eine andere Felddefinition; der erfasste Wert kann nicht übernommen werden."
                    : "„{$previous->label}“ entfällt im neuen Werbemittelkontext, enthält aber einen erfassten Wert.";

                continue;
            }

            if ($next->key !== $previous->key) {
                $errors[$errorKey] = "„{$previous->label}“ trägt im neuen Werbemittelkontext einen anderen technischen Key.";

                continue;
            }

            if ($hasValue && $next->field_type !== $previous->field_type) {
                $errors[$errorKey] = "„{$previous->label}“ hat im neuen Werbemittelkontext einen anderen Feldtyp; der erfasste Wert kann nicht übernommen werden.";

                continue;
            }

            if ($hasValue
                && (int) $next->field_definition_revision_id !== (int) $previous->field_definition_revision_id
            ) {
                $violation = $this->validationViolation($next, $row);
                if ($violation !== null) {
                    $errors[$errorKey] = $violation;

                    continue;
                }
            }

            // Unsichtbare Felder behalten ihren Wert; Sichtbarkeit ist kein Matchkriterium.
            $updates[] = [$row, (int) $next->id];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        foreach ($deletions as $row) {
            $row->delete();
        }

        foreach ($updates as [$row, $definitionId]) {
            if ((int) $row->snapshot_field_definition_id === $definitionId) {
                continue;
            }
            $row->snapshot_field_definition_id = $definitionId;
            $row->save();
        }
    }

    private function isEmpty(
        SnapshotFieldDefinition $definition,
        CalculationPositionFieldValue|DispoOrderPositionFieldValue $row,
    ): bool {
        return match ($definition->field_type) {
            FieldType::Boolean => $row->value_boolean === null,
            FieldType::Period => $row->value_period_start === null && $row->value_period_end === null,
            FieldType::ShortText => $this->isBlank($row->value_string),
            FieldType::LongText => $this->isBlank($row->value_text),
        };
    }

    private function isBlank(mixed $value): bool
    {
        return $value === null || trim((string) $value) === '';
    }

    /**
     * Revisionswechsel ist nur zulässig, wenn der erfasste Wert die neue
     * Validierung weiterhin erfüllt.
     */
    private function validationViolation(
        SnapshotFieldDefinition $definition,
        CalculationPositionFieldValue|DispoOrderPositionFieldValue $row,
    ): ?string {
        $validation = is_array($definition->validation_json) ? $definition->validation_json : [];
        $maxLength = $validation['max_length'] ?? null;

        if (! is_numeric($maxLength)) {
            return null;
        }

        $text = match ($definition->field_type) {
            FieldType::ShortText => $row->value_string,
            FieldType::LongText => $row->value_text,
            default => null,
        };

        if ($text === null || mb_strlen((string) $text) <= (int) $maxLength) {
            return null;
        }

        return "„{$definition->label}“ überschreitet im neuen Werbemittelkontext die zulässige Länge von "
            .((int) $maxLength).' Zeichen.';
    }
}
