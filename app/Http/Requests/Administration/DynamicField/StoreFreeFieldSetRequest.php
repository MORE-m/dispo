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
            'is_system' => ['prohibited'],
            'is_assignable' => ['prohibited'],
            'active_version_id' => ['prohibited'],
            'lock_version' => ['prohibited'],
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
            'is_system' => 'Systemstatus',
            'is_assignable' => 'Assignierbarkeit',
            'active_version_id' => 'Aktive Version',
            'lock_version' => 'Sperrversion',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'is_system.prohibited' => 'Der Systemstatus darf beim Anlegen nicht gesetzt werden.',
            'is_assignable.prohibited' => 'Die Assignierbarkeit darf beim Anlegen nicht gesetzt werden.',
            'active_version_id.prohibited' => 'Die aktive Version darf beim Anlegen nicht gesetzt werden.',
            'lock_version.prohibited' => 'Die Sperrversion darf beim Anlegen nicht gesetzt werden.',
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
