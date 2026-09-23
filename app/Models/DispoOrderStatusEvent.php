<?php

namespace App\Models;

use App\Enums\DispoOrderStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Append-only Statushistorie für operative Dispo-Übergänge (BL-P8-02a).
 *
 * @property int $id
 * @property int $dispo_order_id
 * @property DispoOrderStatus $from_status
 * @property DispoOrderStatus $to_status
 * @property int $changed_by_id
 * @property string $changed_by_name
 * @property CarbonImmutable $changed_at
 * @property string|null $reason
 * @property bool $is_reopen
 * @property int $lock_version_after
 */
class DispoOrderStatusEvent extends Model
{
    protected $fillable = [
        'dispo_order_id',
        'from_status',
        'to_status',
        'changed_by_id',
        'changed_by_name',
        'changed_at',
        'reason',
        'is_reopen',
        'lock_version_after',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'from_status' => DispoOrderStatus::class,
            'to_status' => DispoOrderStatus::class,
            'changed_at' => 'datetime',
            'is_reopen' => 'boolean',
            'lock_version_after' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Statusereignisse sind unveränderbar.');
        });

        static::deleting(function (): never {
            throw new LogicException('Statusereignisse dürfen nicht gelöscht werden.');
        });
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
    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by_id');
    }
}
