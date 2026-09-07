<?php

namespace App\Http\Requests\Administration\DynamicField;

use Illuminate\Foundation\Http\FormRequest;

/**
 * DF-3.3-fs / DYN-003 / ADM-001
 */
class ReactivateFieldSetRequest extends FormRequest
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
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'lock_version' => 'Sperrversion',
        ];
    }

    public function lockVersion(): int
    {
        return (int) $this->validated('lock_version');
    }
}
