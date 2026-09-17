<?php

namespace App\Models;

use App\Enums\CalculationKind;
use App\Enums\SpotCalculationMethod;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $dispo_order_id
 * @property int|null $calculation_position_id
 * @property string $inventory_name
 * @property string $advertising_medium_name
 * @property string|null $advertising_medium_code
 * @property int|null $advertising_category_id
 * @property string|null $advertising_category_key
 * @property string|null $advertising_category_name
 * @property int|null $effective_configuration_snapshot_id
 * @property CalculationKind $kind
 * @property SpotCalculationMethod $spot_method
 * @property string|null $engine_profile_key
 * @property string|null $calculation_method_key
 * @property string|null $calculation_method_name
 * @property string|null $algorithm_version
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
        'advertising_category_id',
        'advertising_category_key',
        'advertising_category_name',
        'effective_configuration_snapshot_id',
        'kind',
        'spot_method',
        'engine_profile_key',
        'calculation_method_key',
        'calculation_method_name',
        'algorithm_version',
        'length_seconds',
        'component_calculation_strategy',
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
        'planner_entries_snapshot',
        'components_snapshot',
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
            'planner_entries_snapshot' => 'array',
            'components_snapshot' => 'array',
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

    /**
     * DF-3.3a2β: historisch eingefrorene Oberkategorie der Position.
     *
     * @return BelongsTo<AdvertisingCategory, $this>
     */
    public function advertisingCategory(): BelongsTo
    {
        return $this->belongsTo(AdvertisingCategory::class, 'advertising_category_id');
    }

    /**
     * DF-3.3a2β / VER-003: positionsscharfer Effektiv-Snapshot.
     *
     * @return BelongsTo<ConfigurationSnapshot, $this>
     */
    public function effectiveConfigurationSnapshot(): BelongsTo
    {
        return $this->belongsTo(ConfigurationSnapshot::class, 'effective_configuration_snapshot_id');
    }

    /**
     * @return HasMany<DispoOrderPositionFieldValue, $this>
     */
    public function fieldValues(): HasMany
    {
        return $this->hasMany(DispoOrderPositionFieldValue::class);
    }
}
