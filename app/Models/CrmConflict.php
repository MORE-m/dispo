<?php

namespace App\Models;

use App\Enums\CrmConflictType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int|null $crm_account_id
 * @property int|null $crm_import_id
 * @property CrmConflictType $type
 * @property string $status
 * @property array<string, mixed> $details
 */
class CrmConflict extends Model
{
    protected $fillable = [
        'crm_account_id',
        'crm_import_id',
        'type',
        'status',
        'details',
        'resolved_by_id',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => CrmConflictType::class,
            'details' => 'array',
            'resolved_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<CrmAccount, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(CrmAccount::class, 'crm_account_id');
    }

    /** @return BelongsTo<CrmImport, $this> */
    public function import(): BelongsTo
    {
        return $this->belongsTo(CrmImport::class, 'crm_import_id');
    }
}
