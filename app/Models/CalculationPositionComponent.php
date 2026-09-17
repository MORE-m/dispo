<?php

namespace App\Models;

use App\Enums\SpotComponentRole;
use Database\Factories\CalculationPositionComponentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property SpotComponentRole $role
 * @property string $label
 * @property int $length_seconds
 * @property int $sort
 * @property int|null $length_index
 * @property string|null $media_gross
 */
class CalculationPositionComponent extends Model
{
    /** @use HasFactory<CalculationPositionComponentFactory> */
    use HasFactory;

    protected $fillable = [
        'calculation_position_id',
        'role',
        'label',
        'length_seconds',
        'sort',
        'length_index',
        'media_gross',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => SpotComponentRole::class,
            'length_seconds' => 'integer',
            'sort' => 'integer',
            'length_index' => 'integer',
            'media_gross' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<CalculationPosition, $this>
     */
    public function position(): BelongsTo
    {
        return $this->belongsTo(CalculationPosition::class, 'calculation_position_id');
    }
}
