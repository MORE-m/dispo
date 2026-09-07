<?php

namespace App\Http\Requests\Administration\DynamicField;

use App\Enums\FieldAppliesTo;
use App\Enums\FieldScope;
use App\Enums\FieldType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCustomFieldDefinitionRequest extends FormRequest
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
            'label' => ['sometimes', 'string', 'max:255'],
            'field_type' => ['sometimes', Rule::enum(FieldType::class)->only([FieldType::ShortText, FieldType::LongText])],
            'scope' => ['sometimes', Rule::enum(FieldScope::class)->only([FieldScope::Header, FieldScope::Position])],
            'applies_to' => ['sometimes', Rule::enum(FieldAppliesTo::class)],
            'help_text' => ['nullable', 'string', 'max:5000'],
            'group_key' => ['nullable', 'string', 'max:64'],
            'sort_default' => ['sometimes', 'integer', 'min:0', 'max:9999'],
            'reportable' => ['sometimes', 'boolean'],
            'max_length' => ['nullable', 'integer', 'min:1', 'max:20000'],
        ];
    }

    /**
     * @return array{
     *     lock_version: int,
     *     label?: string,
     *     field_type?: string,
     *     scope?: string,
     *     applies_to?: string,
     *     help_text?: string|null,
     *     group_key?: string|null,
     *     sort_default?: int,
     *     reportable?: bool,
     *     max_length?: int|null
     * }
     */
    public function payload(): array
    {
        /** @var array<string, mixed> $data */
        $data = $this->validated();
        $payload = ['lock_version' => (int) $data['lock_version']];

        if (array_key_exists('label', $data)) {
            $payload['label'] = (string) $data['label'];
        }
        if (array_key_exists('field_type', $data)) {
            $payload['field_type'] = (string) $data['field_type'];
        }
        if (array_key_exists('scope', $data)) {
            $payload['scope'] = (string) $data['scope'];
        }
        if (array_key_exists('applies_to', $data)) {
            $payload['applies_to'] = (string) $data['applies_to'];
        }
        if (array_key_exists('help_text', $data)) {
            $payload['help_text'] = $data['help_text'] !== null ? (string) $data['help_text'] : null;
        }
        if (array_key_exists('group_key', $data)) {
            $payload['group_key'] = $data['group_key'] !== null ? (string) $data['group_key'] : null;
        }
        if (array_key_exists('sort_default', $data)) {
            $payload['sort_default'] = (int) $data['sort_default'];
        }
        if (array_key_exists('reportable', $data)) {
            $payload['reportable'] = (bool) $data['reportable'];
        }
        if (array_key_exists('max_length', $data)) {
            $payload['max_length'] = $data['max_length'] !== null ? (int) $data['max_length'] : null;
        }

        return $payload;
    }
}
