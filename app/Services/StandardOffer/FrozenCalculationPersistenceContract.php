<?php

namespace App\Services\StandardOffer;

use App\Enums\CalculationStatus;
use App\Enums\ComponentCalculationStrategy;
use App\Enums\DayGroup;
use App\Enums\DiscountType;
use App\Enums\PlanningMode;
use App\Enums\PricingSettlementMode;
use App\Enums\SpotCalculationMethod;
use App\Enums\SpotComponentProfile;
use App\Enums\SpotComponentRole;
use App\Models\Calculation;
use App\Models\CalculationOrderDiscount;
use App\Models\CalculationPosition;
use App\Models\CalculationPositionComponent;
use App\Models\CalculationPositionDiscount;
use App\Models\CalculationPositionPlannerEntry;
use App\Models\CalculationPositionTimeRange;
use App\Models\ConfigurationSnapshot;
use App\Models\SpotClassicPlanRow;
use App\Models\StandardOfferVersion;
use App\Models\User;
use App\Services\Calculation\CalculationNumberSequencer;
use App\Services\Calculation\CalculationWriter;
use App\Services\Calculation\DayGroupFromDate;
use App\Services\Calculation\Decimal;
use App\Services\Crm\CrmOrderHeaderBinder;
use App\Services\DynamicField\CalculationDynamicFieldWriter;
use App\Services\DynamicField\ConfigurationSnapshotCloneService;
use App\Support\Advertising\SpotComponentProfileContract;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * BL-P4-03d / BL-P4-03e / BL-P4-03f / VER-004 / STD-004 / STD-005 / STD-006.
 *
 * Ausdrücklich versionierter Persistenzvertrag für eingefrorene Spot-Classic-
 * Average-Kalkulationsdaten (optional Hauptspot+Allonge; ab v2 optional N/N-
 * Festpreis-Abschluss 02d; ab v3 optional Tandem/Tridem 02e).
 *
 * Architekturgrenze:
 * - Freeze schreibt diesen Vertrag ({@see StandardOfferMaterializer::freeze()}).
 * - Hydrate materialisiert Kundenkalkulationen ausschließlich aus Frozen-Werten
 *   und geklonten Config-Snapshots ({@see self::hydrateAdoptedCalculation()}).
 * - Adopt-Persistenz ist aus {@see StandardOfferWriter::adopt()} extrahiert und
 *   versioniert; Freeze- und Hydrate-Feldabbildungen bleiben **zwei gepflegte
 *   Seiten** desselben Vertrags (nicht eine automatisch synchrone Abbildung).
 * - Kein {@see CalculationWriter::create()}, keine Live-Preisauflösung, keine
 *   Kopplung zur Quellkalkulation.
 * - Übernahmekontext (Kunde, optionale Agentur, Advisor, Kampagne) wird von
 *   außen ergänzt; Vorlagenwerte bleiben unverändert.
 *
 * Average-v1 Hydrate-Feldabbildung (hier pflegen):
 * - Kopf: campaign/product_title/briefing, order_discount*, ae_*, Summen,
 *   Sonderfreigabe, configuration_snapshot_id, draft_payload
 * - Position: Katalog-Identität, Preislisten-Pin, Länge/Spots, Konditionen,
 *   AE, Engine-Freeze, Komponenten (+ Strategie), time_ranges, plan_rows,
 *   position_discounts, effective_configuration_snapshot_id
 * - Methoden-/Abrechnungskennzeichen: nur `spot_method=average` und
 *   `pricing_settlement_mode=normal`; kein `component_profile`; Strategie nur
 *   gültige Enum-Werte bzw. leer ohne Komponenten
 * - Kindlisten: `time_ranges`/`plan_rows` Pflicht (fehlend/null ≠ `[]`);
 *   `components`/`position_discounts`/`order_discounts` dürfen fehlen (Legacy
 *   leer); explizites null ungültig; `[]` = gültige leere Liste
 *
 * Average-v2 Ergänzung (BL-P4-03e):
 * - `pricing_settlement_mode` `normal`|`fixed_price`
 * - bei `fixed_price`: `fixed_price_nn` Pflicht (> 0), Persistenz aus Frozen
 * - bei `normal`: kein `fixed_price_nn`; Widersprüche fail-closed
 * - kein stilles Zurücksetzen auf `normal`, keine Live-Neuberechnung des Festpreises
 *
 * Average-v3 Ergänzung (BL-P4-03f):
 * - optional `component_profile` `tandem`|`tridem` (null/'' = bisherige Average/Allonge)
 * - bei Profil: Pflicht `shared_total_length`; Komponenten gegen
 *   {@see SpotComponentProfileContract::slots()} (Rollen, kanonische Sortierung
 *   Tandem 1/2 bzw. Tridem 1/2/3, eindeutige int-sort, Längen positiv ganzzahlig,
 *   Summe = `position.length_seconds`)
 * - explizites nicht-skalares `component_profile` (z. B. `[]`) fail-closed, kein
 *   stilles „ohne Profil“
 * - v1/v2 ohne Profil bleiben übernehmbar (bisheriges Leseverhalten inkl. Legacy
 *   `[]` als „kein Profil“); Profil in v1/v2 fail-closed
 *
 * v4 Ergänzung (BL-P4-03g / PO-BLP403G-1 / A1+B1+C1; erweitert BL-P4-03h / PO-BLP403H-1;
 * erweitert BL-P4-03i / PO-BLP403I-1 / A1; erweitert BL-P4-03j / PO-BLP403J-1 / A1;
 * erweitert BL-P4-03k / PO-BLP403K-1 / A1):
 * - optional `spot_method=calendar` mit Pflicht-`planner_entries` (konkrete ISO-Daten)
 * - **03h:** Calendar × `normal` darf optional Hauptspot+Allonge
 *   (`components` + `component_calculation_strategy`) wie Average-Allonge
 * - **03i Vertragserweiterung:** Calendar-Einzelspot (`components` leer/absent) darf
 *   `pricing_settlement_mode` `normal`|`fixed_price` inkl. `fixed_price_nn` (02d/03e-Semantik).
 * - **03j Vertragserweiterung:** Calendar × Festpreis × optional Hauptspot+Allonge
 *   (Strategien laut Inventarregel). Reader vor dieser Erweiterung (PR #126) weist
 *   Calendar-Festpreis×Komponenten ab – neue Snapshots brauchen den erweiterten Reader
 *   (Keys allein ≠ Kompatibilität mit #126-Code). Kein v5, keine Schema-Migration.
 * - **03k Vertragserweiterung:** Calendar × optional `component_profile` `tandem`|`tridem`
 *   × `normal`|`fixed_price` (02e/03f-Semantik; verbindlich `shared_total_length`;
 *   Slot-Asserts wie Average-v3+). Reader vor dieser Erweiterung (PR #127) weist
 *   Calendar×`component_profile` ab – neue Snapshots brauchen den erweiterten Reader
 *   (Keys allein ≠ Kompatibilität mit #127-Code). Kein v5, keine Schema-Migration.
 * - Calendar-Hydrate: Spot-Summe = `total_spot_count`; keine Duplikat-Zellen;
 *   `day_group` muss zum Datum passen; `second_price`/`line_gross` dezimal gültig
 * - Average-Positionen in v4 wie v3; `planner_entries` absent/`[]` (nicht-leer fail-closed)
 * - Adopt hydratisiert Frozen-Zellen/Preise/Pins/Summen/Komponenten/Profil/`fixed_price_nn`
 *   ohne Live-Preisauflösung
 * - Legacy v1–v3 Average, v4 Calendar×`normal` (inkl. 03h-Allonge), Calendar×Festpreis
 *   (03i/03j) und Average-v4 weiter lesbar
 *
 * Verbleibende Pflege bei weiteren Methoden (Abbinder, Budget):
 * 1) neue `materialization_version` oder explizite Contract-Erweiterung,
 * 2) Freeze-Seite im Materializer erweitern,
 * 3) Hydrate-Asserts + Persistenzspiegel hier erweitern,
 * 4) Tests für Parity und Fail-closed.
 */
final class FrozenCalculationPersistenceContract
{
    /**
     * Bekannte lesbare Hydrate-Versionen. Fehlendes materialization_version
     * gilt als Legacy-Version 1 (03a/03c-Stände vor 03b-Tagging).
     *
     * @var list<int>
     */
    public const SUPPORTED_VERSIONS = [1, 2, 3, 4];

    public const LEGACY_IMPLICIT_VERSION = 1;

    /** Aktuelle Freeze-Schreibversion (Calendar×normal/Festpreis inkl. Allonge/Tandem/Tridem, Average-Varianten). */
    public const CURRENT_WRITE_VERSION = 4;

    /**
     * Kindliste muss als Array-Schlüssel vorhanden sein (auch `[]` erlaubt).
     * Fehlender Schlüssel oder null → Fail-closed (keine stille Leer-Übernahme).
     */
    private const CHILD_LIST_REQUIRED = 'required';

    /**
     * Kindliste darf aus Legacy-Gründen fehlen (= gültig leer).
     * Explizites null ist ungültig; `[]` ist gültige leere Liste.
     */
    private const CHILD_LIST_OPTIONAL_ABSENT = 'optional_absent';

    public function __construct(
        private readonly ConfigurationSnapshotCloneService $clones,
        private readonly CalculationNumberSequencer $calculationNumbers,
        private readonly CalculationDynamicFieldWriter $dynamicFields,
    ) {}

    /**
     * @param  array<string, mixed>  $materialization
     */
    public function resolveVersion(array $materialization): int
    {
        if (! array_key_exists('materialization_version', $materialization)
            || $materialization['materialization_version'] === null) {
            return self::LEGACY_IMPLICIT_VERSION;
        }

        $version = $materialization['materialization_version'];
        if (! is_int($version)) {
            throw ValidationException::withMessages([
                'frozen_materialization' => 'Die Materialisierungsversion der Vorlage ist ungültig.',
            ]);
        }

        if (! in_array($version, self::SUPPORTED_VERSIONS, true)) {
            throw ValidationException::withMessages([
                'frozen_materialization' => "Materialisierungsversion {$version} wird von dieser Anwendung nicht unterstützt.",
            ]);
        }

        return $version;
    }

    /**
     * Version + Pflichtstruktur prüfen, bevor Persistenz beginnt.
     *
     * @param  array<string, mixed>  $materialization
     */
    public function assertHydratable(array $materialization): int
    {
        $version = $this->resolveVersion($materialization);
        $this->assertAverageShape($materialization, $version);

        return $version;
    }

    /**
     * Persistiert eine übernommene Draft-Kalkulation aus Frozen-Stand.
     * Muss innerhalb einer DB-Transaktion des Aufrufers laufen.
     *
     * @param  array<string, mixed>  $materialization
     * @param  array{
     *     customer_name: string,
     *     agency_name: ?string,
     *     campaign: ?string,
     *     advisor: User,
     *     origin_version: StandardOfferVersion
     * }  $adoptionContext
     */
    public function hydrateAdoptedCalculation(array $materialization, array $adoptionContext): Calculation
    {
        $this->assertHydratable($materialization);

        $user = $adoptionContext['advisor'];
        $origin = $adoptionContext['origin_version'];
        $customerName = $adoptionContext['customer_name'];
        $agencyName = $adoptionContext['agency_name'];
        $campaignOverride = $adoptionContext['campaign'];

        $baseSnapshotId = (int) $materialization['configuration_snapshot_id'];
        $templateBase = ConfigurationSnapshot::query()->whereKey($baseSnapshotId)->first();
        if ($templateBase === null) {
            throw ValidationException::withMessages([
                'frozen_materialization' => 'Eingefrorener Konfigurationssnapshot der Vorlage fehlt oder ist ungültig.',
            ]);
        }

        $calcBase = $this->clones->cloneCalculationBase($templateBase);
        [$year, $seq, $number] = $this->calculationNumbers->next();

        $calculation = new Calculation;
        $calculation->number = $number;
        $calculation->number_year = $year;
        $calculation->number_seq = $seq;
        $calculation->status = CalculationStatus::Draft;
        $calculation->planning_mode = PlanningMode::Manual;
        $calculation->advisor_id = $user->id;
        // BL-P2-03a: Freitext-Übernahme → nachvollziehbare vorläufige Accounts (ohne Domain).
        // Standardangebot→Calc: Freitext → vorläufige Accounts; Rechnungsempfänger Kunde (F1, keine Historie erfinden).
        $crmPayload = [
            'customer_name' => $customerName,
            'agency_name' => $agencyName,
            'ensure_provisional_customer' => true,
            'ensure_provisional_agency' => $agencyName !== null && $agencyName !== '',
            'invoice_recipient' => 'customer',
        ];
        $crm = app(CrmOrderHeaderBinder::class)
            ->resolveForCalculation($crmPayload, $user, allowFreitextProvisional: true);
        $calculation->customer_name = $crm['customer_name'];
        $calculation->agency_name = $crm['agency_name'];
        $calculation->customer_account_id = $crm['customer_account_id'];
        $calculation->agency_account_id = $crm['agency_account_id'];
        $calculation->customer_version_id = $crm['customer_version_id'];
        $calculation->agency_version_id = $crm['agency_version_id'];
        $calculation->invoice_recipient = $crm['invoice_recipient'];
        $calculation->customer_meridian_number = $crm['customer_meridian_number'];
        $calculation->agency_meridian_number = $crm['agency_meridian_number'];
        $calculation->customer_salesforce_account_id = $crm['customer_salesforce_account_id'];
        $calculation->agency_salesforce_account_id = $crm['agency_salesforce_account_id'];
        $calculation->campaign = $campaignOverride !== null && $campaignOverride !== ''
            ? $campaignOverride
            : ($materialization['campaign'] ?? null);
        $calculation->product_title = $materialization['product_title'] ?? null;
        $calculation->briefing = $materialization['briefing'] ?? null;
        $calculation->order_discount_percent = $materialization['order_discount_percent'] ?? '0';
        $calculation->ae_enabled = (bool) ($materialization['ae_enabled'] ?? false);
        $calculation->media_gross = $materialization['media_gross'] ?? '0';
        $calculation->position_discount_total = $materialization['position_discount_total'] ?? '0';
        $calculation->order_discount_total = $materialization['order_discount_total'] ?? '0';
        $calculation->ae_total = $materialization['ae_total'] ?? '0';
        $calculation->nn_invest = $materialization['nn_invest'] ?? '0';
        $calculation->requires_special_approval = (bool) ($materialization['requires_special_approval'] ?? false);
        $calculation->special_approval_reasons = $materialization['special_approval_reasons'] ?? null;
        $calculation->personal_discount_limit_percent = $user->discount_limit_percent;
        $calculation->lock_version = 1;
        $calculation->configuration_snapshot_id = $calcBase->id;
        $calculation->originStandardOfferVersion()->associate($origin);
        $calculation->save();

        $this->persistOrderDiscounts($calculation, $materialization['order_discounts'] ?? []);
        $this->persistPositions($calculation, $calcBase, $materialization['positions']);
        $this->syncDynamicFields($calculation, $calcBase, $materialization, $customerName);

        return $calculation->fresh([
            'positions.timeRanges',
            'positions.planRows',
            'positions.plannerEntries',
            'positions.components',
            'positions.discounts',
            'orderDiscounts',
        ]) ?? $calculation;
    }

    /**
     * @param  array<string, mixed>  $materialization
     */
    private function assertAverageShape(array $materialization, int $version): void
    {
        $baseSnapshotId = (int) ($materialization['configuration_snapshot_id'] ?? 0);
        if ($baseSnapshotId <= 0) {
            throw ValidationException::withMessages([
                'frozen_materialization' => 'Eingefrorene Vorlagendaten sind unvollständig (Konfigurationssnapshot fehlt).',
            ]);
        }

        $positions = $materialization['positions'] ?? null;
        if (! is_array($positions) || $positions === []) {
            throw ValidationException::withMessages([
                'frozen_materialization' => 'Eingefrorene Vorlagendaten sind unvollständig (keine Positionen).',
            ]);
        }

        $orderDiscounts = $this->assertRootChildList($materialization, 'order_discounts');
        foreach ($orderDiscounts as $index => $discount) {
            if (! is_array($discount)) {
                throw ValidationException::withMessages([
                    'frozen_materialization' => "Eingefrorene Vorlagendaten sind ungültig (Kopfrabatt {$index}).",
                ]);
            }
            $this->assertDiscountRow($discount, "Kopfrabatt {$index}");
        }

        foreach ($positions as $index => $position) {
            if (! is_array($position)) {
                throw ValidationException::withMessages([
                    'frozen_materialization' => "Eingefrorene Vorlagendaten sind ungültig (Position {$index}).",
                ]);
            }

            foreach ([
                'inventory_id',
                'advertising_medium_id',
                'price_list_id',
                'kind',
                'length_seconds',
                'total_spot_count',
                'effective_configuration_snapshot_id',
            ] as $required) {
                if (! array_key_exists($required, $position) || $position[$required] === null || $position[$required] === '') {
                    throw ValidationException::withMessages([
                        'frozen_materialization' => "Eingefrorene Vorlagendaten sind unvollständig (Position {$index}: {$required}).",
                    ]);
                }
            }

            if ((int) $position['effective_configuration_snapshot_id'] <= 0
                || (int) $position['inventory_id'] <= 0
                || (int) $position['advertising_medium_id'] <= 0
                || (int) $position['price_list_id'] <= 0) {
                throw ValidationException::withMessages([
                    'frozen_materialization' => "Eingefrorene Vorlagendaten sind ungültig (Position {$index}: Referenzen).",
                ]);
            }

            $this->assertAverageMethodAndSettlement($position, $index, $version);

            $spotMethod = array_key_exists('spot_method', $position) && is_string($position['spot_method'])
                ? $position['spot_method']
                : SpotCalculationMethod::Average->value;

            if ($spotMethod === SpotCalculationMethod::Calendar->value) {
                $this->assertCalendarPositionChildren($position, $index, $version);
            } else {
                $this->assertAveragePositionChildren($position, $index, $version);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $position
     */
    private function assertAveragePositionChildren(array $position, int $index, int $version): void
    {
        // Kindlisten (03a/03c-Freeze): time_ranges/plan_rows immer geschrieben und
        // Pflicht; components/position_discounts dürfen aus Legacy fehlen (= leer).
        $components = $this->assertChildList(
            $position,
            $index,
            'components',
            self::CHILD_LIST_OPTIONAL_ABSENT,
        );
        $this->assertComponentStrategyAndProfile($position, $index, $components, $version);
        foreach ($components as $childIndex => $component) {
            $this->assertComponentRow($component, $index, $childIndex, $position);
        }
        $profileRaw = $position['component_profile'] ?? null;
        if ($components !== [] && ($profileRaw === null || $profileRaw === '' || $profileRaw === [])) {
            $this->assertOptionalAllongeComponentStructure($components, $index);
        }

        $ranges = $this->assertChildList(
            $position,
            $index,
            'time_ranges',
            self::CHILD_LIST_REQUIRED,
        );
        foreach ($ranges as $childIndex => $range) {
            $this->assertTimeRangeRow($range, $index, $childIndex);
        }

        $planRows = $this->assertChildList(
            $position,
            $index,
            'plan_rows',
            self::CHILD_LIST_REQUIRED,
        );
        foreach ($planRows as $childIndex => $row) {
            $this->assertPlanRow($row, $index, $childIndex);
        }

        $plannerEntries = $this->assertChildList(
            $position,
            $index,
            'planner_entries',
            self::CHILD_LIST_OPTIONAL_ABSENT,
        );
        if ($plannerEntries !== []) {
            throw ValidationException::withMessages([
                'frozen_materialization' => "Eingefrorene Vorlagendaten sind ungültig (Position {$index}: planner_entries nur für calendar).",
            ]);
        }

        $discounts = $this->assertChildList(
            $position,
            $index,
            'position_discounts',
            self::CHILD_LIST_OPTIONAL_ABSENT,
        );
        foreach ($discounts as $childIndex => $discount) {
            $this->assertDiscountRow($discount, "Position {$index}: position_discounts.{$childIndex}");
        }
    }

    /**
     * BL-P4-03g/03h/03i/03j/03k: Calendar×normal/Festpreis optional Hauptspot+Allonge
     * oder Tandem/Tridem-Profil; Settlement in assertAverageMethodAndSettlement.
     *
     * @param  array<string, mixed>  $position
     */
    private function assertCalendarPositionChildren(array $position, int $index, int $version): void
    {
        $components = $this->assertChildList(
            $position,
            $index,
            'components',
            self::CHILD_LIST_OPTIONAL_ABSENT,
        );
        $this->assertComponentStrategyAndProfile($position, $index, $components, $version);
        foreach ($components as $childIndex => $component) {
            $this->assertComponentRow($component, $index, $childIndex, $position);
        }
        $profileRaw = $position['component_profile'] ?? null;
        if ($components !== [] && ($profileRaw === null || $profileRaw === '' || $profileRaw === [])) {
            $this->assertOptionalAllongeComponentStructure($components, $index);
        }

        $ranges = $this->assertChildList(
            $position,
            $index,
            'time_ranges',
            self::CHILD_LIST_REQUIRED,
        );
        foreach ($ranges as $childIndex => $range) {
            $this->assertTimeRangeRow($range, $index, $childIndex);
        }

        $planRows = $this->assertChildList(
            $position,
            $index,
            'plan_rows',
            self::CHILD_LIST_REQUIRED,
        );
        foreach ($planRows as $childIndex => $row) {
            $this->assertPlanRow($row, $index, $childIndex);
        }

        $plannerEntries = $this->assertChildList(
            $position,
            $index,
            'planner_entries',
            self::CHILD_LIST_REQUIRED,
        );

        $totalSpotCount = $position['total_spot_count'];
        if ((! is_int($totalSpotCount) && ! (is_string($totalSpotCount) && ctype_digit($totalSpotCount)))
            || (int) $totalSpotCount < 1) {
            throw ValidationException::withMessages([
                'frozen_materialization' => "Eingefrorene Vorlagendaten sind ungültig (Position {$index}: total_spot_count).",
            ]);
        }
        $expectedTotal = (int) $totalSpotCount;

        if ($plannerEntries === []) {
            throw ValidationException::withMessages([
                'frozen_materialization' => "Eingefrorene Vorlagendaten sind ungültig (Position {$index}: planner_entries leer trotz total_spot_count {$expectedTotal}).",
            ]);
        }

        $spotSum = 0;
        /** @var array<string, true> $seenCells */
        $seenCells = [];
        foreach ($plannerEntries as $childIndex => $entry) {
            $this->assertPlannerEntryRow($entry, $index, $childIndex);
            $cellKey = ((string) $entry['date']).'|'.((int) $entry['hour']);
            if (array_key_exists($cellKey, $seenCells)) {
                throw ValidationException::withMessages([
                    'frozen_materialization' => "Eingefrorene Vorlagendaten sind ungültig (Position {$index}: doppelte planner_entries-Zelle {$cellKey}).",
                ]);
            }
            $seenCells[$cellKey] = true;
            $spotSum += (int) $entry['spot_count'];
        }

        if ($spotSum !== $expectedTotal) {
            throw ValidationException::withMessages([
                'frozen_materialization' => "Eingefrorene Vorlagendaten sind ungültig (Position {$index}: Spot-Summe {$spotSum} ≠ total_spot_count {$expectedTotal}).",
            ]);
        }

        $discounts = $this->assertChildList(
            $position,
            $index,
            'position_discounts',
            self::CHILD_LIST_OPTIONAL_ABSENT,
        );
        foreach ($discounts as $childIndex => $discount) {
            $this->assertDiscountRow($discount, "Position {$index}: position_discounts.{$childIndex}");
        }
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function assertPlannerEntryRow(array $entry, int $positionIndex, int $childIndex): void
    {
        foreach (['date', 'hour', 'day_group', 'spot_count', 'second_price', 'line_gross'] as $required) {
            if (! array_key_exists($required, $entry) || $entry[$required] === null || $entry[$required] === '') {
                throw ValidationException::withMessages([
                    'frozen_materialization' => "Eingefrorene Vorlagendaten sind unvollständig (Position {$positionIndex}: planner_entries.{$childIndex}.{$required}).",
                ]);
            }
        }

        $date = $entry['date'];
        if (! is_string($date) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            throw ValidationException::withMessages([
                'frozen_materialization' => "Eingefrorene Vorlagendaten sind ungültig (Position {$positionIndex}: planner_entries.{$childIndex}.date).",
            ]);
        }
        $parts = explode('-', $date);
        if (! checkdate((int) $parts[1], (int) $parts[2], (int) $parts[0])) {
            throw ValidationException::withMessages([
                'frozen_materialization' => "Eingefrorene Vorlagendaten sind ungültig (Position {$positionIndex}: planner_entries.{$childIndex}.date).",
            ]);
        }

        $hour = $entry['hour'];
        if ((! is_int($hour) && ! (is_string($hour) && ctype_digit($hour))) || (int) $hour < 0 || (int) $hour > 23) {
            throw ValidationException::withMessages([
                'frozen_materialization' => "Eingefrorene Vorlagendaten sind ungültig (Position {$positionIndex}: planner_entries.{$childIndex}.hour).",
            ]);
        }

        $spots = $entry['spot_count'];
        if ((! is_int($spots) && ! (is_string($spots) && ctype_digit($spots))) || (int) $spots < 1) {
            throw ValidationException::withMessages([
                'frozen_materialization' => "Eingefrorene Vorlagendaten sind ungültig (Position {$positionIndex}: planner_entries.{$childIndex}.spot_count).",
            ]);
        }

        if (! is_string($entry['day_group'])) {
            throw ValidationException::withMessages([
                'frozen_materialization' => "Eingefrorene Vorlagendaten sind ungültig (Position {$positionIndex}: planner_entries.{$childIndex}.day_group).",
            ]);
        }

        $dayGroup = DayGroup::tryFrom($entry['day_group']);
        if ($dayGroup === null) {
            throw ValidationException::withMessages([
                'frozen_materialization' => "Eingefrorene Vorlagendaten sind ungültig (Position {$positionIndex}: planner_entries.{$childIndex}.day_group).",
            ]);
        }

        $expectedDayGroup = DayGroupFromDate::resolve($date);
        if ($dayGroup !== $expectedDayGroup) {
            throw ValidationException::withMessages([
                'frozen_materialization' => "Eingefrorene Vorlagendaten sind ungültig (Position {$positionIndex}: planner_entries.{$childIndex}.day_group passt nicht zum Datum).",
            ]);
        }

        $this->assertFrozenPlannerDecimal(
            $entry['second_price'],
            $positionIndex,
            $childIndex,
            'second_price',
            Decimal::PRICE_SCALE,
            mustBePositive: true,
        );
        $this->assertFrozenPlannerDecimal(
            $entry['line_gross'],
            $positionIndex,
            $childIndex,
            'line_gross',
            Decimal::MONEY_SCALE,
            mustBePositive: true,
        );
    }

    private function assertFrozenPlannerDecimal(
        mixed $value,
        int $positionIndex,
        int $childIndex,
        string $field,
        int $maxScale,
        bool $mustBePositive,
    ): void {
        if (! is_string($value) && ! is_int($value)) {
            throw ValidationException::withMessages([
                'frozen_materialization' => "Eingefrorene Vorlagendaten sind ungültig (Position {$positionIndex}: planner_entries.{$childIndex}.{$field}).",
            ]);
        }

        $raw = is_int($value) ? (string) $value : trim($value);
        $pattern = '/^\d+(\.\d{1,'.$maxScale.'})?$/';
        if ($raw === '' || preg_match($pattern, $raw) !== 1 || ! is_numeric($raw)) {
            throw ValidationException::withMessages([
                'frozen_materialization' => "Eingefrorene Vorlagendaten sind ungültig (Position {$positionIndex}: planner_entries.{$childIndex}.{$field}).",
            ]);
        }

        if ($mustBePositive && bccomp($raw, '0', $maxScale) !== 1) {
            throw ValidationException::withMessages([
                'frozen_materialization' => "Eingefrorene Vorlagendaten sind ungültig (Position {$positionIndex}: planner_entries.{$childIndex}.{$field}).",
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $position
     */
    private function assertAverageMethodAndSettlement(array $position, int $index, int $version): void
    {
        $spotMethod = SpotCalculationMethod::Average->value;
        if (array_key_exists('spot_method', $position) && $position['spot_method'] !== null) {
            if (! is_string($position['spot_method'])) {
                throw ValidationException::withMessages([
                    'frozen_materialization' => "Eingefrorene Vorlagendaten sind ungültig (Position {$index}: spot_method).",
                ]);
            }
            $spotMethod = $position['spot_method'];
        }

        if ($spotMethod === SpotCalculationMethod::Calendar->value) {
            if ($version < 4) {
                throw ValidationException::withMessages([
                    'frozen_materialization' => "Eingefrorene Vorlagendaten sind ungültig (Position {$index}: calendar erfordert Materialisierung v4).",
                ]);
            }

            $modeRaw = $position['pricing_settlement_mode'] ?? null;
            $hasFixedNn = array_key_exists('fixed_price_nn', $position)
                && $position['fixed_price_nn'] !== null
                && $position['fixed_price_nn'] !== '';

            $hasComponents = false;
            if (array_key_exists('components', $position) && $position['components'] !== null) {
                if (! is_array($position['components'])) {
                    throw ValidationException::withMessages([
                        'frozen_materialization' => "Eingefrorene Vorlagendaten sind ungültig (Position {$index}: components).",
                    ]);
                }
                $hasComponents = $position['components'] !== [];
            }

            if ($hasComponents) {
                if ($modeRaw === null || $modeRaw === '') {
                    throw ValidationException::withMessages([
                        'frozen_materialization' => "Eingefrorene Vorlagendaten sind unvollständig (Position {$index}: pricing_settlement_mode).",
                    ]);
                }
                if (! is_string($modeRaw)) {
                    throw ValidationException::withMessages([
                        'frozen_materialization' => "Eingefrorene Vorlagendaten sind ungültig (Position {$index}: pricing_settlement_mode).",
                    ]);
                }
                $calendarModeWithComponents = PricingSettlementMode::tryFrom($modeRaw);
                if ($calendarModeWithComponents === null) {
                    throw ValidationException::withMessages([
                        'frozen_materialization' => "Eingefrorene Vorlagendaten sind ungültig (Position {$index}: pricing_settlement_mode).",
                    ]);
                }
                if ($calendarModeWithComponents === PricingSettlementMode::FixedPrice) {
                    if (! $hasFixedNn) {
                        throw ValidationException::withMessages([
                            'frozen_materialization' => "Eingefrorene Vorlagendaten sind unvollständig (Position {$index}: fixed_price_nn).",
                        ]);
                    }
                    $this->assertFrozenFixedPriceNn($position['fixed_price_nn'], $index);

                    return;
                }
                if ($hasFixedNn) {
                    throw ValidationException::withMessages([
                        'frozen_materialization' => "Eingefrorene Vorlagendaten sind ungültig (Position {$index}: fixed_price_nn ohne Festpreismodus).",
                    ]);
                }

                return;
            }

            if ($modeRaw === null || $modeRaw === '') {
                throw ValidationException::withMessages([
                    'frozen_materialization' => "Eingefrorene Vorlagendaten sind unvollständig (Position {$index}: pricing_settlement_mode).",
                ]);
            }
            if (! is_string($modeRaw)) {
                throw ValidationException::withMessages([
                    'frozen_materialization' => "Eingefrorene Vorlagendaten sind ungültig (Position {$index}: pricing_settlement_mode).",
                ]);
            }
            $calendarMode = PricingSettlementMode::tryFrom($modeRaw);
            if ($calendarMode === null) {
                throw ValidationException::withMessages([
                    'frozen_materialization' => "Eingefrorene Vorlagendaten sind ungültig (Position {$index}: pricing_settlement_mode).",
                ]);
            }
            if ($calendarMode === PricingSettlementMode::FixedPrice) {
                if (! $hasFixedNn) {
                    throw ValidationException::withMessages([
                        'frozen_materialization' => "Eingefrorene Vorlagendaten sind unvollständig (Position {$index}: fixed_price_nn).",
                    ]);
                }
                $this->assertFrozenFixedPriceNn($position['fixed_price_nn'], $index);

                return;
            }
            if ($hasFixedNn) {
                throw ValidationException::withMessages([
                    'frozen_materialization' => "Eingefrorene Vorlagendaten sind ungültig (Position {$index}: fixed_price_nn ohne Festpreismodus).",
                ]);
            }

            return;
        }

        if ($spotMethod !== SpotCalculationMethod::Average->value) {
            throw ValidationException::withMessages([
                'frozen_materialization' => "Eingefrorene Vorlagendaten sind ungültig (Position {$index}: spot_method muss average oder calendar sein).",
            ]);
        }

        $modeRaw = $position['pricing_settlement_mode'] ?? null;
        $hasFixedNn = array_key_exists('fixed_price_nn', $position)
            && $position['fixed_price_nn'] !== null
            && $position['fixed_price_nn'] !== '';

        if ($version <= self::LEGACY_IMPLICIT_VERSION) {
            if ($modeRaw !== null && $modeRaw !== '') {
                if (! is_string($modeRaw) || $modeRaw !== PricingSettlementMode::Normal->value) {
                    throw ValidationException::withMessages([
                        'frozen_materialization' => "Eingefrorene Vorlagendaten sind ungültig (Position {$index}: pricing_settlement_mode muss normal sein).",
                    ]);
                }
            }
            if ($hasFixedNn) {
                throw ValidationException::withMessages([
                    'frozen_materialization' => "Eingefrorene Vorlagendaten sind ungültig (Position {$index}: fixed_price_nn ist für Average-v1 nicht erlaubt).",
                ]);
            }

            return;
        }

        // v2+: Settlement-Kennzeichen Pflicht; Widersprüche fail-closed (kein stilles Zurücksetzen).
        if ($modeRaw === null || $modeRaw === '') {
            throw ValidationException::withMessages([
                'frozen_materialization' => "Eingefrorene Vorlagendaten sind unvollständig (Position {$index}: pricing_settlement_mode).",
            ]);
        }

        if (! is_string($modeRaw)) {
            throw ValidationException::withMessages([
                'frozen_materialization' => "Eingefrorene Vorlagendaten sind ungültig (Position {$index}: pricing_settlement_mode).",
            ]);
        }

        $mode = PricingSettlementMode::tryFrom($modeRaw);
        if ($mode === null) {
            throw ValidationException::withMessages([
                'frozen_materialization' => "Eingefrorene Vorlagendaten sind ungültig (Position {$index}: pricing_settlement_mode).",
            ]);
        }

        if ($mode === PricingSettlementMode::FixedPrice) {
            if (! $hasFixedNn) {
                throw ValidationException::withMessages([
                    'frozen_materialization' => "Eingefrorene Vorlagendaten sind unvollständig (Position {$index}: fixed_price_nn).",
                ]);
            }
            $this->assertFrozenFixedPriceNn($position['fixed_price_nn'], $index);

            return;
        }

        if ($hasFixedNn) {
            throw ValidationException::withMessages([
                'frozen_materialization' => "Eingefrorene Vorlagendaten sind ungültig (Position {$index}: fixed_price_nn ohne Festpreismodus).",
            ]);
        }
    }

    /**
     * Strictes Dezimalformat vor jedem bccomp: höchstens zwei Nachkommastellen,
     * keine Exponentialnotation, nur skalar string|int.
     */
    private function assertFrozenFixedPriceNn(mixed $value, int $index): string
    {
        if (! is_string($value) && ! is_int($value)) {
            throw ValidationException::withMessages([
                'frozen_materialization' => "Eingefrorene Vorlagendaten sind ungültig (Position {$index}: fixed_price_nn).",
            ]);
        }

        $raw = is_int($value) ? (string) $value : trim($value);
        if ($raw === '' || ! preg_match('/^\d+(\.\d{1,2})?$/', $raw) || ! is_numeric($raw)) {
            throw ValidationException::withMessages([
                'frozen_materialization' => "Eingefrorene Vorlagendaten sind ungültig (Position {$index}: fixed_price_nn).",
            ]);
        }

        if (bccomp($raw, '0', 2) !== 1) {
            throw ValidationException::withMessages([
                'frozen_materialization' => "Eingefrorene Vorlagendaten sind ungültig (Position {$index}: fixed_price_nn).",
            ]);
        }

        return bcadd($raw, '0', 2);
    }

    /**
     * @param  array<string, mixed>  $position
     * @param  list<array<string, mixed>>  $components
     */
    private function assertComponentStrategyAndProfile(array $position, int $index, array $components, int $version): void
    {
        $profileRaw = $position['component_profile'] ?? null;
        // v1/v2 Legacy: explizites [] blieb bisher „kein Profil“ – Leseverhalten erhalten.
        $hasProfile = $profileRaw !== null && $profileRaw !== '' && $profileRaw !== [];

        if ($version < 3) {
            if ($hasProfile) {
                throw ValidationException::withMessages([
                    'frozen_materialization' => "Eingefrorene Vorlagendaten sind ungültig (Position {$index}: component_profile ist für Average-v{$version} nicht erlaubt).",
                ]);
            }

            $this->assertOptionalAllongeStrategy($position, $index, $components);

            return;
        }

        if ($profileRaw === null || $profileRaw === '') {
            $this->assertOptionalAllongeStrategy($position, $index, $components);

            return;
        }

        // v3: nicht-skalares Profil (z. B. []) nicht still als „ohne Profil“ lesen.
        if (! is_string($profileRaw)) {
            throw ValidationException::withMessages([
                'frozen_materialization' => "Eingefrorene Vorlagendaten sind ungültig (Position {$index}: component_profile).",
            ]);
        }

        $profile = SpotComponentProfile::tryFrom($profileRaw);
        if ($profile === null) {
            throw ValidationException::withMessages([
                'frozen_materialization' => "Eingefrorene Vorlagendaten sind ungültig (Position {$index}: component_profile).",
            ]);
        }

        $required = SpotComponentProfileContract::requiredStrategy($profile)->value;
        $strategy = $position['component_calculation_strategy'] ?? null;
        if (! is_string($strategy) || $strategy !== $required) {
            throw ValidationException::withMessages([
                'frozen_materialization' => "Eingefrorene Vorlagendaten sind ungültig (Position {$index}: component_calculation_strategy muss shared_total_length sein).",
            ]);
        }
        if ($components === []) {
            throw ValidationException::withMessages([
                'frozen_materialization' => "Eingefrorene Vorlagendaten sind unvollständig (Position {$index}: components für {$profile->value}).",
            ]);
        }

        $this->assertV3ProfileComponentsMatchSlots($profile, $components, $position, $index);
    }

    /**
     * v3 Tandem/Tridem: Komponenten müssen exakt den kanonischen Slots entsprechen.
     *
     * @param  list<array<string, mixed>>  $components
     * @param  array<string, mixed>  $position
     */
    private function assertV3ProfileComponentsMatchSlots(
        SpotComponentProfile $profile,
        array $components,
        array $position,
        int $index,
    ): void {
        $slots = SpotComponentProfileContract::slots($profile);
        $expectedSorts = array_map(
            static fn (array $slot): int => $slot['sort'],
            $slots,
        );

        if (count($components) !== count($slots)) {
            throw ValidationException::withMessages([
                'frozen_materialization' => "Eingefrorene Vorlagendaten sind ungültig (Position {$index}: Komponenten passen nicht zu {$profile->value}).",
            ]);
        }

        $seenSorts = [];
        $lengthSum = 0;

        foreach ($components as $childIndex => $component) {
            $sort = $component['sort'] ?? null;
            if (! is_int($sort) || ! in_array($sort, $expectedSorts, true)) {
                throw ValidationException::withMessages([
                    'frozen_materialization' => "Eingefrorene Vorlagendaten sind ungültig (Position {$index}: components.{$childIndex}.sort).",
                ]);
            }
            if (isset($seenSorts[$sort])) {
                throw ValidationException::withMessages([
                    'frozen_materialization' => "Eingefrorene Vorlagendaten sind ungültig (Position {$index}: components.{$childIndex}.sort).",
                ]);
            }
            $seenSorts[$sort] = true;

            $length = $component['length_seconds'] ?? null;
            if (! is_int($length) || $length < 1) {
                throw ValidationException::withMessages([
                    'frozen_materialization' => "Eingefrorene Vorlagendaten sind ungültig (Position {$index}: components.{$childIndex}.length_seconds).",
                ]);
            }
            $lengthSum += $length;

            $role = $component['role'] ?? null;
            if (! is_string($role) || $role === '') {
                throw ValidationException::withMessages([
                    'frozen_materialization' => "Eingefrorene Vorlagendaten sind ungültig (Position {$index}: components.{$childIndex}.role).",
                ]);
            }
        }

        if (count($seenSorts) !== count($expectedSorts)) {
            throw ValidationException::withMessages([
                'frozen_materialization' => "Eingefrorene Vorlagendaten sind ungültig (Position {$index}: Komponenten passen nicht zu {$profile->value}).",
            ]);
        }

        $ordered = $components;
        usort(
            $ordered,
            static fn (array $left, array $right): int => ((int) $left['sort']) <=> ((int) $right['sort']),
        );

        foreach ($slots as $slotIndex => $slot) {
            $role = $ordered[$slotIndex]['role'] ?? null;
            if (! is_string($role) || $role !== $slot['role']->value) {
                throw ValidationException::withMessages([
                    'frozen_materialization' => "Eingefrorene Vorlagendaten sind ungültig (Position {$index}: Komponenten passen nicht zu {$profile->value}).",
                ]);
            }
            if ((int) $ordered[$slotIndex]['sort'] !== $slot['sort']) {
                throw ValidationException::withMessages([
                    'frozen_materialization' => "Eingefrorene Vorlagendaten sind ungültig (Position {$index}: Komponenten passen nicht zu {$profile->value}).",
                ]);
            }
        }

        $positionLength = $position['length_seconds'] ?? null;
        if (! is_int($positionLength) || $lengthSum !== $positionLength) {
            throw ValidationException::withMessages([
                'frozen_materialization' => "Eingefrorene Vorlagendaten sind ungültig (Position {$index}: Komponentenlängen weichen von length_seconds ab).",
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $position
     * @param  list<array<string, mixed>>  $components
     */
    private function assertOptionalAllongeStrategy(array $position, int $index, array $components): void
    {
        $strategy = $position['component_calculation_strategy'] ?? null;
        if ($components !== []) {
            if (! is_string($strategy) || $strategy === '') {
                throw ValidationException::withMessages([
                    'frozen_materialization' => "Eingefrorene Vorlagendaten sind unvollständig (Position {$index}: component_calculation_strategy).",
                ]);
            }
            if (ComponentCalculationStrategy::tryFrom($strategy) === null) {
                throw ValidationException::withMessages([
                    'frozen_materialization' => "Eingefrorene Vorlagendaten sind ungültig (Position {$index}: component_calculation_strategy).",
                ]);
            }

            return;
        }

        // Draft-/Komponentenvertrag: Strategie ohne Komponenten ist unzulässig
        // (auch bei gültigem Enum-Wert) – Legacy ohne Strategy-Key bleibt leer/OK.
        if ($strategy !== null && $strategy !== '') {
            throw ValidationException::withMessages([
                'frozen_materialization' => "Eingefrorene Vorlagendaten sind ungültig (Position {$index}: component_calculation_strategy ohne Komponenten).",
            ]);
        }
    }

    /**
     * Optional Hauptspot+Allonge laut ComponentValidator: genau ein Hauptspot,
     * höchstens eine Allonge, positive Längen, eindeutige sort/length_index.
     *
     * @param  list<array<string, mixed>>  $components
     */
    private function assertOptionalAllongeComponentStructure(array $components, int $index): void
    {
        $mainCount = 0;
        $allongeCount = 0;
        /** @var array<int, true> $seenSorts */
        $seenSorts = [];

        foreach ($components as $childIndex => $component) {
            $role = $component['role'] ?? null;
            if ($role === SpotComponentRole::MainSpot->value) {
                $mainCount++;
            }
            if ($role === SpotComponentRole::Allonge->value) {
                $allongeCount++;
            }

            $length = $component['length_seconds'] ?? null;
            if ((! is_int($length) && ! (is_string($length) && ctype_digit($length)))
                || (int) $length < 1) {
                throw ValidationException::withMessages([
                    'frozen_materialization' => "Eingefrorene Vorlagendaten sind ungültig (Position {$index}: components.{$childIndex}.length_seconds).",
                ]);
            }

            $sort = $component['sort'] ?? null;
            if (! is_int($sort) && ! (is_string($sort) && ctype_digit($sort))) {
                throw ValidationException::withMessages([
                    'frozen_materialization' => "Eingefrorene Vorlagendaten sind ungültig (Position {$index}: components.{$childIndex}.sort).",
                ]);
            }
            $sortInt = (int) $sort;
            if (isset($seenSorts[$sortInt])) {
                throw ValidationException::withMessages([
                    'frozen_materialization' => "Eingefrorene Vorlagendaten sind ungültig (Position {$index}: components.{$childIndex}.sort).",
                ]);
            }
            $seenSorts[$sortInt] = true;

            $lengthIndex = $component['length_index'] ?? null;
            if (! is_int($lengthIndex) && ! (is_string($lengthIndex) && ctype_digit($lengthIndex))) {
                throw ValidationException::withMessages([
                    'frozen_materialization' => "Eingefrorene Vorlagendaten sind ungültig (Position {$index}: components.{$childIndex}.length_index).",
                ]);
            }
            if ((int) $lengthIndex < 0) {
                throw ValidationException::withMessages([
                    'frozen_materialization' => "Eingefrorene Vorlagendaten sind ungültig (Position {$index}: components.{$childIndex}.length_index).",
                ]);
            }
        }

        if ($mainCount < 1) {
            throw ValidationException::withMessages([
                'frozen_materialization' => "Eingefrorene Vorlagendaten sind ungültig (Position {$index}: Hauptspot fehlt).",
            ]);
        }
        if ($mainCount > 1) {
            throw ValidationException::withMessages([
                'frozen_materialization' => "Eingefrorene Vorlagendaten sind ungültig (Position {$index}: doppelte Komponentenrollen).",
            ]);
        }
        if ($allongeCount > 1) {
            throw ValidationException::withMessages([
                'frozen_materialization' => "Eingefrorene Vorlagendaten sind ungültig (Position {$index}: doppelte Komponentenrollen).",
            ]);
        }
    }

    /**
     * Kindlisten-Präsenz laut Average-v1 Freeze (Materializer schreibt alle Keys):
     * - required: time_ranges, plan_rows – Schlüssel muss Array sein; fehlend/null
     *   scheitert (keine scheinbar erfolgreiche Übernahme mit verlorenen Zeilen).
     * - optional_absent: components, position_discounts – fehlender Schlüssel =
     *   Legacy leer; null ungültig; [] = gültige leere Liste.
     *
     * @param  array<string, mixed>  $position
     * @param  self::CHILD_LIST_REQUIRED|self::CHILD_LIST_OPTIONAL_ABSENT  $presence
     * @return list<array<string, mixed>>
     */
    private function assertChildList(array $position, int $index, string $childKey, string $presence): array
    {
        if (! array_key_exists($childKey, $position)) {
            if ($presence === self::CHILD_LIST_OPTIONAL_ABSENT) {
                return [];
            }

            throw ValidationException::withMessages([
                'frozen_materialization' => "Eingefrorene Vorlagendaten sind unvollständig (Position {$index}: {$childKey} fehlt).",
            ]);
        }

        if ($position[$childKey] === null) {
            throw ValidationException::withMessages([
                'frozen_materialization' => "Eingefrorene Vorlagendaten sind ungültig (Position {$index}: {$childKey} ist null).",
            ]);
        }

        $children = $position[$childKey];
        if (! is_array($children)) {
            throw ValidationException::withMessages([
                'frozen_materialization' => "Eingefrorene Vorlagendaten sind ungültig (Position {$index}: {$childKey}).",
            ]);
        }

        foreach ($children as $childIndex => $child) {
            if (! is_array($child)) {
                throw ValidationException::withMessages([
                    'frozen_materialization' => "Eingefrorene Vorlagendaten sind ungültig (Position {$index}: {$childKey}.{$childIndex}).",
                ]);
            }
        }

        /** @var list<array<string, mixed>> $children */
        return $children;
    }

    /**
     * Kopfrabatte: fehlender Schlüssel = Legacy leer; null ungültig; [] gültig.
     *
     * @param  array<string, mixed>  $materialization
     * @return list<mixed>
     */
    private function assertRootChildList(array $materialization, string $childKey): array
    {
        if (! array_key_exists($childKey, $materialization)) {
            return [];
        }

        if ($materialization[$childKey] === null) {
            throw ValidationException::withMessages([
                'frozen_materialization' => "Eingefrorene Vorlagendaten sind ungültig ({$childKey} ist null).",
            ]);
        }

        $children = $materialization[$childKey];
        if (! is_array($children)) {
            throw ValidationException::withMessages([
                'frozen_materialization' => 'Eingefrorene Vorlagendaten sind ungültig (Kopfrabatte).',
            ]);
        }

        return array_values($children);
    }

    /**
     * @param  array<string, mixed>  $component
     * @param  array<string, mixed>  $position
     */
    private function assertComponentRow(array $component, int $positionIndex, int|string $childIndex, array $position): void
    {
        foreach (['role', 'length_seconds', 'sort', 'length_index'] as $required) {
            if (! array_key_exists($required, $component) || $component[$required] === null || $component[$required] === '') {
                throw ValidationException::withMessages([
                    'frozen_materialization' => "Eingefrorene Vorlagendaten sind unvollständig (Position {$positionIndex}: components.{$childIndex}.{$required}).",
                ]);
            }
        }

        if (! array_key_exists('media_gross', $component) || $component['media_gross'] === null) {
            throw ValidationException::withMessages([
                'frozen_materialization' => "Eingefrorene Vorlagendaten sind unvollständig (Position {$positionIndex}: components.{$childIndex}.media_gross).",
            ]);
        }

        $this->assertFrozenComponentMediaGross(
            $component['media_gross'],
            $positionIndex,
            $childIndex,
        );

        if (! array_key_exists('label', $component) || ! is_string($component['label'])) {
            throw ValidationException::withMessages([
                'frozen_materialization' => "Eingefrorene Vorlagendaten sind unvollständig (Position {$positionIndex}: components.{$childIndex}.label).",
            ]);
        }

        $profileRaw = $position['component_profile'] ?? null;
        $profile = is_string($profileRaw) ? SpotComponentProfile::tryFrom($profileRaw) : null;
        $allowedRoles = $profile !== null
            ? array_map(
                static fn (SpotComponentRole $role): string => $role->value,
                SpotComponentProfileContract::allowedRoles($profile),
            )
            : array_map(
                static fn (SpotComponentRole $role): string => $role->value,
                SpotComponentRole::allowedForOptionalAllonge(),
            );
        if (! is_string($component['role']) || ! in_array($component['role'], $allowedRoles, true)) {
            throw ValidationException::withMessages([
                'frozen_materialization' => "Eingefrorene Vorlagendaten sind ungültig (Position {$positionIndex}: components.{$childIndex}.role).",
            ]);
        }

        if (! is_numeric($component['length_seconds']) || (int) $component['length_seconds'] < 0) {
            throw ValidationException::withMessages([
                'frozen_materialization' => "Eingefrorene Vorlagendaten sind ungültig (Position {$positionIndex}: components.{$childIndex}.length_seconds).",
            ]);
        }
    }

    private function assertFrozenComponentMediaGross(
        mixed $value,
        int $positionIndex,
        int|string $childIndex,
    ): void {
        // Shared-Total-Length friert Komponenten-media_gross als '' ein
        // (Brutto nur positionsseitig); Individual liefert Dezimalbeträge.
        if ($value === '') {
            return;
        }

        if (! is_string($value) && ! is_int($value)) {
            throw ValidationException::withMessages([
                'frozen_materialization' => "Eingefrorene Vorlagendaten sind ungültig (Position {$positionIndex}: components.{$childIndex}.media_gross).",
            ]);
        }

        $raw = is_int($value) ? (string) $value : trim($value);
        $pattern = '/^\d+(\.\d{1,'.Decimal::MONEY_SCALE.'})?$/';
        if ($raw === '' || preg_match($pattern, $raw) !== 1 || ! is_numeric($raw)) {
            throw ValidationException::withMessages([
                'frozen_materialization' => "Eingefrorene Vorlagendaten sind ungültig (Position {$positionIndex}: components.{$childIndex}.media_gross).",
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $range
     */
    private function assertTimeRangeRow(array $range, int $positionIndex, int|string $childIndex): void
    {
        foreach (['start_hour', 'end_hour_exclusive', 'day_group', 'spot_count'] as $required) {
            if (! array_key_exists($required, $range) || $range[$required] === null || $range[$required] === '') {
                throw ValidationException::withMessages([
                    'frozen_materialization' => "Eingefrorene Vorlagendaten sind unvollständig (Position {$positionIndex}: time_ranges.{$childIndex}.{$required}).",
                ]);
            }
        }

        if (! is_string($range['day_group'])) {
            throw ValidationException::withMessages([
                'frozen_materialization' => "Eingefrorene Vorlagendaten sind ungültig (Position {$positionIndex}: time_ranges.{$childIndex}.day_group).",
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function assertPlanRow(array $row, int $positionIndex, int|string $childIndex): void
    {
        foreach (['hour', 'day_group', 'second_price'] as $required) {
            if (! array_key_exists($required, $row) || $row[$required] === null || $row[$required] === '') {
                throw ValidationException::withMessages([
                    'frozen_materialization' => "Eingefrorene Vorlagendaten sind unvollständig (Position {$positionIndex}: plan_rows.{$childIndex}.{$required}).",
                ]);
            }
        }

        if (! is_string($row['day_group'])) {
            throw ValidationException::withMessages([
                'frozen_materialization' => "Eingefrorene Vorlagendaten sind ungültig (Position {$positionIndex}: plan_rows.{$childIndex}.day_group).",
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $discount
     */
    private function assertDiscountRow(array $discount, string $path): void
    {
        if (! array_key_exists('type', $discount) || $discount['type'] === null || $discount['type'] === '') {
            throw ValidationException::withMessages([
                'frozen_materialization' => "Eingefrorene Vorlagendaten sind unvollständig ({$path}: type).",
            ]);
        }

        if (! is_string($discount['type']) || DiscountType::tryFrom($discount['type']) === null) {
            throw ValidationException::withMessages([
                'frozen_materialization' => "Eingefrorene Vorlagendaten sind ungültig ({$path}: type).",
            ]);
        }

        if (! array_key_exists('percent', $discount) || $discount['percent'] === null || $discount['percent'] === '') {
            throw ValidationException::withMessages([
                'frozen_materialization' => "Eingefrorene Vorlagendaten sind unvollständig ({$path}: percent).",
            ]);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $discounts
     */
    private function persistOrderDiscounts(Calculation $calculation, array $discounts): void
    {
        foreach ($discounts as $index => $discount) {
            $row = new CalculationOrderDiscount;
            $row->calculation()->associate($calculation);
            $row->type = DiscountType::from((string) $discount['type']);
            $row->custom_label = $discount['custom_label'] ?? null;
            $row->percent = $discount['percent'];
            $row->sort = max(0, (int) $index);
            $row->save();
        }
    }

    /**
     * @param  list<array<string, mixed>>  $positions
     */
    private function persistPositions(
        Calculation $calculation,
        ConfigurationSnapshot $calcBase,
        array $positions,
    ): void {
        foreach ($positions as $index => $frozenPosition) {
            $templateEffectiveId = (int) $frozenPosition['effective_configuration_snapshot_id'];
            $templateEffective = ConfigurationSnapshot::query()->whereKey($templateEffectiveId)->first();
            if ($templateEffective === null) {
                throw ValidationException::withMessages([
                    'frozen_materialization' => "Eingefrorener Positionssnapshot fehlt (Position {$index}).",
                ]);
            }

            $calcEffective = $this->clones->cloneCalculationPositionEffective($templateEffective, $calcBase);

            $frozenStrategy = $frozenPosition['component_calculation_strategy'] ?? null;
            $strategyValue = is_string($frozenStrategy) && $frozenStrategy !== ''
                ? ComponentCalculationStrategy::from($frozenStrategy)->value
                : null;

            $frozenProfile = $frozenPosition['component_profile'] ?? null;
            $profileValue = is_string($frozenProfile) && $frozenProfile !== ''
                ? SpotComponentProfile::from($frozenProfile)->value
                : null;

            $spotMethod = array_key_exists('spot_method', $frozenPosition) && is_string($frozenPosition['spot_method'])
                ? $frozenPosition['spot_method']
                : SpotCalculationMethod::Average->value;
            $settlement = array_key_exists('pricing_settlement_mode', $frozenPosition) && is_string($frozenPosition['pricing_settlement_mode'])
                ? $frozenPosition['pricing_settlement_mode']
                : PricingSettlementMode::Normal->value;
            $fixedPriceNn = null;
            if ($settlement === PricingSettlementMode::FixedPrice->value) {
                $fixedPriceNn = array_key_exists('fixed_price_nn', $frozenPosition)
                    && $frozenPosition['fixed_price_nn'] !== null
                    && $frozenPosition['fixed_price_nn'] !== ''
                    ? (string) $frozenPosition['fixed_price_nn']
                    : null;
            }

            $position = new CalculationPosition;
            $position->fill([
                'client_key' => (string) ($frozenPosition['client_key'] ?? (string) Str::uuid()),
                'inventory_id' => (int) $frozenPosition['inventory_id'],
                'inventory_name' => $frozenPosition['inventory_name'] ?? null,
                'inventory_code' => $frozenPosition['inventory_code'] ?? null,
                'advertising_medium_id' => (int) $frozenPosition['advertising_medium_id'],
                'advertising_medium_name' => $frozenPosition['advertising_medium_name'] ?? null,
                'advertising_medium_code' => $frozenPosition['advertising_medium_code'] ?? null,
                'advertising_category_id' => $frozenPosition['advertising_category_id'] ?? null,
                'advertising_category_key' => $frozenPosition['advertising_category_key'] ?? null,
                'advertising_category_name' => $frozenPosition['advertising_category_name'] ?? null,
                'effective_configuration_snapshot_id' => $calcEffective->id,
                'inventory_medium_rule_id' => $frozenPosition['inventory_medium_rule_id'] ?? null,
                'price_list_id' => (int) $frozenPosition['price_list_id'],
                'kind' => $frozenPosition['kind'],
                'spot_method' => $spotMethod,
                'length_seconds' => (int) $frozenPosition['length_seconds'],
                'component_calculation_strategy' => $strategyValue,
                'component_profile' => $profileValue,
                'total_spot_count' => (int) $frozenPosition['total_spot_count'],
                'needs_spot_redistribution' => (bool) ($frozenPosition['needs_spot_redistribution'] ?? false),
                'average_second_price' => $frozenPosition['average_second_price'] ?? null,
                'length_index' => $frozenPosition['length_index'] ?? null,
                'surcharge_percent' => $frozenPosition['surcharge_percent'] ?? '0',
                'position_discount_percent' => $frozenPosition['position_discount_percent'] ?? '0',
                'ae_percent' => $frozenPosition['ae_percent'] ?? '0',
                'is_discountable' => (bool) ($frozenPosition['is_discountable'] ?? true),
                'is_ae_eligible' => (bool) ($frozenPosition['is_ae_eligible'] ?? true),
                'price_list_version' => $frozenPosition['price_list_version'] ?? null,
                'media_gross' => $frozenPosition['media_gross'] ?? '0',
                'position_discount_amount' => $frozenPosition['position_discount_amount'] ?? '0',
                'order_discount_amount' => $frozenPosition['order_discount_amount'] ?? '0',
                'ae_amount' => $frozenPosition['ae_amount'] ?? '0',
                'nn_invest' => $frozenPosition['nn_invest'] ?? '0',
                'pricing_settlement_mode' => $settlement,
                'fixed_price_nn' => $fixedPriceNn,
                'effective_pay_factor_percent' => $frozenPosition['effective_pay_factor_percent'] ?? null,
                'effective_total_discount_percent' => $frozenPosition['effective_total_discount_percent'] ?? null,
                'sort' => (int) $index,
            ]);
            $position->engine_profile_key = $frozenPosition['engine_profile_key'] ?? null;
            $position->calculation_method_key = $frozenPosition['calculation_method_key'] ?? null;
            $position->calculation_method_name = $frozenPosition['calculation_method_name'] ?? null;
            $position->algorithm_version = $frozenPosition['algorithm_version'] ?? null;
            $position->calculation()->associate($calculation);
            $position->save();

            $this->persistComponents($position, $frozenPosition['components'] ?? []);
            $this->persistTimeRanges($position, $frozenPosition['time_ranges'] ?? []);
            $this->persistPlanRows($position, $frozenPosition['plan_rows'] ?? []);
            $this->persistPlannerEntries($position, $frozenPosition['planner_entries'] ?? []);
            $this->persistPositionDiscounts($position, $frozenPosition['position_discounts'] ?? []);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $components
     */
    private function persistComponents(CalculationPosition $position, array $components): void
    {
        foreach ($components as $componentIndex => $component) {
            $componentModel = new CalculationPositionComponent;
            $mediaGross = $component['media_gross'];
            $componentModel->fill([
                'role' => $component['role'],
                'label' => (string) $component['label'],
                'length_seconds' => (int) $component['length_seconds'],
                'sort' => (int) $component['sort'],
                'length_index' => (int) $component['length_index'],
                // Freeze speichert leeren Brutto als ""; Spalte ist nullable.
                'media_gross' => $mediaGross === '' ? null : (string) $mediaGross,
            ]);
            $componentModel->position()->associate($position);
            $componentModel->save();
        }
    }

    /**
     * @param  list<array<string, mixed>>  $ranges
     */
    private function persistTimeRanges(CalculationPosition $position, array $ranges): void
    {
        foreach ($ranges as $rangeIndex => $range) {
            $model = new CalculationPositionTimeRange;
            $model->fill([
                'start_hour' => (int) $range['start_hour'],
                'end_hour_exclusive' => (int) $range['end_hour_exclusive'],
                'day_group' => $range['day_group'],
                'spot_count' => (int) $range['spot_count'],
                'sort' => (int) $rangeIndex,
                'average_second_price' => $range['average_second_price'] ?? null,
                'range_gross' => $range['range_gross'] ?? '0',
            ]);
            $model->position()->associate($position);
            $model->save();
        }
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function persistPlanRows(CalculationPosition $position, array $rows): void
    {
        foreach ($rows as $row) {
            $planRow = new SpotClassicPlanRow;
            $planRow->fill([
                'hour' => (int) $row['hour'],
                'day_group' => $row['day_group'],
                'spot_count' => (int) ($row['spot_count'] ?? 0),
                'second_price' => $row['second_price'],
                'line_gross' => $row['line_gross'] ?? '0.00',
            ]);
            $planRow->position()->associate($position);
            $planRow->save();
        }
    }

    /**
     * @param  list<array<string, mixed>>  $entries
     */
    private function persistPlannerEntries(CalculationPosition $position, array $entries): void
    {
        foreach ($entries as $entry) {
            $model = new CalculationPositionPlannerEntry;
            $model->fill([
                'date' => (string) $entry['date'],
                'hour' => (int) $entry['hour'],
                'day_group' => $entry['day_group'],
                'spot_count' => (int) $entry['spot_count'],
                'second_price' => (string) $entry['second_price'],
                'line_gross' => (string) ($entry['line_gross'] ?? '0.00'),
            ]);
            $model->position()->associate($position);
            $model->save();
        }
    }

    /**
     * @param  list<array<string, mixed>>  $discounts
     */
    private function persistPositionDiscounts(CalculationPosition $position, array $discounts): void
    {
        foreach ($discounts as $discountIndex => $discount) {
            $model = new CalculationPositionDiscount;
            $model->fill([
                'type' => $discount['type'],
                'custom_label' => $discount['custom_label'] ?? null,
                'percent' => $discount['percent'],
                'sort' => (int) $discountIndex,
            ]);
            $model->position()->associate($position);
            $model->save();
        }
    }

    /**
     * @param  array<string, mixed>  $materialization
     */
    private function syncDynamicFields(
        Calculation $calculation,
        ConfigurationSnapshot $calcBase,
        array $materialization,
        string $customerName,
    ): void {
        $syncPayload = $materialization['draft_payload'] ?? null;
        if (! is_array($syncPayload)) {
            return;
        }

        $syncPayload['customer_name'] = $customerName;
        $syncPayload['agency_name'] = $calculation->agency_name;
        $syncPayload['schema_fingerprint'] = $calcBase->schema_fingerprint;
        $calculation->load('positions');
        foreach ($calculation->positions as $posIndex => $pos) {
            if (! isset($syncPayload['positions'][$posIndex]) || ! is_array($syncPayload['positions'][$posIndex])) {
                continue;
            }
            $syncPayload['positions'][$posIndex]['id'] = $pos->id;
            $syncPayload['positions'][$posIndex]['client_key'] = $pos->client_key;
            $syncPayload['positions'][$posIndex]['schema_fingerprint'] = ConfigurationSnapshot::query()
                ->whereKey($pos->effective_configuration_snapshot_id)
                ->value('schema_fingerprint');
        }
        $this->dynamicFields->syncFromPayload($calculation, $syncPayload);
    }
}
