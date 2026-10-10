<?php

namespace App\Models;

use App\Enums\CrmAccountVersionSource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int<1, max> $id
 * @property int<1, max> $crm_account_id
 * @property int $version_number
 * @property string $name
 * @property string|null $billing_email
 * @property string|null $matching_domain
 * @property string|null $meridian_number
 * @property CrmAccountVersionSource $source
 */
class CrmAccountVersion extends Model
{
    protected $fillable = [
        'crm_account_id',
        'version_number',
        'name',
        'billing_email',
        'matching_domain',
        'meridian_number',
        'source',
        'created_by_id',
        'crm_import_id',
    ];

    protected function casts(): array
    {
        return [
            'source' => CrmAccountVersionSource::class,
            'version_number' => 'integer',
        ];
    }

    /** @return BelongsTo<CrmAccount, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(CrmAccount::class, 'crm_account_id');
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }
}
