<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PO-CALC-TKP-1B: Snapshot einer Targeting-Auswahl (absoluter TKP-Aufschlag).
 *
 * @property int $id
 * @property int $calculation_position_id
 * @property int|null $oa_targeting_definition_id
 * @property string $targeting_code
 * @property string $targeting_name
 * @property string $targeting_category
 * @property string $tkp_surcharge_snapshot
 * @property string|null $minimum_tkp_surcharge_snapshot
 * @property int $targeting_revision_number
 * @property int $sort
 */
class CalculationPositionOaTargetingSelection extends Model
{
    protected $table = 'calculation_position_oa_targeting_selections';

    protected $fillable = [
        'calculation_position_id',
        'oa_targeting_definition_id',
        'targeting_code',
        'targeting_name',
        'targeting_category',
        'tkp_surcharge_snapshot',
        'minimum_tkp_surcharge_snapshot',
        'targeting_revision_number',
        'sort',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'targeting_revision_number' => 'integer',
            'sort' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<CalculationPosition, $this>
     */
    public function calculationPosition(): BelongsTo
    {
        return $this->belongsTo(CalculationPosition::class);
    }
}
