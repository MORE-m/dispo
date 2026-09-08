<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * DF-3.3a2α / VER-002: eingefrorene Quelle eines v2-Snapshots.
 *
 * @property int $id
 * @property int $configuration_snapshot_id
 * @property int $merge_order
 * @property string $layer
 * @property string $role
 * @property int $field_set_id
 * @property string $field_set_key
 * @property string $field_set_name
 * @property int $field_set_version_id
 * @property int $field_set_version_number
 * @property int|null $field_set_assignment_id
 * @property int|null $assignment_lock_version
 * @property string|null $assignment_applies_to_process
 * @property int|null $assignment_sort
 * @property bool|null $assignment_is_active
 * @property string $target_layer
 * @property string $target_identity
 * @property int|null $target_id
 * @property string|null $target_key
 * @property string|null $target_name
 */
class ConfigurationSnapshotSource extends Model
{
    public const ROLE_CORE = 'core';

    public const ROLE_ASSIGNMENT = 'assignment';

    public const ROLE_ADDITIONAL = 'additional';

    public const TARGET_IDENTITY_CALC_ORIGIN = 'calc_origin';

    public $timestamps = false;

    protected $fillable = [
        'configuration_snapshot_id',
        'merge_order',
        'layer',
        'role',
        'field_set_id',
        'field_set_key',
        'field_set_name',
        'field_set_version_id',
        'field_set_version_number',
        'field_set_assignment_id',
        'assignment_lock_version',
        'assignment_applies_to_process',
        'assignment_sort',
        'assignment_is_active',
        'target_layer',
        'target_identity',
        'target_id',
        'target_key',
        'target_name',
        'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'assignment_is_active' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<ConfigurationSnapshot, $this>
     */
    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(ConfigurationSnapshot::class, 'configuration_snapshot_id');
    }

    /**
     * @return HasMany<ConfigurationSnapshotSourceField, $this>
     */
    public function fields(): HasMany
    {
        return $this->hasMany(
            ConfigurationSnapshotSourceField::class,
            'configuration_snapshot_source_id',
        )->orderBy('membership_sort')->orderBy('id');
    }

    /**
     * @return HasMany<ConfigurationSnapshotSourceRule, $this>
     */
    public function rules(): HasMany
    {
        return $this->hasMany(
            ConfigurationSnapshotSourceRule::class,
            'configuration_snapshot_source_id',
        )->orderBy('sort')->orderBy('id');
    }
}
