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
