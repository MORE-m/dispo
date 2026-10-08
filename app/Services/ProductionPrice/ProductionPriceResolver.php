<?php

namespace App\Services\ProductionPrice;

use App\Enums\CalculationKind;
use App\Enums\PriceListStatus;
use App\Enums\PricingSettlementMode;
use App\Enums\ProductionType;
use App\Enums\SpotCalculationMethod;
use App\Models\CalculationPosition;
use App\Models\CalculationPositionProductionLine;
use App\Models\Inventory;
use App\Models\ProductionPriceList;
use App\Services\Calculation\Decimal;
use App\Services\Calculation\ProductionLineInput;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * BL-P5-02a / PO-BLP502-1 Auflösungs- und Pin-Vertrag.
 *
 * Preis und Flags kommen ausschließlich aus der Pin- bzw. Live-Auflösung – nie aus dem Sales-Payload.
 * Mit Zusatzzeilen gilt fail-closed (fehlende/mehrdeutige Active-Liste blockiert, auch bei Menge 0);
 * ohne Zusatzzeilen wird nichts aufgelöst und die Spot-Kalkulation bleibt unberührt.
 */
final class ProductionPriceResolver
{
    public const MAX_LINES_PER_POSITION = 20;

    /**
     * Genau eine aktive Liste je (Inventar, Art, Jahr). Keine → null. Mehrere → fail-closed.
     */
    public function findActive(int $inventoryId, ProductionType|string $type, int $year): ?ProductionPriceList
    {
        $typeValue = $type instanceof ProductionType ? $type->value : $type;

        $lists = ProductionPriceList::query()
            ->where('inventory_id', $inventoryId)
            ->where('production_type', $typeValue)
            ->where('year', $year)
            ->where('status', PriceListStatus::Active)
            ->orderBy('id')
            ->limit(2)
            ->get();

        if ($lists->count() > 1) {
            throw ValidationException::withMessages([
                'positions' => 'Für den Sender ist die aktive Produktionsliste nicht eindeutig. Bitte die Administration informieren.',
            ]);
        }

        return $lists->first();
    }

    /**
     * @param  list<array<string, mixed>>  $payloadLines  Roh-Payload (nur client_key/production_type/label/quantity/remark/sort werden gelesen)
     * @return list<ProductionLineInput>
     */
    public function resolveLinesForPosition(
        array $payloadLines,
        ?CalculationPosition $existing,
        Inventory $inventory,
        int $priceYear,
        SpotCalculationMethod $spotMethod,
        CalculationKind $kind,
        PricingSettlementMode $settlement,
        int $positionIndex = 0,
    ): array {
        if ($payloadLines === []) {
            return [];
        }

        $field = "positions.{$positionIndex}.production_lines";

        if ($kind !== CalculationKind::SpotClassic
            || $spotMethod !== SpotCalculationMethod::Average
            || $settlement !== PricingSettlementMode::Normal
        ) {
            throw ValidationException::withMessages([
                $field => 'Produktion ist nur für Spot Classic mit der Kalkulationsart Durchschnitt (ohne Festpreis) möglich. '
                    .'Bitte die Produktionszeilen entfernen oder die Auswahl ändern.',
            ]);
        }

        if (count($payloadLines) > self::MAX_LINES_PER_POSITION) {
            throw ValidationException::withMessages([
                $field => 'Pro Position sind höchstens '.self::MAX_LINES_PER_POSITION.' Produktionszeilen möglich.',
            ]);
        }

        $stored = $this->storedLinesByClientKey($existing);
        $seenKeys = [];
        $liveCache = [];
        $inputs = [];

        foreach (array_values($payloadLines) as $offset => $raw) {
            $linePrefix = "{$field}.{$offset}";

            $type = $this->parseType($raw['production_type'] ?? null, $linePrefix);
            $quantity = $this->parseQuantity($raw['quantity'] ?? null, $linePrefix);
            $label = $this->parseLabel($raw['label'] ?? null, $type);
            $remark = $this->parseRemark($raw['remark'] ?? null, $linePrefix);
            $sort = isset($raw['sort']) && is_numeric($raw['sort']) ? max(0, (int) $raw['sort']) : $offset;

            $clientKey = isset($raw['client_key']) && is_string($raw['client_key']) && trim($raw['client_key']) !== ''
                ? trim($raw['client_key'])
                : (string) Str::uuid();
            if (isset($seenKeys[$clientKey])) {
                throw ValidationException::withMessages([
                    "{$linePrefix}.client_key" => 'Der Zeilenschlüssel der Produktionszeile ist doppelt.',
                ]);
            }
            $seenKeys[$clientKey] = true;

            $pin = $this->pinnedValues($stored[$clientKey] ?? null, $inventory, $type, $priceYear);

            if ($pin === null) {
                $cacheKey = $type->value;
                if (! array_key_exists($cacheKey, $liveCache)) {
                    $liveCache[$cacheKey] = $this->findActive((int) $inventory->id, $type, $priceYear);
                }
                $list = $liveCache[$cacheKey];

                if ($list === null) {
                    throw ValidationException::withMessages([
                        $field => "Für {$inventory->name} ist kein aktiver Produktionspreis ({$type->label()}) für {$priceYear} hinterlegt. "
                            .'Bitte die Zeile entfernen oder die Administration informieren.',
                    ]);
                }

                $pin = [
                    'unit_price' => $this->money($list->unit_price),
                    'is_discountable' => (bool) $list->is_discountable,
                    'is_ae_eligible' => (bool) $list->is_ae_eligible,
                    'list_id' => (int) $list->id,
                    'version' => (string) $list->version,
                ];
            }

            $inputs[] = new ProductionLineInput(
                clientKey: $clientKey,
                productionType: $type->value,
                label: $label,
                quantity: $quantity,
                unitPrice: $pin['unit_price'],
                isDiscountable: $pin['is_discountable'],
                isAeEligible: $pin['is_ae_eligible'],
                remark: $remark,
                productionPriceListId: $pin['list_id'],
                productionPriceListVersion: $pin['version'],
                sort: $sort,
            );
        }

        return $inputs;
    }

    /**
     * Zeilen einer gespeicherten Position in Payload-Form (ohne Preis/Flags).
     *
     * @return list<array<string, mixed>>
     */
    public function payloadLinesFromExisting(CalculationPosition $position): array
    {
        $position->loadMissing('productionLines');

        return $position->productionLines
            ->map(fn (CalculationPositionProductionLine $line): array => [
                'client_key' => $line->client_key,
                'production_type' => $line->production_type,
                'label' => $line->label,
                'quantity' => (string) $line->quantity,
                'remark' => $line->remark,
                'sort' => (int) $line->sort,
                'production_price_list_id' => $line->production_price_list_id,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<string, CalculationPositionProductionLine>
     */
    private function storedLinesByClientKey(?CalculationPosition $existing): array
    {
        if ($existing === null) {
            return [];
        }

        $existing->loadMissing('productionLines.productionPriceList');

        $map = [];
        foreach ($existing->productionLines as $line) {
            if ($line->client_key !== null && $line->client_key !== '') {
                $map[$line->client_key] = $line;
            }
        }

        return $map;
    }

    /**
     * Pin gilt nur bei gleichem Inventar, gleicher Art und gleichem Preisjahr (sonst Rebind).
     *
     * @return array{unit_price: string, is_discountable: bool, is_ae_eligible: bool, list_id: int|null, version: string}|null
     */
    private function pinnedValues(
        ?CalculationPositionProductionLine $stored,
        Inventory $inventory,
        ProductionType $type,
        int $priceYear,
    ): ?array {
        if ($stored === null) {
            return null;
        }

        $list = $stored->productionPriceList;
        if ($list === null
            || (int) $list->inventory_id !== (int) $inventory->id
            || (int) $list->year !== $priceYear
            || $list->production_type !== $type
            || $stored->production_type !== $type->value
        ) {
            return null;
        }

        return [
            'unit_price' => $this->money($stored->unit_price),
            'is_discountable' => (bool) $stored->is_discountable,
            'is_ae_eligible' => (bool) $stored->is_ae_eligible,
            'list_id' => $stored->production_price_list_id === null ? null : (int) $stored->production_price_list_id,
            'version' => (string) $stored->production_price_list_version,
        ];
    }

    private function parseType(mixed $raw, string $prefix): ProductionType
    {
        $value = $raw === null || $raw === '' ? ProductionType::SpotProduction->value : (is_scalar($raw) ? (string) $raw : '');
        $type = ProductionType::tryFrom($value);

        if ($type === null || ! $type->isSupportedInSlice()) {
            throw ValidationException::withMessages([
                "{$prefix}.production_type" => 'Diese Produktionsart wird nicht unterstützt.',
            ]);
        }

        return $type;
    }

    private function parseQuantity(mixed $raw, string $prefix): string
    {
        if ($raw === null || $raw === '') {
            return Decimal::roundPrice('0');
        }

        $normalized = is_scalar($raw) ? str_replace(',', '.', trim((string) $raw)) : '';

        if (preg_match('/^\d{1,9}(?:\.\d{1,4})?$/', $normalized) !== 1) {
            throw ValidationException::withMessages([
                "{$prefix}.quantity" => 'Die Menge muss eine Zahl von 0 bis 999999999 (höchstens vier Nachkommastellen) sein.',
            ]);
        }

        return Decimal::roundPrice($normalized);
    }

    private function parseLabel(mixed $raw, ProductionType $type): string
    {
        $label = is_scalar($raw) ? trim((string) $raw) : '';

        if ($label === '') {
            return $type->label();
        }

        return mb_substr($label, 0, 120);
    }

    private function parseRemark(mixed $raw, string $prefix): ?string
    {
        if ($raw === null) {
            return null;
        }

        $remark = is_scalar($raw) ? trim((string) $raw) : '';
        if ($remark === '') {
            return null;
        }

        if (mb_strlen($remark) > 1000) {
            throw ValidationException::withMessages([
                "{$prefix}.remark" => 'Die Bemerkung darf höchstens 1000 Zeichen lang sein.',
            ]);
        }

        return $remark;
    }

    private function money(mixed $value): string
    {
        return Decimal::roundMoney((string) $value);
    }
}
