<?php

namespace App\Models;

use App\Enums\InventoryType;
use Database\Factories\InventoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $organization_id
 * @property string $name
 * @property string $code
 * @property InventoryType $type
 * @property bool $is_active
 * @property int $sort
 * @property string|null $logo_path
 * @property int $lock_version
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
            'lock_version' => 'integer',
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

    /**
     * @return HasMany<CalculationPosition, $this>
     */
    public function calculationPositions(): HasMany
    {
        return $this->hasMany(CalculationPosition::class);
    }

    /**
     * @return HasMany<DispoOrderPosition, $this>
     */
    public function dispoOrderPositions(): HasMany
    {
        return $this->hasMany(DispoOrderPosition::class);
    }
}
