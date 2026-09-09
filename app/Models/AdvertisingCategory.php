<?php

namespace App\Models;

use Database\Factories\AdvertisingCategoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ADV-001a/ADV-001b/ADV-001c1: Oberkategorie (Stammdaten; Admin-Lifecycle ADV-001b).
 *
 * @property string $key
 * @property string $name
 * @property bool $is_active
 * @property int $sort
 * @property int $lock_version
 * @property int|null $default_calculation_method_id
 */
class AdvertisingCategory extends Model
{
    /** @use HasFactory<AdvertisingCategoryFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
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
     * @return HasMany<AdvertisingMedium, $this>
     */
    public function advertisingMedia(): HasMany
    {
        return $this->hasMany(AdvertisingMedium::class, 'category_id');
    }

    /**
     * @return BelongsTo<CalculationMethod, $this>
     */
    public function defaultCalculationMethod(): BelongsTo
    {
        return $this->belongsTo(CalculationMethod::class, 'default_calculation_method_id');
    }

    /**
     * @return HasMany<AdvertisingCategoryCalculationMethod, $this>
     */
    public function calculationMethodAssignments(): HasMany
    {
        return $this->hasMany(AdvertisingCategoryCalculationMethod::class);
    }
}
