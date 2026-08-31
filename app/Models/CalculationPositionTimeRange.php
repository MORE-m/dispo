<?php

namespace App\Models;

use App\Enums\DayGroup;
use Database\Factories\CalculationPositionTimeRangeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property DayGroup $day_group
 * @property int $start_hour
 * @property int $end_hour_exclusive
 * @property int $spot_count
 * @property string|null $average_second_price
 * @property string|null $range_gross
 */
class CalculationPositionTimeRange extends Model
{
    /** @use HasFactory<CalculationPositionTimeRangeFactory> */
    use HasFactory;

    protected $fillable = [
        'calculation_position_id',
        'start_hour',
        'end_hour_exclusive',
        'day_group',
        'spot_count',
        'sort',
        'average_second_price',
        'range_gross',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'start_hour' => 'integer',
            'end_hour_exclusive' => 'integer',
            'day_group' => DayGroup::class,
            'spot_count' => 'integer',
            'sort' => 'integer',
            'average_second_price' => 'decimal:4',
            'range_gross' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<CalculationPosition, $this>
     */
    public function position(): BelongsTo
    {
        return $this->belongsTo(CalculationPosition::class, 'calculation_position_id');
    }
}
