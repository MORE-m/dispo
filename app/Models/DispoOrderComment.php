<?php

namespace App\Models;

use App\Enums\DispoOrderCommentType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

/**
 * Append-only Kommunikationsfundament (BL-P8-02b / CMT-003; BL-P9-02a / CMT-001).
 * Typen: general, sales_inquiry, sales_inquiry_response. Unveränderbar (CMT-002).
 *
 * @property int $id
 * @property int $dispo_order_id
 * @property DispoOrderCommentType $type
 * @property string $body
 * @property int $created_by_id
 * @property string $created_by_name
 * @property int|null $parent_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class DispoOrderComment extends Model
{
    protected $fillable = [
        'dispo_order_id',
        'type',
        'body',
        'created_by_id',
        'created_by_name',
        'parent_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => DispoOrderCommentType::class,
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Kommunikationseinträge sind unveränderbar.');
        });

        static::deleting(function (): never {
            throw new LogicException('Kommunikationseinträge dürfen nicht gelöscht werden.');
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
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    /**
     * @return BelongsTo<DispoOrderComment, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasOne<DispoOrderComment, $this>
     */
    public function response(): HasOne
    {
        return $this->hasOne(self::class, 'parent_id');
    }

    public function isOpenSalesInquiry(): bool
    {
        return $this->type === DispoOrderCommentType::SalesInquiry
            && $this->response()->doesntExist();
    }
}
