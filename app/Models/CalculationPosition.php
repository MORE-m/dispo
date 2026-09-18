<?php

namespace App\Models;

use App\Enums\CalculationKind;
use App\Enums\PricingSettlementMode;
use App\Enums\SpotCalculationMethod;
use App\Enums\SpotComponentProfile;
use Database\Factories\CalculationPositionFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string|null $client_key
 * @property CalculationKind $kind
 * @property SpotCalculationMethod $spot_method
 * @property PricingSettlementMode $pricing_settlement_mode
 * @property string|null $fixed_price_nn
 * @property string|null $effective_pay_factor_percent
 * @property string|null $effective_total_discount_percent
 * @property string|null $engine_profile_key
 * @property string|null $calculation_method_key
 * @property string|null $calculation_method_name
 * @property string|null $algorithm_version
 * @property int $total_spot_count
 * @property bool $needs_spot_redistribution
 * @property int $inventory_id
 * @property string|null $inventory_name
 * @property string|null $inventory_code
 * @property int $advertising_medium_id
 * @property string|null $advertising_medium_name
 * @property string|null $advertising_medium_code
 * @property int|null $advertising_category_id
 * @property string|null $advertising_category_key
 * @property string|null $advertising_category_name
 * @property int|null $effective_configuration_snapshot_id
 * @property-read Collection<int, CalculationPositionTimeRange> $timeRanges
 * @property-read Collection<int, CalculationPositionPlannerEntry> $plannerEntries
 * @property-read Collection<int, CalculationPositionComponent> $components
 * @property-read Collection<int, CalculationPositionDiscount> $discounts
 * @property int $length_seconds
 * @property string|null $component_calculation_strategy
 * @property SpotComponentProfile|null $component_profile
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
        'inventory_name',
        'inventory_code',
        'advertising_medium_id',
        'advertising_medium_name',
        'advertising_medium_code',
        'advertising_category_id',
        'advertising_category_key',
        'advertising_category_name',
        'effective_configuration_snapshot_id',
        'inventory_medium_rule_id',
        'price_list_id',
        'kind',
        'spot_method',
        'length_seconds',
        'component_calculation_strategy',
        'component_profile',
        'total_spot_count',
        'needs_spot_redistribution',
        'average_second_price',
        'length_index',
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
        'pricing_settlement_mode',
        'fixed_price_nn',
        'effective_pay_factor_percent',
        'effective_total_discount_percent',
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
            'component_profile' => SpotComponentProfile::class,
            'average_second_price' => 'decimal:4',
            'length_index' => 'integer',
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
            'pricing_settlement_mode' => PricingSettlementMode::class,
            'fixed_price_nn' => 'decimal:2',
            'effective_pay_factor_percent' => 'decimal:4',
            'effective_total_discount_percent' => 'decimal:4',
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

    /**
     * @return HasMany<CalculationPositionTimeRange, $this>
     */
    public function timeRanges(): HasMany
    {
        return $this->hasMany(CalculationPositionTimeRange::class)->orderBy('sort')->orderBy('id');
    }

    /**
     * @return HasMany<CalculationPositionPlannerEntry, $this>
     */
    public function plannerEntries(): HasMany
    {
        return $this->hasMany(CalculationPositionPlannerEntry::class)
            ->orderBy('date')
            ->orderBy('hour')
            ->orderBy('id');
    }

    /**
     * @return HasMany<CalculationPositionComponent, $this>
     */
    public function components(): HasMany
    {
        return $this->hasMany(CalculationPositionComponent::class)
            ->orderBy('sort')
            ->orderBy('id');
    }

    /**
     * @return HasMany<CalculationPositionDiscount, $this>
     */
    public function discounts(): HasMany
    {
        return $this->hasMany(CalculationPositionDiscount::class)->orderBy('sort')->orderBy('id');
    }

    /**
     * @return HasMany<CalculationPositionFieldValue, $this>
     */
    public function fieldValues(): HasMany
    {
        return $this->hasMany(CalculationPositionFieldValue::class);
    }
}
