<?php

namespace App\Http\Requests\Administration\DynamicField;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

/**
 * DF-3-RULE-C: Desired-State-Apply für Feldset-Regeln.
 */
class ReplaceFieldSetVersionRulesRequest extends FormRequest
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
            'fingerprint' => ['required', 'string', 'size:64', 'regex:/^[a-f0-9]{64}$/'],
            'rules' => ['present', 'array'],
        ];
    }

    /**
     * @return array{
     *     lock_version: int,
     *     fingerprint: string,
     *     rules: list<array{condition: array<string, mixed>, action: array<string, mixed>}>
     * }
     */
    public function payload(): array
    {
        $validated = $this->validated();

        return [
            'lock_version' => (int) $validated['lock_version'],
            'fingerprint' => (string) $validated['fingerprint'],
            'rules' => $this->strictRulesFromInput(),
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
