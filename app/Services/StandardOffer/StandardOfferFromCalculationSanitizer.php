<?php

namespace App\Services\StandardOffer;

use App\Enums\PricingSettlementMode;
use App\Enums\SpotCalculationMethod;
use App\Enums\SpotComponentProfile;
use App\Models\Calculation;
use Illuminate\Validation\ValidationException;

/**
 * BL-P4-03b / PO-BLP403B-1 / STD-001: Calc-Payload → kundenloser Vorlagen-Draft
 * plus Prüfstufe (nur Feldnamen, keine Quell-Freitextwerte).
 */
final class StandardOfferFromCalculationSanitizer
{
    public function __construct(
        private readonly StandardOfferFieldClassification $classification,
        private readonly StandardOfferAverageContract $averageContract,
    ) {}

    /**
     * @param  array<string, mixed>  $calcPayload  aus CalculationWriter::payloadFromCalculation
     * @return array{
     *     title: string,
     *     draft_payload: array<string, mixed>,
     *     proposal_review: array<string, mixed>
     * }
     */
    public function sanitize(Calculation $calculation, array $calcPayload): array
    {
        $this->assertCompatibleOrFail($calcPayload);

        /** @var list<string> $reviewFieldKeys */
        $reviewFieldKeys = [];

        foreach (StandardOfferFieldClassification::FREE_TEXT_REVIEW_HEADER_KEYS as $key) {
            $value = $calcPayload[$key] ?? null;
            if ($this->hasNonEmptyValue($value)) {
                $reviewFieldKeys[] = $key;
            }
        }

        $headerDyn = [];
        $rawHeaderDyn = is_array($calcPayload['dynamic_field_values'] ?? null)
            ? $calcPayload['dynamic_field_values']
            : [];
        foreach ($rawHeaderDyn as $key => $value) {
            if (! is_string($key)) {
                continue;
            }
            if ($this->classification->isTemplateSafeHeaderDynamicKey($key)) {
                $headerDyn[$key] = $value;

                continue;
            }
            if ($this->hasNonEmptyValue($value)) {
                $reviewFieldKeys[] = 'dynamic_field_values.'.$key;
            }
        }

        $positions = [];
        foreach ($calcPayload['positions'] as $index => $position) {
            if (! is_array($position)) {
                continue;
            }
            [$sanitizedPosition, $positionReviewKeys] = $this->sanitizePosition($position, (int) $index);
            $positions[] = $sanitizedPosition;
            foreach ($positionReviewKeys as $key) {
                $reviewFieldKeys[] = $key;
            }
        }

        $draft = [
            'planning_mode' => 'manual',
            'campaign' => null,
            'product_title' => null,
            'briefing' => null,
            'order_discount_percent' => $calcPayload['order_discount_percent'] ?? '0',
            'order_discounts' => is_array($calcPayload['order_discounts'] ?? null)
                ? $calcPayload['order_discounts']
                : [],
            'ae_enabled' => (bool) ($calcPayload['ae_enabled'] ?? false),
            'schema_fingerprint' => $calcPayload['schema_fingerprint'] ?? null,
            'dynamic_field_values' => $headerDyn,
            'positions' => $positions,
        ];

        $draft = $this->averageContract->normalizeDraftPayload($draft);
        $this->assertNoCustomerLeak($draft);

        $reviewFieldKeys = array_values(array_unique($reviewFieldKeys));

        return [
            // Neutrale Vorlage ohne Quell-Freitext; Nummernreferenz ist Vorgangs-ID, kein Kundeninhalt.
            'title' => 'Vorschlag aus '.$calculation->number,
            'draft_payload' => $draft,
            'proposal_review' => [
                'field_keys_requiring_review' => $reviewFieldKeys,
                'review_required' => $reviewFieldKeys !== [],
                'acknowledged_at' => null,
                'acknowledged_by' => null,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function assertCompatibleOrFail(array $payload): void
    {
        $positions = $payload['positions'] ?? null;
        if (! is_array($positions) || $positions === []) {
            throw ValidationException::withMessages([
                'positions' => 'Die Kalkulation enthält keine Positionen, die als Standardangebot übernommen werden können.',
            ]);
        }

        $errors = [];
        $planningMode = (string) ($payload['planning_mode'] ?? 'manual');
        if ($planningMode !== 'manual') {
            $errors['planning_mode'] = 'Budgetplanung kann nicht als Standardangebot gespeichert werden (nur Spot Classic Average).';
        }

        foreach ($positions as $index => $position) {
            if (! is_array($position)) {
                $errors["positions.{$index}"] = 'Position '.((int) $index + 1).' ist ungültig.';

                continue;
            }

            $label = 'Position '.((int) $index + 1);
            $method = array_key_exists('spot_method', $position)
                ? (string) $position['spot_method']
                : SpotCalculationMethod::Average->value;
            if ($method !== SpotCalculationMethod::Average->value) {
                $errors["positions.{$index}.spot_method"] = "{$label}: Methode „{$method}“ wird nicht unterstützt (nur Spot Classic Average).";
            }

            $calcMethod = $position['calculation_method_key'] ?? null;
            if ($calcMethod !== null && $calcMethod !== ''
                && (string) $calcMethod !== SpotCalculationMethod::Average->value) {
                $errors["positions.{$index}.calculation_method_key"] = "{$label}: Berechnungsmethode „{$calcMethod}“ wird nicht unterstützt.";
            }

            $profileRaw = $position['component_profile'] ?? null;
            if ($profileRaw !== null && $profileRaw !== '') {
                if (! is_string($profileRaw) || SpotComponentProfile::tryFrom($profileRaw) === null) {
                    $errors["positions.{$index}.component_profile"] = "{$label}: Komponentenprofil ist ungültig.";
                }
            }

            if (! empty($position['planner_entries']) && is_array($position['planner_entries'])) {
                $errors["positions.{$index}.planner_entries"] = "{$label}: Kalenderplaner kann nicht als Standardangebot gespeichert werden.";
            }

            $settlementRaw = array_key_exists('pricing_settlement_mode', $position)
                ? $position['pricing_settlement_mode']
                : PricingSettlementMode::Normal->value;
            if ($settlementRaw === null || $settlementRaw === '') {
                $settlementRaw = PricingSettlementMode::Normal->value;
            }
            $settlement = is_scalar($settlementRaw)
                ? PricingSettlementMode::tryFrom((string) $settlementRaw)
                : null;
            if ($settlement === null) {
                $errors["positions.{$index}.pricing_settlement_mode"] = "{$label}: Preisabschluss ist ungültig.";
            } elseif ($settlement === PricingSettlementMode::FixedPrice) {
                $nn = $position['fixed_price_nn'] ?? null;
                if ($nn === null || $nn === '' || ! is_numeric(str_replace(',', '.', (string) $nn))
                    || bccomp(str_replace(',', '.', (string) $nn), '0', 2) !== 1) {
                    $errors["positions.{$index}.fixed_price_nn"] = "{$label}: Festpreis erfordert einen N/N-Endbetrag größer 0.";
                }
            } elseif (($position['fixed_price_nn'] ?? null) !== null && $position['fixed_price_nn'] !== '') {
                $errors["positions.{$index}.fixed_price_nn"] = "{$label}: Festpreis-N/N ist nur im Festpreismodus erlaubt.";
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * @param  array<string, mixed>  $position
     * @return array{0: array<string, mixed>, 1: list<string>}
     */
    private function sanitizePosition(array $position, int $index): array
    {
        $safe = [];
        foreach (StandardOfferFieldClassification::TEMPLATE_SAFE_POSITION_STRUCTURAL_KEYS as $key) {
            if (array_key_exists($key, $position)) {
                $safe[$key] = $position[$key];
            }
        }

        $reviewKeys = [];
        $posDyn = [];
        $rawPosDyn = is_array($position['dynamic_field_values'] ?? null)
            ? $position['dynamic_field_values']
            : [];
        foreach ($rawPosDyn as $key => $value) {
            if (! is_string($key)) {
                continue;
            }
            if ($this->classification->isTemplateSafePositionDynamicKey($key)) {
                $posDyn[$key] = $value;

                continue;
            }
            if ($this->hasNonEmptyValue($value)) {
                $reviewKeys[] = "positions.{$index}.dynamic_field_values.{$key}";
            }
        }
        $safe['dynamic_field_values'] = $posDyn === [] ? ['period_open' => true] : $posDyn;
        $safe['planner_entries'] = [];
        $profileRaw = $safe['component_profile'] ?? null;
        $safe['component_profile'] = is_string($profileRaw) && $profileRaw !== ''
            ? $profileRaw
            : null;
        $safe['spot_method'] = SpotCalculationMethod::Average->value;

        // BL-P4-03e: Festpreis unverändert übernehmen (kein stilles Zurücksetzen auf normal).
        $modeRaw = $safe['pricing_settlement_mode'] ?? PricingSettlementMode::Normal->value;
        if ($modeRaw === '') {
            $modeRaw = PricingSettlementMode::Normal->value;
        }
        $safe['pricing_settlement_mode'] = is_scalar($modeRaw)
            ? (string) $modeRaw
            : PricingSettlementMode::Normal->value;
        if (array_key_exists('fixed_price_nn', $safe)
            && ($safe['fixed_price_nn'] === null || $safe['fixed_price_nn'] === '')) {
            $safe['fixed_price_nn'] = null;
        }

        if (! array_key_exists('components', $safe) || $safe['components'] === null) {
            $safe['components'] = [];
        }

        return [$safe, $reviewKeys];
    }

    /**
     * @param  array<string, mixed>  $draft
     */
    public function assertNoCustomerLeak(array $draft): void
    {
        foreach (StandardOfferFieldClassification::STRIP_HEADER_KEYS as $key) {
            if (array_key_exists($key, $draft)) {
                throw ValidationException::withMessages([
                    $key => 'Kundendaten dürfen nicht in Standardangebote übernommen werden (STD-001).',
                ]);
            }
        }
    }

    private function hasNonEmptyValue(mixed $value): bool
    {
        if ($value === null) {
            return false;
        }
        if (is_string($value)) {
            return trim($value) !== '';
        }
        if (is_array($value)) {
            return $value !== [];
        }
        if (is_bool($value) || is_int($value) || is_float($value)) {
            return true;
        }

        return false;
    }
}
