<?php

namespace App\Models;

use App\Enums\FieldAppliesTo;
use App\Enums\FieldScope;
use App\Enums\FieldType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $configuration_snapshot_id
 * @property int $field_definition_id
 * @property int $field_definition_revision_id
 * @property string $key
 * @property FieldType $field_type
 * @property string $label
 * @property string|null $help_text
 * @property FieldScope $scope
 * @property FieldAppliesTo $applies_to
 * @property int $sort
 * @property string|null $group_key
 * @property bool $reportable
 * @property bool $required
 * @property bool $visible
 * @property array<string, mixed>|null $validation_json
 */
class SnapshotFieldDefinition extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'configuration_snapshot_id',
        'field_definition_id',
        'field_definition_revision_id',
        'key',
        'field_type',
        'label',
        'help_text',
        'scope',
        'applies_to',
        'sort',
        'group_key',
        'reportable',
        'required',
        'visible',
        'validation_json',
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
            'reportable' => 'boolean',
            'required' => 'boolean',
            'visible' => 'boolean',
            'validation_json' => 'array',
        ];
    }

    /**
     * Membership: null → optional; true → Pflicht.
     */
    public static function effectiveRequired(?bool $requiredOverride): bool
    {
        return $requiredOverride === true;
    }

    /**
     * Membership: null → sichtbar; false → unsichtbar.
     */
    public static function effectiveVisible(?bool $visibleOverride): bool
    {
        return $visibleOverride !== false;
    }

    /**
     * @return BelongsTo<ConfigurationSnapshot, $this>
     */
    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(ConfigurationSnapshot::class, 'configuration_snapshot_id');
    }
}
