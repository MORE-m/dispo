<?php

namespace App\Support\InventoryMediumRule\Import;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use RuntimeException;
use Throwable;

/**
 * PO-MAT-CORE-MATRIX-1: liest Buchungs- und Einplanungsblatt, baut den
 * importierbaren Desired-State. Keine stillen Defaults für Kennzeichen/Einplanung.
 */
final class CombinationMatrixWorkbookParser
{
    public const BOOKING_SHEET = 'Buchungskenn durch Kombitabelle';

    public const PLANNING_SHEET = 'Einplanung durch Kombitabelle';

    public const MUST_NOT_PLAN_LABEL = 'darf nicht geplant werden';

    /** @var list<string> */
    public const SKIP_MEDIA_LABELS = [
        'Pre-/In-Stream',
        'Pre-/In-Stream Influencer',
    ];

    /**
     * MAT-003 / Vertrag §8: Single-Spot auf diesen Inventaren existiert nicht.
     *
     * @var list<array{0: string, 1: string}>
     */
    public const BLOCKED_COMBINATIONS = [
        ['MORE Hamburg-Kombi+', 'Single-Spot'],
        ['RADIO BOLLERWAGEN DAB+ Hamburg', 'Single-Spot'],
        ['ffn Hamburg Plus', 'Single-Spot'],
    ];

    /**
     * Excel-Label → planning_responsibility_key (OperativeContract).
     *
     * @var array<string, string>
     */
    public const PLANNING_LABEL_TO_KEY = [
        'Disposition' => 'disposition',
        'OAP' => 'oap',
        'PDM-Digital / Niklas Farin' => 'pdm_digital',
        'Redaktion' => 'redaktion',
        'Moderator' => 'moderator',
        'Events' => 'events',
        'Disposition, bitte Abbinder nutzen' => 'disposition_abbinder',
    ];

    /**
     * @return array{
     *     rows: list<array{
     *         inventory_name: string,
     *         medium_name: string,
     *         booking_code: string,
     *         planning_responsibility_key: string,
     *         planning_label: string
     *     }>,
     *     skipped: array{
     *         must_not_plan: int,
     *         blocked_mat003: int,
     *         skip_media: int,
     *         planable_without_booking: int
     *     }
     * }
     */
    public function parse(string $absolutePath): array
    {
        if (! is_file($absolutePath)) {
            throw new RuntimeException("Kombinationsmatrix nicht gefunden: {$absolutePath}");
        }

        try {
            $reader = IOFactory::createReaderForFile($absolutePath);
            $reader->setReadDataOnly(true);
            $spreadsheet = $reader->load($absolutePath);
        } catch (Throwable $exception) {
            throw new RuntimeException(
                'Kombinationsmatrix konnte nicht gelesen werden: '.$exception->getMessage(),
                0,
                $exception,
            );
        }

        try {
            $bookingSheet = $spreadsheet->getSheetByName(self::BOOKING_SHEET);
            $planningSheet = $spreadsheet->getSheetByName(self::PLANNING_SHEET);
            if ($bookingSheet === null || $planningSheet === null) {
                throw new RuntimeException(
                    'Erwartete Blätter fehlen: '.self::BOOKING_SHEET.' / '.self::PLANNING_SHEET,
                );
            }

            $booking = $this->extractBookingMatrix($bookingSheet);
            $planning = $this->extractPlanningRows($planningSheet);

            return $this->mergeDesiredState($booking, $planning);
        } finally {
            $spreadsheet->disconnectWorksheets();
            unset($spreadsheet);
        }
    }

    private function cellAt(Worksheet $sheet, int $column, int $row): mixed
    {
        $coordinate = Coordinate::stringFromColumnIndex($column).$row;

        return $sheet->getCell($coordinate)->getValue();
    }

    /**
     * @return array{
     *     inventories: list<string>,
     *     cells: array<string, array<string, list<string>>>,
     *     skip_media_cells: int
     * }
     */
    private function extractBookingMatrix(Worksheet $sheet): array
    {
        $inventories = [];
        for ($col = 2; $col <= 15; $col++) {
            $name = $this->cellString($this->cellAt($sheet, $col, 1));
            if ($name === null) {
                throw new RuntimeException("Buchungsmatrix: Inventar-Spalte {$col} ohne Namen.");
            }
            $inventories[] = $name;
        }

        /** @var array<string, array<string, list<string>>> $cells */
        $cells = [];
        $skipMedia = 0;

        for ($row = 2; $row <= 47; $row++) {
            $medium = $this->cellString($this->cellAt($sheet, 1, $row));
            if ($medium === null) {
                throw new RuntimeException("Buchungsmatrix: Zeile {$row} ohne Werbemittel-Label.");
            }

            if (in_array($medium, self::SKIP_MEDIA_LABELS, true)) {
                $skipMedia += count($inventories);

                continue;
            }

            foreach ($inventories as $index => $inventory) {
                $raw = $this->cellString($this->cellAt($sheet, $index + 2, $row));
                if ($raw === null) {
                    continue;
                }
                $cells[$inventory][$medium][] = $raw;
            }
        }

        return [
            'inventories' => $inventories,
            'cells' => $cells,
            'skip_media_cells' => $skipMedia,
        ];
    }

    /**
     * @return array<string, array<string, list<string>>>
     */
    private function extractPlanningRows(Worksheet $sheet): array
    {
        $headerInv = $this->cellString($this->cellAt($sheet, 1, 1));
        $headerMed = $this->cellString($this->cellAt($sheet, 2, 1));
        $headerPlan = $this->cellString($this->cellAt($sheet, 3, 1));
        if ($headerInv !== 'Audio-Angebot' || $headerMed !== 'Werbeformat' || $headerPlan !== 'Einplanung durch?') {
            throw new RuntimeException('Einplanungsblatt: unerwartete Kopfzeile.');
        }

        /** @var array<string, array<string, list<string>>> $rows */
        $rows = [];
        $highest = (int) $sheet->getHighestDataRow();

        for ($row = 2; $row <= $highest; $row++) {
            $inventory = $this->cellString($this->cellAt($sheet, 1, $row));
            $medium = $this->cellString($this->cellAt($sheet, 2, $row));
            $planning = $this->cellString($this->cellAt($sheet, 3, $row));

            if ($inventory === null && $medium === null && $planning === null) {
                continue;
            }

            if ($inventory === null || $medium === null || $planning === null) {
                throw new RuntimeException("Einplanungsblatt Zeile {$row}: unvollständige Zeile.");
            }

            if (in_array($medium, self::SKIP_MEDIA_LABELS, true)) {
                continue;
            }

            $rows[$inventory][$medium][] = $planning;
        }

        return $rows;
    }

    /**
     * @param  array{
     *     inventories: list<string>,
     *     cells: array<string, array<string, list<string>>>,
     *     skip_media_cells: int
     * }  $booking
     * @param  array<string, array<string, list<string>>>  $planning
     * @return array{
     *     rows: list<array{
     *         inventory_name: string,
     *         medium_name: string,
     *         booking_code: string,
     *         planning_responsibility_key: string,
     *         planning_label: string
     *     }>,
     *     skipped: array{
     *         must_not_plan: int,
     *         blocked_mat003: int,
     *         skip_media: int,
     *         planable_without_booking: int
     *     }
     * }
     */
    private function mergeDesiredState(array $booking, array $planning): array
    {
        $blocked = [];
        foreach (self::BLOCKED_COMBINATIONS as [$inv, $med]) {
            $blocked[$inv."\0".$med] = true;
        }

        $rows = [];
        $mustNot = 0;
        $blockedCount = 0;

        foreach ($booking['cells'] as $inventory => $mediaMap) {
            foreach ($mediaMap as $medium => $codes) {
                $uniqueCodes = array_values(array_unique($codes));
                if (count($uniqueCodes) !== 1) {
                    throw new RuntimeException(
                        "Buchungskonflikt für {$inventory} / {$medium}: ".implode(' | ', $uniqueCodes),
                    );
                }
                $bookingCode = $uniqueCodes[0];
                if (strlen($bookingCode) > 32) {
                    throw new RuntimeException(
                        "Buchungskennzeichen zu lang für {$inventory} / {$medium}: {$bookingCode}",
                    );
                }

                if (isset($blocked[$inventory."\0".$medium])) {
                    $blockedCount++;

                    continue;
                }

                $planLabels = $planning[$inventory][$medium] ?? [];
                if ($planLabels === []) {
                    throw new RuntimeException(
                        "Buchungskennzeichen ohne Einplanung: {$inventory} / {$medium} = {$bookingCode}",
                    );
                }

                $uniquePlans = array_values(array_unique($planLabels));
                if (count($uniquePlans) !== 1) {
                    throw new RuntimeException(
                        "Einplanungskonflikt für {$inventory} / {$medium}: ".implode(' | ', $uniquePlans),
                    );
                }

                $planLabel = $uniquePlans[0];
                if ($planLabel === self::MUST_NOT_PLAN_LABEL) {
                    $mustNot++;

                    continue;
                }

                if (! array_key_exists($planLabel, self::PLANNING_LABEL_TO_KEY)) {
                    throw new RuntimeException(
                        "Unbekannter Einplanungswert für {$inventory} / {$medium}: {$planLabel}",
                    );
                }

                $rows[] = [
                    'inventory_name' => $inventory,
                    'medium_name' => $medium,
                    'booking_code' => $bookingCode,
                    'planning_responsibility_key' => self::PLANNING_LABEL_TO_KEY[$planLabel],
                    'planning_label' => $planLabel,
                ];
            }
        }

        $planableWithoutBooking = 0;
        foreach ($planning as $inventory => $mediaMap) {
            foreach ($mediaMap as $medium => $planLabels) {
                if (isset($booking['cells'][$inventory][$medium])) {
                    continue;
                }
                if (in_array($medium, self::SKIP_MEDIA_LABELS, true)) {
                    continue;
                }
                $uniquePlans = array_values(array_unique($planLabels));
                $label = $uniquePlans[0] ?? null;
                if ($label !== null && $label !== self::MUST_NOT_PLAN_LABEL) {
                    $planableWithoutBooking++;
                }
            }
        }

        usort(
            $rows,
            static fn (array $a, array $b): int => [$a['inventory_name'], $a['medium_name']]
                <=> [$b['inventory_name'], $b['medium_name']],
        );

        return [
            'rows' => $rows,
            'skipped' => [
                'must_not_plan' => $mustNot,
                'blocked_mat003' => $blockedCount,
                'skip_media' => $booking['skip_media_cells'],
                'planable_without_booking' => $planableWithoutBooking,
            ],
        ];
    }

    private function cellString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (is_string($value)) {
            $trimmed = trim($value);

            return $trimmed === '' ? null : $trimmed;
        }
        if (is_int($value) || is_float($value)) {
            $trimmed = trim((string) $value);

            return $trimmed === '' ? null : $trimmed;
        }

        throw new RuntimeException('Unerwarteter Zelltyp in der Kombinationsmatrix.');
    }
}
