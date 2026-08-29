<?php

namespace App\Models;

use App\Enums\BudgetStrategy;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $calculation_id
 * @property BudgetStrategy $strategy
 * @property string $target_budget_nn
 * @property array<string, mixed> $payload
 * @property CarbonImmutable|null $applied_at
 * @property int|null $applied_by
 */
class BudgetProposal extends Model
{
    protected $fillable = [
        'calculation_id',
        'strategy',
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
            'target_budget_nn' => 'decimal:2',
            'payload' => 'array',
            'applied_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Calculation, $this>
     */
    public function calculation(): BelongsTo
    {
        return $this->belongsTo(Calculation::class);
    }
}
