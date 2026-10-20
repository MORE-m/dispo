<?php

namespace App\Models;

use App\Enums\OaTargetingCategory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * PO-CALC-TKP-1A: Targeting-Stammdatum mit absolutem TKP-Aufschlag (EUR je 1000).
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property OaTargetingCategory $category
 * @property string $tkp_surcharge
 * @property int $revision_number
 * @property bool $is_active
 * @property Carbon|null $valid_from
 * @property Carbon|null $valid_to
 */
class OaTargetingDefinition extends Model
{
    protected $fillable = [
        'code',
        'name',
        'category',
        'tkp_surcharge',
        'revision_number',
        'is_active',
        'valid_from',
        'valid_to',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'category' => OaTargetingCategory::class,
            'tkp_surcharge' => 'decimal:4',
            'revision_number' => 'integer',
            'is_active' => 'boolean',
            'valid_from' => 'date',
            'valid_to' => 'date',
        ];
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
