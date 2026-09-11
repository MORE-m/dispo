<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * DF-3-REST-A: versionierte Auswahloption einer FieldDefinitionRevision.
 *
 * @property int $id
 * @property int $field_definition_revision_id
 * @property string $key
 * @property string $label
 * @property int $sort
 * @property bool $is_active
 */
class FieldDefinitionRevisionOption extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'field_definition_revision_id',
        'key',
        'label',
        'sort',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sort' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<FieldDefinitionRevision, $this>
     */
    public function revision(): BelongsTo
    {
        return $this->belongsTo(FieldDefinitionRevision::class, 'field_definition_revision_id');
    }
}
