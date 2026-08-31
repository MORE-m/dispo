<?php

namespace App\Models;

use App\Enums\BudgetStrategy;
use App\Enums\CalculationStatus;
use App\Enums\PlanningMode;
use Database\Factories\CalculationFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $number
 * @property int $number_year
 * @property int $number_seq
 * @property CalculationStatus $status
 * @property PlanningMode $planning_mode
 * @property int $advisor_id
 * @property string|null $customer_name
 * @property string|null $agency_name
 * @property string|null $campaign
 * @property string|null $product_title
 * @property string|null $briefing
 * @property string $order_discount_percent
 * @property bool $ae_enabled
 * @property-read Collection<int, CalculationOrderDiscount> $orderDiscounts
 * @property string|null $target_budget_nn
 * @property BudgetStrategy|null $budget_strategy
 * @property string $media_gross
 * @property string $position_discount_total
 * @property string $order_discount_total
 * @property string $ae_total
 * @property string $nn_invest
 * @property bool $requires_special_approval
 * @property int $lock_version
 */
class Calculation extends Model
{
    /** @use HasFactory<CalculationFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'number',
        'number_year',
        'number_seq',
        'status',
        'planning_mode',
        'advisor_id',
        'customer_name',
        'agency_name',
        'campaign',
        'product_title',
        'briefing',
        'order_discount_percent',
        'ae_enabled',
        'target_budget_nn',
        'budget_strategy',
        'media_gross',
        'position_discount_total',
        'order_discount_total',
        'ae_total',
        'nn_invest',
        'requires_special_approval',
        'lock_version',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => CalculationStatus::class,
            'planning_mode' => PlanningMode::class,
            'budget_strategy' => BudgetStrategy::class,
            'order_discount_percent' => 'decimal:4',
            'ae_enabled' => 'boolean',
            'target_budget_nn' => 'decimal:2',
            'media_gross' => 'decimal:2',
            'position_discount_total' => 'decimal:2',
            'order_discount_total' => 'decimal:2',
            'ae_total' => 'decimal:2',
            'nn_invest' => 'decimal:2',
            'requires_special_approval' => 'boolean',
            'lock_version' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function advisor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'advisor_id');
    }

    /**
     * @return HasMany<CalculationPosition, $this>
     */
    public function positions(): HasMany
    {
        return $this->hasMany(CalculationPosition::class)->orderBy('sort');
    }

    /**
     * @return HasMany<BudgetProposal, $this>
     */
    public function budgetProposals(): HasMany
    {
        return $this->hasMany(BudgetProposal::class);
    }

    /**
     * @return HasMany<CalculationOrderDiscount, $this>
     */
    public function orderDiscounts(): HasMany
    {
        return $this->hasMany(CalculationOrderDiscount::class)->orderBy('sort')->orderBy('id');
    }
}
