<?php

namespace App\Models;

use Database\Factories\CalculationMethodFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ADV-001c1: fachliche Berechnungsmethode (systemseitig geseedet; Keys immutable).
 *
 * @property string $key
 * @property string $name
 * @property string|null $help_text
 * @property int $sort
 * @property bool $is_active
 * @property int $lock_version
 */
class CalculationMethod extends Model
{
    /** @use HasFactory<CalculationMethodFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'help_text',
        'sort',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort' => 'integer',
            'lock_version' => 'integer',
        ];
    }

    /**
     * @return HasMany<AdvertisingCategoryCalculationMethod, $this>
     */
    public function categoryAssignments(): HasMany
    {
        return $this->hasMany(AdvertisingCategoryCalculationMethod::class);
    }

    /**
     * @return HasMany<AdvertisingMediumCalculationMethod, $this>
     */
    public function mediumAssignments(): HasMany
    {
        return $this->hasMany(AdvertisingMediumCalculationMethod::class);
    }
}
