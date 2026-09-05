<?php

namespace App\Http\Requests\Administration\DynamicField;

use Illuminate\Foundation\Http\FormRequest;

/**
 * DF-3.1 / DF-3.2a / DYN-001 / ADM-001
 */
class StoreFieldDefinitionRevisionRequest extends FormRequest
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
            'label' => ['required', 'string', 'max:255'],
            'help_text' => ['nullable', 'string', 'max:5000'],
            'group_key' => ['nullable', 'string', 'max:64'],
            'sort_default' => ['required', 'integer', 'min:0', 'max:9999'],
            'reportable' => ['required', 'boolean'],
            'max_length' => ['nullable', 'integer', 'min:1', 'max:20000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'lock_version' => 'Version',
            'label' => 'Anzeigename',
            'help_text' => 'Hilfetext',
            'group_key' => 'Gruppe',
            'sort_default' => 'Standardsortierung',
            'reportable' => 'Reportfähig',
            'max_length' => 'Maximallänge',
        ];
    }

    /**
     * @return array{
     *     label: string,
     *     help_text: string|null,
     *     group_key: string|null,
     *     sort_default: int,
     *     reportable: bool,
     *     max_length: int|null,
     *     lock_version: int
     * }
     */
    public function payload(): array
    {
        /** @var array{label: string, help_text?: string|null, group_key?: string|null, sort_default: int|string, reportable: bool|string|int, max_length?: int|string|null, lock_version: int|string} $data */
        $data = $this->validated();

        return [
            'label' => $data['label'],
            'help_text' => $data['help_text'] ?? null,
            'group_key' => $data['group_key'] ?? null,
            'sort_default' => (int) $data['sort_default'],
            'reportable' => (bool) $data['reportable'],
            'max_length' => array_key_exists('max_length', $data) && $data['max_length'] !== null
                ? (int) $data['max_length']
                : null,
            'lock_version' => (int) $data['lock_version'],
        ];
    }

    public function lockVersion(): int
    {
        return (int) $this->validated('lock_version');
    }
}
