<?php

namespace App\Http\Requests\Administration;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSpecialApproveRightRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage-special-approve-rights') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'can_special_approve' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'can_special_approve' => 'Sonderfreigaberecht',
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('can_special_approve')) {
            $raw = $this->input('can_special_approve');
            if (is_string($raw)) {
                $this->merge([
                    'can_special_approve' => filter_var(
                        $raw,
                        FILTER_VALIDATE_BOOLEAN,
                        FILTER_NULL_ON_FAILURE,
                    ) ?? $raw,
                ]);
            }
        }
    }

    public function canSpecialApprove(): bool
    {
        return (bool) $this->validated('can_special_approve');
    }
}
