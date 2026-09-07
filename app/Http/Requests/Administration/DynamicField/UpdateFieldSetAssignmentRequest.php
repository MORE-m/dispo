<?php

namespace App\Http\Requests\Administration\DynamicField;

use App\Enums\FieldAppliesTo;
use App\Enums\FieldSetAssignmentTargetLayer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * DF-3.3a1 / DYN-002
 */
class UpdateFieldSetAssignmentRequest extends FormRequest
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
            'field_set_id' => ['sometimes', 'integer', 'exists:field_sets,id'],
            'target_layer' => ['sometimes', Rule::enum(FieldSetAssignmentTargetLayer::class)],
            'advertising_category_id' => ['nullable', 'integer', 'exists:advertising_categories,id'],
            'advertising_medium_id' => ['nullable', 'integer', 'exists:advertising_media,id'],
            'applies_to_process' => ['sometimes', Rule::enum(FieldAppliesTo::class)],
            'sort' => ['sometimes', 'integer', 'min:0'],
        ];
    }

    /**
     * @return array{
     *     lock_version: int,
     *     field_set_id?: int,
     *     target_layer?: string,
     *     advertising_category_id?: int|null,
     *     advertising_medium_id?: int|null,
     *     applies_to_process?: string,
     *     sort?: int
     * }
     */
    public function payload(): array
    {
        $validated = $this->validated();
        $payload = [
            'lock_version' => (int) $validated['lock_version'],
        ];

        if (array_key_exists('field_set_id', $validated)) {
            $payload['field_set_id'] = (int) $validated['field_set_id'];
        }
        if (array_key_exists('target_layer', $validated)) {
            $payload['target_layer'] = (string) $validated['target_layer'];
        }
        if (array_key_exists('advertising_category_id', $validated)) {
            $payload['advertising_category_id'] = $validated['advertising_category_id'];
        }
        if (array_key_exists('advertising_medium_id', $validated)) {
            $payload['advertising_medium_id'] = $validated['advertising_medium_id'];
        }
        if (array_key_exists('applies_to_process', $validated)) {
            $payload['applies_to_process'] = (string) $validated['applies_to_process'];
        }
        if (array_key_exists('sort', $validated)) {
            $payload['sort'] = (int) $validated['sort'];
        }

        return $payload;
    }
}
