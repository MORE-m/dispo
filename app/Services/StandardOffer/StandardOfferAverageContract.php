<?php

namespace App\Services\StandardOffer;

use App\Enums\PricingSettlementMode;
use App\Enums\SpotCalculationMethod;
use Illuminate\Validation\ValidationException;

/**
 * BL-P4-03a: nur Spot Classic Average; übrige Methoden/Bausteine serverseitig abweisen.
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

            $method = (string) ($position['spot_method'] ?? SpotCalculationMethod::Average->value);
            if ($method !== SpotCalculationMethod::Average->value) {
                throw ValidationException::withMessages([
                    "positions.{$index}.spot_method" => 'BL-P4-03a erlaubt nur Spot Classic Average.',
                ]);
            }

            if (! empty($position['components']) && is_array($position['components'])) {
                throw ValidationException::withMessages([
                    "positions.{$index}.components" => 'Komponenten sind in BL-P4-03a nicht erlaubt.',
                ]);
            }

            if (! empty($position['planner_entries']) && is_array($position['planner_entries'])) {
                throw ValidationException::withMessages([
                    "positions.{$index}.planner_entries" => 'Kalenderplaner ist in BL-P4-03a nicht erlaubt.',
                ]);
            }

            if (($position['component_profile'] ?? null) !== null && $position['component_profile'] !== '') {
                throw ValidationException::withMessages([
                    "positions.{$index}.component_profile" => 'Tandem/Tridem ist in BL-P4-03a nicht erlaubt.',
                ]);
            }

            $settlement = (string) ($position['pricing_settlement_mode'] ?? PricingSettlementMode::Normal->value);
            if ($settlement !== PricingSettlementMode::Normal->value) {
                throw ValidationException::withMessages([
                    "positions.{$index}.pricing_settlement_mode" => 'Festpreis ist in BL-P4-03a nicht erlaubt.',
                ]);
            }

            if (($position['fixed_price_nn'] ?? null) !== null && $position['fixed_price_nn'] !== '') {
                throw ValidationException::withMessages([
                    "positions.{$index}.fixed_price_nn" => 'Festpreis ist in BL-P4-03a nicht erlaubt.',
                ]);
            }

            $normalizedPositions[] = [
                ...$position,
                'spot_method' => SpotCalculationMethod::Average->value,
                'pricing_settlement_mode' => PricingSettlementMode::Normal->value,
                'fixed_price_nn' => null,
                'components' => [],
                'planner_entries' => [],
                'component_profile' => null,
                'component_calculation_strategy' => null,
            ];
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
}
