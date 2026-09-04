<?php

namespace App\Models;

use App\Enums\ConfigurationSnapshotSource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $field_set_id
 * @property int $field_set_version_id
 * @property ConfigurationSnapshotSource $source
 * @property int|null $source_configuration_snapshot_id
 */
class ConfigurationSnapshot extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'field_set_id',
        'field_set_version_id',
        'source',
        'source_configuration_snapshot_id',
        'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source' => ConfigurationSnapshotSource::class,
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<ConfigurationSnapshot, $this>
     */
    public function sourceConfigurationSnapshot(): BelongsTo
    {
        return $this->belongsTo(self::class, 'source_configuration_snapshot_id');
    }

    /**
     * @return BelongsTo<FieldSet, $this>
     */
    public function fieldSet(): BelongsTo
    {
        return $this->belongsTo(FieldSet::class);
    }

    /**
     * @return BelongsTo<FieldSetVersion, $this>
     */
    public function fieldSetVersion(): BelongsTo
    {
        return $this->belongsTo(FieldSetVersion::class);
    }

    /**
     * @return HasMany<SnapshotFieldDefinition, $this>
     */
    public function fieldDefinitions(): HasMany
    {
        return $this->hasMany(SnapshotFieldDefinition::class)->orderBy('sort');
    }

    /**
     * @return HasMany<SnapshotFieldRule, $this>
     */
    public function rules(): HasMany
    {
        return $this->hasMany(SnapshotFieldRule::class)->orderBy('sort');
    }
}
