<?php

namespace App\Models;

use App\Enums\DispoOrderApprovalKind;
use App\Enums\DispoOrderStatus;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int $id
 * @property int $calculation_id
 * @property string $number
 * @property int $number_year
 * @property int $number_org_seq
 * @property int $number_calc_seq
 * @property DispoOrderStatus $status
 * @property int $created_by_id
 * @property string $source_calculation_number
 * @property DispoOrderApprovalKind $approval_kind
 * @property array<int, array<string, mixed>>|null $special_approval_reasons
 * @property-read Collection<int, DispoOrderPosition> $positions
 * @property-read Collection<int, DispoOrderApprovalRequest> $approvalRequests
 */
class DispoOrder extends Model
{
    protected $fillable = [
        'calculation_id',
        'number',
        'number_year',
        'number_org_seq',
        'number_calc_seq',
        'status',
        'created_by_id',
        'source_calculation_number',
        'customer_name',
        'agency_name',
        'campaign',
        'product_title',
        'briefing',
        'advisor_id',
        'advisor_name',
        'order_discount_percent',
        'ae_enabled',
        'target_budget_nn',
        'media_gross',
        'position_discount_total',
        'order_discount_total',
        'ae_total',
        'nn_invest',
        'requires_special_approval',
        'approval_kind',
        'special_approval_reasons',
        'order_discounts_snapshot',
        'source_calculation_totals_snapshot',
        'lock_version',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => DispoOrderStatus::class,
            'order_discount_percent' => 'decimal:4',
            'ae_enabled' => 'boolean',
            'target_budget_nn' => 'decimal:2',
            'media_gross' => 'decimal:2',
            'position_discount_total' => 'decimal:2',
            'order_discount_total' => 'decimal:2',
            'ae_total' => 'decimal:2',
            'nn_invest' => 'decimal:2',
            'requires_special_approval' => 'boolean',
            'approval_kind' => DispoOrderApprovalKind::class,
            'special_approval_reasons' => 'array',
            'order_discounts_snapshot' => 'array',
            'source_calculation_totals_snapshot' => 'array',
            'lock_version' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Calculation, $this>
     */
    public function calculation(): BelongsTo
    {
        return $this->belongsTo(Calculation::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function advisor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'advisor_id');
    }

    /**
     * @return HasMany<DispoOrderPosition, $this>
     */
    public function positions(): HasMany
    {
        return $this->hasMany(DispoOrderPosition::class)->orderBy('sort')->orderBy('id');
    }

    /**
     * @return HasMany<DispoOrderApprovalRequest, $this>
     */
    public function approvalRequests(): HasMany
    {
        return $this->hasMany(DispoOrderApprovalRequest::class)->orderBy('cycle_number')->orderBy('id');
    }

    /**
     * @return HasOne<DispoOrderApprovalRequest, $this>
     */
    public function latestApprovalRequest(): HasOne
    {
        return $this->hasOne(DispoOrderApprovalRequest::class)->latestOfMany('cycle_number');
    }

    /**
     * @return HasOne<DispoOrderApprovalRequest, $this>
     */
    public function pendingApprovalRequest(): HasOne
    {
        return $this->hasOne(DispoOrderApprovalRequest::class)
            ->where('status', 'pending')
            ->where('open_guard', 1);
    }

    public function requiresSpecialApproval(): bool
    {
        return $this->approval_kind === DispoOrderApprovalKind::Special
            || (bool) $this->requires_special_approval;
    }
}
