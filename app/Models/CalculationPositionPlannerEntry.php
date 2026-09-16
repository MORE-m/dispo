<?php

namespace App\Models;

use App\Enums\DayGroup;
use Database\Factories\CalculationPositionPlannerEntryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $date
 * @property int $hour
 * @property int $spot_count
 * @property DayGroup $day_group
 * @property string $second_price
 * @property string $line_gross
 */
class CalculationPositionPlannerEntry extends Model
{
    /** @use HasFactory<CalculationPositionPlannerEntryFactory> */
    use HasFactory;

    protected $fillable = [
        'calculation_position_id',
        'date',
        'hour',
        'spot_count',
        'day_group',
        'second_price',
        'line_gross',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date:Y-m-d',
            'hour' => 'integer',
            'spot_count' => 'integer',
            'day_group' => DayGroup::class,
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

    public function dateIso(): string
    {
        return Carbon::parse($this->date)->format('Y-m-d');
    }
}
