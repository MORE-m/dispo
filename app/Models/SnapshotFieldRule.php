<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $configuration_snapshot_id
 * @property int|null $source_field_rule_id
 * @property int $sort
 * @property array<string, mixed> $condition_json
 * @property array<string, mixed> $action_json
 */
class SnapshotFieldRule extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'configuration_snapshot_id',
        'source_field_rule_id',
        'sort',
        'condition_json',
        'action_json',
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
     * @return BelongsTo<ConfigurationSnapshot, $this>
     */
    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(ConfigurationSnapshot::class, 'configuration_snapshot_id');
    }
}
