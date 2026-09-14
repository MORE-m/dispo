<?php

namespace App\Models;

use App\Enums\PriceListStatus;
use Database\Factories\PriceListFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $inventory_id
 * @property int $year
 * @property int $revision_number
 * @property int $lock_version
 * @property string $version
 * @property PriceListStatus $status
 * @property Carbon|null $valid_from
 */
class PriceList extends Model
{
    /** @use HasFactory<PriceListFactory> */
    use HasFactory;

    protected $fillable = [
        'inventory_id',
        'name',
        'year',
        'version',
        'revision_number',
        'status',
        'valid_from',
        'lock_version',
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
     * @return BelongsTo<Inventory, $this>
     */
    public function inventory(): BelongsTo
    {
        return $this->belongsTo(Inventory::class);
    }

    /**
     * @return HasMany<PriceListItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(PriceListItem::class);
    }
}
