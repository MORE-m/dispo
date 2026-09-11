<?php

namespace App\Models;

use App\Enums\FieldAppliesTo;
use App\Enums\FieldScope;
use App\Enums\FieldType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * DF-3.3a2α / VER-002: eingefrorene Membership einer Snapshot-Quelle.
 *
 * @property int $id
 * @property int $configuration_snapshot_source_id
 * @property int $field_definition_id
 * @property int $field_definition_revision_id
 * @property string $field_key
 * @property FieldType $field_type
 * @property FieldScope $scope
 * @property FieldAppliesTo $applies_to
 * @property string $label
 * @property string|null $help_text
 * @property string|null $group_key
 * @property int $membership_sort
 * @property bool|null $required_override
 * @property bool|null $visible_override
 * @property array<string, mixed>|null $validation_json
 * @property list<array{key: string, label: string, sort: int, is_active: bool}>|null $options_json
 * @property bool $reportable
 * @property bool $definition_is_system
 * @property bool $definition_is_active
 */
class ConfigurationSnapshotSourceField extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'configuration_snapshot_source_id',
        'field_definition_id',
        'field_definition_revision_id',
        'field_key',
        'field_type',
        'scope',
        'applies_to',
        'label',
        'help_text',
        'group_key',
        'membership_sort',
        'required_override',
        'visible_override',
        'validation_json',
        'options_json',
        'reportable',
        'definition_is_system',
        'definition_is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'field_type' => FieldType::class,
            'scope' => FieldScope::class,
            'applies_to' => FieldAppliesTo::class,
            'required_override' => 'boolean',
            'visible_override' => 'boolean',
            'validation_json' => 'array',
            'options_json' => 'array',
            'reportable' => 'boolean',
            'definition_is_system' => 'boolean',
            'definition_is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<ConfigurationSnapshotSource, $this>
     */
    public function source(): BelongsTo
    {
        return $this->belongsTo(
            ConfigurationSnapshotSource::class,
            'configuration_snapshot_source_id',
        );
    }
}
