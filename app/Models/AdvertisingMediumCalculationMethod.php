<?php

namespace App\Models;

use Database\Factories\AdvertisingMediumCalculationMethodFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ADV-001c1: Medium ↔ Berechnungsmethode (wirksam nur bei calculation_method_mode=override).
 *
 * @property int $advertising_medium_id
 * @property int $calculation_method_id
 * @property string|null $engine_profile_key
 * @property bool $is_active
 * @property int $sort
 * @property int $lock_version
 */
class AdvertisingMediumCalculationMethod extends Model
{
    /** @use HasFactory<AdvertisingMediumCalculationMethodFactory> */
    use HasFactory;

    protected $fillable = [
        'advertising_medium_id',
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
     * @return BelongsTo<AdvertisingMedium, $this>
     */
    public function advertisingMedium(): BelongsTo
    {
        return $this->belongsTo(AdvertisingMedium::class);
    }

    /**
     * @return BelongsTo<CalculationMethod, $this>
     */
    public function calculationMethod(): BelongsTo
    {
        return $this->belongsTo(CalculationMethod::class);
    }
}
