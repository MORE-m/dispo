<?php

namespace App\Models;

use App\Enums\PriceListImportStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property int $year
 * @property PriceListImportStatus $status
 * @property string $original_filename
 * @property string $stored_path
 * @property string $checksum_sha256
 * @property string|null $mime_type
 * @property int $file_size
 * @property array<string, mixed>|null $report
 * @property string|null $fingerprint
 * @property list<int>|null $created_price_list_ids
 * @property \Carbon\CarbonInterface|null $validated_at
 * @property \Carbon\CarbonInterface|null $confirmed_at
 * @property \Carbon\CarbonInterface|null $completed_at
 * @property \Carbon\CarbonInterface|null $failed_at
 */
class PriceListImport extends Model
{
    protected $fillable = [
        'user_id',
        'year',
        'status',
        'original_filename',
        'stored_path',
        'checksum_sha256',
        'mime_type',
        'file_size',
        'sheet_count',
        'row_count',
        'valid_row_count',
        'error_count',
        'warning_count',
        'report',
        'fingerprint',
        'created_price_list_ids',
        'validated_at',
        'confirmed_at',
        'completed_at',
        'failed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'status' => PriceListImportStatus::class,
            'file_size' => 'integer',
            'sheet_count' => 'integer',
            'row_count' => 'integer',
            'valid_row_count' => 'integer',
            'error_count' => 'integer',
            'warning_count' => 'integer',
            'report' => 'array',
            'created_price_list_ids' => 'array',
            'validated_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'completed_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
