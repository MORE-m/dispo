<?php

namespace App\Models;

use App\Enums\DayGroup;
use Database\Factories\SpotClassicPlanRowFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property DayGroup $day_group
 * @property int $hour
 * @property int $spot_count
 * @property string $second_price
 */
class SpotClassicPlanRow extends Model
{
    /** @use HasFactory<SpotClassicPlanRowFactory> */
    use HasFactory;

    protected $fillable = [
        'calculation_position_id',
        'hour',
        'day_group',
        'spot_count',
        'second_price',
        'line_gross',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'hour' => 'integer',
            'day_group' => DayGroup::class,
            'spot_count' => 'integer',
            'second_price' => 'decimal:4',
            'line_gross' => 'decimal:2',
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
