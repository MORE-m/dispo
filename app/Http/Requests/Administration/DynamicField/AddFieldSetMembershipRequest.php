<?php

namespace App\Http\Requests\Administration\DynamicField;

use Illuminate\Foundation\Http\FormRequest;

class AddFieldSetMembershipRequest extends FormRequest
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
            'field_definition_id' => ['required', 'integer', 'min:1'],
            'field_definition_revision_id' => ['required', 'integer', 'min:1'],
            'sort' => ['required', 'integer', 'min:0', 'max:9999'],
            'required_override' => ['nullable', 'boolean'],
            'visible_override' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array{
     *     field_definition_id: int,
     *     field_definition_revision_id: int,
     *     sort: int,
     *     required_override: bool|null,
     *     visible_override: bool|null,
     *     lock_version: int
     * }
     */
    public function payload(): array
    {
        /** @var array<string, mixed> $data */
        $data = $this->validated();

        return [
            'field_definition_id' => (int) $data['field_definition_id'],
            'field_definition_revision_id' => (int) $data['field_definition_revision_id'],
            'sort' => (int) $data['sort'],
            'required_override' => array_key_exists('required_override', $data) ? $data['required_override'] : null,
            'visible_override' => array_key_exists('visible_override', $data) ? $data['visible_override'] : null,
            'lock_version' => (int) $data['lock_version'],
        ];
    }
}
