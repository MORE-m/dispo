<?php

namespace App\Http\Controllers\Administration;

use App\Enums\ComponentCalculationStrategy;
use App\Http\Controllers\Controller;
use App\Models\Inventory;
use App\Models\InventoryMediumRule;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * BL-P4-02c: Admin-Strategie je Inventar-/Werbemedium-Regel (SPT-014).
 */
class InventoryMediumRuleAdminController extends Controller
{
    public function update(
        Request $request,
        Inventory $inventory,
        InventoryMediumRule $rule,
        AuditLogger $audit,
    ): JsonResponse {
        $this->authorize('access-administration');

        if ((int) $rule->inventory_id !== (int) $inventory->id) {
            throw ValidationException::withMessages([
                'rule' => 'Die Regel gehört nicht zu diesem Inventar.',
            ]);
        }

        $validated = $request->validate([
            'component_calculation_strategy' => ['required', 'string', Rule::enum(ComponentCalculationStrategy::class)],
        ]);

        $before = $rule->component_calculation_strategy->value;

        $rule->component_calculation_strategy = ComponentCalculationStrategy::from(
            (string) $validated['component_calculation_strategy'],
        );
        $rule->save();

        $audit->record(
            $rule,
            'inventory_medium_rule.component_strategy_updated',
            $request->user(),
            ['component_calculation_strategy' => $before],
            ['component_calculation_strategy' => $rule->component_calculation_strategy->value],
        );

        return response()->json([
            'rule' => [
                'id' => $rule->id,
                'inventory_id' => $rule->inventory_id,
                'advertising_medium_id' => $rule->advertising_medium_id,
                'component_calculation_strategy' => $rule->component_calculation_strategy->value,
                'component_calculation_strategy_label' => $rule->component_calculation_strategy->label(),
            ],
        ]);
    }
}
