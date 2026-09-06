<?php

namespace App\Http\Requests\Administration\DynamicField;

use Illuminate\Foundation\Http\FormRequest;

class RemoveFieldSetMembershipRequest extends FormRequest
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

    public function lockVersion(): int
    {
        return (int) $this->validated('lock_version');
    }
}
