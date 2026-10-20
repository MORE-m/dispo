<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * PO-CALC-TKP-1A: Adserver-TKP-Stammdatum (Scope flexibel; Formeleinbettung Phase C / FD).
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string $tkp_amount
 * @property int $revision_number
 * @property bool $is_active
 * @property array<string, mixed>|null $scope_json
 * @property Carbon|null $valid_from
 * @property Carbon|null $valid_to
 */
class OaAdserverSurcharge extends Model
{
    protected $fillable = [
        'code',
        'name',
        'tkp_amount',
        'revision_number',
        'is_active',
        'scope_json',
        'valid_from',
        'valid_to',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tkp_amount' => 'decimal:4',
            'revision_number' => 'integer',
            'is_active' => 'boolean',
            'scope_json' => 'array',
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
