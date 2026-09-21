<?php

namespace App\Services\DispoOrder\SpotDistributionExport;

use App\Enums\DayGroup;
use App\Enums\SpotCalculationMethod;
use App\Enums\SpotComponentProfile;
use App\Enums\SpotComponentRole;
use App\Models\DispoOrder;
use App\Models\DispoOrderPosition;
use App\Services\Calculation\DayGroupFromDate;
use App\Support\Advertising\SpotComponentProfileContract;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Validation\ValidationException;

/**
 * SPT-008: baut den Spotverteilungs-Export ausschließlich aus Dispo-Snapshots.
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

    public function build(DispoOrder $order): SpotDistributionExportDocument
    {
        $order->loadMissing(['positions']);

        $rows = [];
        $exportedPositionIds = [];

        foreach ($order->positions as $position) {
            if (! $this->isExportableCalendarPosition($position)) {
                continue;
            }

            $positionRows = $this->rowsForPosition($order, $position);
            if ($positionRows === []) {
                continue;
            }

            $exportedPositionIds[$position->id] = true;
            foreach ($positionRows as $row) {
                $rows[] = $row;
            }
        }

        if ($rows === []) {
            throw ValidationException::withMessages([
                'export' => 'Für diesen Dispoauftrag liegt keine exportierbare Kalender-Spotverteilung vor.',
            ]);
        }

        usort(
            $rows,
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

        return new SpotDistributionExportDocument(
            dispoOrderId: (int) $order->id,
            dispoOrderNumber: (string) $order->number,
            headers: SpotDistributionExportDocument::defaultHeaders(),
            rows: $rows,
            exportedPositionCount: count($exportedPositionIds),
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
     *     exportable_row_count: int,
     *     disabled_reason: string|null
     * }
     */
    public function capability(DispoOrder $order): array
    {
        $order->loadMissing(['positions']);

        $hasAverage = false;
        $hasCalendar = false;
        $exportableRowCount = 0;

        foreach ($order->positions as $position) {
            if ($position->spot_method === SpotCalculationMethod::Average) {
                $hasAverage = true;
            }

            if ($this->isExportableCalendarPosition($position)) {
                $hasCalendar = true;
                $exportableRowCount += $this->countExportableEntries($position);
            }
        }

        $enabled = $exportableRowCount > 0;

        return [
            'enabled' => $enabled,
            'has_calendar_positions' => $hasCalendar,
            'has_average_positions' => $hasAverage,
            'mixed_order' => $hasCalendar && $hasAverage,
            'exportable_row_count' => $exportableRowCount,
            'disabled_reason' => $enabled
                ? null
                : 'Keine kalendergeplante Spotverteilung vorhanden. Average-Positionen sind im Spotverteilungs-Export nicht enthalten.',
        ];
    }

    public function isExportableCalendarPosition(DispoOrderPosition $position): bool
    {
        if ($position->spot_method !== SpotCalculationMethod::Calendar) {
            return false;
        }

        return $this->countExportableEntries($position) > 0;
    }

    private function countExportableEntries(DispoOrderPosition $position): int
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

    /**
     * @return list<SpotDistributionExportRow>
     */
    private function rowsForPosition(DispoOrder $order, DispoOrderPosition $position): array
    {
        $entries = $position->planner_entries_snapshot;
        if ($entries === null) {
            throw ValidationException::withMessages([
                'export' => 'Der Planner-Snapshot der Position ist ungültig.',
            ]);
        }

        $profile = $position->component_profile;
        $componentsLabel = $this->formatComponents($position, $profile);
        $totalLength = $this->resolveTotalLengthSeconds($position);
        $quantityUnit = $this->quantityUnit($profile);
        $positionLabel = $this->positionLabel($position);
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
                componentAirings: $this->componentAirings($profile, $spotCount),
            );
        }

        return $rows;
    }

    private function positionLabel(DispoOrderPosition $position): string
    {
        return 'Position '.(int) $position->sort;
    }

    private function quantityUnit(?SpotComponentProfile $profile): string
    {
        if ($profile === null) {
            return 'Spots';
        }

        return $profile->unitLabel();
    }

    private function componentAirings(?SpotComponentProfile $profile, int $unitCount): int
    {
        if ($profile === null) {
            return $unitCount;
        }

        return SpotComponentProfileContract::derivedAirings($profile, $unitCount);
    }

    private function resolveTotalLengthSeconds(DispoOrderPosition $position): int
    {
        $components = $position->components_snapshot ?? [];
        if ($components !== []) {
            $sum = 0;
            foreach ($components as $component) {
                if (! isset($component['length_seconds'])) {
                    throw ValidationException::withMessages([
                        'export' => 'Komponenten-Snapshot enthält ungültige Längenwerte.',
                    ]);
                }
                $length = (int) $component['length_seconds'];
                if ($length < 1) {
                    throw ValidationException::withMessages([
                        'export' => 'Komponenten-Snapshot enthält ungültige Längenwerte.',
                    ]);
                }
                $sum += $length;
            }

            return $sum;
        }

        $length = (int) $position->length_seconds;
        if ($length < 1) {
            throw ValidationException::withMessages([
                'export' => 'Die Spotlänge der Position ist ungültig.',
            ]);
        }

        return $length;
    }

    /**
     * @return string lesbare Komponentenzeile
     */
    private function formatComponents(DispoOrderPosition $position, ?SpotComponentProfile $profile): string
    {
        $components = $position->components_snapshot ?? [];

        if ($components === []) {
            $length = (int) $position->length_seconds;
            if ($length < 1) {
                throw ValidationException::withMessages([
                    'export' => 'Legacy-Position ohne Komponenten und ohne gültige Spotlänge.',
                ]);
            }

            return 'Spot '.$length.' s';
        }

        $normalized = [];
        foreach ($components as $index => $component) {
            $roleValue = $component['role'] ?? null;
            if (! is_string($roleValue) || $roleValue === '') {
                throw ValidationException::withMessages([
                    'export' => 'Komponenten-Snapshot ohne Rolle.',
                ]);
            }

            $role = SpotComponentRole::tryFrom($roleValue);
            if ($role === null) {
                throw ValidationException::withMessages([
                    'export' => "Unbekannte Komponentenrolle „{$roleValue}“.",
                ]);
            }

            $sort = isset($component['sort']) ? (int) $component['sort'] : $index + 1;
            $length = isset($component['length_seconds']) ? (int) $component['length_seconds'] : 0;
            if ($length < 1) {
                throw ValidationException::withMessages([
                    'export' => 'Komponenten-Snapshot enthält ungültige Längenwerte.',
                ]);
            }

            $label = $this->componentDisplayLabel($profile, $role, $sort, $component);

            $normalized[] = [
                'sort' => $sort,
                'label' => $label,
                'length_seconds' => $length,
            ];
        }

        usort($normalized, static fn (array $a, array $b): int => $a['sort'] <=> $b['sort']);

        if ($profile !== null) {
            $this->assertForcedProfileStructure($profile, $normalized);
        }

        return implode(' | ', array_map(
            static fn (array $part): string => $part['label'].' '.$part['length_seconds'].' s',
            $normalized,
        ));
    }

    /**
     * @param  array<string, mixed>  $component
     */
    private function componentDisplayLabel(
        ?SpotComponentProfile $profile,
        SpotComponentRole $role,
        int $sort,
        array $component,
    ): string {
        if ($profile !== null) {
            return SpotComponentProfileContract::displayLabel($profile, $role, $sort);
        }

        $label = $component['label'] ?? null;
        if (is_string($label) && trim($label) !== '') {
            return trim($label);
        }

        return $role->label();
    }

    /**
     * @param  list<array{sort: int, label: string, length_seconds: int}>  $normalized
     */
    private function assertForcedProfileStructure(SpotComponentProfile $profile, array $normalized): void
    {
        $expected = SpotComponentProfileContract::slots($profile);
        if (count($normalized) !== count($expected)) {
            throw ValidationException::withMessages([
                'export' => 'Komponenten-Snapshot passt nicht zum gespeicherten Profil.',
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function requirePositiveSpotCount(array $entry, string $positionLabel, int $index): ?int
    {
        if (! array_key_exists('spot_count', $entry)) {
            throw ValidationException::withMessages([
                'export' => "Planner-Eintrag {$index} der Position {$positionLabel} ohne Spotanzahl.",
            ]);
        }

        if (! is_numeric($entry['spot_count'])) {
            throw ValidationException::withMessages([
                'export' => "Planner-Eintrag {$index} der Position {$positionLabel} hat eine ungültige Spotanzahl.",
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
