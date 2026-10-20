<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PO-CALC-TKP-1B: Snapshot einer Plattform-Impressions-Zuordnung.
 *
 * @property int $id
 * @property int $calculation_position_id
 * @property int|null $oa_platform_id
 * @property string $platform_code
 * @property string $platform_name
 * @property int $impressions
 * @property int $sort
 */
class CalculationPositionOaPlatformAllocation extends Model
{
    protected $table = 'calculation_position_oa_platform_allocations';

    protected $fillable = [
        'calculation_position_id',
        'oa_platform_id',
        'platform_code',
        'platform_name',
        'impressions',
        'sort',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'impressions' => 'integer',
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
