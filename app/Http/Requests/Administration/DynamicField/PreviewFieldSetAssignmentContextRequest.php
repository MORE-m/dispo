<?php

namespace App\Http\Requests\Administration\DynamicField;

use App\Enums\FieldAppliesTo;
use App\Enums\FieldScope;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * DF-3.3a1 / ADM-002
 */
class PreviewFieldSetAssignmentContextRequest extends FormRequest
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
            'process' => ['required', Rule::in([
                FieldAppliesTo::Calculation->value,
                FieldAppliesTo::DispoOrder->value,
            ])],
            'scope' => ['required', Rule::enum(FieldScope::class)],
            'advertising_category_id' => ['nullable', 'integer', 'exists:advertising_categories,id'],
            'advertising_medium_id' => ['nullable', 'integer', 'exists:advertising_media,id'],
            'candidate_assignment_id' => ['nullable', 'integer', 'exists:field_set_assignments,id'],
        ];
    }

    /**
     * @return array{
     *     process: string,
     *     scope: string,
     *     advertising_category_id: int|null,
     *     advertising_medium_id: int|null,
     *     candidate_assignment_id: int|null
     * }
     */
    public function payload(): array
    {
        $validated = $this->validated();

        return [
            'process' => (string) $validated['process'],
            'scope' => (string) $validated['scope'],
            'advertising_category_id' => $validated['advertising_category_id'] ?? null,
            'advertising_medium_id' => $validated['advertising_medium_id'] ?? null,
            'candidate_assignment_id' => $validated['candidate_assignment_id'] ?? null,
        ];
    }
}
