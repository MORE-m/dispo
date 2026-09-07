<?php

namespace App\Models;

use Database\Factories\AdvertisingCategoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ADV-001a: Oberkategorie (Stammdatenbasis; Defaults/Assignments folgen später).
 *
 * @property string $key
 * @property string $name
 * @property bool $is_active
 * @property int $sort
 */
class AdvertisingCategory extends Model
{
    /** @use HasFactory<AdvertisingCategoryFactory> */
    use HasFactory;

    protected $fillable = [
        'key',
        'name',
        'is_active',
        'sort',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort' => 'integer',
        ];
    }

    /**
     * @return HasMany<AdvertisingMedium, $this>
     */
    public function advertisingMedia(): HasMany
    {
        return $this->hasMany(AdvertisingMedium::class, 'category_id');
    }
}
