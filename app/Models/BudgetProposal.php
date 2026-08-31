<?php

namespace App\Models;

use App\Enums\BudgetProposalStatus;
use App\Enums\BudgetStrategy;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $calculation_id
 * @property BudgetStrategy $strategy
 * @property string $target_budget_nn
 * @property BudgetProposalStatus $status
 * @property string|null $algorithm_version
 * @property string|null $input_fingerprint
 * @property CarbonImmutable|null $calculated_at
 * @property CarbonImmutable|null $applied_at
 * @property int|null $applied_by
 */
class BudgetProposal extends Model
{
    protected $fillable = [
        'calculation_id',
        'strategy',
        'status',
        'algorithm_version',
        'input_fingerprint',
        'target_budget_nn',
        'lock_version',
        'payload',
        'applied_at',
        'applied_by',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'strategy' => BudgetStrategy::class,
            'status' => BudgetProposalStatus::class,
            'target_budget_nn' => 'decimal:2',
            'payload' => 'array',
            'applied_at' => 'datetime',
            'calculated_at' => 'datetime',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function payloadArray(): array
    {
        /** @var array<string, mixed> $payload */
        $payload = $this->payload;

        return $payload;
    }

    /**
     * @return BelongsTo<Calculation, $this>
     */
    public function calculation(): BelongsTo
    {
        return $this->belongsTo(Calculation::class);
    }
}
