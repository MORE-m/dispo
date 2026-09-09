<?php

namespace App\Models;

use Database\Factories\AdvertisingCategoryCalculationMethodFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ADV-001c1: Kategorie ↔ Berechnungsmethode (engine_profile_key technisch geschützt).
 *
 * @property int $advertising_category_id
 * @property int $calculation_method_id
 * @property string|null $engine_profile_key
 * @property bool $is_active
 * @property int $sort
 * @property int $lock_version
 */
class AdvertisingCategoryCalculationMethod extends Model
{
    /** @use HasFactory<AdvertisingCategoryCalculationMethodFactory> */
    use HasFactory;

    protected $fillable = [
        'advertising_category_id',
        'calculation_method_id',
        'is_active',
        'sort',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort' => 'integer',
            'lock_version' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<AdvertisingCategory, $this>
     */
    public function advertisingCategory(): BelongsTo
    {
        return $this->belongsTo(AdvertisingCategory::class);
    }

    /**
     * @return BelongsTo<CalculationMethod, $this>
     */
    public function calculationMethod(): BelongsTo
    {
        return $this->belongsTo(CalculationMethod::class);
    }
}
