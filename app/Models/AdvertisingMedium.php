<?php

namespace App\Models;

use App\Enums\CalculationKind;
use App\Enums\CalculationMethodMode;
use Database\Factories\AdvertisingMediumFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ADV-001b/ADV-001c1: Werbemittel-Stammdaten mit Admin-Lifecycle.
 *
 * @property int $category_id
 * @property CalculationKind|null $kind
 * @property CalculationMethodMode $calculation_method_mode
 * @property int|null $default_calculation_method_id
 * @property int $default_length_seconds
 * @property bool $is_discountable
 * @property bool $is_ae_eligible
 * @property bool $is_active
 * @property int $sort
 * @property int $lock_version
 */
class AdvertisingMedium extends Model
{
    /** @use HasFactory<AdvertisingMediumFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'default_length_seconds',
        'is_discountable',
        'is_ae_eligible',
        'is_active',
        'sort',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => CalculationKind::class,
            'calculation_method_mode' => CalculationMethodMode::class,
            'is_discountable' => 'boolean',
            'is_ae_eligible' => 'boolean',
            'is_active' => 'boolean',
            'sort' => 'integer',
            'lock_version' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<AdvertisingCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(AdvertisingCategory::class, 'category_id');
    }

    /**
     * @return BelongsTo<CalculationMethod, $this>
     */
    public function defaultCalculationMethod(): BelongsTo
    {
        return $this->belongsTo(CalculationMethod::class, 'default_calculation_method_id');
    }

    /**
     * @return HasMany<AdvertisingMediumCalculationMethod, $this>
     */
    public function calculationMethodAssignments(): HasMany
    {
        return $this->hasMany(AdvertisingMediumCalculationMethod::class);
    }
}
