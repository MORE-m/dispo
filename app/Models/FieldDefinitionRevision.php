<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $field_definition_id
 * @property int $revision
 * @property string $label
 * @property string|null $help_text
 * @property array<string, mixed>|null $validation_json
 * @property string|null $group_key
 * @property int $sort_default
 * @property bool $reportable
 */
class FieldDefinitionRevision extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'field_definition_id',
        'revision',
        'label',
        'help_text',
        'validation_json',
        'group_key',
        'sort_default',
        'reportable',
        'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'validation_json' => 'array',
            'reportable' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<FieldDefinition, $this>
     */
    public function definition(): BelongsTo
    {
        return $this->belongsTo(FieldDefinition::class, 'field_definition_id');
    }
}
