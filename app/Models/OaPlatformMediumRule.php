<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PO-CALC-TKP-1D4c-4j-1: Zulässigkeit einer OA-Plattform für ein OA-TKP-Kernmedium.
 *
 * Soft-Deactivate über {@see $is_active}. Kein Hard-Delete-Pfad in v1.
 * Operatives Enforcement erst ab D4c-4j-5 (Resolver).
 *
 * @property int $id
 * @property int $oa_platform_id
 * @property int $advertising_medium_id
 * @property bool $is_active
 */
class OaPlatformMediumRule extends Model
{
    protected $fillable = [
        'oa_platform_id',
        'advertising_medium_id',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<OaPlatform, $this>
     */
    public function oaPlatform(): BelongsTo
    {
        return $this->belongsTo(OaPlatform::class);
    }

    /**
     * @return BelongsTo<AdvertisingMedium, $this>
     */
    public function advertisingMedium(): BelongsTo
    {
        return $this->belongsTo(AdvertisingMedium::class);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
