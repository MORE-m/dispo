<?php

namespace App\Services\StandardOffer;

use App\Enums\ComponentCalculationStrategy;
use App\Enums\PricingSettlementMode;
use App\Enums\SpotCalculationMethod;
use App\Enums\SpotComponentProfile;
use App\Services\Calculation\ComponentValidator;
use App\Support\Advertising\SpotComponentProfileContract;
use Illuminate\Validation\ValidationException;

/**
 * BL-P4-03a/03c/03e/03f/03g/03h/03i/03j: Spot Classic Average (optional Hauptspot+Allonge,
 * N/N-Festpreis, Tandem/Tridem) und Spot Classic Calendar × `normal`/`fixed_price` mit
 * optionaler Hauptspot+Allonge (PO-BLP403H-1 / PO-BLP403I-1 / PO-BLP403J-1 / A1).
 * Ohne Tandem am Calendar. Budget bleibt abgewiesen.
 */
final class StandardOfferAverageContract
{
    public function __construct(
        private readonly ComponentValidator $components,
    ) {}

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
                'positions' => 'Mindestens eine Spot-Classic-Position (Average oder Calendar) ist erforderlich.',
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

            if ($method === SpotCalculationMethod::Calendar->value) {
                $normalizedPositions[] = $this->normalizeCalendarPosition($position, (int) $index);

                continue;
            }

            if ($method !== SpotCalculationMethod::Average->value) {
                throw ValidationException::withMessages([
                    "positions.{$index}.spot_method" => 'BL-P4-03g erlaubt nur Spot Classic Average oder Calendar.',
                ]);
            }

            $normalizedPositions[] = $this->normalizeAveragePosition($position, (int) $index);
        }

        $planningMode = (string) ($payload['planning_mode'] ?? 'manual');
        if ($planningMode !== 'manual') {
            throw ValidationException::withMessages([
                'planning_mode' => 'Budgetplanung ist in BL-P4-03a/03c/03e/03f/03g nicht erlaubt.',
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
     * @return array<string, mixed>
     */
    private function normalizeAveragePosition(array $position, int $index): array
    {
        $calculationMethodKey = $position['calculation_method_key'] ?? null;
        if ($calculationMethodKey !== null && $calculationMethodKey !== ''
            && (string) $calculationMethodKey !== SpotCalculationMethod::Average->value) {
            throw ValidationException::withMessages([
                "positions.{$index}.calculation_method_key" => 'Average-Positionen erfordern calculation_method_key average.',
            ]);
        }

        if (! empty($position['planner_entries']) && is_array($position['planner_entries'])) {
            throw ValidationException::withMessages([
                "positions.{$index}.planner_entries" => 'Kalenderplaner ist nur für Calendar-Positionen erlaubt.',
            ]);
        }

        $profile = $this->normalizeProfile($position, $index);
        $settlement = $this->normalizeSettlement($position, $index);

        if (array_key_exists('components', $position) && $position['components'] === null) {
            throw ValidationException::withMessages([
                "positions.{$index}.components" => 'Komponenten dürfen nicht null sein. Fehlendes Feld oder [] deaktiviert sie; gefüllte Liste aktiviert Komponenten.',
            ]);
        }

        $normalizedComponents = $this->components->validateAndNormalize(
            $position,
            $index,
            SpotCalculationMethod::Average,
            null,
            $profile,
        );

        $strategy = $position['component_calculation_strategy'] ?? null;
        if ($profile !== null) {
            $required = SpotComponentProfileContract::requiredStrategy($profile);
            if ($strategy !== null && $strategy !== ''
                && (string) $strategy !== $required->value) {
                throw ValidationException::withMessages([
                    "positions.{$index}.component_calculation_strategy" => 'Tandem/Tridem erfordert shared_total_length.',
                ]);
            }
            $strategy = $required->value;
        } elseif ($normalizedComponents === []) {
            if ($strategy !== null && $strategy !== '') {
                throw ValidationException::withMessages([
                    "positions.{$index}.component_calculation_strategy" => 'Strategie ohne Komponenten ist unzulässig.',
                ]);
            }
            $strategy = null;
        } else {
            if ($strategy === null || $strategy === '') {
                throw ValidationException::withMessages([
                    "positions.{$index}.component_calculation_strategy" => 'Strategie ist für Komponenten erforderlich.',
                ]);
            }
            if (ComponentCalculationStrategy::tryFrom((string) $strategy) === null) {
                throw ValidationException::withMessages([
                    "positions.{$index}.component_calculation_strategy" => 'Ungültige Komponentenstrategie.',
                ]);
            }
        }

        return [
            ...$position,
            'spot_method' => SpotCalculationMethod::Average->value,
            'calculation_method_key' => SpotCalculationMethod::Average->value,
            'pricing_settlement_mode' => $settlement['mode']->value,
            'fixed_price_nn' => $settlement['fixed_price_nn'],
            'components' => $normalizedComponents,
            'planner_entries' => [],
            'component_profile' => $profile?->value,
            'component_calculation_strategy' => $strategy,
        ];
    }

    /**
     * PO-BLP403H-1 / A1: Calendar × normal, optional Hauptspot+Allonge.
     * PO-BLP403I-1 / A1: Calendar × Festpreis Einzelspot.
     * PO-BLP403J-1 / A1: Calendar × Festpreis × optional Hauptspot+Allonge.
     * Ohne Tandem. Strategien laut Inventarregel (über assertResolvable/preview).
     *
     * @param  array<string, mixed>  $position
     * @return array<string, mixed>
     */
    private function normalizeCalendarPosition(array $position, int $index): array
    {
        $calculationMethodKey = $position['calculation_method_key'] ?? null;
        if ($calculationMethodKey !== null && $calculationMethodKey !== ''
            && (string) $calculationMethodKey !== SpotCalculationMethod::Calendar->value) {
            throw ValidationException::withMessages([
                "positions.{$index}.calculation_method_key" => 'Calendar-Positionen erfordern calculation_method_key calendar.',
            ]);
        }

        $profileRaw = $position['component_profile'] ?? null;
        if ($profileRaw !== null && $profileRaw !== '') {
            throw ValidationException::withMessages([
                "positions.{$index}.component_profile" => 'Tandem/Tridem ist in Calendar-Vorlagen (BL-P4-03h) nicht erlaubt.',
            ]);
        }

        if (array_key_exists('components', $position) && $position['components'] === null) {
            throw ValidationException::withMessages([
                "positions.{$index}.components" => 'Komponenten dürfen nicht null sein. Fehlendes Feld oder [] deaktiviert sie; gefüllte Liste aktiviert Komponenten.',
            ]);
        }

        $normalizedComponents = $this->components->validateAndNormalize(
            $position,
            $index,
            SpotCalculationMethod::Calendar,
            null,
            null,
        );

        $strategy = $position['component_calculation_strategy'] ?? null;
        if ($normalizedComponents === []) {
            if ($strategy !== null && $strategy !== '') {
                throw ValidationException::withMessages([
                    "positions.{$index}.component_calculation_strategy" => 'Strategie ohne Komponenten ist unzulässig.',
                ]);
            }
            $strategy = null;
        } else {
            if ($strategy === null || $strategy === '') {
                throw ValidationException::withMessages([
                    "positions.{$index}.component_calculation_strategy" => 'Strategie ist für Komponenten erforderlich.',
                ]);
            }
            if (ComponentCalculationStrategy::tryFrom((string) $strategy) === null) {
                throw ValidationException::withMessages([
                    "positions.{$index}.component_calculation_strategy" => 'Ungültige Komponentenstrategie.',
                ]);
            }
        }

        $settlement = $this->normalizeSettlement($position, $index);

        $plannerEntries = $position['planner_entries'] ?? [];
        if (! is_array($plannerEntries)) {
            throw ValidationException::withMessages([
                "positions.{$index}.planner_entries" => 'Kalenderplaner-Einträge sind ungültig.',
            ]);
        }

        return [
            ...$position,
            'spot_method' => SpotCalculationMethod::Calendar->value,
            'calculation_method_key' => SpotCalculationMethod::Calendar->value,
            'pricing_settlement_mode' => $settlement['mode']->value,
            'fixed_price_nn' => $settlement['fixed_price_nn'],
            'components' => $normalizedComponents,
            'planner_entries' => $plannerEntries,
            'component_profile' => null,
            'component_calculation_strategy' => $strategy,
        ];
    }

    /**
     * @param  array<string, mixed>  $position
     */
    private function normalizeProfile(array $position, int $index): ?SpotComponentProfile
    {
        if (! array_key_exists('component_profile', $position)
            || $position['component_profile'] === null
            || $position['component_profile'] === '') {
            return null;
        }

        if (! is_string($position['component_profile'])) {
            throw ValidationException::withMessages([
                "positions.{$index}.component_profile" => 'Komponentenprofil ist ungültig.',
            ]);
        }

        $profile = SpotComponentProfile::tryFrom($position['component_profile']);
        if ($profile === null) {
            throw ValidationException::withMessages([
                "positions.{$index}.component_profile" => 'Komponentenprofil ist ungültig (nur tandem|tridem).',
            ]);
        }

        return $profile;
    }

    /**
     * @param  array<string, mixed>  $position
     * @return array{mode: PricingSettlementMode, fixed_price_nn: ?string}
     */
    private function normalizeSettlement(array $position, int $index): array
    {
        $modeRaw = $position['pricing_settlement_mode'] ?? PricingSettlementMode::Normal->value;
        if ($modeRaw === '') {
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
