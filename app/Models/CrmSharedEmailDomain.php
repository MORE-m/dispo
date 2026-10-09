<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $domain
 * @property bool $is_active
 */
class CrmSharedEmailDomain extends Model
{
    protected $fillable = ['domain', 'is_active'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }
}
