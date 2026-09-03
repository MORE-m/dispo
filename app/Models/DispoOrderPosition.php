<?php

namespace App\Models;

use App\Enums\CalculationKind;
use App\Enums\SpotCalculationMethod;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $dispo_order_id
 * @property int|null $calculation_position_id
 * @property string $inventory_name
 * @property string $advertising_medium_name
 * @property CalculationKind $kind
 * @property SpotCalculationMethod $spot_method
 */
class DispoOrderPosition extends Model
{
    protected $fillable = [
        'dispo_order_id',
        'calculation_position_id',
        'sort',
        'inventory_id',
        'inventory_name',
        'inventory_code',
        'advertising_medium_id',
        'advertising_medium_name',
        'advertising_medium_code',
        'kind',
        'spot_method',
        'length_seconds',
        'total_spot_count',
        'needs_spot_redistribution',
        'price_list_id',
        'price_list_version',
        'price_list_name',
        'average_second_price',
        'length_index',
        'surcharge_percent',
        'position_discount_percent',
        'ae_percent',
        'is_discountable',
        'is_ae_eligible',
        'media_gross',
        'position_discount_amount',
        'order_discount_amount',
        'ae_amount',
        'nn_invest',
        'plan_rows_snapshot',
        'time_ranges_snapshot',
        'position_discounts_snapshot',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => CalculationKind::class,
            'spot_method' => SpotCalculationMethod::class,
            'average_second_price' => 'decimal:4',
            'surcharge_percent' => 'decimal:4',
            'position_discount_percent' => 'decimal:4',
            'ae_percent' => 'decimal:4',
            'is_discountable' => 'boolean',
            'is_ae_eligible' => 'boolean',
            'needs_spot_redistribution' => 'boolean',
            'media_gross' => 'decimal:2',
            'position_discount_amount' => 'decimal:2',
            'order_discount_amount' => 'decimal:2',
            'ae_amount' => 'decimal:2',
            'nn_invest' => 'decimal:2',
            'plan_rows_snapshot' => 'array',
            'time_ranges_snapshot' => 'array',
            'position_discounts_snapshot' => 'array',
        ];
    }

    /**
     * @return BelongsTo<DispoOrder, $this>
     */
    public function dispoOrder(): BelongsTo
    {
        return $this->belongsTo(DispoOrder::class);
    }

    /**
     * @return BelongsTo<CalculationPosition, $this>
     */
    public function calculationPosition(): BelongsTo
    {
        return $this->belongsTo(CalculationPosition::class);
    }
}
