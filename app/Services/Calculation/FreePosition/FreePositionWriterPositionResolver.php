<?php

namespace App\Services\Calculation\FreePosition;

use App\Enums\CalculationKind;
use App\Enums\CalculationMethodMode;
use App\Enums\DiscountType;
use App\Enums\EngineCapabilityStatus;
use App\Enums\PricingSettlementMode;
use App\Enums\SpotCalculationMethod;
use App\Models\AdvertisingMedium;
use App\Models\CalculationPosition;
use App\Models\Inventory;
use App\Models\InventoryMediumRule;
use App\Services\Calculation\Decimal;
use App\Services\Calculation\DiscountInput;
use App\Services\Calculation\DiscountValidator;
use App\Support\Calculation\CalculationMethodFreezeDescriptor;
use App\Support\Calculation\CalculationPositionMethodKeyNormalizer;
use App\Support\Calculation\EngineProfileRegistry;
use App\Support\InventoryMediumRule\InventoryMediumRuleOperativeContract;
use Illuminate\Validation\ValidationException;

/**
 * NATIVE-2: löst eine Payload-Position (Profil free_position × Methode free_position)
 * für den CalculationWriter auf.
 *
 * Server-autoritativ: Rabatt-/AE-Flags, Freeze, kind und Methoden-Keys kommen aus
 * Medium/Regel/Registry – nie aus dem Client. `free_price` ist die einzige
 * User-Preiseingabe; Client-`media_gross` wird nie gelesen.
 *
 * Das Pair muss in der Registry `released` sein (Produktiv: planned → fail-closed;
 * Tests: {@see EngineProfileRegistry::fakeFreePositionPairReleasedForTests()}).
 * Kein Medium-Code-Branching: Berechtigung nur über aktive Methoden-Zuordnung.
 */
final class FreePositionWriterPositionResolver
{
    public const METHOD_KEY = 'free_position';

    public const PAIR_NOT_RELEASED_MESSAGE = 'Kalkulationsart Freie Preisposition ist noch nicht freigegeben.';

    public function __construct(
        private readonly FreePositionEngine $engine = new FreePositionEngine,
    ) {}

    /**
     * Nur Payload-Signal (ohne bestehende Position): Methode free_position angefordert.
     *
     * @param  array<string, mixed>  $position
     */
    public static function payloadSignals(array $position): bool
    {
        $intent = (new CalculationPositionMethodKeyNormalizer)->normalize($position);

        return $intent['present'] && $intent['key'] === self::METHOD_KEY;
    }

    public static function isStored(?CalculationPosition $existing): bool
    {
        if ($existing === null) {
            return false;
        }

        return (string) ($existing->engine_profile_key ?? '') === EngineProfileRegistry::PROFILE_FREE_POSITION
            && (string) ($existing->calculation_method_key ?? '') === self::METHOD_KEY;
    }

    /**
     * Dispatch: Methodenschlüssel/spot_method free_position oder unveränderter Free-Freeze.
     *
     * @param  array<string, mixed>  $position
     */
    public static function isFreePositionRequest(array $position, ?CalculationPosition $existing): bool
    {
        $intent = (new CalculationPositionMethodKeyNormalizer)->normalize($position);

        if ($intent['present']) {
            return $intent['key'] === self::METHOD_KEY;
        }

        return self::isStored($existing);
    }

    /**
     * Gemeinsame Feldprüfung (Resolver + Request): Spot-/OA-/Settlement-Felder sind auf Free unzulässig.
     * Neutralwerte aus dem Reload-Roundtrip (leere Listen, 0, normal, null) bleiben erlaubt.
     *
     * @param  array<string, mixed>  $position
     * @return array<string, string> Feldname → Fehlermeldung
     */
    public static function foreignFieldViolations(array $position): array
    {
        $errors = [];
        $message = 'Dieses Feld wird für Freie Preisposition nicht unterstützt.';

        foreach ([
            'components',
            'production_lines',
            'plan_rows',
            'time_ranges',
            'planner_entries',
            'oa_platform_allocations',
            'oa_targeting_selections',
        ] as $key) {
            if (isset($position[$key]) && is_array($position[$key]) && $position[$key] !== []) {
                $errors[$key] = $message;
            }
        }

        foreach ([
            'basis_tkp',
            'impressions',
            'price_year',
            'expected_price_list_id',
            'expected_oa_tkp_price_list_id',
            'component_calculation_strategy',
            'component_profile',
        ] as $key) {
            if (self::isFilled($position[$key] ?? null)) {
                $errors[$key] = $message;
            }
        }

        if (isset($position['total_spot_count']) && (int) $position['total_spot_count'] > 0) {
            $errors['total_spot_count'] = $message;
        }

        $mode = $position['pricing_settlement_mode'] ?? null;
        if (self::isFilled($mode) && (string) $mode !== PricingSettlementMode::Normal->value) {
            $errors['pricing_settlement_mode'] = $message;
        }

        if (self::isFilled($position['fixed_price_nn'] ?? null)) {
            $errors['fixed_price_nn'] = $message;
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $position
     * @param  array<string, mixed>  $payload  Kopf-Payload (ae_enabled)
     * @return array<string, mixed>
     */
    public function resolve(array $position, ?CalculationPosition $existing, int $index, array $payload = []): array
    {
        $prefix = "positions.{$index}";

        if ($existing !== null && ! self::isStored($existing)) {
            throw ValidationException::withMessages([
                $prefix => 'Eine bestehende Position kann nicht auf Freie Preisposition umgestellt werden. Bitte eine neue Position anlegen.',
            ]);
        }

        $this->assertPairReleased($prefix);

        $inventoryId = (int) ($position['inventory_id'] ?? 0);
        $mediumId = (int) ($position['advertising_medium_id'] ?? 0);

        $inventory = Inventory::query()->find($inventoryId);
        if ($inventory === null) {
            throw ValidationException::withMessages([
                "{$prefix}.inventory_id" => 'Sender oder Kombi ist unbekannt.',
            ]);
        }

        $medium = AdvertisingMedium::query()->find($mediumId);
        if ($medium === null) {
            throw ValidationException::withMessages([
                "{$prefix}.advertising_medium_id" => 'Das Werbemittel ist unbekannt.',
            ]);
        }

        $samePair = $existing !== null
            && (int) $existing->inventory_id === (int) $inventory->id
            && (int) $existing->advertising_medium_id === (int) $medium->id;

        if (! $samePair) {
            if (! $inventory->is_active) {
                throw ValidationException::withMessages([
                    "{$prefix}.inventory_id" => 'Sender oder Kombi ist unbekannt oder inaktiv.',
                ]);
            }
            $this->assertMediumHasFreePositionAssignment($medium, $prefix);
        }

        $freeze = $this->resolveFreeze($existing, $prefix);

        $rule = InventoryMediumRule::query()
            ->where('inventory_id', $inventory->id)
            ->where('advertising_medium_id', $medium->id)
            ->where('is_active', true)
            ->first();

        if ($samePair) {
            $combination = InventoryMediumRuleOperativeContract::freezeFromStoredPosition(
                $existing->booking_code ?? null,
                $existing->planning_responsibility_key ?? null,
                $existing->planning_responsibility_label ?? null,
                $existing->combination_hint_text ?? null,
            );
            $isDiscountable = (bool) $existing->is_discountable;
            $isAeEligible = (bool) $existing->is_ae_eligible;
            $ruleId = $existing->inventory_medium_rule_id !== null ? (int) $existing->inventory_medium_rule_id : null;
        } else {
            if ($rule === null) {
                throw ValidationException::withMessages([
                    "{$prefix}.inventory_id" => 'Die Kombination Sender/Werbemittel ist nicht zulässig.',
                ]);
            }
            InventoryMediumRuleOperativeContract::assertAllowedForPlanning($rule, "{$prefix}.inventory_id");
            $combination = [
                'booking_code' => null,
                'planning_responsibility_key' => null,
                'planning_responsibility_label' => null,
                'combination_hint_text' => null,
            ];
            if (InventoryMediumRuleOperativeContract::normalizeBookingCode($rule->booking_code) !== null
                && InventoryMediumRuleOperativeContract::normalizePlanningKey($rule->planning_responsibility_key) !== null
            ) {
                $combination = InventoryMediumRuleOperativeContract::freezeFromRule($rule);
            }
            $ruleId = (int) $rule->id;
            $isDiscountable = (bool) $medium->is_discountable && (bool) $rule->is_discountable;
            $isAeEligible = (bool) $medium->is_ae_eligible && (bool) $rule->is_ae_eligible;
        }

        foreach (self::foreignFieldViolations($position) as $field => $message) {
            throw ValidationException::withMessages(["{$prefix}.{$field}" => $message]);
        }

        $freePrice = $this->resolveFreePrice($position, $existing, $prefix);
        $positionDiscounts = $this->positionDiscountInputs($position);
        $aePercent = $this->resolveAePercent($payload, $position, $isAeEligible, $existing);

        $input = new FreePositionInput(
            freePrice: $freePrice,
            positionDiscounts: $positionDiscounts,
            positionDiscountPercent: '0',
            aePercent: $aePercent,
            isDiscountable: $isDiscountable,
            isAeEligible: $isAeEligible,
            positionKey: null,
            inventoryName: (string) $inventory->name,
        );

        return [
            'is_free_position' => true,
            'freeze' => $freeze,
            'inventory' => $inventory,
            'medium' => $medium,
            'free_input' => $input,
            'inventory_medium_rule_id' => $ruleId,
            'booking_code' => $combination['booking_code'],
            'planning_responsibility_key' => $combination['planning_responsibility_key'],
            'planning_responsibility_label' => $combination['planning_responsibility_label'],
            'combination_hint_text' => $combination['combination_hint_text'],
            'is_discountable' => $isDiscountable,
            'is_ae_eligible' => $isAeEligible,
            'position_discounts' => $positionDiscounts,
            'ae_percent' => $aePercent,
            // Spot-kompatible Platzhalter (kein Spot-Pfad).
            'priceList' => null,
            'spot_method' => SpotCalculationMethod::FreePosition,
            'length_seconds' => 0,
            'total_spot_count' => 0,
            'rows' => [],
            'time_ranges' => [],
            'planner_entries' => [],
            'components' => [],
            'component_calculation_strategy' => null,
            'component_profile' => null,
            'production_lines' => [],
            'production_ae_percent' => '0',
            'needs_spot_redistribution' => false,
            'pricing_settlement_mode' => PricingSettlementMode::Normal,
            'fixed_price_nn' => null,
            'surcharge_percent' => '0',
            'rule' => $rule,
        ];
    }

    private function assertPairReleased(string $prefix): void
    {
        $pair = EngineProfileRegistry::pairStatus(EngineProfileRegistry::PROFILE_FREE_POSITION, self::METHOD_KEY);
        $current = EngineProfileRegistry::currentReleasedVersion(
            EngineProfileRegistry::PROFILE_FREE_POSITION,
            self::METHOD_KEY,
        );

        if ($pair !== EngineCapabilityStatus::Released || $current !== FreePositionEngine::ALGORITHM_VERSION) {
            throw ValidationException::withMessages([
                $prefix => self::PAIR_NOT_RELEASED_MESSAGE,
            ]);
        }
    }

    /**
     * Freeze wird serverseitig gesetzt; bestehende Positionen müssen die bekannte Algorithmusversion tragen.
     */
    private function resolveFreeze(?CalculationPosition $existing, string $prefix): CalculationMethodFreezeDescriptor
    {
        if ($existing !== null) {
            $stored = (string) ($existing->algorithm_version ?? '');
            if ($stored !== FreePositionEngine::ALGORITHM_VERSION) {
                throw ValidationException::withMessages([
                    $prefix => 'Die eingefrorene Algorithmusversion der Freien Preisposition ist unbekannt und kann nicht ausgeführt werden.',
                ]);
            }
        }

        return new CalculationMethodFreezeDescriptor(
            engineProfileKey: EngineProfileRegistry::PROFILE_FREE_POSITION,
            calculationMethodKey: self::METHOD_KEY,
            calculationMethodName: SpotCalculationMethod::FreePosition->label(),
            algorithmVersion: FreePositionEngine::ALGORITHM_VERSION,
        );
    }

    /**
     * Berechtigung ausschließlich über kind=free_position und aktive Zuordnung
     * engine_profile free_position × Methode free_position (Override: Medium, Inherit: Oberkategorie).
     */
    private function assertMediumHasFreePositionAssignment(AdvertisingMedium $medium, string $prefix): void
    {
        $medium->loadMissing([
            'category.calculationMethodAssignments.calculationMethod',
            'calculationMethodAssignments.calculationMethod',
        ]);

        $category = $medium->category;
        if (! $medium->is_active || $category === null || ! $category->is_active) {
            throw ValidationException::withMessages([
                "{$prefix}.advertising_medium_id" => 'Das Werbemittel ist deaktiviert oder hat keine aktive Oberkategorie.',
            ]);
        }

        $kindRaw = trim((string) ($medium->getAttributes()['kind'] ?? ''));
        if (CalculationKind::tryFrom($kindRaw) !== CalculationKind::FreePosition) {
            throw ValidationException::withMessages([
                "{$prefix}.advertising_medium_id" => 'Dieses Werbemittel ist nicht für Freie Preisposition vorgesehen.',
            ]);
        }

        $assignments = $medium->calculation_method_mode === CalculationMethodMode::Override
            ? $medium->calculationMethodAssignments
            : $category->calculationMethodAssignments;

        foreach ($assignments as $assignment) {
            if (! $assignment->is_active) {
                continue;
            }
            $method = $assignment->calculationMethod;
            if ($method === null || ! $method->is_active || $method->key !== self::METHOD_KEY) {
                continue;
            }
            if (trim((string) $assignment->engine_profile_key) === EngineProfileRegistry::PROFILE_FREE_POSITION) {
                return;
            }
        }

        throw ValidationException::withMessages([
            "{$prefix}.advertising_medium_id" => 'Dieses Werbemittel ist für Freie Preisposition nicht zugeordnet.',
        ]);
    }

    /**
     * User-Ausgangspreis aus dem Payload; fehlt der Schlüssel (Header-only/Reload), gilt der
     * gespeicherte Wert (`media_gross` = `free_price`). Client-`media_gross` wird nie gelesen.
     *
     * @param  array<string, mixed>  $position
     */
    private function resolveFreePrice(array $position, ?CalculationPosition $existing, string $prefix): string
    {
        if (array_key_exists('free_price', $position)) {
            $raw = $position['free_price'];
            if (! is_scalar($raw) || trim((string) $raw) === '') {
                throw ValidationException::withMessages([
                    "{$prefix}.free_price" => 'Freier Ausgangspreis ist erforderlich.',
                ]);
            }

            return $this->assertPrice((string) $raw, $prefix);
        }

        if ($existing !== null && self::isStored($existing) && self::isFilled($existing->media_gross)) {
            return $this->assertPrice((string) $existing->media_gross, $prefix);
        }

        throw ValidationException::withMessages([
            "{$prefix}.free_price" => 'Freier Ausgangspreis ist erforderlich.',
        ]);
    }

    /**
     * Geldformat (max. 2 Nachkommastellen) für die Engine.
     */
    private function assertPrice(string $raw, string $prefix): string
    {
        try {
            // Engine erwartet Geldformat (max. 2 NK); assertFreePrice liefert die interne Skala.
            return Decimal::roundMoney($this->engine->assertFreePrice($raw));
        } catch (ValidationException $exception) {
            $messages = $exception->errors()['free_price'] ?? ['Freier Ausgangspreis ist ungültig.'];

            throw ValidationException::withMessages([
                "{$prefix}.free_price" => (string) ($messages[0] ?? 'Freier Ausgangspreis ist ungültig.'),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $position
     * @return list<DiscountInput>
     */
    private function positionDiscountInputs(array $position): array
    {
        $raw = $position['position_discounts'] ?? [];
        if (! is_array($raw) || $raw === []) {
            $legacy = (string) ($position['position_discount_percent'] ?? '0');
            if (Decimal::cmp($legacy, '0') <= 0) {
                return [];
            }

            return [new DiscountInput(DiscountType::Quantity, $legacy)];
        }

        $inputs = [];
        foreach ((new DiscountValidator)->validated($raw, 'discounts') as $row) {
            $inputs[] = new DiscountInput(
                type: DiscountType::from($row['type']),
                percent: $row['percent'],
                customLabel: $row['custom_label'],
                sort: $row['sort'],
            );
        }

        return $inputs;
    }

    /**
     * Gleiche Semantik wie CalculationWriter::resolveAePercent.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $position
     */
    private function resolveAePercent(array $payload, array $position, bool $isAeEligible, ?CalculationPosition $existing): string
    {
        if (! $isAeEligible) {
            return '0';
        }

        if (array_key_exists('ae_enabled', $payload)) {
            if (! (bool) $payload['ae_enabled']) {
                return '0';
            }

            if ($existing !== null && Decimal::cmp((string) $existing->ae_percent, '0') > 0) {
                return (string) $existing->ae_percent;
            }

            return '15';
        }

        return (string) ($position['ae_percent'] ?? '15');
    }

    private static function isFilled(mixed $value): bool
    {
        if ($value === null) {
            return false;
        }

        return ! is_string($value) || trim($value) !== '';
    }
}
