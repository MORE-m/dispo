<?php

namespace App\Services\Calculation\OaTkp;

use App\Enums\CalculationMethodMode;
use App\Enums\DiscountType;
use App\Enums\PricingSettlementMode;
use App\Enums\SpotCalculationMethod;
use App\Exceptions\PriceListSelectionConflictException;
use App\Models\AdvertisingMedium;
use App\Models\Calculation;
use App\Models\CalculationPosition;
use App\Models\CalculationPositionOaTargetingSelection;
use App\Models\Inventory;
use App\Models\InventoryMediumRule;
use App\Models\OaPlatform;
use App\Models\OaTargetingDefinition;
use App\Models\OaTkpPriceList;
use App\Services\Calculation\Decimal;
use App\Services\Calculation\DiscountInput;
use App\Services\Calculation\DiscountValidator;
use App\Services\OnlineAudio\Import\OaTkpImportDecimal;
use App\Support\Calculation\CalculationMethodFreezeDescriptor;
use App\Support\Calculation\CalculationPositionMethodKeyNormalizer;
use App\Support\Calculation\EngineProfileRegistry;
use App\Support\InventoryMediumRule\InventoryMediumRuleOperativeContract;
use App\Support\PriceList\PriceListCalendar;
use App\Support\PriceList\PriceListYearSelection;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * PO-CALC-TKP-1D1: löst eine Payload-Position (Profil oa_tkp × Methode tkp) für den CalculationWriter auf.
 *
 * Server-autoritativ: Listen-/Mindest-TKP, Plattform- und Targeting-Stammdaten werden
 * serverseitig aufgelöst; Client-Werte für list_tkp / minimum_tkp / final_tkp, Targeting-Aufschlag
 * und Plattform-/Targeting-Namen werden nie verwendet.
 *
 * Umgeht bewusst LiveBookability/Registry-Release (Pair bleibt `planned`); die HTTP-Sperre
 * liegt im Controller. Kein Medium-Code-Branching: die Berechtigung kommt aus der
 * effektiven Methoden-Zuordnung (engine_profile oa_tkp × Methode tkp) des Werbemittels.
 */
final class OaTkpWriterPositionResolver
{
    public const METHOD_KEY = 'tkp';

    public function __construct(
        private readonly OaTkpPriceResolver $prices = new OaTkpPriceResolver,
    ) {}

    /**
     * Sperrt OA-TKP-Preislisten der Jahre in stabiler Reihenfolge (Serialisierung mit Aktivierung).
     *
     * @param  list<int>|array<int, int>  $years
     */
    public static function lockOaPriceListsForYears(array $years): void
    {
        $unique = self::normalizeLockYears($years);

        if ($unique === []) {
            return;
        }

        OaTkpPriceList::query()
            ->whereIn('year', $unique)
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id']);
    }

    /**
     * Jahre, die vor OA-Resolver-Reads gelockt werden müssen:
     * explizites price_year, gepinntes oa_tkp_price_list_year, Default current für neue OA.
     *
     * @param  array<string, mixed>  $payload
     * @return list<int>
     */
    public static function yearsToLockForPayload(array $payload, ?Calculation $existing = null): array
    {
        $years = [];
        $existingById = $existing?->positions->keyBy('id') ?? collect();
        $existingByClient = $existing?->positions->keyBy('client_key') ?? collect();
        $hasOa = false;

        foreach (is_array($payload['positions'] ?? null) ? $payload['positions'] : [] as $position) {
            if (! is_array($position)) {
                continue;
            }

            $existingPosition = null;
            if (isset($position['id'])) {
                $existingPosition = $existingById->get((int) $position['id']);
            } elseif (isset($position['client_key'])) {
                $existingPosition = $existingByClient->get((string) $position['client_key']);
            }

            $isOa = self::payloadSignalsOaTkp($position) || self::isStoredOaTkp($existingPosition);
            if (! $isOa) {
                continue;
            }
            $hasOa = true;

            if (PriceListYearSelection::hasExplicitPriceYear($position)) {
                $years[] = (int) $position['price_year'];
            } elseif ($existingPosition !== null && $existingPosition->oa_tkp_price_list_year !== null) {
                $years[] = (int) $existingPosition->oa_tkp_price_list_year;
            } else {
                $years[] = PriceListCalendar::currentYear();
            }
        }

        // Header-only / Payload ohne OA-Marker: bestehende OA-Jahre trotzdem sperren.
        if (! $hasOa && $existing !== null) {
            foreach ($existing->positions as $stored) {
                if (self::isStoredOaTkp($stored) && $stored->oa_tkp_price_list_year !== null) {
                    $years[] = (int) $stored->oa_tkp_price_list_year;
                }
            }
        }

        $normalized = self::normalizeLockYears($years);

        return $normalized === [] ? [PriceListCalendar::currentYear()] : $normalized;
    }

    /**
     * @param  list<int>|array<int, int>  $years
     * @return list<int>
     */
    private static function normalizeLockYears(array $years): array
    {
        $unique = array_values(array_unique(array_filter(
            array_map(static fn ($year): int => (int) $year, $years),
            static fn (int $year): bool => $year >= 2000,
        )));
        sort($unique);

        return $unique;
    }

    /**
     * Nur Payload-Signal (ohne bestehende Position): Methode tkp angefordert.
     *
     * @param  array<string, mixed>  $position
     */
    public static function payloadSignalsOaTkp(array $position): bool
    {
        $intent = (new CalculationPositionMethodKeyNormalizer)->normalize($position);

        return $intent['present'] && $intent['key'] === self::METHOD_KEY;
    }

    public static function isStoredOaTkp(?CalculationPosition $existing): bool
    {
        if ($existing === null) {
            return false;
        }

        return (string) ($existing->engine_profile_key ?? '') === EngineProfileRegistry::PROFILE_OA_TKP
            && (string) ($existing->calculation_method_key ?? '') === self::METHOD_KEY;
    }

    /**
     * Dispatch: TKP per Methodenschlüssel/spot_method oder unveränderter OA-Freeze (Methode nicht gewechselt).
     *
     * @param  array<string, mixed>  $position
     */
    public static function isOaTkpRequest(array $position, ?CalculationPosition $existing): bool
    {
        $intent = (new CalculationPositionMethodKeyNormalizer)->normalize($position);

        if ($intent['present']) {
            return $intent['key'] === self::METHOD_KEY;
        }

        return self::isStoredOaTkp($existing);
    }

    /**
     * @param  array<string, mixed>  $position
     * @param  array<string, mixed>  $payload  Kopf-Payload (ae_enabled)
     * @return array<string, mixed>
     */
    public function resolve(array $position, ?CalculationPosition $existing, int $index, array $payload = []): array
    {
        $prefix = "positions.{$index}";

        if ($existing !== null && ! self::isStoredOaTkp($existing)) {
            throw ValidationException::withMessages([
                $prefix => 'Eine bestehende Position kann nicht auf TKP umgestellt werden. Bitte eine neue Position anlegen.',
            ]);
        }

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
            $this->assertMediumHasOaTkpAssignment($medium, $prefix);
        }

        $freeze = $this->resolveFreeze($existing, $prefix);

        $rule = InventoryMediumRule::query()
            ->where('inventory_id', $inventory->id)
            ->where('advertising_medium_id', $medium->id)
            ->where('is_active', true)
            ->first();

        // Kombinationsfreeze: Pin bei unverändertem Paar, sonst aus (aktiver) Regel falls vorhanden.
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
            $combination = [
                'booking_code' => null,
                'planning_responsibility_key' => null,
                'planning_responsibility_label' => null,
                'combination_hint_text' => null,
            ];
            $isDiscountable = (bool) $medium->is_discountable;
            $isAeEligible = (bool) $medium->is_ae_eligible;
            $ruleId = null;
            if ($rule !== null) {
                $ruleId = (int) $rule->id;
                $isDiscountable = $isDiscountable && (bool) $rule->is_discountable;
                $isAeEligible = $isAeEligible && (bool) $rule->is_ae_eligible;
                InventoryMediumRuleOperativeContract::assertAllowedForPlanning($rule, "{$prefix}.inventory_id");
                if (InventoryMediumRuleOperativeContract::normalizeBookingCode($rule->booking_code) !== null
                    && InventoryMediumRuleOperativeContract::normalizePlanningKey($rule->planning_responsibility_key) !== null
                ) {
                    $combination = InventoryMediumRuleOperativeContract::freezeFromRule($rule);
                }
            }
        }

        $priceYear = $this->resolvePriceYear($position, $existing, $samePair);
        $pin = $samePair
            && (int) $existing->oa_tkp_price_list_year === $priceYear
            && $existing->oa_tkp_price_list_id !== null
            && $existing->list_tkp_snapshot !== null
            && $existing->minimum_tkp_snapshot !== null;

        if ($pin) {
            $listTkp = (string) $existing->list_tkp_snapshot;
            $minimumTkp = (string) $existing->minimum_tkp_snapshot;
            $priceListId = (int) $existing->oa_tkp_price_list_id;
            $priceListVersion = (string) $existing->oa_tkp_price_list_version;
            $priceListYear = (int) $existing->oa_tkp_price_list_year;
            $priceListRevision = (int) $existing->oa_tkp_price_list_revision;
        } else {
            PriceListYearSelection::assertAllowedLiveYear($priceYear, "{$prefix}.price_year");
            $active = $this->prices->resolveActive(
                $priceYear,
                (int) $medium->id,
                (int) $inventory->id,
                (string) $medium->code,
            );

            // Analog Spot PO-PRI-YEAR-1: Token Pflicht bei explizitem price_year (Create)
            // oder bei Rebind einer bestehenden Position; Default-Jahr ohne Token ok.
            $this->assertExpectedOaTkpPriceList(
                $position['expected_oa_tkp_price_list_id'] ?? null,
                (int) $active['price_list_id'],
                required: $existing !== null || PriceListYearSelection::hasExplicitPriceYear($position),
                errorKey: "{$prefix}.expected_oa_tkp_price_list_id",
            );

            $listTkp = $active['list_tkp'];
            $minimumTkp = $active['minimum_tkp'];
            $priceListId = $active['price_list_id'];
            $priceListVersion = $active['version'];
            $priceListYear = $active['year'];
            $priceListRevision = $active['revision_number'];
        }

        $this->assertNoUnsupportedFields($position, $prefix);

        $impressions = $this->parseImpressions($position['impressions'] ?? null, "{$prefix}.impressions");
        $basisTkp = OaTkpImportDecimal::normalizePositive($position['basis_tkp'] ?? null);
        if ($basisTkp === null) {
            throw ValidationException::withMessages([
                "{$prefix}.basis_tkp" => 'Basis-TKP ist ungültig (muss > 0 sein, max. 4 Nachkommastellen, Punkt als Trenner).',
            ]);
        }

        $allocations = $this->resolvePlatformAllocations($position, $existing, $prefix);
        $targetings = $this->resolveTargetings($position, $existing, $prefix);

        $positionDiscounts = $this->positionDiscountInputs($position);
        $aePercent = $this->resolveAePercent($payload, $position, $isAeEligible, $existing);

        $oaInput = new OaTkpPositionInput(
            inventoryId: (int) $inventory->id,
            inventoryCode: (string) $inventory->code,
            inventoryName: (string) $inventory->name,
            advertisingMediumId: (int) $medium->id,
            advertisingMediumCode: (string) $medium->code,
            advertisingMediumName: (string) $medium->name,
            impressions: $impressions,
            basisTkp: $basisTkp,
            listTkp: $listTkp,
            minimumTkp: $minimumTkp,
            oaTkpPriceListId: $priceListId,
            oaTkpPriceListVersion: $priceListVersion,
            oaTkpPriceListYear: $priceListYear,
            oaTkpPriceListRevision: $priceListRevision,
            platformAllocations: $allocations,
            targetings: $targetings,
            positionDiscounts: $positionDiscounts,
            positionDiscountPercent: '0',
            aePercent: $aePercent,
            isDiscountable: $isDiscountable,
            isAeEligible: $isAeEligible,
            positionKey: null,
        );

        return [
            'is_oa_tkp' => true,
            'freeze' => $freeze,
            'inventory' => $inventory,
            'medium' => $medium,
            'oa_input' => $oaInput,
            'price_year' => $priceYear,
            'expected_oa_tkp_price_list_id' => $priceListId,
            'rebound' => ! $pin,
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
            'spot_method' => SpotCalculationMethod::Tkp,
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

    /**
     * Freeze manuell wie der Persister (Pair bleibt planned; keine LiveBookability).
     */
    private function resolveFreeze(?CalculationPosition $existing, string $prefix): CalculationMethodFreezeDescriptor
    {
        if ($existing !== null) {
            $stored = (string) ($existing->algorithm_version ?? '');
            if ($stored !== OaTkpNormalEngine::ALGORITHM_VERSION) {
                throw ValidationException::withMessages([
                    $prefix => 'Die eingefrorene TKP-Algorithmusversion ist unbekannt und kann nicht ausgeführt werden.',
                ]);
            }
        }

        return new CalculationMethodFreezeDescriptor(
            engineProfileKey: EngineProfileRegistry::PROFILE_OA_TKP,
            calculationMethodKey: self::METHOD_KEY,
            calculationMethodName: SpotCalculationMethod::Tkp->label(),
            algorithmVersion: OaTkpNormalEngine::ALGORITHM_VERSION,
        );
    }

    /**
     * Berechtigung ausschließlich über aktive Zuordnung engine_profile oa_tkp × Methode tkp
     * (Override: Medium-Zuordnung, Inherit: Oberkategorie-Zuordnung). Kein Medium-Code-Switch.
     */
    private function assertMediumHasOaTkpAssignment(AdvertisingMedium $medium, string $prefix): void
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

        $assignments = $medium->calculation_method_mode === CalculationMethodMode::Override
            ? $medium->calculationMethodAssignments
            : $category->calculationMethodAssignments;

        $allowed = false;
        foreach ($assignments as $assignment) {
            if (! $assignment->is_active) {
                continue;
            }
            $method = $assignment->calculationMethod;
            if ($method === null || ! $method->is_active || $method->key !== self::METHOD_KEY) {
                continue;
            }
            if (trim((string) $assignment->engine_profile_key) === EngineProfileRegistry::PROFILE_OA_TKP) {
                $allowed = true;
                break;
            }
        }

        // Kernmedien-Scope (native_ad OUT) prüft zusätzlich OaTkpPriceResolver.
        if (! $allowed) {
            throw ValidationException::withMessages([
                "{$prefix}.advertising_medium_id" => 'Dieses Werbemittel ist für TKP nicht zugeordnet.',
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $position
     */
    private function resolvePriceYear(array $position, ?CalculationPosition $existing, bool $samePair): int
    {
        if (PriceListYearSelection::hasExplicitPriceYear($position)) {
            return (int) $position['price_year'];
        }

        // Legacy-Payload ohne price_year: bestehender Pin bleibt, sonst aktuelles Kalenderjahr.
        if ($samePair && $existing !== null && $existing->oa_tkp_price_list_year !== null) {
            return (int) $existing->oa_tkp_price_list_year;
        }

        return PriceListCalendar::currentYear();
    }

    /**
     * @param  array<string, mixed>  $position
     */
    private function assertNoUnsupportedFields(array $position, string $prefix): void
    {
        foreach (['components', 'production_lines'] as $key) {
            if (isset($position[$key]) && is_array($position[$key]) && $position[$key] !== []) {
                throw ValidationException::withMessages([
                    "{$prefix}.{$key}" => 'Dieses Feld wird für TKP-Positionen nicht unterstützt.',
                ]);
            }
        }

        $settlement = is_scalar($position['pricing_settlement_mode'] ?? null)
            ? (string) $position['pricing_settlement_mode']
            : '';
        if ($settlement !== '' && $settlement !== PricingSettlementMode::Normal->value) {
            throw ValidationException::withMessages([
                "{$prefix}.pricing_settlement_mode" => 'Festpreis-Abschluss ist für TKP nicht verfügbar.',
            ]);
        }
    }

    private function parseImpressions(mixed $raw, string $key): int
    {
        if (is_int($raw)) {
            $value = $raw;
        } elseif (is_string($raw) && preg_match('/^\d{1,12}$/', trim($raw)) === 1) {
            $value = (int) trim($raw);
        } else {
            throw ValidationException::withMessages([
                $key => 'Impressions müssen eine positive ganze Zahl ≥ 1 sein.',
            ]);
        }

        if ($value < 1) {
            throw ValidationException::withMessages([
                $key => 'Impressions müssen eine positive ganze Zahl ≥ 1 sein.',
            ]);
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $position
     * @return list<OaTkpPlatformAllocationInput>
     */
    private function resolvePlatformAllocations(array $position, ?CalculationPosition $existing, string $prefix): array
    {
        $rows = $position['oa_platform_allocations'] ?? null;
        if (! is_array($rows) || $rows === []) {
            throw ValidationException::withMessages([
                "{$prefix}.oa_platform_allocations" => 'Plattformverteilung ist Pflicht: mindestens eine Allocation.',
            ]);
        }

        $stored = $existing === null
            ? collect()
            : $existing->oaPlatformAllocations()->get()->filter(fn ($a): bool => $a->oa_platform_id !== null)
                ->keyBy(fn ($a): int => (int) $a->oa_platform_id);

        $inputs = [];
        $seen = [];
        foreach (array_values($rows) as $i => $row) {
            $rowKey = "{$prefix}.oa_platform_allocations.{$i}";
            if (! is_array($row)) {
                throw ValidationException::withMessages([$rowKey => 'Ungültige Plattform-Allocation.']);
            }

            $impressions = $this->parseImpressions($row['impressions'] ?? null, "{$rowKey}.impressions");

            $id = isset($row['oa_platform_id']) && $row['oa_platform_id'] !== '' ? (int) $row['oa_platform_id'] : null;
            $code = isset($row['platform_code']) && is_string($row['platform_code']) ? trim($row['platform_code']) : '';

            if ($id === null && $code === '') {
                throw ValidationException::withMessages([
                    $rowKey => 'Plattform fehlt (oa_platform_id oder platform_code).',
                ]);
            }

            $platform = $id !== null
                ? OaPlatform::query()->find($id)
                : OaPlatform::query()->where('code', $code)->first();

            if ($platform === null || ($id !== null && $code !== '' && $platform->code !== $code)) {
                throw ValidationException::withMessages([$rowKey => 'Unbekannte Plattform.']);
            }

            if (! $platform->is_active) {
                // Bereits gespeicherte, unveränderte Plattform bleibt (Pin); neue inaktive werden abgelehnt.
                $keep = $stored->get((int) $platform->id);
                if ($keep === null) {
                    throw ValidationException::withMessages([$rowKey => 'Die Plattform ist inaktiv.']);
                }
            }

            if (isset($seen[(int) $platform->id])) {
                throw ValidationException::withMessages([
                    $rowKey => 'Plattform darf pro Position nur einmal vorkommen.',
                ]);
            }
            $seen[(int) $platform->id] = true;

            $keep = $stored->get((int) $platform->id);
            $inputs[] = new OaTkpPlatformAllocationInput(
                platformCode: $keep !== null ? (string) $keep->platform_code : (string) $platform->code,
                platformName: $keep !== null ? (string) $keep->platform_name : (string) $platform->name,
                impressions: $impressions,
                oaPlatformId: (int) $platform->id,
                sort: ($i + 1) * 10,
            );
        }

        return $inputs;
    }

    /**
     * Expected-Token analog Spot: fehlt bei Pflicht → 422; Mismatch → Conflict (409).
     */
    private function assertExpectedOaTkpPriceList(
        mixed $expected,
        int $activePriceListId,
        bool $required,
        string $errorKey,
    ): void {
        if ($expected === null || $expected === '') {
            if ($required) {
                throw ValidationException::withMessages([
                    $errorKey => 'Die erwartete OA-TKP-Preisliste fehlt. Bitte neu laden und bewusst speichern.',
                ]);
            }

            return;
        }

        if ((int) $expected !== $activePriceListId) {
            throw new PriceListSelectionConflictException(
                'Die aktive OA-TKP-Preisliste hat sich geändert. Bitte neu laden und bewusst speichern.',
            );
        }
    }

    /**
     * @param  array<string, mixed>  $position
     * @return list<OaTkpTargetingSelectionInput>
     */
    private function resolveTargetings(array $position, ?CalculationPosition $existing, string $prefix): array
    {
        $rows = $position['oa_targeting_selections'] ?? [];
        if (! is_array($rows)) {
            throw ValidationException::withMessages([
                "{$prefix}.oa_targeting_selections" => 'Ungültige Targeting-Auswahl.',
            ]);
        }

        $storedRows = $existing === null
            ? collect()
            : $existing->oaTargetingSelections()->get()
                ->filter(fn ($s): bool => $s->oa_targeting_definition_id !== null && trim((string) $s->targeting_code) !== '');

        $storedById = $storedRows->keyBy(fn ($s): int => (int) $s->oa_targeting_definition_id);
        $storedByCode = $storedRows->keyBy(fn ($s): string => (string) $s->targeting_code);

        $today = Carbon::today();
        $inputs = [];
        $seenIds = [];
        $seenCodes = [];
        foreach (array_values($rows) as $i => $row) {
            $rowKey = "{$prefix}.oa_targeting_selections.{$i}";
            if (! is_array($row)) {
                throw ValidationException::withMessages([$rowKey => 'Ungültige Targeting-Auswahl.']);
            }

            $id = isset($row['oa_targeting_definition_id']) && $row['oa_targeting_definition_id'] !== ''
                ? (int) $row['oa_targeting_definition_id']
                : null;
            $code = isset($row['targeting_code']) && is_string($row['targeting_code']) ? trim($row['targeting_code']) : '';

            if ($id === null && $code === '') {
                throw ValidationException::withMessages([
                    $rowKey => 'Targeting fehlt (oa_targeting_definition_id oder targeting_code).',
                ]);
            }

            // Fall A: gespeicherte Definition-ID → historischen Snapshot halten (auch wenn inactive).
            if ($id !== null) {
                $definition = OaTargetingDefinition::query()->find($id);
                if ($definition === null) {
                    throw ValidationException::withMessages([$rowKey => 'Unbekanntes Targeting.']);
                }
                if ($code !== '' && $definition->code !== $code) {
                    throw ValidationException::withMessages([
                        $rowKey => 'Targeting-ID und Targeting-Code passen nicht zusammen.',
                    ]);
                }

                $keep = $storedById->get($id);
                if ($keep !== null) {
                    $pinCode = (string) $keep->targeting_code;
                    if (isset($seenIds[$id]) || isset($seenCodes[$pinCode])) {
                        throw ValidationException::withMessages([
                            $rowKey => 'Targeting darf pro Position nur einmal ausgewählt werden.',
                        ]);
                    }
                    $seenIds[$id] = true;
                    $seenCodes[$pinCode] = true;
                    $inputs[] = $this->targetingInputFromStored($keep, ($i + 1) * 10);

                    continue;
                }

                // Fall D: bewusste neue Definition-ID → aktuelle Stammdaten, muss aktiv/gültig sein.
                if (! $definition->is_active
                    || ($definition->valid_from !== null && $definition->valid_from->greaterThan($today))
                    || ($definition->valid_to !== null && $definition->valid_to->lessThan($today))
                ) {
                    throw ValidationException::withMessages([$rowKey => 'Das Targeting ist nicht aktiv oder nicht gültig.']);
                }

                if (isset($seenIds[(int) $definition->id]) || isset($seenCodes[(string) $definition->code])) {
                    throw ValidationException::withMessages([
                        $rowKey => 'Targeting darf pro Position nur einmal ausgewählt werden.',
                    ]);
                }
                $seenIds[(int) $definition->id] = true;
                $seenCodes[(string) $definition->code] = true;
                $inputs[] = $this->targetingInputFromDefinition($definition, ($i + 1) * 10);

                continue;
            }

            // Fall B: code-only + gespeicherter gleicher Code → historischen Snapshot halten.
            $keepByCode = $storedByCode->get($code);
            if ($keepByCode !== null) {
                $keepId = (int) $keepByCode->oa_targeting_definition_id;
                if (isset($seenIds[$keepId]) || isset($seenCodes[$code])) {
                    throw ValidationException::withMessages([
                        $rowKey => 'Targeting darf pro Position nur einmal ausgewählt werden.',
                    ]);
                }
                $seenIds[$keepId] = true;
                $seenCodes[$code] = true;
                $inputs[] = $this->targetingInputFromStored($keepByCode, ($i + 1) * 10);

                continue;
            }

            // Fall C: neue Auswahl nur per Code → höchste aktive Revision.
            $definition = OaTargetingDefinition::query()
                ->where('code', $code)
                ->where('is_active', true)
                ->orderByDesc('revision_number')
                ->first();

            if ($definition === null) {
                throw ValidationException::withMessages([$rowKey => 'Unbekanntes Targeting.']);
            }

            if (($definition->valid_from !== null && $definition->valid_from->greaterThan($today))
                || ($definition->valid_to !== null && $definition->valid_to->lessThan($today))
            ) {
                throw ValidationException::withMessages([$rowKey => 'Das Targeting ist nicht aktiv oder nicht gültig.']);
            }

            if (isset($seenIds[(int) $definition->id]) || isset($seenCodes[(string) $definition->code])) {
                throw ValidationException::withMessages([
                    $rowKey => 'Targeting darf pro Position nur einmal ausgewählt werden.',
                ]);
            }
            $seenIds[(int) $definition->id] = true;
            $seenCodes[(string) $definition->code] = true;
            $inputs[] = $this->targetingInputFromDefinition($definition, ($i + 1) * 10);
        }

        return $inputs;
    }

    private function targetingInputFromStored(CalculationPositionOaTargetingSelection $keep, int $sort): OaTkpTargetingSelectionInput
    {
        return new OaTkpTargetingSelectionInput(
            code: (string) $keep->targeting_code,
            name: (string) $keep->targeting_name,
            category: (string) $keep->targeting_category,
            tkpSurcharge: (string) $keep->tkp_surcharge_snapshot,
            revisionNumber: (int) $keep->targeting_revision_number,
            oaTargetingDefinitionId: (int) $keep->oa_targeting_definition_id,
            sort: $sort,
        );
    }

    private function targetingInputFromDefinition(OaTargetingDefinition $definition, int $sort): OaTkpTargetingSelectionInput
    {
        return new OaTkpTargetingSelectionInput(
            code: (string) $definition->code,
            name: (string) $definition->name,
            category: $definition->category->value,
            tkpSurcharge: (string) $definition->tkp_surcharge,
            revisionNumber: (int) $definition->revision_number,
            oaTargetingDefinitionId: (int) $definition->id,
            sort: $sort,
        );
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
}
