<?php

namespace App\Support\InventoryMediumRule;

use App\Models\InventoryMediumRule;
use Illuminate\Validation\ValidationException;

/**
 * BL-P2-02a / MAT-CORE-1: operative Pflichtwerte und Freeze-Vertrag der Kombinationstabelle.
 *
 * Buchungskennzeichen und Einplanung sind serverseitig; nie aus Client-Payload.
 * PO-MAT-BOOKING-VIS-1 Option A: Anzeige nur im Dispo, nicht in der Kalkulation.
 */
final class InventoryMediumRuleOperativeContract
{
    public const PLANNING_MUST_NOT_PLAN = 'must_not_plan';

    /**
     * Kanonische Einplanung-durch-Keys (Anforderungskatalog §6.3 / initialdaten).
     *
     * Hinweis: `disposition_abbinder` ist ein Stammdatum-Auswahlwert für
     * „Einplanung durch“ („Disposition, bitte Abbinder nutzen“). Das ist keine
     * Abbinder-/SPT-013-Funktionalität und gehört zum MAT-CORE-Katalog der
     * Einplanungswerte; SPT-013 bleibt zurückgestellt.
     *
     * @var array<string, string>
     */
    public const PLANNING_RESPONSIBILITIES = [
        'disposition' => 'Disposition',
        'oap' => 'OAP',
        'pdm_digital' => 'PDM-Digital / Niklas Farin',
        'redaktion' => 'Redaktion',
        'moderator' => 'Moderator',
        'events' => 'Events',
        self::PLANNING_MUST_NOT_PLAN => 'darf nicht geplant werden',
        'disposition_abbinder' => 'Disposition, bitte Abbinder nutzen',
    ];

    /**
     * @return list<array{key: string, label: string}>
     */
    public static function planningOptions(): array
    {
        $options = [];
        foreach (self::PLANNING_RESPONSIBILITIES as $key => $label) {
            $options[] = ['key' => $key, 'label' => $label];
        }

        return $options;
    }

    public static function planningLabel(string $key): string
    {
        return self::PLANNING_RESPONSIBILITIES[$key] ?? $key;
    }

    public static function isKnownPlanningKey(string $key): bool
    {
        return array_key_exists($key, self::PLANNING_RESPONSIBILITIES);
    }

    public static function isMustNotPlan(?string $planningKey): bool
    {
        return $planningKey === self::PLANNING_MUST_NOT_PLAN;
    }

    /**
     * Aktive Regeln brauchen Buchungskennzeichen und Einplanung (MAT-001 / Dispo-Pflicht).
     * Keine stillen Defaults.
     */
    public static function assertCompleteForActiveUse(InventoryMediumRule $rule, string $errorKey = 'positions'): void
    {
        $booking = self::normalizeBookingCode($rule->booking_code);
        $planning = self::normalizePlanningKey($rule->planning_responsibility_key);

        if ($booking === null || $planning === null) {
            throw ValidationException::withMessages([
                $errorKey => 'Die Inventar-/Werbemittel-Kombination ist unvollständig '
                    .'(Buchungskennzeichen oder Einplanung durch fehlen). '
                    .'Bitte die Kombination in der Administration vervollständigen.',
            ]);
        }
    }

    public static function assertAllowedForPlanning(InventoryMediumRule $rule, string $errorKey = 'positions'): void
    {
        $planning = self::normalizePlanningKey($rule->planning_responsibility_key);
        if (self::isMustNotPlan($planning)) {
            throw ValidationException::withMessages([
                $errorKey => 'Diese Kombination darf nicht geplant werden '
                    .'(Einplanung durch: darf nicht geplant werden).',
            ]);
        }
    }

    /**
     * Freeze aus Live-Regel (nur nach Completeness-/Planungsprüfung).
     *
     * @return array{
     *     booking_code: string,
     *     planning_responsibility_key: string,
     *     planning_responsibility_label: string,
     *     combination_hint_text: string|null
     * }
     */
    public static function freezeFromRule(InventoryMediumRule $rule): array
    {
        $booking = self::normalizeBookingCode($rule->booking_code);
        $planning = self::normalizePlanningKey($rule->planning_responsibility_key);
        if ($booking === null || $planning === null) {
            throw ValidationException::withMessages([
                'positions' => 'Die Inventar-/Werbemittel-Kombination ist unvollständig '
                    .'(Buchungskennzeichen oder Einplanung durch fehlen).',
            ]);
        }

        $hint = self::normalizeHint($rule->hint_text);

        return [
            'booking_code' => $booking,
            'planning_responsibility_key' => $planning,
            'planning_responsibility_label' => self::planningLabel($planning),
            'combination_hint_text' => $hint,
        ];
    }

    /**
     * Historischer Freeze von der Calc-Position (Legacy: alle null erlaubt).
     *
     * @return array{
     *     booking_code: string|null,
     *     planning_responsibility_key: string|null,
     *     planning_responsibility_label: string|null,
     *     combination_hint_text: string|null
     * }
     */
    public static function freezeFromStoredPosition(
        ?string $bookingCode,
        ?string $planningKey,
        ?string $planningLabel,
        ?string $hintText,
    ): array {
        return [
            'booking_code' => self::normalizeBookingCode($bookingCode),
            'planning_responsibility_key' => self::normalizePlanningKey($planningKey),
            'planning_responsibility_label' => self::normalizeHint($planningLabel),
            'combination_hint_text' => self::normalizeHint($hintText),
        ];
    }

    public static function normalizeBookingCode(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    public static function normalizePlanningKey(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    public static function normalizeHint(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * TEST-FIXTURE-Defaults für Factories/E2E – keine Produktivmatrix.
     *
     * @return array{
     *     booking_code: string,
     *     planning_responsibility_key: string,
     *     hint_text: string|null
     * }
     */
    public static function testFixtureDefaults(): array
    {
        return [
            'booking_code' => 'L',
            'planning_responsibility_key' => 'disposition',
            'hint_text' => null,
        ];
    }
}
