<?php

namespace App\Services\StandardOffer;

use App\Enums\PricingSettlementMode;
use App\Enums\SpotCalculationMethod;
use Illuminate\Validation\ValidationException;

/**
 * BL-P4-03a/03c/03e: Spot Classic Average; optional Hauptspot+Allonge (02c);
 * optional N/N-Festpreis-Abschluss (02d) auf Average-Basis.
 * Calendar/Tandem/Budget bleiben abgewiesen.
 */
final class StandardOfferAverageContract
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function normalizeDraftPayload(array $payload): array
    {
        if (array_key_exists('customer_name', $payload) || array_key_exists('agency_name', $payload)) {
            throw ValidationException::withMessages([
                'customer_name' => 'Standardangebote speichern keine Kundendaten (STD-001).',
            ]);
        }

        foreach (StandardOfferFieldClassification::STRIP_HEADER_KEYS as $key) {
            if (array_key_exists($key, $payload)) {
                throw ValidationException::withMessages([
                    $key => 'Standardangebote speichern keine Kundendaten (STD-001).',
                ]);
            }
        }

        $positions = $payload['positions'] ?? null;
        if (! is_array($positions) || $positions === []) {
            throw ValidationException::withMessages([
                'positions' => 'Mindestens eine Spot-Classic-Average-Position ist erforderlich.',
            ]);
        }

        $normalizedPositions = [];
        foreach ($positions as $index => $position) {
            if (! is_array($position)) {
                throw ValidationException::withMessages([
                    "positions.{$index}" => 'Position ist ungültig.',
                ]);
            }

            $method = array_key_exists('spot_method', $position)
                ? (string) $position['spot_method']
                : SpotCalculationMethod::Average->value;
            if ($method !== SpotCalculationMethod::Average->value) {
                throw ValidationException::withMessages([
                    "positions.{$index}.spot_method" => 'BL-P4-03a/03c/03e erlaubt nur Spot Classic Average.',
                ]);
            }

            $calculationMethodKey = $position['calculation_method_key'] ?? null;
            if ($calculationMethodKey !== null && $calculationMethodKey !== ''
                && (string) $calculationMethodKey !== SpotCalculationMethod::Average->value) {
                throw ValidationException::withMessages([
                    "positions.{$index}.calculation_method_key" => 'BL-P4-03a/03c/03e erlaubt nur Spot Classic Average.',
                ]);
            }

            if (($position['component_profile'] ?? null) !== null && $position['component_profile'] !== '') {
                throw ValidationException::withMessages([
                    "positions.{$index}.component_profile" => 'Tandem/Tridem ist in BL-P4-03a/03c/03e nicht erlaubt.',
                ]);
            }

            if (! empty($position['planner_entries']) && is_array($position['planner_entries'])) {
                throw ValidationException::withMessages([
                    "positions.{$index}.planner_entries" => 'Kalenderplaner ist in BL-P4-03a/03c/03e nicht erlaubt.',
                ]);
            }

            $settlement = $this->normalizeSettlement($position, (int) $index);

            if (array_key_exists('components', $position) && $position['components'] === null) {
                throw ValidationException::withMessages([
                    "positions.{$index}.components" => 'Komponenten dürfen nicht null sein. Fehlendes Feld oder [] deaktiviert sie; gefüllte Liste aktiviert Hauptspot+Allonge.',
                ]);
            }

            $components = [];
            if (array_key_exists('components', $position)) {
                if (! is_array($position['components'])) {
                    throw ValidationException::withMessages([
                        "positions.{$index}.components" => 'Komponenten müssen als Liste übergeben werden.',
                    ]);
                }
                $components = $position['components'];
            }

            $strategy = $position['component_calculation_strategy'] ?? null;
            if ($components === []) {
                if ($strategy !== null && $strategy !== '') {
                    throw ValidationException::withMessages([
                        "positions.{$index}.component_calculation_strategy" => 'Strategie ohne Komponenten ist unzulässig.',
                    ]);
                }
                $strategy = null;
            }

            $normalizedPositions[] = [
                ...$position,
                'spot_method' => SpotCalculationMethod::Average->value,
                'pricing_settlement_mode' => $settlement['mode']->value,
                'fixed_price_nn' => $settlement['fixed_price_nn'],
                'components' => $components,
                'planner_entries' => [],
                'component_profile' => null,
                'component_calculation_strategy' => $strategy,
            ];
        }

        $planningMode = (string) ($payload['planning_mode'] ?? 'manual');
        if ($planningMode !== 'manual') {
            throw ValidationException::withMessages([
                'planning_mode' => 'Budgetplanung ist in BL-P4-03a/03c/03e nicht erlaubt.',
            ]);
        }

        return [
            'planning_mode' => 'manual',
            'campaign' => $payload['campaign'] ?? null,
            'product_title' => $payload['product_title'] ?? null,
            'briefing' => $payload['briefing'] ?? null,
            'order_discount_percent' => $payload['order_discount_percent'] ?? '0',
            'order_discounts' => is_array($payload['order_discounts'] ?? null) ? $payload['order_discounts'] : [],
            'ae_enabled' => (bool) ($payload['ae_enabled'] ?? false),
            'schema_fingerprint' => $payload['schema_fingerprint'] ?? null,
            'dynamic_field_values' => is_array($payload['dynamic_field_values'] ?? null)
                ? $payload['dynamic_field_values']
                : [],
            'positions' => $normalizedPositions,
        ];
    }

    /**
     * @param  array<string, mixed>  $position
     * @return array{mode: PricingSettlementMode, fixed_price_nn: ?string}
     */
    private function normalizeSettlement(array $position, int $index): array
    {
        $modeRaw = $position['pricing_settlement_mode'] ?? PricingSettlementMode::Normal->value;
        if ($modeRaw === null || $modeRaw === '') {
            $modeRaw = PricingSettlementMode::Normal->value;
        }
        if (! is_scalar($modeRaw)) {
            throw ValidationException::withMessages([
                "positions.{$index}.pricing_settlement_mode" => 'Preisabschluss ist ungültig.',
            ]);
        }

        $mode = PricingSettlementMode::tryFrom((string) $modeRaw);
        if ($mode === null) {
            throw ValidationException::withMessages([
                "positions.{$index}.pricing_settlement_mode" => 'Preisabschluss ist ungültig.',
            ]);
        }

        $hasFixedNn = array_key_exists('fixed_price_nn', $position)
            && $position['fixed_price_nn'] !== null
            && $position['fixed_price_nn'] !== '';

        if ($mode === PricingSettlementMode::FixedPrice) {
            if (! $hasFixedNn) {
                throw ValidationException::withMessages([
                    "positions.{$index}.fixed_price_nn" => 'Festpreis erfordert einen N/N-Endbetrag größer 0.',
                ]);
            }

            $nn = $this->assertPositiveFixedPriceNn((string) $position['fixed_price_nn'], $index);

            return ['mode' => $mode, 'fixed_price_nn' => $nn];
        }

        if ($hasFixedNn) {
            throw ValidationException::withMessages([
                "positions.{$index}.fixed_price_nn" => 'Festpreis-N/N ist nur im Festpreismodus erlaubt (kein stilles Zurücksetzen).',
            ]);
        }

        return ['mode' => PricingSettlementMode::Normal, 'fixed_price_nn' => null];
    }

    private function assertPositiveFixedPriceNn(string $raw, int $index): string
    {
        $normalized = str_replace(',', '.', trim($raw));
        if (! is_numeric($normalized)) {
            throw ValidationException::withMessages([
                "positions.{$index}.fixed_price_nn" => 'Festpreis-N/N ist ungültig.',
            ]);
        }

        if (! preg_match('/^\d+(\.\d{1,2})?$/', $normalized)) {
            throw ValidationException::withMessages([
                "positions.{$index}.fixed_price_nn" => 'Festpreis-N/N darf höchstens zwei Nachkommastellen haben.',
            ]);
        }

        if (bccomp($normalized, '0', 2) !== 1) {
            throw ValidationException::withMessages([
                "positions.{$index}.fixed_price_nn" => 'Festpreis erfordert einen N/N-Endbetrag größer 0.',
            ]);
        }

        return bcadd($normalized, '0', 2);
    }
}
