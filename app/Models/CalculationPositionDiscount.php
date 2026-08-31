<?php

namespace App\Models;

use App\Enums\DiscountType;
use Database\Factories\CalculationPositionDiscountFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property DiscountType $type
 * @property string|null $custom_label
 * @property string $percent
 */
class CalculationPositionDiscount extends Model
{
    /** @use HasFactory<CalculationPositionDiscountFactory> */
    use HasFactory;

    protected $fillable = [
        'calculation_position_id',
        'type',
        'custom_label',
        'percent',
        'sort',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => DiscountType::class,
            'percent' => 'decimal:4',
            'sort' => 'integer',
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
