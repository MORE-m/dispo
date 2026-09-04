<?php

namespace App\Http\Requests\Administration\DynamicField;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\In;

/**
 * DF-3.1 / DYN-003 – Membership-Overrides; keine Regelmutation.
 */
class UpdateFieldSetDraftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canAccessAdministration() ?? false;
    }

    /**
     * @return array<string, list<string|In>>
     */
    public function rules(): array
    {
        return [
            'lock_version' => ['required', 'integer', 'min:1'],
            'fields' => ['required', 'array', 'min:1'],
            'fields.*.id' => ['required', 'integer', 'min:1'],
            'fields.*.field_definition_revision_id' => ['required', 'integer', 'min:1'],
            'fields.*.sort' => ['required', 'integer', 'min:0', 'max:9999'],
            'fields.*.required_override' => ['nullable', 'boolean'],
            'fields.*.visible_override' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'lock_version' => 'Version',
            'fields' => 'Felder',
            'fields.*.field_definition_revision_id' => 'Feldrevision',
            'fields.*.sort' => 'Sortierung',
            'fields.*.required_override' => 'Pflicht-Override',
            'fields.*.visible_override' => 'Sichtbarkeits-Override',
        ];
    }

    public function lockVersion(): int
    {
        return (int) $this->validated('lock_version');
    }

    /**
     * @return list<array{
     *     id: int,
     *     field_definition_revision_id: int,
     *     sort: int,
     *     required_override: bool|null,
     *     visible_override: bool|null
     * }>
     */
    public function memberships(): array
    {
        /** @var list<array{id: int|string, field_definition_revision_id: int|string, sort: int|string, required_override?: bool|null, visible_override?: bool|null}> $fields */
        $fields = $this->validated('fields');

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'field_definition_revision_id' => (int) $row['field_definition_revision_id'],
            'sort' => (int) $row['sort'],
            'required_override' => array_key_exists('required_override', $row) ? $row['required_override'] : null,
            'visible_override' => array_key_exists('visible_override', $row) ? $row['visible_override'] : null,
        ], $fields);
    }
}
