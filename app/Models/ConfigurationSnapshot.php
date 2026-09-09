<?php

namespace App\Models;

use App\Enums\ConfigurationSnapshotSource as ConfigurationSnapshotSourceEnum;
use App\Services\DynamicField\ConfigurationSnapshotIntegrity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $field_set_id
 * @property int $field_set_version_id
 * @property ConfigurationSnapshotSourceEnum $source
 * @property int|null $source_configuration_snapshot_id
 * @property int|null $parent_configuration_snapshot_id
 * @property int $format_version
 * @property string|null $schema_fingerprint
 * @property int|null $context_advertising_medium_id
 * @property string|null $context_advertising_medium_code
 * @property string|null $context_advertising_medium_name
 * @property int|null $context_advertising_category_id
 * @property string|null $context_advertising_category_key
 * @property string|null $context_advertising_category_name
 */
class ConfigurationSnapshot extends Model
{
    /** Generation 1: Legacy/Core-only Materialisierung. */
    public const FORMAT_VERSION_LEGACY = 1;

    /** Generation 2: Core + globale Assignments inkl. Quellengraph. */
    public const FORMAT_VERSION_GLOBAL_FREEZE = 2;

    /** Generation 3: Core + global + Kat/Medium; Basis oder Positions-Effektiv. */
    public const FORMAT_VERSION_CONTEXTUAL_FREEZE = 3;

    /** @var list<int> */
    public const SUPPORTED_FORMAT_VERSIONS = [
        self::FORMAT_VERSION_LEGACY,
        self::FORMAT_VERSION_GLOBAL_FREEZE,
        self::FORMAT_VERSION_CONTEXTUAL_FREEZE,
    ];

    public $timestamps = false;

    protected $fillable = [
        'field_set_id',
        'field_set_version_id',
        'source',
        'source_configuration_snapshot_id',
        'parent_configuration_snapshot_id',
        'format_version',
        'schema_fingerprint',
        'context_advertising_medium_id',
        'context_advertising_medium_code',
        'context_advertising_medium_name',
        'context_advertising_category_id',
        'context_advertising_category_key',
        'context_advertising_category_name',
        'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source' => ConfigurationSnapshotSourceEnum::class,
            'format_version' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /**
     * Fail-closed: unbekannte bzw. beschädigte Snapshots dürfen nicht gelesen werden.
     */
    public function assertReadable(): void
    {
        app(ConfigurationSnapshotIntegrity::class)->assertReadable($this);
    }

    public function isEffectiveSnapshot(): bool
    {
        return $this->parent_configuration_snapshot_id !== null
            || in_array($this->source, [
                ConfigurationSnapshotSourceEnum::CalculationPositionEffective,
                ConfigurationSnapshotSourceEnum::DispoOrderPositionEffective,
            ], true);
    }

    /**
     * @return BelongsTo<ConfigurationSnapshot, $this>
     */
    public function sourceConfigurationSnapshot(): BelongsTo
    {
        return $this->belongsTo(self::class, 'source_configuration_snapshot_id');
    }

    /**
     * @return BelongsTo<ConfigurationSnapshot, $this>
     */
    public function parentConfigurationSnapshot(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_configuration_snapshot_id');
    }

    /**
     * @return BelongsTo<AdvertisingMedium, $this>
     */
    public function contextAdvertisingMedium(): BelongsTo
    {
        return $this->belongsTo(AdvertisingMedium::class, 'context_advertising_medium_id');
    }

    /**
     * @return BelongsTo<AdvertisingCategory, $this>
     */
    public function contextAdvertisingCategory(): BelongsTo
    {
        return $this->belongsTo(AdvertisingCategory::class, 'context_advertising_category_id');
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

    /**
     * DF-3.3a2α / DF-3.3a2β: eingefrorener Quellengraph (format_version 2 und 3).
     *
     * @return HasMany<ConfigurationSnapshotSource, $this>
     */
    public function sources(): HasMany
    {
        return $this->hasMany(ConfigurationSnapshotSource::class)->orderBy('merge_order');
    }
}
