<?php

namespace App\Models;

use App\Enums\CalculationKind;
use App\Enums\SpotCalculationMethod;
use Database\Factories\CalculationPositionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string|null $client_key
 * @property SpotCalculationMethod $spot_method
 * @property int $total_spot_count
 * @property int $advertising_medium_id
 * @property int $length_seconds
 * @property string $position_discount_percent
 * @property string $ae_percent
 */
class CalculationPosition extends Model
{
    /** @use HasFactory<CalculationPositionFactory> */
    use HasFactory;

    protected $fillable = [
        'calculation_id',
        'client_key',
        'inventory_id',
        'advertising_medium_id',
        'price_list_id',
        'kind',
        'spot_method',
        'length_seconds',
        'total_spot_count',
        'surcharge_percent',
        'position_discount_percent',
        'ae_percent',
        'is_discountable',
        'is_ae_eligible',
        'price_list_version',
        'media_gross',
        'position_discount_amount',
        'order_discount_amount',
        'ae_amount',
        'nn_invest',
        'sort',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => CalculationKind::class,
            'spot_method' => SpotCalculationMethod::class,
            'surcharge_percent' => 'decimal:4',
            'position_discount_percent' => 'decimal:4',
            'ae_percent' => 'decimal:4',
            'is_discountable' => 'boolean',
            'is_ae_eligible' => 'boolean',
            'media_gross' => 'decimal:2',
            'position_discount_amount' => 'decimal:2',
            'order_discount_amount' => 'decimal:2',
            'ae_amount' => 'decimal:2',
            'nn_invest' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<Calculation, $this>
     */
    public function calculation(): BelongsTo
    {
        return $this->belongsTo(Calculation::class);
    }

    /**
     * @return BelongsTo<Inventory, $this>
     */
    public function inventory(): BelongsTo
    {
        return $this->belongsTo(Inventory::class);
    }

    /**
     * @return BelongsTo<AdvertisingMedium, $this>
     */
    public function advertisingMedium(): BelongsTo
    {
        return $this->belongsTo(AdvertisingMedium::class);
    }

    /**
     * @return BelongsTo<PriceList, $this>
     */
    public function priceList(): BelongsTo
    {
        return $this->belongsTo(PriceList::class);
    }

    /**
     * @return HasMany<SpotClassicPlanRow, $this>
     */
    public function planRows(): HasMany
    {
        return $this->hasMany(SpotClassicPlanRow::class);
    }
}
