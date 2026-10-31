<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * PO-CALC-TKP-1A: Online-Audio-Plattform-Stammdatum (Mengenverteilung, nicht Preis-Key).
 *
 * Ab D4c-4j-1: Medium-Zuordnungen über {@see OaPlatformMediumRule} (noch ohne Writer-Enforce).
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property bool $is_active
 * @property int $sort
 * @property bool $is_adserver_relevant
 */
class OaPlatform extends Model
{
    protected $fillable = [
        'code',
        'name',
        'is_active',
        'sort',
        'is_adserver_relevant',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort' => 'integer',
            'is_adserver_relevant' => 'boolean',
        ];
    }

    /**
     * @return HasMany<OaPlatformMediumRule, $this>
     */
    public function mediumRules(): HasMany
    {
        return $this->hasMany(OaPlatformMediumRule::class);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)->orderBy('sort')->orderBy('code');
    }
}
