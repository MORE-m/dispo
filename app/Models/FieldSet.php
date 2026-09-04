<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $key
 * @property string $name
 * @property int|null $active_version_id
 */
class FieldSet extends Model
{
    protected $fillable = [
        'key',
        'name',
        'active_version_id',
    ];

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
