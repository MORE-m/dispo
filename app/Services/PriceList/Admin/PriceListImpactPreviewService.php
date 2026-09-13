<?php

namespace App\Services\PriceList\Admin;

use App\Enums\DayGroup;
use App\Enums\PriceListStatus;
use App\Exceptions\CatalogAdminConflictException;
use App\Exceptions\PriceListAdminConflictException;
use App\Models\CalculationPosition;
use App\Models\DispoOrderPosition;
use App\Models\PriceList;
use App\Services\Advertising\Admin\CatalogImpactPreviewService;
use App\Services\Calculation\DayGroupPrice;
use App\Support\PriceList\PriceListCalendar;
use App\Support\PriceList\PriceListItemContract;
use Illuminate\Validation\ValidationException;

/**
 * BL-P4-01a: Auswirkungsvorschau für Aktivierung und Archivierung.
 */
final class PriceListImpactPreviewService
{
    public const ACTION_ACTIVATE = 'price_list_activate';

    public const ACTION_ARCHIVE = 'price_list_archive';

    public function __construct(
        private readonly CatalogImpactPreviewService $fingerprints,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function previewActivate(PriceList $priceList): array
    {
        $priceList->loadMissing(['inventory', 'items']);
        $blocking = [];

        if ($priceList->status !== PriceListStatus::Draft) {
            $blocking[] = [
                'code' => 'not_draft',
                'message' => 'Nur Entwürfe können veröffentlicht werden.',
            ];
        }

        try {
            $coverage = [];
            foreach ($priceList->items as $item) {
                if ($item->day_group->isDerived()) {
                    continue;
                }
                $coverage[] = [
                    'hour' => (int) $item->hour,
                    'day_group' => $item->day_group,
                    'second_price' => (string) $item->second_price,
                ];
            }
            PriceListItemContract::assertActivationCoverage($coverage);
        } catch (ValidationException $exception) {
            $blocking[] = [
                'code' => 'incomplete_items',
                'message' => $exception->errors()['items'][0] ?? 'Die Preisliste ist unvollständig.',
            ];
        }

        $predecessor = $this->currentActive($priceList);
        $historical = $this->historicalCounts($priceList);
        $currentYear = PriceListCalendar::currentYear();
        $affectsCurrentYearDefault = (int) $priceList->year === $currentYear;

        $replaced = $predecessor === null ? [] : [[
            'id' => (int) $predecessor->id,
            'name' => $predecessor->name,
            'version' => $predecessor->version,
            'year' => (int) $predecessor->year,
            'lock_version' => (int) $predecessor->lock_version,
            'status' => $predecessor->status->value,
        ]];

        $unchangedYears = PriceList::query()
            ->where('inventory_id', $priceList->inventory_id)
            ->where('status', PriceListStatus::Active)
            ->where('year', '!=', $priceList->year)
            ->orderBy('year')
            ->get(['id', 'name', 'version', 'year'])
            ->map(fn (PriceList $list): array => [
                'id' => (int) $list->id,
                'name' => $list->name,
                'version' => $list->version,
                'year' => (int) $list->year,
            ])
            ->values()
            ->all();

        $body = [
            'entity' => 'price_list',
            'action' => self::ACTION_ACTIVATE,
            'entity_id' => (int) $priceList->id,
            'lock_version' => (int) $priceList->lock_version,
            'inventory_id' => (int) $priceList->inventory_id,
            'inventory_name' => $priceList->inventory?->name,
            'inventory_type' => $priceList->inventory?->type->value,
            'year' => (int) $priceList->year,
            'version' => $priceList->version,
            'current_calendar_year' => $currentYear,
            'affects_current_year_default' => $affectsCurrentYearDefault,
            'replaced_active' => $replaced,
            'unchanged_active_years' => $unchangedYears,
            'calculation_positions_count' => $historical['calculations'],
            'dispo_order_positions_count' => $historical['dispos'],
            'historical_note' => 'Bestehende Kalkulationen und Dispoaufträge behalten ihre gepinnte Preislistenversion. Zusätzliche Stunden lesen weiter aus derselben Version.',
            'current_year_note' => $affectsCurrentYearDefault
                ? 'Nach der Veröffentlichung wird diese Version für neue Positionen des Jahres '.$currentYear.' vorausgewählt.'
                : 'Die Vorauswahl für das aktuelle Kalenderjahr '.$currentYear.' bleibt unverändert. Eine Zukunftsliste ersetzt aktuelle Jahrespreise nicht automatisch.',
            'blocking_reasons' => $blocking,
            'can_proceed' => $blocking === [],
        ];

        $body['fingerprint'] = $this->fingerprints->fingerprint($body);

        return $body;
    }

    /**
     * @return array<string, mixed>
     */
    public function previewArchive(PriceList $priceList): array
    {
        $priceList->loadMissing(['inventory']);
        $blocking = [];

        if ($priceList->status === PriceListStatus::Archived) {
            $blocking[] = [
                'code' => 'already_archived',
                'message' => 'Die Preisliste ist bereits archiviert.',
            ];
        }

        if ($priceList->status === PriceListStatus::Draft) {
            // Draft-Archivierung ist zulässig; keine Reaktivierung.
        }

        $isLastActiveOfYear = $priceList->status === PriceListStatus::Active
            && ! PriceList::query()
                ->where('inventory_id', $priceList->inventory_id)
                ->where('year', $priceList->year)
                ->where('status', PriceListStatus::Active)
                ->where('id', '!=', $priceList->id)
                ->exists();

        $currentYear = PriceListCalendar::currentYear();
        $affectsCurrentYearDefault = $isLastActiveOfYear && (int) $priceList->year === $currentYear;
        $historical = $this->historicalCounts($priceList);

        $warnings = [];
        if ($isLastActiveOfYear) {
            $warnings[] = 'Dies ist die letzte aktive Preisliste für '
                .($priceList->inventory->name ?? 'dieses Inventar')
                .' im Jahr '.$priceList->year
                .'. Neue Positionen dieses Jahres erhalten danach keine automatische Jahresvorauswahl.';
        }

        $body = [
            'entity' => 'price_list',
            'action' => self::ACTION_ARCHIVE,
            'entity_id' => (int) $priceList->id,
            'lock_version' => (int) $priceList->lock_version,
            'inventory_id' => (int) $priceList->inventory_id,
            'inventory_name' => $priceList->inventory?->name,
            'year' => (int) $priceList->year,
            'version' => $priceList->version,
            'status' => $priceList->status->value,
            'current_calendar_year' => $currentYear,
            'is_last_active_of_year' => $isLastActiveOfYear,
            'affects_current_year_default' => $affectsCurrentYearDefault,
            'warnings' => $warnings,
            'calculation_positions_count' => $historical['calculations'],
            'dispo_order_positions_count' => $historical['dispos'],
            'historical_note' => 'Historische Vorgänge bleiben unverändert an dieser Version gepinnt.',
            'blocking_reasons' => $blocking,
            'can_proceed' => $blocking === [],
        ];

        $body['fingerprint'] = $this->fingerprints->fingerprint($body);

        return $body;
    }

    /**
     * @param  array<string, mixed>  $preview
     */
    public function assertFingerprint(array $preview, string $expected): void
    {
        try {
            $this->fingerprints->assertFingerprint($preview, $expected);
        } catch (CatalogAdminConflictException) {
            throw new PriceListAdminConflictException(
                'Die Auswirkungsvorschau ist veraltet. Bitte Vorschau erneut laden und bestätigen.',
            );
        }
    }

    /**
     * @param  list<array{hour: int, day_group: DayGroup, second_price: string}>  $baseItems
     * @return list<array{hour: int, day_group: string, day_group_label: string, second_price: string}>
     */
    public function derivedPrices(array $baseItems): array
    {
        $byHour = [];
        foreach ($baseItems as $item) {
            $byHour[$item['hour']][$item['day_group']->value] = $item['second_price'];
        }

        $derived = [];
        ksort($byHour);
        foreach ($byHour as $hour => $groups) {
            if (
                ! isset($groups[DayGroup::MoFr->value], $groups[DayGroup::Sa->value], $groups[DayGroup::So->value])
            ) {
                continue;
            }

            foreach ([DayGroup::MoSa, DayGroup::MoSo] as $group) {
                $derived[] = [
                    'hour' => (int) $hour,
                    'day_group' => $group->value,
                    'day_group_label' => $group->label(),
                    'second_price' => DayGroupPrice::fromBaseMap($groups, $group),
                ];
            }
        }

        return $derived;
    }

    private function currentActive(PriceList $priceList): ?PriceList
    {
        return PriceList::query()
            ->where('inventory_id', $priceList->inventory_id)
            ->where('year', $priceList->year)
            ->where('status', PriceListStatus::Active)
            ->where('id', '!=', $priceList->id)
            ->first();
    }

    /**
     * @return array{calculations: int, dispos: int}
     */
    private function historicalCounts(PriceList $priceList): array
    {
        return [
            'calculations' => CalculationPosition::query()
                ->where('price_list_id', $priceList->id)
                ->count(),
            'dispos' => DispoOrderPosition::query()
                ->where('price_list_id', $priceList->id)
                ->count(),
        ];
    }
}
