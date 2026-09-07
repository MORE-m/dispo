<?php

namespace App\Http\Requests\Administration\DynamicField;

use App\Enums\FieldAppliesTo;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * DF-3.3-fs / DYN-003 / ADM-001
 */
class UpdateFieldSetMetadataRequest extends FormRequest
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
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'applies_to' => ['sometimes', 'required', 'string', Rule::enum(FieldAppliesTo::class)],
            'key' => ['prohibited'],
            'is_system' => ['prohibited'],
            'is_assignable' => ['prohibited'],
            'active_version_id' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'lock_version' => 'Sperrversion',
            'name' => 'Name',
            'applies_to' => 'Gültigkeit',
            'key' => 'Schlüssel',
            'is_system' => 'Systemstatus',
            'is_assignable' => 'Assignierbarkeit',
            'active_version_id' => 'Aktive Version',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'key.prohibited' => 'Der technische Schlüssel darf nach dem Anlegen nicht geändert werden.',
            'is_system.prohibited' => 'Der Systemstatus darf nicht geändert werden.',
            'is_assignable.prohibited' => 'Die Assignierbarkeit darf nicht über Metadaten gesetzt werden.',
            'active_version_id.prohibited' => 'Die aktive Version darf nicht über Metadaten gesetzt werden.',
        ];
    }

    public function lockVersion(): int
    {
        return (int) $this->validated('lock_version');
    }

    /**
     * @return array{name?: string, applies_to?: string, lock_version: int}
     */
    public function payload(): array
    {
        /** @var array{lock_version: int, name?: string, applies_to?: string} $validated */
        $validated = $this->validated();

        return $validated;
    }
}
