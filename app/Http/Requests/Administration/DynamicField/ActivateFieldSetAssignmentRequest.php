<?php

namespace App\Http\Requests\Administration\DynamicField;

use Illuminate\Foundation\Http\FormRequest;

/**
 * DF-3.3a1 / DYN-002
 */
class ActivateFieldSetAssignmentRequest extends FormRequest
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
            'fingerprint' => ['required', 'string', 'size:64'],
        ];
    }

    /**
     * @return array{lock_version: int, fingerprint: string}
     */
    public function payload(): array
    {
        $validated = $this->validated();

        return [
            'lock_version' => (int) $validated['lock_version'],
            'fingerprint' => (string) $validated['fingerprint'],
        ];
    }
}
