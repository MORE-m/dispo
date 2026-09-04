<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $field_set_version_id
 * @property int $field_definition_id
 * @property int $field_definition_revision_id
 * @property int $sort
 * @property bool|null $required_override
 * @property bool|null $visible_override
 */
class FieldSetVersionField extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'field_set_version_id',
        'field_definition_id',
        'field_definition_revision_id',
        'sort',
        'required_override',
        'visible_override',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'required_override' => 'boolean',
            'visible_override' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<FieldDefinition, $this>
     */
    public function definition(): BelongsTo
    {
        return $this->belongsTo(FieldDefinition::class, 'field_definition_id');
    }

    /**
     * @return BelongsTo<FieldDefinitionRevision, $this>
     */
    public function revision(): BelongsTo
    {
        return $this->belongsTo(FieldDefinitionRevision::class, 'field_definition_revision_id');
    }
}
