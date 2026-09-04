<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $dispo_order_id
 * @property int $snapshot_field_definition_id
 * @property string|null $value_text
 * @property string|null $value_period_start
 * @property string|null $value_period_end
 */
class DispoOrderFieldValue extends Model
{
    protected $fillable = [
        'dispo_order_id',
        'snapshot_field_definition_id',
        'value_text',
        'value_period_start',
        'value_period_end',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'value_period_start' => 'date',
            'value_period_end' => 'date',
        ];
    }

    /**
     * @return BelongsTo<DispoOrder, $this>
     */
    public function dispoOrder(): BelongsTo
    {
        return $this->belongsTo(DispoOrder::class);
    }

    /**
     * @return BelongsTo<SnapshotFieldDefinition, $this>
     */
    public function snapshotFieldDefinition(): BelongsTo
    {
        return $this->belongsTo(SnapshotFieldDefinition::class);
    }
}
