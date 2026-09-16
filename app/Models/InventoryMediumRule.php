<?php

namespace App\Models;

use App\Enums\ComponentCalculationStrategy;
use Database\Factories\InventoryMediumRuleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $surcharge_percent
 * @property int|null $default_length_seconds
 * @property bool $is_discountable
 * @property bool $is_ae_eligible
 * @property ComponentCalculationStrategy $component_calculation_strategy
 */
class InventoryMediumRule extends Model
{
    /** @use HasFactory<InventoryMediumRuleFactory> */
    use HasFactory;

    protected $fillable = [
        'inventory_id',
        'advertising_medium_id',
        'is_active',
        'default_length_seconds',
        'surcharge_percent',
        'is_discountable',
        'is_ae_eligible',
        'component_calculation_strategy',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_discountable' => 'boolean',
            'is_ae_eligible' => 'boolean',
            'surcharge_percent' => 'decimal:4',
            'component_calculation_strategy' => ComponentCalculationStrategy::class,
        ];
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
}
