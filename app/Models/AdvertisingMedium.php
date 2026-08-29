<?php

namespace App\Models;

use App\Enums\CalculationKind;
use Database\Factories\AdvertisingMediumFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property CalculationKind $kind
 * @property int $default_length_seconds
 * @property bool $is_discountable
 * @property bool $is_ae_eligible
 */
class AdvertisingMedium extends Model
{
    /** @use HasFactory<AdvertisingMediumFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'code',
        'kind',
        'default_length_seconds',
        'is_discountable',
        'is_ae_eligible',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => CalculationKind::class,
            'is_discountable' => 'boolean',
            'is_ae_eligible' => 'boolean',
            'is_active' => 'boolean',
        ];
    }
}
