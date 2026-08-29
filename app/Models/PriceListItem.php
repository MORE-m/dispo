<?php

namespace App\Models;

use App\Enums\DayGroup;
use Database\Factories\PriceListItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property DayGroup $day_group
 * @property int $hour
 * @property string $second_price
 */
class PriceListItem extends Model
{
    /** @use HasFactory<PriceListItemFactory> */
    use HasFactory;

    protected $fillable = [
        'price_list_id',
        'hour',
        'day_group',
        'second_price',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'hour' => 'integer',
            'day_group' => DayGroup::class,
            'second_price' => 'decimal:4',
        ];
    }

    /**
     * @return BelongsTo<PriceList, $this>
     */
    public function priceList(): BelongsTo
    {
        return $this->belongsTo(PriceList::class);
    }
}
