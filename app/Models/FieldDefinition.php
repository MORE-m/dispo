<?php

namespace App\Models;

use App\Enums\FieldAppliesTo;
use App\Enums\FieldScope;
use App\Enums\FieldType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $key
 * @property FieldType $field_type
 * @property bool $is_system
 * @property bool $is_key_protected
 * @property FieldScope $scope
 * @property FieldAppliesTo $applies_to
 * @property int|null $current_revision_id
 */
class FieldDefinition extends Model
{
    protected $fillable = [
        'key',
        'field_type',
        'is_system',
        'is_key_protected',
        'scope',
        'applies_to',
        'current_revision_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'field_type' => FieldType::class,
            'is_system' => 'boolean',
            'is_key_protected' => 'boolean',
            'scope' => FieldScope::class,
            'applies_to' => FieldAppliesTo::class,
        ];
    }

    /**
     * @return BelongsTo<FieldDefinitionRevision, $this>
     */
    public function currentRevision(): BelongsTo
    {
        return $this->belongsTo(FieldDefinitionRevision::class, 'current_revision_id');
    }

    /**
     * @return HasMany<FieldDefinitionRevision, $this>
     */
    public function revisions(): HasMany
    {
        return $this->hasMany(FieldDefinitionRevision::class);
    }
}
