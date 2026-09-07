<?php

namespace App\Models;

use App\Enums\ConfigurationSnapshotSource as ConfigurationSnapshotSourceEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

/**
 * @property int $id
 * @property int $field_set_id
 * @property int $field_set_version_id
 * @property ConfigurationSnapshotSourceEnum $source
 * @property int|null $source_configuration_snapshot_id
 * @property int $format_version
 * @property string|null $schema_fingerprint
 */
class ConfigurationSnapshot extends Model
{
    /** Generation 1: Legacy/Core-only Materialisierung. */
    public const FORMAT_VERSION_LEGACY = 1;

    /** Generation 2: Core + globale Assignments inkl. Quellengraph. */
    public const FORMAT_VERSION_GLOBAL_FREEZE = 2;

    /** @var list<int> */
    public const SUPPORTED_FORMAT_VERSIONS = [
        self::FORMAT_VERSION_LEGACY,
        self::FORMAT_VERSION_GLOBAL_FREEZE,
    ];

    public $timestamps = false;

    protected $fillable = [
        'field_set_id',
        'field_set_version_id',
        'source',
        'source_configuration_snapshot_id',
        'format_version',
        'schema_fingerprint',
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
     * Fail-closed: unbekannte Snapshot-Generationen dürfen nicht gelesen werden.
     */
    public function assertReadable(): void
    {
        $version = (int) $this->format_version;

        if (! in_array($version, self::SUPPORTED_FORMAT_VERSIONS, true)) {
            throw new RuntimeException(
                "Konfigurationssnapshot {$this->id} hat unbekannte format_version {$version}.",
            );
        }
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

    /**
     * DF-3.3a2α: eingefrorener Quellengraph (nur format_version 2).
     *
     * @return HasMany<ConfigurationSnapshotSource, $this>
     */
    public function sources(): HasMany
    {
        return $this->hasMany(ConfigurationSnapshotSource::class)->orderBy('merge_order');
    }
}
