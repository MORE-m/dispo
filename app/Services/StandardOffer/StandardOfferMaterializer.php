<?php

namespace App\Services\StandardOffer;

use App\Enums\ComponentCalculationStrategy;
use App\Enums\PricingSettlementMode;
use App\Enums\SpotCalculationMethod;
use App\Models\ConfigurationSnapshot;
use App\Models\User;
use App\Services\Calculation\CalculationWriter;
use App\Services\DynamicField\ConfigurationSnapshotFreezeService;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * BL-P4-03b/03d/03e / VER-004: Freeze-Seite des versionierten Persistenzvertrags
 * {@see FrozenCalculationPersistenceContract} für Spot-Classic-Average-Vorlagen
 * inkl. optionalem N/N-Festpreis (02d).
 *
 * Adopt hydratisiert über denselben Vertrag (nicht über CalculationWriter::create()).
 * Altstände ohne materialization_version bleiben lesbar (implizit Version 1).
 * Neue Freezes schreiben Version 2 (Festpreis-fähiges Settlement-Schema).
 * Feldabbildung Average v1/v2 wird im Persistenzvertrag zentral dokumentiert/gepflegt.
 */
final class StandardOfferMaterializer
{
    public const MATERIALIZATION_VERSION = FrozenCalculationPersistenceContract::CURRENT_WRITE_VERSION;

    public function __construct(
        private readonly CalculationWriter $calculations,
        private readonly ConfigurationSnapshotFreezeService $snapshots,
    ) {}

    /**
     * @param  array<string, mixed>  $payload  normalisierter Average-Draft
     * @return array{base_snapshot_id: int, materialization: array<string, mixed>}
     */
    public function freeze(array $payload, User $user): array
    {
        $fingerprint = $payload['schema_fingerprint'] ?? null;
        if (! is_string($fingerprint) || $fingerprint === '') {
            throw ValidationException::withMessages([
                'schema_fingerprint' => 'Schema-Fingerprint fehlt oder ist ungültig.',
            ]);
        }

        $totals = $this->calculations->totalsFromPayload($payload, $user);
        $resolved = $this->calculations->resolvedPositions($payload);

        $freezeInputs = [];
        foreach ($resolved as $index => $item) {
            $positionPayload = $payload['positions'][$index] ?? [];
            $freezeInputs[] = [
                'client_key' => (string) $index,
                'advertising_medium_id' => (int) $item['medium']->id,
                'schema_fingerprint' => $positionPayload['schema_fingerprint'] ?? null,
            ];
        }

        $frozen = $this->snapshots->freezeCalculationV3($fingerprint, $freezeInputs);
        $positions = [];
        /** @var array<string, ConfigurationSnapshot> $effectives */
        $effectives = $frozen['effectives_by_client_key'];

        foreach ($resolved as $index => $item) {
            $result = $totals->positions[$index];
            $effectiveKey = (string) $index;
            if (! array_key_exists($effectiveKey, $effectives)) {
                throw ValidationException::withMessages([
                    "positions.{$index}" => 'Konfigurationssnapshot für Position fehlt.',
                ]);
            }
            $effective = $effectives[$effectiveKey];

            $settlementMode = $result->pricingSettlementMode;
            $fixedPriceNn = $settlementMode === PricingSettlementMode::FixedPrice
                ? $result->fixedPriceNn
                : null;
            if ($settlementMode === PricingSettlementMode::FixedPrice
                && ($fixedPriceNn === null || $fixedPriceNn === '' || bccomp((string) $fixedPriceNn, '0', 2) !== 1)) {
                throw ValidationException::withMessages([
                    "positions.{$index}.fixed_price_nn" => 'Festpreis erfordert einen N/N-Endbetrag größer 0.',
                ]);
            }

            $positions[] = [
                'client_key' => (string) Str::uuid(),
                'inventory_id' => (int) $item['inventory']->id,
                'inventory_name' => $item['inventory']->name,
                'inventory_code' => $item['inventory']->code,
                'advertising_medium_id' => (int) $item['medium']->id,
                'advertising_medium_name' => $effective->context_advertising_medium_name,
                'advertising_medium_code' => $effective->context_advertising_medium_code,
                'advertising_category_id' => $effective->context_advertising_category_id,
                'advertising_category_key' => $effective->context_advertising_category_key,
                'advertising_category_name' => $effective->context_advertising_category_name,
                'effective_configuration_snapshot_id' => $effective->id,
                'inventory_medium_rule_id' => $item['inventory_medium_rule_id'],
                'price_list_id' => (int) $item['priceList']->id,
                'price_list_version' => $item['priceList']->version,
                'kind' => $item['freeze']->legacyKind()->value,
                'spot_method' => SpotCalculationMethod::Average->value,
                'length_seconds' => (int) $item['length_seconds'],
                'component_calculation_strategy' => $result->componentCalculationStrategy instanceof ComponentCalculationStrategy
                    ? $result->componentCalculationStrategy->value
                    : ($item['component_calculation_strategy'] instanceof ComponentCalculationStrategy
                        ? $item['component_calculation_strategy']->value
                        : null),
                'component_profile' => null,
                'components' => array_map(
                    static function (array $component): array {
                        return [
                            'role' => (string) $component['role'],
                            'label' => (string) $component['label'],
                            'length_seconds' => (int) $component['length_seconds'],
                            'sort' => (int) $component['sort'],
                            'length_index' => (int) $component['length_index'],
                            'media_gross' => (string) $component['media_gross'],
                        ];
                    },
                    $result->components,
                ),
                'total_spot_count' => (int) $item['total_spot_count'],
                'needs_spot_redistribution' => (bool) $item['needs_spot_redistribution'],
                'average_second_price' => $result->averageSecondPrice,
                'length_index' => $result->lengthIndex,
                'surcharge_percent' => $item['surcharge_percent'],
                'position_discount_percent' => $item['is_discountable']
                    ? ($payload['positions'][$index]['position_discount_percent'] ?? '0')
                    : '0',
                'ae_percent' => $item['is_ae_eligible'] ? ($item['ae_percent'] ?? '0') : '0',
                'is_discountable' => (bool) $item['is_discountable'],
                'is_ae_eligible' => (bool) $item['is_ae_eligible'],
                'media_gross' => $result->mediaGross,
                'position_discount_amount' => $result->positionDiscountAmount,
                'order_discount_amount' => $result->orderDiscountAmount,
                'ae_amount' => $result->aeAmount,
                'nn_invest' => $result->nnInvest,
                'pricing_settlement_mode' => $settlementMode->value,
                'fixed_price_nn' => $fixedPriceNn,
                'effective_pay_factor_percent' => $result->effectivePayFactorPercent,
                'effective_total_discount_percent' => $result->effectiveDiscountPercent,
                'engine_profile_key' => $item['freeze']->engineProfileKey,
                'calculation_method_key' => $item['freeze']->calculationMethodKey,
                'calculation_method_name' => $item['freeze']->calculationMethodName,
                'algorithm_version' => $item['freeze']->algorithmVersion,
                'time_ranges' => $result->timeRanges,
                'plan_rows' => $result->rows,
                'position_discounts' => array_map(
                    static fn (array $discount): array => [
                        'type' => $discount['type'],
                        'custom_label' => $discount['label'] === '' ? null : $discount['label'],
                        'percent' => $discount['percent'],
                    ],
                    $result->positionDiscounts,
                ),
            ];
        }

        return [
            'base_snapshot_id' => (int) $frozen['base']->id,
            'materialization' => [
                'materialization_version' => self::MATERIALIZATION_VERSION,
                'draft_payload' => $payload,
                'configuration_snapshot_id' => (int) $frozen['base']->id,
                'schema_fingerprint' => $frozen['base']->schema_fingerprint,
                'campaign' => $payload['campaign'] ?? null,
                'product_title' => $payload['product_title'] ?? null,
                'briefing' => $payload['briefing'] ?? null,
                'order_discount_percent' => $payload['order_discount_percent'] ?? '0',
                'order_discounts' => $payload['order_discounts'] ?? [],
                'ae_enabled' => (bool) ($payload['ae_enabled'] ?? false),
                'media_gross' => $totals->mediaGross,
                'position_discount_total' => $totals->positionDiscountTotal,
                'order_discount_total' => $totals->orderDiscountTotal,
                'ae_total' => $totals->aeTotal,
                'nn_invest' => $totals->nnInvest,
                'requires_special_approval' => $totals->requiresSpecialApproval,
                'special_approval_reasons' => $totals->specialApprovalReasons,
                'positions' => $positions,
            ],
        ];
    }
}
