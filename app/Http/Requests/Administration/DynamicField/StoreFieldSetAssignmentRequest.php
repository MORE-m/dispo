<?php

namespace App\Http\Requests\Administration\DynamicField;

use App\Enums\FieldAppliesTo;
use App\Enums\FieldSetAssignmentTargetLayer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * DF-3.3a1 / DYN-002
 */
class StoreFieldSetAssignmentRequest extends FormRequest
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
            'id' => ['prohibited'],
            'target_identity' => ['prohibited'],
            'is_active' => ['prohibited'],
            'lock_version' => ['prohibited'],
            'field_set_id' => ['required', 'integer', 'exists:field_sets,id'],
            'target_layer' => ['required', Rule::enum(FieldSetAssignmentTargetLayer::class)],
            'advertising_category_id' => ['nullable', 'integer', 'exists:advertising_categories,id'],
            'advertising_medium_id' => ['nullable', 'integer', 'exists:advertising_media,id'],
            'applies_to_process' => ['required', Rule::enum(FieldAppliesTo::class)],
            'sort' => ['nullable', 'integer', 'min:0'],
        ];
    }

    /**
     * @return array{
     *     field_set_id: int,
     *     target_layer: string,
     *     advertising_category_id: int|null,
     *     advertising_medium_id: int|null,
     *     applies_to_process: string,
     *     sort: int
     * }
     */
    public function payload(): array
    {
        $validated = $this->validated();

        return [
            'field_set_id' => (int) $validated['field_set_id'],
            'target_layer' => (string) $validated['target_layer'],
            'advertising_category_id' => $validated['advertising_category_id'] ?? null,
            'advertising_medium_id' => $validated['advertising_medium_id'] ?? null,
            'applies_to_process' => (string) $validated['applies_to_process'],
            'sort' => (int) ($validated['sort'] ?? 0),
        ];
    }
}
