<?php

namespace App\Models;

use App\Enums\DispoOrderUploadCategory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Privater Dispo-Upload (BL-P9-01a). Kein Hard-Delete; Archivierung über archived_at.
 *
 * @property int $id
 * @property int $dispo_order_id
 * @property DispoOrderUploadCategory $category
 * @property string $original_filename
 * @property string $storage_path
 * @property string|null $mime_type
 * @property int $size_bytes
 * @property string $sha256
 * @property int $uploaded_by_user_id
 * @property string $uploaded_by_name_snapshot
 * @property Carbon $uploaded_at
 * @property Carbon|null $archived_at
 * @property int|null $archived_by_user_id
 * @property string|null $archived_by_name_snapshot
 * @property string|null $field_key
 * @property string|null $field_label_snapshot
 * @property int|null $snapshot_field_definition_id
 * @property int|null $dispo_order_position_id
 * @property string|null $position_label_snapshot
 */
class DispoOrderUpload extends Model
{
    protected $fillable = [
        'dispo_order_id',
        'category',
        'field_key',
        'field_label_snapshot',
        'snapshot_field_definition_id',
        'dispo_order_position_id',
        'position_label_snapshot',
        'original_filename',
        'storage_path',
        'mime_type',
        'size_bytes',
        'sha256',
        'uploaded_by_user_id',
        'uploaded_by_name_snapshot',
        'uploaded_at',
        'archived_at',
        'archived_by_user_id',
        'archived_by_name_snapshot',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'category' => DispoOrderUploadCategory::class,
            'size_bytes' => 'integer',
            'uploaded_by_user_id' => 'integer',
            'uploaded_at' => 'datetime',
            'archived_at' => 'datetime',
            'archived_by_user_id' => 'integer',
            'snapshot_field_definition_id' => 'integer',
            'dispo_order_position_id' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<SnapshotFieldDefinition, $this>
     */
    public function snapshotFieldDefinition(): BelongsTo
    {
        return $this->belongsTo(SnapshotFieldDefinition::class);
    }

    /**
     * @return BelongsTo<DispoOrderPosition, $this>
     */
    public function dispoOrderPosition(): BelongsTo
    {
        return $this->belongsTo(DispoOrderPosition::class);
    }

    /**
     * @return BelongsTo<DispoOrder, $this>
     */
    public function dispoOrder(): BelongsTo
    {
        return $this->belongsTo(DispoOrder::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function archivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'archived_by_user_id');
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    public function isActive(): bool
    {
        return ! $this->isArchived();
    }
}
