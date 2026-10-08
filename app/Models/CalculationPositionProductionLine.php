<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * BL-P5-02a: eingefrorene Produktionszeile einer Kalkulationsposition.
 *
 * @property int $id
 * @property int $calculation_position_id
 * @property string|null $client_key
 * @property string $production_type
 * @property string $label
 * @property string $quantity
 * @property string $unit_price
 * @property string $line_gross
 * @property string|null $remark
 * @property int|null $production_price_list_id
 * @property string $production_price_list_version
 * @property bool $is_discountable
 * @property bool $is_ae_eligible
 * @property string $ae_percent
 * @property string $position_discount_percent
 * @property list<array{type: string, custom_label: string|null, percent: string}>|null $position_discounts_snapshot
 * @property string $position_discount_amount
 * @property string $order_discount_amount
 * @property string $ae_amount
 * @property string $nn_invest
 * @property int $sort
 */
class CalculationPositionProductionLine extends Model
{
    protected $fillable = [
        'calculation_position_id',
        'client_key',
        'production_type',
        'label',
        'quantity',
        'unit_price',
        'line_gross',
        'remark',
        'production_price_list_id',
        'production_price_list_version',
        'is_discountable',
        'is_ae_eligible',
        'ae_percent',
        'position_discount_percent',
        'position_discounts_snapshot',
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
            'quantity' => 'decimal:4',
            'unit_price' => 'decimal:2',
            'line_gross' => 'decimal:2',
            'is_discountable' => 'boolean',
            'is_ae_eligible' => 'boolean',
            'ae_percent' => 'decimal:4',
            'position_discount_percent' => 'decimal:4',
            'position_discounts_snapshot' => 'array',
            'position_discount_amount' => 'decimal:2',
            'order_discount_amount' => 'decimal:2',
            'ae_amount' => 'decimal:2',
            'nn_invest' => 'decimal:2',
            'sort' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<CalculationPosition, $this>
     */
    public function calculationPosition(): BelongsTo
    {
        return $this->belongsTo(CalculationPosition::class);
    }

    /**
     * @return BelongsTo<ProductionPriceList, $this>
     */
    public function productionPriceList(): BelongsTo
    {
        return $this->belongsTo(ProductionPriceList::class);
    }
}
