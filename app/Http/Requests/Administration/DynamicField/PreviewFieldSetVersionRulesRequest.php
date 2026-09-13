<?php

namespace App\Http\Requests\Administration\DynamicField;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

/**
 * DF-3-RULE-C: Desired-State-Vorschau für Feldset-Regeln.
 */
class PreviewFieldSetVersionRulesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canAccessAdministration() ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'lock_version' => ['required', 'integer', 'min:1'],
            'rules' => ['present', 'array'],
            'example_values' => ['sometimes', 'array'],
            'example_values.header' => ['sometimes', 'array'],
            'example_values.position' => ['sometimes', 'array'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->exists('fingerprint')) {
            throw ValidationException::withMessages([
                'fingerprint' => 'Die Regelvorschau erwartet keinen Fingerprint.',
            ]);
        }
    }

    /**
     * @return array{
     *     lock_version: int,
     *     rules: list<array{condition: array<string, mixed>, action: array<string, mixed>}>,
     *     example_header: array<string, mixed>,
     *     example_position: array<string, mixed>
     * }
     */
    public function payload(): array
    {
        $this->validated();

        $examples = $this->input('example_values');
        $header = is_array($examples) && is_array($examples['header'] ?? null)
            ? $examples['header']
            : [];
        $position = is_array($examples) && is_array($examples['position'] ?? null)
            ? $examples['position']
            : [];

        return [
            'lock_version' => (int) $this->input('lock_version'),
            'rules' => $this->strictRulesFromInput(),
            'example_header' => $header,
            'example_position' => $position,
        ];
    }

    /**
     * @return list<array{condition: array<string, mixed>, action: array<string, mixed>}>
     */
    private function strictRulesFromInput(): array
    {
        $rawRules = $this->input('rules');
        if (! is_array($rawRules)) {
            throw ValidationException::withMessages([
                'rules' => 'Regeln müssen als Liste übergeben werden.',
            ]);
        }

        $rules = [];
        foreach (array_values($rawRules) as $index => $row) {
            if (! is_array($row)) {
                throw ValidationException::withMessages([
                    "rules.{$index}" => 'Jede Regel muss ein Objekt mit condition und action sein.',
                ]);
            }

            if (array_key_exists('sort', $row)) {
                throw ValidationException::withMessages([
                    "rules.{$index}.sort" => 'Sortwerte werden serverseitig vergeben und dürfen nicht gesendet werden.',
                ]);
            }
            if (array_key_exists('is_system', $row) || array_key_exists('is_system_seed', $row)) {
                throw ValidationException::withMessages([
                    "rules.{$index}" => 'Systemregel-Kennzeichnungen werden nur serverseitig vergeben.',
                ]);
            }

            if (! array_key_exists('condition', $row) || ! is_array($row['condition'])) {
                throw ValidationException::withMessages([
                    "rules.{$index}.condition" => 'Die Regelbedingung muss ein Objekt sein.',
                ]);
            }
            if (! array_key_exists('action', $row) || ! is_array($row['action'])) {
                throw ValidationException::withMessages([
                    "rules.{$index}.action" => 'Die Regelaktion muss ein Objekt sein.',
                ]);
            }

            $rules[] = [
                'condition' => $row['condition'],
                'action' => $row['action'],
            ];
        }

        return $rules;
    }
}
