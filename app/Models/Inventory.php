<?php

namespace App\Models;

use App\Enums\InventoryType;
use Database\Factories\InventoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $name
 * @property string $code
 */
class Inventory extends Model
{
    /** @use HasFactory<InventoryFactory> */
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'name',
        'code',
        'type',
        'is_active',
        'sort',
        'logo_path',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => InventoryType::class,
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return HasMany<InventoryMediumRule, $this>
     */
    public function mediumRules(): HasMany
    {
        return $this->hasMany(InventoryMediumRule::class);
    }

    /**
     * @return HasMany<PriceList, $this>
     */
    public function priceLists(): HasMany
    {
        return $this->hasMany(PriceList::class);
    }
}
