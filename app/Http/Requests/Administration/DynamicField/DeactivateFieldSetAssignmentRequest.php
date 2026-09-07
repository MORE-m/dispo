<?php

namespace App\Http\Requests\Administration\DynamicField;

use Illuminate\Foundation\Http\FormRequest;

/**
 * DF-3.3a1 / DYN-002
 */
class DeactivateFieldSetAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canAccessAdministration() ?? false;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'lock_version' => ['required', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array{lock_version: int}
     */
    public function payload(): array
    {
        return [
            'lock_version' => (int) $this->validated('lock_version'),
        ];
    }
}
