<?php

namespace App\Models;

use App\Enums\DiscountType;
use Database\Factories\CalculationOrderDiscountFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property DiscountType $type
 * @property string|null $custom_label
 * @property string $percent
 */
class CalculationOrderDiscount extends Model
{
    /** @use HasFactory<CalculationOrderDiscountFactory> */
    use HasFactory;

    protected $fillable = [
        'calculation_id',
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
     * @return BelongsTo<Calculation, $this>
     */
    public function calculation(): BelongsTo
    {
        return $this->belongsTo(Calculation::class);
    }
}
