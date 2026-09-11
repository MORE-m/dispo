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

class UpdateDispoOrderDraftRequest extends FormRequest
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
        $rules = [
            'lock_version' => ['required', 'integer', 'min:1'],
            'dynamic_field_values' => ['required', 'array'],
        ];

        foreach ($this->editableHeaderDefinitions() as $def) {
            if ($def->field_type === FieldType::MultiSelect) {
                $rules["dynamic_field_values.{$def->key}"] = ['nullable', 'array'];
                $rules["dynamic_field_values.{$def->key}.*"] = ['string'];

                continue;
            }

            if ($def->field_type === FieldType::Select) {
                $rules["dynamic_field_values.{$def->key}"] = ['nullable', 'string'];

                continue;
            }

            $max = $this->maxLengthForDefinition($def);
            $rules["dynamic_field_values.{$def->key}"] = ['nullable', 'string', "max:{$max}"];
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $messages = [];
        foreach ($this->editableHeaderDefinitions() as $def) {
            if ($def->field_type->isChoice()) {
                continue;
            }
            $max = $this->maxLengthForDefinition($def);
            $messages["dynamic_field_values.{$def->key}.max"] =
                $def->label." darf höchstens {$max} Zeichen haben.";
        }

        return $messages;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $values = $this->input('dynamic_field_values');
            if (! is_array($values)) {
                return;
            }

            $allowed = [];
            foreach ($this->editableHeaderDefinitions() as $def) {
                $allowed[$def->key] = true;
            }

            $order = $this->dispoOrder();
            $snapshot = $order?->configurationSnapshot;
            if ($snapshot instanceof ConfigurationSnapshot) {
                $snapshot->assertReadable();
            }

            foreach (array_keys($values) as $key) {
                $key = (string) $key;
                if (isset($allowed[$key])) {
                    continue;
                }

                $known = $snapshot?->fieldDefinitions->firstWhere('key', $key) !== null;
                $validator->errors()->add(
                    "dynamic_field_values.{$key}",
                    $known || in_array($key, DispoConfigurationSnapshotComposer::CALC_ORIGIN_KEYS, true)
                        ? 'Dieses Feld ist im Dispoauftrag nicht bearbeitbar.'
                        : 'Unbekanntes dynamisches Feld.',
                );
            }

            foreach ($this->editableHeaderDefinitions() as $def) {
                if (! array_key_exists($def->key, $values)) {
                    continue;
                }
                if (! $def->required || ! $def->visible) {
                    continue;
                }
                $raw = $values[$def->key];
                $isEmpty = $def->field_type === FieldType::MultiSelect
                    ? ($raw === [] || $raw === null)
                    : ($raw === null || $raw === '');
                if ($isEmpty) {
                    $validator->errors()->add(
                        "dynamic_field_values.{$def->key}",
                        $def->label.' ist erforderlich.',
                    );
                }
            }
        });
    }

    public function expectedLockVersion(): int
    {
        return (int) $this->validated('lock_version');
    }

    /**
     * @return array<string, string|list<string>|null>
     */
    public function dynamicFieldValues(): array
    {
        /** @var array<string, mixed> $values */
        $values = $this->input('dynamic_field_values', []);
        $defsByKey = [];
        foreach ($this->editableHeaderDefinitions() as $def) {
            $defsByKey[$def->key] = $def;
        }

        /** @var array<string, string|list<string>|null> $filtered */
        $filtered = [];
        foreach ($values as $key => $value) {
            $key = (string) $key;
            $def = $defsByKey[$key] ?? null;
            if ($def === null) {
                continue;
            }

            if ($def->field_type === FieldType::MultiSelect) {
                if ($value === null) {
                    $filtered[$key] = null;
                } elseif (is_array($value)) {
                    $list = [];
                    foreach ($value as $item) {
                        if (is_string($item)) {
                            $list[] = $item;
                        }
                    }
                    $filtered[$key] = $list;
                }

                continue;
            }

            $filtered[$key] = is_string($value) || $value === null ? $value : (string) $value;
        }

        return $filtered;
    }

    private function dispoOrder(): ?DispoOrder
    {
        /** @var DispoOrder|null $order */
        $order = $this->route('dispoOrder');
        if ($order instanceof DispoOrder) {
            $order->loadMissing('configurationSnapshot.fieldDefinitions');
        }

        return $order instanceof DispoOrder ? $order : null;
    }

    /**
     * @return list<SnapshotFieldDefinition>
     */
    private function editableHeaderDefinitions(): array
    {
        $order = $this->dispoOrder();
        $snapshot = $order?->configurationSnapshot;
        if (! $snapshot instanceof ConfigurationSnapshot) {
            return [];
        }

        $snapshot->assertReadable();

        $calcKeys = $this->calcOriginKeys($snapshot);
        $editable = [];
        foreach ($snapshot->fieldDefinitions->where('scope', FieldScope::Header) as $def) {
            if (! in_array($def->field_type, [
                FieldType::ShortText,
                FieldType::LongText,
                FieldType::Select,
                FieldType::MultiSelect,
            ], true)) {
                continue;
            }
            if (isset($calcKeys[$def->key])) {
                continue;
            }
            $editable[] = $def;
        }

        return $editable;
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
