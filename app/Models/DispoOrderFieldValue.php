<?php

namespace App\Models;

use App\Casts\ChoiceValueJsonCast;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $dispo_order_id
 * @property int $snapshot_field_definition_id
 * @property string|null $value_string
 * @property string|null $value_text
 * @property string|null $value_period_start
 * @property string|null $value_period_end
 * @property string|list<string>|null $value_json
 */
class DispoOrderFieldValue extends Model
{
    protected $fillable = [
        'dispo_order_id',
        'snapshot_field_definition_id',
        'value_string',
        'value_text',
        'value_period_start',
        'value_period_end',
        'value_json',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'value_period_start' => 'date',
            'value_period_end' => 'date',
            'value_json' => ChoiceValueJsonCast::class,
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
