<?php

namespace App\Models;

use App\Enums\DerivedCampaignPeriodStatus;
use App\Enums\DispoOrderApprovalKind;
use App\Enums\DispoOrderStatus;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $calculation_id
 * @property int|null $revises_dispo_order_id
 * @property string $number
 * @property int $number_year
 * @property int $number_org_seq
 * @property int $number_calc_seq
 * @property DispoOrderStatus $status
 * @property int $created_by_id
 * @property string $source_calculation_number
 * @property DispoOrderApprovalKind $approval_kind
 * @property array<int, array<string, mixed>>|null $special_approval_reasons
 * @property int $configuration_snapshot_id
 * @property string|null $derived_campaign_period_start
 * @property string|null $derived_campaign_period_end
 * @property DerivedCampaignPeriodStatus $derived_campaign_period_status
 * @property Carbon|null $derived_campaign_period_at
 * @property array<string, mixed>|null $derived_campaign_period_snapshot
 * @property bool $customer_confirmation_without_upload
 * @property string|null $customer_confirmation_exception_reason
 * @property int|null $customer_confirmation_exception_set_by_id
 * @property string|null $customer_confirmation_exception_set_by_name
 * @property Carbon|null $customer_confirmation_exception_set_at
 * @property-read Collection<int, DispoOrderPosition> $positions
 * @property-read Collection<int, DispoOrderApprovalRequest> $approvalRequests
 * @property-read Collection<int, DispoOrderStatusEvent> $statusEvents
 * @property-read Collection<int, DispoOrderComment> $comments
 * @property-read Collection<int, DispoOrderFieldValue> $fieldValues
 * @property-read ConfigurationSnapshot $configurationSnapshot
 * @property-read DispoOrder|null $revises
 * @property-read DispoOrder|null $revision
 */
class DispoOrder extends Model
{
    protected $fillable = [
        'calculation_id',
        'revises_dispo_order_id',
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
        'configuration_snapshot_id',
        // derived_campaign_period_* bewusst nicht fillable (nur Deriver).
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => DispoOrderStatus::class,
            'revises_dispo_order_id' => 'integer',
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
            'derived_campaign_period_start' => 'date:Y-m-d',
            'derived_campaign_period_end' => 'date:Y-m-d',
            'derived_campaign_period_status' => DerivedCampaignPeriodStatus::class,
            'derived_campaign_period_at' => 'datetime',
            'derived_campaign_period_snapshot' => 'array',
            'customer_confirmation_without_upload' => 'boolean',
            'customer_confirmation_exception_set_by_id' => 'integer',
            'customer_confirmation_exception_set_at' => 'datetime',
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
     * @return BelongsTo<ConfigurationSnapshot, $this>
     */
    public function configurationSnapshot(): BelongsTo
    {
        return $this->belongsTo(ConfigurationSnapshot::class);
    }

    /**
     * @return HasMany<DispoOrderFieldValue, $this>
     */
    public function fieldValues(): HasMany
    {
        return $this->hasMany(DispoOrderFieldValue::class);
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
     * @return HasMany<DispoOrderStatusEvent, $this>
     */
    public function statusEvents(): HasMany
    {
        return $this->hasMany(DispoOrderStatusEvent::class)->orderBy('id');
    }

    /**
     * Append-only Kommunikationshistorie (allgemeine Kommentare + Rückfragen/Antworten).
     *
     * @return HasMany<DispoOrderComment, $this>
     */
    public function comments(): HasMany
    {
        return $this->hasMany(DispoOrderComment::class)->orderBy('id');
    }

    /**
     * @return HasMany<DispoOrderUpload, $this>
     */
    public function uploads(): HasMany
    {
        return $this->hasMany(DispoOrderUpload::class)->orderByDesc('uploaded_at')->orderByDesc('id');
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

    /**
     * Abgelehnter Vorgänger, den dieser Auftrag nachbessert.
     *
     * @return BelongsTo<DispoOrder, $this>
     */
    public function revises(): BelongsTo
    {
        return $this->belongsTo(DispoOrder::class, 'revises_dispo_order_id');
    }

    /**
     * Direkter Nachfolger (korrigierter Draft) dieses Auftrags.
     *
     * @return HasOne<DispoOrder, $this>
     */
    public function revision(): HasOne
    {
        return $this->hasOne(DispoOrder::class, 'revises_dispo_order_id');
    }

    public function requiresSpecialApproval(): bool
    {
        return $this->approval_kind === DispoOrderApprovalKind::Special
            || (bool) $this->requires_special_approval;
    }

    public function isApprovalRejected(): bool
    {
        return $this->status === DispoOrderStatus::ApprovalRejected;
    }

    public function hasRevision(): bool
    {
        if ($this->relationLoaded('revision')) {
            return $this->revision !== null;
        }

        return $this->revision()->exists();
    }
}
