<?php

namespace App\Http\Requests\Administration\DynamicField;

use Illuminate\Foundation\Http\FormRequest;

/**
 * DF-3.1 / DYN-003 / VER-005
 */
class CreateFieldSetDraftRequest extends FormRequest
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
            'source_version_id' => ['required', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'lock_version' => 'Version',
            'source_version_id' => 'Quellversion',
        ];
    }

    public function lockVersion(): int
    {
        return (int) $this->validated('lock_version');
    }

    public function sourceVersionId(): int
    {
        return (int) $this->validated('source_version_id');
    }
}
