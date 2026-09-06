<?php

namespace App\Http\Requests\DispoOrder;

use App\Enums\FieldScope;
use App\Enums\FieldType;
use App\Models\ConfigurationSnapshot;
use App\Models\DispoOrder;
use App\Models\SnapshotFieldDefinition;
use App\Services\DynamicField\DispoConfigurationSnapshotComposer;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class UpdateDispoOrderPositionCustomsRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var DispoOrder $order */
        $order = $this->route('dispoOrder');

        return $this->user()?->can('update', $order) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'lock_version' => ['required', 'integer', 'min:1'],
            'position_dynamic_field_values' => ['required', 'array'],
            'position_dynamic_field_values.*' => ['array'],
            'position_dynamic_field_values.*.*' => ['nullable', 'string'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $byPosition = $this->input('position_dynamic_field_values');
            if (! is_array($byPosition)) {
                return;
            }

            $order = $this->dispoOrder();
            $snapshot = $order?->configurationSnapshot;
            if ($snapshot === null) {
                return;
            }

            $order->loadMissing('positions');
            $positionsById = $order->positions->keyBy('id');
            $editableByKey = [];
            foreach ($snapshot->fieldDefinitions->where('scope', FieldScope::Position) as $def) {
                if ($this->isNativeEditable($snapshot, $def)) {
                    $editableByKey[$def->key] = $def;
                }
            }
            $calcKeys = $this->calcOriginKeys($snapshot);

            foreach ($byPosition as $positionId => $values) {
                $positionId = (int) $positionId;
                if (! $positionsById->has($positionId)) {
                    $validator->errors()->add(
                        "position_dynamic_field_values.{$positionId}",
                        'Unbekannte Position.',
                    );

                    continue;
                }
                if (! is_array($values)) {
                    continue;
                }

                foreach ($values as $key => $raw) {
                    $key = (string) $key;
                    if (! isset($editableByKey[$key])) {
                        $known = $snapshot->fieldDefinitions->firstWhere('key', $key) !== null;
                        $validator->errors()->add(
                            "position_dynamic_field_values.{$positionId}.{$key}",
                            $known || isset($calcKeys[$key])
                                ? 'Dieses Feld ist im Dispoauftrag nicht bearbeitbar.'
                                : 'Unbekanntes dynamisches Feld.',
                        );

                        continue;
                    }

                    $def = $editableByKey[$key];
                    if ($raw !== null && ! is_string($raw)) {
                        $validator->errors()->add(
                            "position_dynamic_field_values.{$positionId}.{$key}",
                            $def->label.' muss Text sein.',
                        );

                        continue;
                    }

                    $max = $this->maxLengthForDefinition($def);
                    if (is_string($raw) && mb_strlen($raw) > $max) {
                        $validator->errors()->add(
                            "position_dynamic_field_values.{$positionId}.{$key}",
                            $def->label." darf höchstens {$max} Zeichen haben.",
                        );
                    }
                }
            }
        });
    }

    public function expectedLockVersion(): int
    {
        return (int) $this->validated('lock_version');
    }

    /**
     * @return array<int, array<string, string|null>>
     */
    public function positionDynamicFieldValues(): array
    {
        /** @var array<int|string, mixed> $raw */
        $raw = $this->input('position_dynamic_field_values', []);
        $out = [];
        foreach ($raw as $positionId => $values) {
            if (! is_array($values)) {
                continue;
            }
            $filtered = [];
            foreach ($values as $key => $value) {
                $filtered[(string) $key] = is_string($value) || $value === null ? $value : (string) $value;
            }
            $out[(int) $positionId] = $filtered;
        }

        return $out;
    }

    private function dispoOrder(): ?DispoOrder
    {
        /** @var DispoOrder|null $order */
        $order = $this->route('dispoOrder');
        if ($order instanceof DispoOrder) {
            $order->loadMissing('configurationSnapshot.fieldDefinitions', 'positions');
        }

        return $order instanceof DispoOrder ? $order : null;
    }

    private function isNativeEditable(ConfigurationSnapshot $snapshot, SnapshotFieldDefinition $def): bool
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

        return ! isset($this->calcOriginKeys($snapshot)[$def->key]);
    }

    /**
     * @return array<string, true>
     */
    private function calcOriginKeys(ConfigurationSnapshot $snapshot): array
    {
        $keys = array_fill_keys(DispoConfigurationSnapshotComposer::CALC_ORIGIN_KEYS, true);
        $sourceId = $snapshot->source_configuration_snapshot_id;
        if ($sourceId === null) {
            return $keys;
        }

        $calcSnapshot = ConfigurationSnapshot::query()
            ->with('fieldDefinitions')
            ->find($sourceId);
        if ($calcSnapshot === null) {
            return $keys;
        }

        foreach ($calcSnapshot->fieldDefinitions as $def) {
            $keys[$def->key] = true;
        }

        return $keys;
    }

    private function maxLengthForDefinition(SnapshotFieldDefinition $def): int
    {
        $fromJson = is_array($def->validation_json) ? ($def->validation_json['max_length'] ?? null) : null;
        if (is_numeric($fromJson)) {
            return (int) $fromJson;
        }

        return $def->field_type === FieldType::ShortText ? 255 : 20000;
    }
}
