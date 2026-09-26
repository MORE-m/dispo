<?php

namespace App\Support\DynamicField;

use App\Models\CalculationFieldValue;
use App\Models\CalculationPositionFieldValue;
use App\Models\DispoOrderFieldValue;
use App\Models\DispoOrderPositionFieldValue;
use App\Models\SnapshotFieldDefinition;
use RuntimeException;

/**
 * BL-P9-01c: kanonischer Vertrag für Datei-Dynamikfelder (optional, dispo_order only).
 *
 * Speicherung in value_json als {"upload_id": int} oder leer (null).
 */
final class FileFieldValueContract
{
    public static function emptyValue(): null
    {
        return null;
    }

    public static function isEmpty(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }

        if (! is_array($value)) {
            return true;
        }

        $uploadId = $value['upload_id'] ?? null;
        if (! is_int($uploadId) && ! (is_string($uploadId) && ctype_digit($uploadId))) {
            return true;
        }

        return (int) $uploadId <= 0;
    }

    public static function readUploadId(
        CalculationFieldValue|CalculationPositionFieldValue|DispoOrderFieldValue|DispoOrderPositionFieldValue $row,
    ): ?int {
        $decoded = $row->value_json;
        if ($decoded === null) {
            return null;
        }

        if (is_string($decoded) && ($row->getAttributes()['value_json'] ?? null) === $decoded) {
            $decoded = self::decodeAttribute($decoded);
        }

        if (! is_array($decoded)) {
            throw new RuntimeException('Datei-Feld value_json ist ungültig.');
        }

        $uploadId = $decoded['upload_id'] ?? null;
        if ($uploadId === null) {
            return null;
        }

        if (! is_int($uploadId) && ! (is_string($uploadId) && ctype_digit((string) $uploadId))) {
            throw new RuntimeException('Datei-Feld value_json enthält keine gültige upload_id.');
        }

        $id = (int) $uploadId;

        return $id > 0 ? $id : null;
    }

    /**
     * @return array{upload_id: int}|null
     */
    public static function readStored(
        SnapshotFieldDefinition $definition,
        CalculationFieldValue|CalculationPositionFieldValue|DispoOrderFieldValue|DispoOrderPositionFieldValue $row,
    ): ?array {
        if (! $definition->field_type->isFile()) {
            throw new RuntimeException('readStored nur für Datei-Felder.');
        }

        self::assertExclusive($definition, $row);

        $uploadId = self::readUploadId($row);
        if ($uploadId === null) {
            return null;
        }

        return ['upload_id' => $uploadId];
    }

    public static function writeStored(
        SnapshotFieldDefinition $definition,
        CalculationFieldValue|CalculationPositionFieldValue|DispoOrderFieldValue|DispoOrderPositionFieldValue $row,
        ?int $uploadId,
    ): void {
        if (! $definition->field_type->isFile()) {
            throw new RuntimeException('writeStored nur für Datei-Felder.');
        }

        ChoiceFieldValueContract::clearScalarChannels($row);

        if ($uploadId === null || $uploadId <= 0) {
            $row->value_json = null;

            return;
        }

        $row->value_json = ['upload_id' => $uploadId];
    }

    public static function assertExclusive(
        SnapshotFieldDefinition $definition,
        CalculationFieldValue|CalculationPositionFieldValue|DispoOrderFieldValue|DispoOrderPositionFieldValue $row,
    ): void {
        if ($definition->field_type->isFile()) {
            if (ChoiceFieldValueContract::hasScalarChannel($row)) {
                throw new RuntimeException(
                    "Datei-Feld „{$definition->key}“ hat belegte skalare Wertkanäle.",
                );
            }

            return;
        }

        $jsonRaw = $row->getAttributes()['value_json'] ?? null;
        if ($jsonRaw !== null) {
            $decoded = is_string($jsonRaw) ? self::decodeAttribute($jsonRaw) : $jsonRaw;
            if (is_array($decoded) && array_key_exists('upload_id', $decoded)) {
                throw new RuntimeException(
                    "Nicht-Datei-Feld „{$definition->key}“ hat belegten Datei-value_json-Kanal.",
                );
            }
        }
    }

    private static function decodeAttribute(mixed $raw): mixed
    {
        if (is_string($raw)) {
            try {
                return json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $exception) {
                throw new RuntimeException('value_json ist kein gültiges JSON.', 0, $exception);
            }
        }

        return $raw;
    }
}
