<?php

namespace App\Http\Controllers\Administration;

use App\Enums\ComponentCalculationStrategy;
use App\Exceptions\CatalogAdminConflictException;
use App\Http\Controllers\Controller;
use App\Models\Inventory;
use App\Models\InventoryMediumRule;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * BL-P4-02c: Admin-Strategie je Inventar-/Werbemedium-Regel (SPT-014 Teil).
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
            'lock_version' => ['required', 'integer', 'min:1'],
        ]);

        $result = DB::transaction(function () use ($request, $inventory, $rule, $audit, $validated): array {
            /** @var Inventory $lockedInventory */
            $lockedInventory = Inventory::query()->whereKey($inventory->id)->lockForUpdate()->firstOrFail();

            if ((int) $lockedInventory->lock_version !== (int) $validated['lock_version']) {
                throw new CatalogAdminConflictException(
                    'Das Inventar wurde parallel geändert. Bitte neu laden und erneut prüfen.',
                );
            }

            /** @var InventoryMediumRule $lockedRule */
            $lockedRule = InventoryMediumRule::query()->whereKey($rule->id)->lockForUpdate()->firstOrFail();

            $before = $lockedRule->component_calculation_strategy->value;
            $next = ComponentCalculationStrategy::from(
                (string) $validated['component_calculation_strategy'],
            );

            if ($before === $next->value) {
                return [
                    'rule' => $lockedRule,
                    'lock_version' => (int) $lockedInventory->lock_version,
                    'changed' => false,
                ];
            }

            $lockedRule->component_calculation_strategy = $next;
            $lockedRule->save();

            $lockedInventory->lock_version = (int) $lockedInventory->lock_version + 1;
            $lockedInventory->save();

            $audit->record(
                $lockedRule,
                'inventory_medium_rule.component_strategy_updated',
                $request->user(),
                ['component_calculation_strategy' => $before],
                ['component_calculation_strategy' => $lockedRule->component_calculation_strategy->value],
            );

            return [
                'rule' => $lockedRule->fresh(),
                'lock_version' => (int) $lockedInventory->lock_version,
                'changed' => true,
            ];
        });

        /** @var InventoryMediumRule $updatedRule */
        $updatedRule = $result['rule'];

        return response()->json([
            'rule' => [
                'id' => $updatedRule->id,
                'inventory_id' => $updatedRule->inventory_id,
                'advertising_medium_id' => $updatedRule->advertising_medium_id,
                'component_calculation_strategy' => $updatedRule->component_calculation_strategy->value,
                'component_calculation_strategy_label' => $updatedRule->component_calculation_strategy->label(),
            ],
            'lock_version' => $result['lock_version'],
        ]);
    }
}
