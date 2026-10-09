<?php

namespace App\Models;

use App\Enums\CrmAccountType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property CrmAccountType $type
 * @property string|null $salesforce_account_id_raw
 * @property string|null $salesforce_account_id_canonical
 * @property bool $is_provisional
 * @property string|null $matching_domain
 * @property int|null $current_version_id
 * @property int|null $merged_into_account_id
 * @property int $lock_version
 */
class CrmAccount extends Model
{
    protected $fillable = [
        'type',
        'salesforce_account_id_raw',
        'salesforce_account_id_canonical',
        'is_provisional',
        'matching_domain',
        'current_version_id',
        'merged_into_account_id',
        'lock_version',
    ];

    protected function casts(): array
    {
        return [
            'type' => CrmAccountType::class,
            'is_provisional' => 'boolean',
            'lock_version' => 'integer',
        ];
    }

    /** @return BelongsTo<CrmAccountVersion, $this> */
    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(CrmAccountVersion::class, 'current_version_id');
    }

    /** @return HasMany<CrmAccountVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(CrmAccountVersion::class, 'crm_account_id')->orderBy('version_number');
    }

    /** @return BelongsTo<CrmAccount, $this> */
    public function mergedInto(): BelongsTo
    {
        return $this->belongsTo(self::class, 'merged_into_account_id');
    }

    /** @return HasMany<CrmConflict, $this> */
    public function conflicts(): HasMany
    {
        return $this->hasMany(CrmConflict::class, 'crm_account_id');
    }

    public function isLinkedToSalesforce(): bool
    {
        return $this->salesforce_account_id_canonical !== null && $this->merged_into_account_id === null;
    }
}
