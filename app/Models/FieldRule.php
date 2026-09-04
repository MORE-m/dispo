<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $field_set_version_id
 * @property int $sort
 * @property array<string, mixed> $condition_json
 * @property array<string, mixed> $action_json
 */
class FieldRule extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'field_set_version_id',
        'sort',
        'condition_json',
        'action_json',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'condition_json' => 'array',
            'action_json' => 'array',
        ];
    }

    /**
     * @return BelongsTo<FieldSetVersion, $this>
     */
    public function fieldSetVersion(): BelongsTo
    {
        return $this->belongsTo(FieldSetVersion::class);
    }
}
