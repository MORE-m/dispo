<?php

namespace App\Models;

use App\Casts\ChoiceValueJsonCast;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $calculation_id
 * @property int $snapshot_field_definition_id
 * @property string|null $value_string
 * @property string|null $value_text
 * @property bool|null $value_boolean
 * @property Carbon|null $value_period_start
 * @property Carbon|null $value_period_end
 * @property string|list<string>|null $value_json
 */
class CalculationFieldValue extends Model
{
    protected $fillable = [
        'calculation_id',
        'snapshot_field_definition_id',
        'value_string',
        'value_text',
        'value_boolean',
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
            'value_boolean' => 'boolean',
            'value_period_start' => 'date',
            'value_period_end' => 'date',
            'value_json' => ChoiceValueJsonCast::class,
        ];
    }

    /**
     * @return BelongsTo<Calculation, $this>
     */
    public function calculation(): BelongsTo
    {
        return $this->belongsTo(Calculation::class);
    }

    /**
     * @return BelongsTo<SnapshotFieldDefinition, $this>
     */
    public function snapshotFieldDefinition(): BelongsTo
    {
        return $this->belongsTo(SnapshotFieldDefinition::class);
    }
}
