<?php

namespace App\Models;

use App\Enums\DispoOrderApprovalKind;
use App\Enums\DispoOrderApprovalStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * @property int $id
 * @property int $dispo_order_id
 * @property int $cycle_number
 * @property DispoOrderApprovalStatus $status
 * @property DispoOrderApprovalKind $kind
 * @property array<int, array<string, mixed>>|null $special_approval_reasons
 * @property int $submitted_by_id
 * @property string $submitted_by_name
 * @property CarbonImmutable $submitted_at
 * @property int|null $decided_by_id
 * @property string|null $decided_by_name
 * @property CarbonImmutable|null $decided_at
 * @property string|null $rejection_reason
 * @property string|null $decision_note
 * @property bool $customer_confirmation_without_upload
 * @property string|null $customer_confirmation_exception_reason
 * @property int|null $customer_confirmation_exception_set_by_id
 * @property string|null $customer_confirmation_exception_set_by_name
 * @property CarbonImmutable|null $customer_confirmation_exception_set_at
 * @property bool $customer_confirmation_exception_acknowledged
 * @property int|null $customer_confirmation_exception_acknowledged_by_id
 * @property string|null $customer_confirmation_exception_acknowledged_by_name
 * @property CarbonImmutable|null $customer_confirmation_exception_acknowledged_at
 * @property int $submitted_lock_version
 * @property int|null $open_guard
 */
class DispoOrderApprovalRequest extends Model
{
    protected $fillable = [
        'dispo_order_id',
        'cycle_number',
        'status',
        'kind',
        'special_approval_reasons',
        'submitted_by_id',
        'submitted_by_name',
        'submitted_at',
        'decided_by_id',
        'decided_by_name',
        'decided_at',
        'rejection_reason',
        'decision_note',
        'submitted_lock_version',
        'open_guard',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => DispoOrderApprovalStatus::class,
            'kind' => DispoOrderApprovalKind::class,
            'special_approval_reasons' => 'array',
            'submitted_at' => 'datetime',
            'decided_at' => 'datetime',
            'customer_confirmation_without_upload' => 'boolean',
            'customer_confirmation_exception_set_by_id' => 'integer',
            'customer_confirmation_exception_set_at' => 'datetime',
            'customer_confirmation_exception_acknowledged' => 'boolean',
            'customer_confirmation_exception_acknowledged_by_id' => 'integer',
            'customer_confirmation_exception_acknowledged_at' => 'datetime',
            'submitted_lock_version' => 'integer',
            'cycle_number' => 'integer',
            'open_guard' => 'integer',
        ];
    }

    public function hasCustomerConfirmationExceptionSnapshot(): bool
    {
        if (! (bool) $this->customer_confirmation_without_upload) {
            return false;
        }

        $reason = $this->customer_confirmation_exception_reason;

        return is_string($reason) && trim($reason) !== '';
    }

    protected static function booted(): void
    {
        static::updating(function (self $request): void {
            $original = $request->getOriginal('status');
            $originalValue = $original instanceof DispoOrderApprovalStatus
                ? $original->value
                : (string) $original;

            if ($originalValue !== DispoOrderApprovalStatus::Pending->value) {
                throw new LogicException('Entschiedene Freigabeanforderungen sind unveränderbar.');
            }
        });

        static::deleting(function (): never {
            throw new LogicException('Freigabeanforderungen dürfen nicht gelöscht werden.');
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
    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_id');
    }

    public function isPending(): bool
    {
        return $this->status === DispoOrderApprovalStatus::Pending;
    }
}
