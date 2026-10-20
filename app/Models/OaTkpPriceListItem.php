<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PO-CALC-TKP-1A: Listen-/Mindest-TKP je Medium × Inventar innerhalb einer Listenrevision.
 *
 * @property int $id
 * @property int $oa_tkp_price_list_id
 * @property int $advertising_medium_id
 * @property int $inventory_id
 * @property string $list_tkp
 * @property string $minimum_tkp
 */
class OaTkpPriceListItem extends Model
{
    protected $fillable = [
        'oa_tkp_price_list_id',
        'advertising_medium_id',
        'inventory_id',
        'list_tkp',
        'minimum_tkp',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'list_tkp' => 'decimal:4',
            'minimum_tkp' => 'decimal:4',
        ];
    }

    /**
     * @return BelongsTo<OaTkpPriceList, $this>
     */
    public function priceList(): BelongsTo
    {
        return $this->belongsTo(OaTkpPriceList::class, 'oa_tkp_price_list_id');
    }

    /**
     * @return BelongsTo<AdvertisingMedium, $this>
     */
    public function advertisingMedium(): BelongsTo
    {
        return $this->belongsTo(AdvertisingMedium::class);
    }

    /**
     * @return BelongsTo<Inventory, $this>
     */
    public function inventory(): BelongsTo
    {
        return $this->belongsTo(Inventory::class);
    }
}
