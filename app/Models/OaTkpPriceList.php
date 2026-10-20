<?php

namespace App\Models;

use App\Enums\PriceListStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * PO-CALC-TKP-1A: OA-TKP-Preisliste (Jahr/Revision; Items = Medium × Inventar).
 *
 * @property int $id
 * @property int $year
 * @property string $name
 * @property string $version
 * @property int $revision_number
 * @property int $lock_version
 * @property PriceListStatus $status
 * @property Carbon|null $valid_from
 * @property Carbon|null $published_at
 * @property Carbon|null $archived_at
 */
class OaTkpPriceList extends Model
{
    protected $fillable = [
        'year',
        'name',
        'version',
        'revision_number',
        'lock_version',
        'status',
        'valid_from',
        'published_at',
        'archived_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'revision_number' => 'integer',
            'lock_version' => 'integer',
            'status' => PriceListStatus::class,
            'valid_from' => 'date',
            'published_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<OaTkpPriceListItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(OaTkpPriceListItem::class, 'oa_tkp_price_list_id');
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', PriceListStatus::Active);
    }
}
