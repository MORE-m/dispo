<?php

namespace App\Models;

use App\Enums\PriceListStatus;
use App\Enums\ProductionType;
use Database\Factories\ProductionPriceListFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * BL-P5-02a: inventarspezifischer Produktionspreis (ein unit_price je Liste).
 *
 * @property int $id
 * @property int $inventory_id
 * @property ProductionType $production_type
 * @property int $year
 * @property string $name
 * @property string $version
 * @property int $revision_number
 * @property int $lock_version
 * @property PriceListStatus $status
 * @property string $unit_price
 * @property bool $is_discountable
 * @property bool $is_ae_eligible
 * @property Carbon|null $published_at
 * @property Carbon|null $archived_at
 */
class ProductionPriceList extends Model
{
    /** @use HasFactory<ProductionPriceListFactory> */
    use HasFactory;

    protected $fillable = [
        'inventory_id',
        'production_type',
        'year',
        'name',
        'version',
        'revision_number',
        'lock_version',
        'status',
        'unit_price',
        'is_discountable',
        'is_ae_eligible',
        'published_at',
        'archived_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'production_type' => ProductionType::class,
            'year' => 'integer',
            'revision_number' => 'integer',
            'lock_version' => 'integer',
            'status' => PriceListStatus::class,
            'unit_price' => 'decimal:2',
            'is_discountable' => 'boolean',
            'is_ae_eligible' => 'boolean',
            'published_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Inventory, $this>
     */
    public function inventory(): BelongsTo
    {
        return $this->belongsTo(Inventory::class);
    }
}
