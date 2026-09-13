<?php

namespace App\Services\Inventory\Admin;

use App\Models\CalculationPosition;
use App\Models\DispoOrderPosition;
use App\Models\Inventory;
use App\Models\InventoryMediumRule;
use App\Models\PriceList;
use App\Services\Advertising\Admin\CatalogImpactPreviewService;
use App\Support\Inventory\InventoryIdentity;

/**
 * BL-P2-01a: Auswirkungsvorschau für Inventar-Lifecycle. Nur lesen.
 * Keine Membership-Zahlen (BL-P2-01b).
 */
final class InventoryImpactPreviewService
{
    public const ACTION_DEACTIVATE = 'inventory_deactivate';

    public const ACTION_REACTIVATE = 'inventory_reactivate';

    public function __construct(
        private readonly CatalogImpactPreviewService $fingerprints,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function previewDeactivate(Inventory $inventory): array
    {
        return $this->preview($inventory, self::ACTION_DEACTIVATE, [
            'is_active' => false,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function previewReactivate(Inventory $inventory): array
    {
        return $this->preview($inventory, self::ACTION_REACTIVATE, [
            'is_active' => true,
        ]);
    }

    /**
     * @param  array<string, mixed>  $intendedChange
     * @return array<string, mixed>
     */
    private function preview(Inventory $inventory, string $action, array $intendedChange): array
    {
        $isDeactivate = $action === self::ACTION_DEACTIVATE;
        $blocking = [];

        if ($isDeactivate && ! $inventory->is_active) {
            $blocking[] = [
                'code' => 'already_inactive',
                'message' => 'Das Inventar ist bereits deaktiviert.',
            ];
        }

        if (! $isDeactivate && $inventory->is_active) {
            $blocking[] = [
                'code' => 'already_active',
                'message' => 'Das Inventar ist bereits aktiv.',
            ];
        }

        $calculationPositionsCount = CalculationPosition::query()
            ->where('inventory_id', $inventory->id)
            ->count();
        $dispoOrderPositionsCount = DispoOrderPosition::query()
            ->where('inventory_id', $inventory->id)
            ->count();
        $priceListsCount = PriceList::query()
            ->where('inventory_id', $inventory->id)
            ->count();
        $rulesCount = InventoryMediumRule::query()
            ->where('inventory_id', $inventory->id)
            ->count();

        $body = [
            'entity' => 'inventory',
            'action' => $action,
            'entity_id' => (int) $inventory->id,
            'lock_version' => (int) $inventory->lock_version,
            'intended_change' => $intendedChange,
            'current' => $this->snapshot($inventory),
            'calculation_positions_count' => $calculationPositionsCount,
            'dispo_order_positions_count' => $dispoOrderPositionsCount,
            'price_lists_count' => $priceListsCount,
            'inventory_medium_rules_count' => $rulesCount,
            'historical_snapshots_note' => $isDeactivate
                ? 'Bestehende Kalkulationen und Dispoaufträge bleiben lesbar und unverändert. Preislisten und Inventar-Werbemittel-Regeln werden nicht gelöscht.'
                : 'Bestehende Kalkulationen, Dispoaufträge, Preislisten und Regeln bleiben unverändert.',
            'new_processes_note' => $isDeactivate
                ? 'Nach der Deaktivierung kann das Inventar nicht mehr neu in Kalkulationen oder Budgetplanung verwendet werden.'
                : 'Nach der Reaktivierung steht das Inventar grundsätzlich wieder für neue Vorgänge bereit. Die konkrete Buchbarkeit hängt weiterhin von aktiven Regeln und Preislisten ab.',
            'blocking_reasons' => $blocking,
            'can_proceed' => $blocking === [],
        ];

        $body['fingerprint'] = $this->fingerprints->fingerprint($body);

        return $body;
    }

    /**
     * @param  array<string, mixed>  $preview
     */
    public function assertFingerprint(array $preview, string $expected): void
    {
        $this->fingerprints->assertFingerprint($preview, $expected);
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(Inventory $inventory): array
    {
        return [
            'id' => (int) $inventory->id,
            'name' => $inventory->name,
            'code' => $inventory->code,
            'type' => $inventory->type->value,
            'type_label' => InventoryIdentity::typeLabel($inventory->type),
            'sort' => (int) $inventory->sort,
            'is_active' => (bool) $inventory->is_active,
            'logo_path' => $inventory->logo_path,
            'lock_version' => (int) $inventory->lock_version,
        ];
    }
}
