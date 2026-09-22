<?php

namespace App\Services\DispoOrder\SpotDistributionExport;

use App\Enums\DayGroup;
use App\Enums\SpotCalculationMethod;
use App\Models\DispoOrder;
use App\Models\DispoOrderPosition;
use App\Services\Calculation\DayGroupFromDate;
use App\Services\Calculation\TimeRangeHours;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Validation\ValidationException;

/**
 * SPT-008: baut Spotplanungs-Export aus Dispo-Snapshots (Calendar + Average).
 */
final class SpotDistributionExportBuilder
{
    private const array WEEKDAYS = [
        1 => 'Montag',
        2 => 'Dienstag',
        3 => 'Mittwoch',
        4 => 'Donnerstag',
        5 => 'Freitag',
        6 => 'Samstag',
        7 => 'Sonntag',
    ];

    public function __construct(
        private readonly SpotPlanningExportSupport $support = new SpotPlanningExportSupport,
    ) {}

    public function build(DispoOrder $order): SpotDistributionExportDocument
    {
        $order->loadMissing(['positions.fieldValues.snapshotFieldDefinition']);

        $calendarRows = [];
        $averageRows = [];
        $exportedCalendarPositionIds = [];
        $exportedAveragePositionIds = [];

        foreach ($order->positions as $position) {
            if ($position->spot_method === SpotCalculationMethod::Calendar) {
                $positionRows = $this->calendarRowsForPosition($order, $position);
                if ($positionRows === []) {
                    continue;
                }
                $exportedCalendarPositionIds[$position->id] = true;
                foreach ($positionRows as $row) {
                    $calendarRows[] = $row;
                }

                continue;
            }

            if ($position->spot_method === SpotCalculationMethod::Average) {
                $positionRows = $this->averageRowsForPosition($order, $position);
                if ($positionRows === []) {
                    continue;
                }
                $exportedAveragePositionIds[$position->id] = true;
                foreach ($positionRows as $row) {
                    $averageRows[] = $row;
                }
            }
        }

        if ($calendarRows === [] && $averageRows === []) {
            throw ValidationException::withMessages([
                'export' => 'Für diesen Dispoauftrag liegt keine exportierbare Spotplanung vor.',
            ]);
        }

        usort(
            $calendarRows,
            static function (SpotDistributionExportRow $left, SpotDistributionExportRow $right): int {
                if ($left->positionSort !== $right->positionSort) {
                    return $left->positionSort <=> $right->positionSort;
                }
                if ($left->positionId !== $right->positionId) {
                    return $left->positionId <=> $right->positionId;
                }
                $dateCompare = strcmp($left->dateIso, $right->dateIso);
                if ($dateCompare !== 0) {
                    return $dateCompare;
                }

                return $left->hour <=> $right->hour;
            },
        );

        usort(
            $averageRows,
            static function (SpotPlanningProposalExportRow $left, SpotPlanningProposalExportRow $right): int {
                if ($left->positionSort !== $right->positionSort) {
                    return $left->positionSort <=> $right->positionSort;
                }
                if ($left->positionId !== $right->positionId) {
                    return $left->positionId <=> $right->positionId;
                }
                $dayCompare = strcmp($left->dayGroupLabel, $right->dayGroupLabel);
                if ($dayCompare !== 0) {
                    return $dayCompare;
                }

                return strcmp($left->hourConstraintLabel, $right->hourConstraintLabel);
            },
        );

        return new SpotDistributionExportDocument(
            dispoOrderId: (int) $order->id,
            dispoOrderNumber: (string) $order->number,
            calendarHeaders: SpotDistributionExportDocument::calendarHeaders(),
            calendarRows: $calendarRows,
            averageHeaders: SpotDistributionExportDocument::averageHeaders(),
            averageRows: $averageRows,
            exportedCalendarPositionCount: count($exportedCalendarPositionIds),
            exportedAveragePositionCount: count($exportedAveragePositionIds),
        );
    }

    /**
     * UI-/Capability-Hinweis ohne Exportausführung.
     *
     * @return array{
     *     enabled: bool,
     *     has_calendar_positions: bool,
     *     has_average_positions: bool,
     *     mixed_order: bool,
     *     hint_kind: 'calendar_only'|'average_only'|'mixed'|null,
     *     exportable_calendar_row_count: int,
     *     exportable_average_row_count: int,
     *     exportable_row_count: int,
     *     disabled_reason: string|null
     * }
     */
    public function capability(DispoOrder $order): array
    {
        $order->loadMissing(['positions']);

        $hasAverage = false;
        $hasCalendar = false;
        $calendarRows = 0;
        $averageRows = 0;

        foreach ($order->positions as $position) {
            if ($position->spot_method === SpotCalculationMethod::Average) {
                $count = $this->countExportableAverageEntries($position);
                if ($count > 0) {
                    $hasAverage = true;
                    $averageRows += $count;
                }
            }

            if ($position->spot_method === SpotCalculationMethod::Calendar) {
                $count = $this->countExportableCalendarEntries($position);
                if ($count > 0) {
                    $hasCalendar = true;
                    $calendarRows += $count;
                }
            }
        }

        $enabled = $calendarRows > 0 || $averageRows > 0;
        $hintKind = null;
        if ($enabled) {
            if ($hasCalendar && $hasAverage) {
                $hintKind = 'mixed';
            } elseif ($hasCalendar) {
                $hintKind = 'calendar_only';
            } else {
                $hintKind = 'average_only';
            }
        }

        return [
            'enabled' => $enabled,
            'has_calendar_positions' => $hasCalendar,
            'has_average_positions' => $hasAverage,
            'mixed_order' => $hasCalendar && $hasAverage,
            'hint_kind' => $hintKind,
            'exportable_calendar_row_count' => $calendarRows,
            'exportable_average_row_count' => $averageRows,
            'exportable_row_count' => $calendarRows + $averageRows,
            'disabled_reason' => $enabled
                ? null
                : 'Keine exportierbare Spotplanung vorhanden (weder Calendar-Verteilung noch Average-Planungsvorschlag).',
        ];
    }

    public function isExportableCalendarPosition(DispoOrderPosition $position): bool
    {
        return $position->spot_method === SpotCalculationMethod::Calendar
            && $this->countExportableCalendarEntries($position) > 0;
    }

    public function isExportableAveragePosition(DispoOrderPosition $position): bool
    {
        return $position->spot_method === SpotCalculationMethod::Average
            && $this->countExportableAverageEntries($position) > 0;
    }

    private function countExportableCalendarEntries(DispoOrderPosition $position): int
    {
        $entries = $position->planner_entries_snapshot ?? [];
        if ($entries === []) {
            return 0;
        }

        $count = 0;
        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $spotCount = $entry['spot_count'] ?? null;
            if (is_int($spotCount) && $spotCount >= 1) {
                $count++;
            } elseif (is_numeric($spotCount) && (int) $spotCount >= 1) {
                $count++;
            }
        }

        return $count;
    }

    private function countExportableAverageEntries(DispoOrderPosition $position): int
    {
        $ranges = $position->time_ranges_snapshot ?? [];
        if ($ranges === []) {
            return 0;
        }

        $count = 0;
        foreach ($ranges as $range) {
            if (! is_array($range)) {
                continue;
            }
            $spotCount = $range['spot_count'] ?? null;
            if (is_numeric($spotCount) && (int) $spotCount >= 1) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @return list<SpotDistributionExportRow>
     */
    private function calendarRowsForPosition(DispoOrder $order, DispoOrderPosition $position): array
    {
        $entries = $position->planner_entries_snapshot;
        if ($entries === null) {
            throw ValidationException::withMessages([
                'export' => 'Der Planner-Snapshot der Position ist ungültig.',
            ]);
        }

        $profile = $position->component_profile;
        $componentsLabel = $this->support->formatComponents($position, $profile);
        $totalLength = $this->support->resolveTotalLengthSeconds($position);
        $quantityUnit = $this->support->quantityUnit($profile);
        $positionLabel = $this->support->positionLabel($position);
        $customerName = (string) ($order->customer_name ?? '');

        $rows = [];
        foreach ($entries as $index => $entry) {
            if (! is_array($entry)) {
                throw ValidationException::withMessages([
                    'export' => "Planner-Eintrag {$index} der Position {$positionLabel} ist ungültig.",
                ]);
            }

            $spotCount = $this->requirePositiveSpotCount($entry, $positionLabel, $index);
            if ($spotCount === null) {
                continue;
            }

            $dateIso = $this->requireDateIso($entry, $positionLabel, $index);
            $hour = $this->requireHour($entry, $positionLabel, $index);
            $dayGroupLabel = $this->resolveDayGroupLabel($entry, $dateIso, $positionLabel, $index);

            $rows[] = new SpotDistributionExportRow(
                dispoOrderNumber: (string) $order->number,
                customerName: $customerName,
                positionLabel: $positionLabel,
                positionSort: (int) $position->sort,
                positionId: (int) $position->id,
                inventoryName: (string) $position->inventory_name,
                advertisingMediumName: (string) $position->advertising_medium_name,
                dateIso: $dateIso,
                weekdayLabel: $this->weekdayLabel($dateIso),
                hour: $hour,
                hourLabel: sprintf('%02d:00', $hour),
                dayGroupLabel: $dayGroupLabel,
                quantity: $spotCount,
                quantityUnit: $quantityUnit,
                totalLengthSeconds: $totalLength,
                componentsLabel: $componentsLabel,
                componentAirings: $this->support->componentAirings($profile, $spotCount),
            );
        }

        return $rows;
    }

    /**
     * @return list<SpotPlanningProposalExportRow>
     */
    private function averageRowsForPosition(DispoOrder $order, DispoOrderPosition $position): array
    {
        $ranges = $position->time_ranges_snapshot;
        if ($ranges === null) {
            throw ValidationException::withMessages([
                'export' => 'Der Average-Zeitraum-Snapshot der Position ist ungültig.',
            ]);
        }

        if ($ranges === []) {
            return [];
        }

        $profile = $position->component_profile;
        $componentsLabel = $this->support->formatComponents($position, $profile);
        $totalLength = $this->support->resolveTotalLengthSeconds($position);
        $quantityUnit = $this->support->quantityUnit($profile);
        $positionLabel = $this->support->positionLabel($position);
        $customerName = (string) ($order->customer_name ?? '');
        $flightPeriod = $this->support->flightPeriodDisplay($position);
        $periodFrom = $flightPeriod[0] ?? '';
        $periodTo = $flightPeriod[1] ?? '';

        $rows = [];
        foreach ($ranges as $index => $range) {
            if (! is_array($range)) {
                throw ValidationException::withMessages([
                    'export' => "Average-Zeitraum {$index} der Position {$positionLabel} ist ungültig.",
                ]);
            }

            $spotCount = $this->requirePositiveSpotCount($range, $positionLabel, $index);
            if ($spotCount === null) {
                // Menge 0 oder fehlend: fail-closed wenn key fehlt; 0 wird übersprungen nur wenn explizit 0?
                // PO: Menge kleiner 1 fail-closed. requirePositiveSpotCount returns null for <1 after validating numeric.
                // But missing spot_count throws. For spot_count=0, currently returns null (skip).
                // PO says Menge kleiner 1 fail-closed - so we should fail, not skip!
                if (array_key_exists('spot_count', $range) && is_numeric($range['spot_count']) && (int) $range['spot_count'] < 1) {
                    throw ValidationException::withMessages([
                        'export' => "Average-Zeitraum {$index} der Position {$positionLabel} hat eine ungültige Menge.",
                    ]);
                }

                continue;
            }

            [$startHour, $endExclusive] = $this->requireAverageHourBounds($range, $positionLabel, $index);
            $dayGroupLabel = $this->requireStoredDayGroupLabel($range, $positionLabel, $index);
            $hourLabel = $this->formatHourConstraint($startHour, $endExclusive);

            $rows[] = new SpotPlanningProposalExportRow(
                dispoOrderNumber: (string) $order->number,
                customerName: $customerName,
                positionLabel: $positionLabel,
                positionSort: (int) $position->sort,
                positionId: (int) $position->id,
                inventoryName: (string) $position->inventory_name,
                advertisingMediumName: (string) $position->advertising_medium_name,
                periodFromDisplay: $periodFrom,
                periodToDisplay: $periodTo,
                dayGroupLabel: $dayGroupLabel,
                hourConstraintLabel: $hourLabel,
                quantity: $spotCount,
                quantityUnit: $quantityUnit,
                totalLengthSeconds: $totalLength,
                componentsLabel: $componentsLabel,
                componentAirings: $this->support->componentAirings($profile, $spotCount),
            );
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array{0: int, 1: int}
     */
    private function requireAverageHourBounds(array $entry, string $positionLabel, int $index): array
    {
        if (! array_key_exists('start_hour', $entry) || ! is_numeric($entry['start_hour'])) {
            throw ValidationException::withMessages([
                'export' => "Average-Zeitraum {$index} der Position {$positionLabel} hat eine ungültige Startstunde.",
            ]);
        }
        if (! array_key_exists('end_hour_exclusive', $entry) || ! is_numeric($entry['end_hour_exclusive'])) {
            throw ValidationException::withMessages([
                'export' => "Average-Zeitraum {$index} der Position {$positionLabel} hat eine ungültige Endstunde.",
            ]);
        }

        $start = (int) $entry['start_hour'];
        $end = (int) $entry['end_hour_exclusive'];
        if ($start < 0 || $start > 23 || $end < 1 || $end > 24 || $end <= $start) {
            throw ValidationException::withMessages([
                'export' => "Average-Zeitraum {$index} der Position {$positionLabel} hat einen ungültigen Stundenbereich.",
            ]);
        }

        return [$start, $end];
    }

    private function formatHourConstraint(int $startHour, int $endHourExclusive): string
    {
        return TimeRangeHours::formatHour($startHour).'–'.TimeRangeHours::formatInclusiveEnd($endHourExclusive);
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function requireStoredDayGroupLabel(array $entry, string $positionLabel, int $index): string
    {
        $raw = $entry['day_group'] ?? null;
        if (! is_string($raw) || $raw === '') {
            throw ValidationException::withMessages([
                'export' => "Average-Zeitraum {$index} der Position {$positionLabel} ohne Tagesgruppe.",
            ]);
        }

        $group = DayGroup::tryFrom($raw);
        if ($group === null) {
            throw ValidationException::withMessages([
                'export' => "Average-Zeitraum {$index} der Position {$positionLabel} hat eine unbekannte Tagesgruppe.",
            ]);
        }

        return $group->label();
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function requirePositiveSpotCount(array $entry, string $positionLabel, int $index): ?int
    {
        if (! array_key_exists('spot_count', $entry)) {
            throw ValidationException::withMessages([
                'export' => "Eintrag {$index} der Position {$positionLabel} ohne Spotanzahl.",
            ]);
        }

        if (! is_numeric($entry['spot_count'])) {
            throw ValidationException::withMessages([
                'export' => "Eintrag {$index} der Position {$positionLabel} hat eine ungültige Spotanzahl.",
            ]);
        }

        $spotCount = (int) $entry['spot_count'];
        if ($spotCount < 1) {
            return null;
        }

        return $spotCount;
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function requireDateIso(array $entry, string $positionLabel, int $index): string
    {
        $date = $entry['date'] ?? null;
        if (! is_string($date) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw ValidationException::withMessages([
                'export' => "Planner-Eintrag {$index} der Position {$positionLabel} hat ein ungültiges Datum.",
            ]);
        }

        $tz = new DateTimeZone(DayGroupFromDate::TIMEZONE);
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date, $tz);
        $errors = DateTimeImmutable::getLastErrors();
        if ($parsed === false || (($errors['warning_count'] ?? 0) > 0) || (($errors['error_count'] ?? 0) > 0)) {
            throw ValidationException::withMessages([
                'export' => "Planner-Eintrag {$index} der Position {$positionLabel} hat ein ungültiges Datum.",
            ]);
        }

        if ($parsed->format('Y-m-d') !== $date) {
            throw ValidationException::withMessages([
                'export' => "Planner-Eintrag {$index} der Position {$positionLabel} hat ein ungültiges Datum.",
            ]);
        }

        return $date;
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function requireHour(array $entry, string $positionLabel, int $index): int
    {
        if (! array_key_exists('hour', $entry) || ! is_numeric($entry['hour'])) {
            throw ValidationException::withMessages([
                'export' => "Planner-Eintrag {$index} der Position {$positionLabel} hat eine ungültige Stunde.",
            ]);
        }

        $hour = (int) $entry['hour'];
        if ($hour < 0 || $hour > 23) {
            throw ValidationException::withMessages([
                'export' => "Planner-Eintrag {$index} der Position {$positionLabel} hat eine ungültige Stunde.",
            ]);
        }

        return $hour;
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function resolveDayGroupLabel(array $entry, string $dateIso, string $positionLabel, int $index): string
    {
        $raw = $entry['day_group'] ?? null;
        if ($raw === null || $raw === '') {
            return DayGroupFromDate::resolve($dateIso)->label();
        }

        if (! is_string($raw)) {
            throw ValidationException::withMessages([
                'export' => "Planner-Eintrag {$index} der Position {$positionLabel} hat eine ungültige Tagesgruppe.",
            ]);
        }

        $group = DayGroup::tryFrom($raw);
        if ($group === null) {
            throw ValidationException::withMessages([
                'export' => "Planner-Eintrag {$index} der Position {$positionLabel} hat eine unbekannte Tagesgruppe.",
            ]);
        }

        return $group->label();
    }

    private function weekdayLabel(string $dateIso): string
    {
        $local = new DateTimeImmutable($dateIso, new DateTimeZone(DayGroupFromDate::TIMEZONE));
        $n = (int) $local->format('N');

        return self::WEEKDAYS[$n];
    }
}
