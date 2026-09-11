<?php

namespace App\Http\Requests\Administration\DynamicField;

use App\Enums\FieldAppliesTo;
use App\Enums\FieldScope;
use App\Enums\FieldType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCustomFieldDefinitionRequest extends FormRequest
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
            'label' => ['required', 'string', 'max:255'],
            'key' => ['nullable', 'string', 'max:64'],
            'field_type' => ['required', Rule::enum(FieldType::class)->only([
                FieldType::ShortText,
                FieldType::LongText,
                FieldType::Select,
                FieldType::MultiSelect,
            ])],
            'scope' => ['required', Rule::enum(FieldScope::class)->only([FieldScope::Header, FieldScope::Position])],
            'applies_to' => ['required', Rule::enum(FieldAppliesTo::class)],
            'help_text' => ['nullable', 'string', 'max:5000'],
            'group_key' => ['nullable', 'string', 'max:64'],
            'sort_default' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'reportable' => ['sometimes', 'boolean'],
            'max_length' => ['nullable', 'integer', 'min:1', 'max:20000'],
        ];
    }

    /**
     * @return array{
     *     label: string,
     *     key: string|null,
     *     field_type: string,
     *     scope: string,
     *     applies_to: string,
     *     help_text: string|null,
     *     group_key: string|null,
     *     sort_default: int,
     *     reportable: bool,
     *     max_length: int|null
     * }
     */
    public function payload(): array
    {
        /** @var array<string, mixed> $data */
        $data = $this->validated();
        $key = isset($data['key']) && is_string($data['key']) && trim($data['key']) !== ''
            ? trim($data['key'])
            : null;

        return [
            'label' => (string) $data['label'],
            'key' => $key,
            'field_type' => (string) $data['field_type'],
            'scope' => (string) $data['scope'],
            'applies_to' => (string) $data['applies_to'],
            'help_text' => $data['help_text'] ?? null,
            'group_key' => $data['group_key'] ?? null,
            'sort_default' => (int) ($data['sort_default'] ?? 100),
            'reportable' => (bool) ($data['reportable'] ?? false),
            'max_length' => array_key_exists('max_length', $data) ? ($data['max_length'] !== null ? (int) $data['max_length'] : null) : null,
        ];
    }
}
