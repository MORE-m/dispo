<?php

namespace App\Http\Requests\Administration\DynamicField;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

/**
 * DF-3-REST-B: Options-Apply (Desired State + Fingerprint).
 */
class ReplaceFieldDefinitionOptionsRequest extends FormRequest
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
            'options' => ['present', 'array'],
        ];
    }

    /**
     * @return array{
     *     lock_version: int,
     *     fingerprint: string,
     *     options: list<array{key: string, label: string, sort: int, is_active: bool}>
     * }
     */
    public function payload(): array
    {
        $validated = $this->validated();

        return [
            'lock_version' => (int) $validated['lock_version'],
            'fingerprint' => (string) $validated['fingerprint'],
            'options' => $this->strictOptionsFromInput(),
        ];
    }

    /**
     * @return list<array{key: string, label: string, sort: int, is_active: bool}>
     */
    private function strictOptionsFromInput(): array
    {
        $rawOptions = $this->input('options');
        if (! is_array($rawOptions)) {
            throw ValidationException::withMessages([
                'options' => 'Optionen müssen als Liste übergeben werden.',
            ]);
        }

        $options = [];
        foreach (array_values($rawOptions) as $index => $row) {
            if (! is_array($row)) {
                throw ValidationException::withMessages([
                    "options.{$index}" => 'Jede Option muss ein Objekt mit key, label und sort sein.',
                ]);
            }

            if (! array_key_exists('key', $row) || ! is_string($row['key'])) {
                throw ValidationException::withMessages([
                    "options.{$index}.key" => 'Der Optionsschlüssel muss ein Text sein.',
                ]);
            }
            if (! array_key_exists('label', $row) || ! is_string($row['label'])) {
                throw ValidationException::withMessages([
                    "options.{$index}.label" => 'Das Optionslabel muss ein Text sein.',
                ]);
            }
            if (! array_key_exists('sort', $row) || ! is_int($row['sort'])) {
                throw ValidationException::withMessages([
                    "options.{$index}.sort" => 'Die Optionssortierung muss ein ganzer Zahlenwert (Integer) sein.',
                ]);
            }
            if (! array_key_exists('is_active', $row) || ! is_bool($row['is_active'])) {
                throw ValidationException::withMessages([
                    "options.{$index}.is_active" => 'is_active muss true oder false sein.',
                ]);
            }

            $options[] = [
                'key' => $row['key'],
                'label' => $row['label'],
                'sort' => $row['sort'],
                'is_active' => $row['is_active'],
            ];
        }

        return $options;
    }
}
