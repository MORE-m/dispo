<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * DF-3.3a2α / VER-002: eingefrorene Regel einer Snapshot-Quelle.
 *
 * @property int $id
 * @property int $configuration_snapshot_source_id
 * @property int|null $source_field_rule_id
 * @property int $sort
 * @property array<string, mixed> $condition_json
 * @property array<string, mixed> $action_json
 * @property string $dedupe_key
 */
class ConfigurationSnapshotSourceRule extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'configuration_snapshot_source_id',
        'source_field_rule_id',
        'sort',
        'condition_json',
        'action_json',
        'dedupe_key',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'condition_json' => 'array',
            'action_json' => 'array',
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
