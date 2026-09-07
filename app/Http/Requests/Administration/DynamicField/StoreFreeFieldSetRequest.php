<?php

namespace App\Http\Requests\Administration\DynamicField;

use App\Enums\FieldAppliesTo;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * DF-3.3-fs / DYN-002 / DYN-003
 */
class StoreFreeFieldSetRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'key' => ['nullable', 'string', 'max:64'],
            'applies_to' => ['required', 'string', Rule::enum(FieldAppliesTo::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'Name',
            'key' => 'Schlüssel',
            'applies_to' => 'Gültigkeit',
        ];
    }

    /**
     * @return array{name: string, key: string|null, applies_to: string}
     */
    public function payload(): array
    {
        /** @var array{name: string, key?: string|null, applies_to: string} $validated */
        $validated = $this->validated();

        return [
            'name' => $validated['name'],
            'key' => $validated['key'] ?? null,
            'applies_to' => $validated['applies_to'],
        ];
    }
}
