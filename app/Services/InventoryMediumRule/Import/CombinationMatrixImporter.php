<?php

namespace App\Services\InventoryMediumRule\Import;

use App\Enums\CalculationKind;
use App\Enums\ComponentCalculationStrategy;
use App\Models\AdvertisingMedium;
use App\Models\Inventory;
use App\Models\InventoryMediumRule;
use App\Support\InventoryMediumRule\Import\CombinationMatrixWorkbookParser;
use App\Support\InventoryMediumRule\InventoryMediumRuleOperativeContract;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * PO-MAT-CORE-MATRIX-1: idempotenter Desired-State-Import der Kombinationstabelle.
 * Erzeugt/aktualisiert nur existierende Inventar-/Werbemittel-Paare; kein hint_text.
 */
final class CombinationMatrixImporter
{
    public function __construct(
        private readonly CombinationMatrixWorkbookParser $parser,
    ) {}

    public static function defaultWorkbookPath(): string
    {
        return database_path('data/Dispositionsauftrag_Spots_und_SWF_Alle_Sender_2026.xlsx');
    }

    /**
     * @return array{
     *     parsed_rows: int,
     *     created: int,
     *     updated: int,
     *     unchanged: int,
     *     skipped: array<string, int>
     * }
     */
    public function import(?string $absolutePath = null): array
    {
        $path = $absolutePath ?? self::defaultWorkbookPath();
        $parsed = $this->parser->parse($path);
        $rows = $parsed['rows'];

        $inventoryIds = $this->resolveInventories(
            array_values(array_unique(array_column($rows, 'inventory_name'))),
        );
        $mediumIds = $this->resolveMedia(
            array_values(array_unique(array_column($rows, 'medium_name'))),
        );

        $created = 0;
        $updated = 0;
        $unchanged = 0;

        DB::transaction(function () use (
            $rows,
            $inventoryIds,
            $mediumIds,
            &$created,
            &$updated,
            &$unchanged,
        ): void {
            foreach ($rows as $row) {
                $inventoryId = $inventoryIds[$row['inventory_name']];
                $mediumId = $mediumIds[$row['medium_name']];

                /** @var InventoryMediumRule|null $existing */
                $existing = InventoryMediumRule::query()
                    ->where('inventory_id', $inventoryId)
                    ->where('advertising_medium_id', $mediumId)
                    ->lockForUpdate()
                    ->first();

                if ($existing === null) {
                    $inventory = Inventory::query()->findOrFail($inventoryId);
                    $medium = AdvertisingMedium::query()->findOrFail($mediumId);
                    $rule = new InventoryMediumRule;
                    $rule->inventory()->associate($inventory);
                    $rule->advertisingMedium()->associate($medium);
                    $rule->is_active = true;
                    $rule->booking_code = $row['booking_code'];
                    $rule->planning_responsibility_key = $row['planning_responsibility_key'];
                    $rule->hint_text = null;
                    $rule->sort = 0;
                    // BL-P5-01a: Trailer – Länge/Aufschlag nie erfinden (NULL = nicht konfiguriert, fail-closed).
                    $isTrailer = $medium->kind === CalculationKind::SwfTrailer;
                    $rule->default_length_seconds = $isTrailer ? null : $medium->default_length_seconds;
                    $rule->surcharge_percent = $isTrailer ? null : '0';
                    $rule->is_discountable = (bool) $medium->is_discountable;
                    $rule->is_ae_eligible = (bool) $medium->is_ae_eligible;
                    $rule->component_calculation_strategy = ComponentCalculationStrategy::SharedTotalLength;
                    $rule->lock_version = 1;
                    $rule->save();

                    InventoryMediumRuleOperativeContract::assertCompleteForActiveUse(
                        $rule->fresh() ?? $rule,
                        'rule',
                    );
                    $created++;

                    continue;
                }

                $sameBooking = $existing->booking_code === $row['booking_code'];
                $samePlanning = $existing->planning_responsibility_key === $row['planning_responsibility_key'];
                $sameActive = $existing->is_active === true;

                if ($sameBooking && $samePlanning && $sameActive) {
                    $unchanged++;

                    continue;
                }

                $existing->booking_code = $row['booking_code'];
                $existing->planning_responsibility_key = $row['planning_responsibility_key'];
                $existing->is_active = true;
                $existing->lock_version = (int) $existing->lock_version + 1;
                $existing->save();

                InventoryMediumRuleOperativeContract::assertCompleteForActiveUse(
                    $existing->fresh() ?? $existing,
                    'rule',
                );
                $updated++;
            }
        });

        return [
            'parsed_rows' => count($rows),
            'created' => $created,
            'updated' => $updated,
            'unchanged' => $unchanged,
            'skipped' => $parsed['skipped'],
        ];
    }

    /**
     * @param  list<string>  $names
     * @return array<string, int>
     */
    private function resolveInventories(array $names): array
    {
        $map = [];
        foreach ($names as $name) {
            $matches = Inventory::query()->where('name', $name)->where('is_active', true)->get(['id', 'name']);
            if ($matches->count() !== 1) {
                throw new RuntimeException(
                    "Inventar für Matrix-Import nicht eindeutig aktiv gefunden: {$name} (Treffer: {$matches->count()})",
                );
            }
            $map[$name] = (int) $matches->first()->id;
        }

        return $map;
    }

    /**
     * @param  list<string>  $names
     * @return array<string, int>
     */
    private function resolveMedia(array $names): array
    {
        $map = [];
        foreach ($names as $name) {
            $matches = AdvertisingMedium::query()->where('name', $name)->where('is_active', true)->get(['id', 'name']);
            if ($matches->count() !== 1) {
                throw new RuntimeException(
                    "Werbemittel für Matrix-Import nicht eindeutig aktiv gefunden: {$name} (Treffer: {$matches->count()})",
                );
            }
            $map[$name] = (int) $matches->first()->id;
        }

        return $map;
    }
}
