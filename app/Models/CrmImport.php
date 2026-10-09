<?php

namespace App\Models;

use App\Enums\CrmImportStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $user_id
 * @property CrmImportStatus $status
 * @property string $original_filename
 * @property string $stored_path
 * @property string $checksum_sha256
 * @property array<string, mixed>|null $preview
 * @property array<string, mixed>|null $report
 * @property string|null $fingerprint
 * @property string|null $catalog_fingerprint
 * @property CarbonInterface|null $validated_at
 * @property CarbonInterface|null $applied_at
 * @property CarbonInterface|null $failed_at
 */
class CrmImport extends Model
{
    protected $fillable = [
        'user_id',
        'status',
        'original_filename',
        'stored_path',
        'checksum_sha256',
        'mime_type',
        'file_size',
        'row_count',
        'valid_row_count',
        'error_count',
        'warning_count',
        'preview',
        'report',
        'fingerprint',
        'catalog_fingerprint',
        'validated_at',
        'applied_at',
        'failed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => CrmImportStatus::class,
            'preview' => 'array',
            'report' => 'array',
            'validated_at' => 'datetime',
            'applied_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<CrmConflict, $this> */
    public function conflicts(): HasMany
    {
        return $this->hasMany(CrmConflict::class, 'crm_import_id');
    }
}
