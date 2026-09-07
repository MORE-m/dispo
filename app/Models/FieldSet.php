<?php

namespace App\Models;

use App\Enums\FieldAppliesTo;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $key
 * @property string $name
 * @property bool $is_system
 * @property FieldAppliesTo $applies_to
 * @property bool $is_assignable
 * @property int|null $active_version_id
 * @property int $lock_version
 */
class FieldSet extends Model
{
    protected $fillable = [
        'name',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
            'applies_to' => FieldAppliesTo::class,
            'is_assignable' => 'boolean',
            'lock_version' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<FieldSetVersion, $this>
     */
    public function activeVersion(): BelongsTo
    {
        return $this->belongsTo(FieldSetVersion::class, 'active_version_id');
    }

    /**
     * @return HasMany<FieldSetVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(FieldSetVersion::class);
    }
}
