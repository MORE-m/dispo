<?php

namespace App\Models;

use App\Enums\FieldSetVersionStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $field_set_id
 * @property int $version
 * @property FieldSetVersionStatus $status
 * @property CarbonInterface|null $created_at
 */
class FieldSetVersion extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'field_set_id',
        'version',
        'status',
        'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => FieldSetVersionStatus::class,
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<FieldSet, $this>
     */
    public function fieldSet(): BelongsTo
    {
        return $this->belongsTo(FieldSet::class);
    }

    /**
     * @return HasMany<FieldSetVersionField, $this>
     */
    public function fields(): HasMany
    {
        return $this->hasMany(FieldSetVersionField::class)->orderBy('sort');
    }

    /**
     * @return HasMany<FieldRule, $this>
     */
    public function rules(): HasMany
    {
        return $this->hasMany(FieldRule::class)->orderBy('sort');
    }
}
