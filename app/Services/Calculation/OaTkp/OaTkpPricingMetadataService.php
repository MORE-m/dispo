<?php

namespace App\Services\Calculation\OaTkp;

use App\Enums\CalculationMethodMode;
use App\Enums\PriceListStatus;
use App\Models\AdvertisingMedium;
use App\Models\Inventory;
use App\Models\OaTkpPriceList;
use App\Services\Calculation\Decimal;
use App\Support\Calculation\EngineProfileRegistry;
use App\Support\Calculation\OaTkpWizardCapability;
use App\Support\OnlineAudio\OaTkpCoreMedia;
use App\Support\PriceList\PriceListCalendar;
use App\Support\PriceList\PriceListYearSelection;
use Illuminate\Validation\ValidationException;

/**
 * PO-CALC-TKP-1D2a: lightweight readonly OA-TKP Pricing Metadata für Wizard-Rebind.
 *
 * Liefert die aktuelle Active-List-ID (Expected-Token-Kandidat) – nicht den historischen Pin.
 * Source of Truth für Preise: {@see OaTkpPriceResolver}.
 */
final class OaTkpPricingMetadataService
{
    public function __construct(
        private readonly OaTkpPriceResolver $priceResolver = new OaTkpPriceResolver,
    ) {}

    /**
     * @return array{
     *     advertising_medium_id: int,
     *     inventory_id: int,
     *     price_year: int,
     *     available_price_years: list<int>,
     *     expected_oa_tkp_price_list_id: int,
     *     list_tkp: string,
     *     minimum_tkp: string,
     *     price_list_version: string,
     *     price_list_revision: int
     * }
     */
    public function resolve(int $advertisingMediumId, int $inventoryId, ?int $priceYear = null): array
    {
        if (! OaTkpWizardCapability::allowsWizardPreviewSurface()) {
            throw ValidationException::withMessages([
                'oa_tkp' => 'OA-TKP Pricing Metadata ist nicht freigegeben.',
            ]);
        }

        $medium = AdvertisingMedium::query()->find($advertisingMediumId);
        if ($medium === null) {
            throw ValidationException::withMessages([
                'advertising_medium_id' => 'Das Werbemittel ist unbekannt.',
            ]);
        }

        $inventory = Inventory::query()->find($inventoryId);
        if ($inventory === null) {
            throw ValidationException::withMessages([
                'inventory_id' => 'Sender oder Kombi ist unbekannt.',
            ]);
        }

        $this->assertMediumEligible($medium);

        $availableYears = $this->availableYearsForPair((int) $medium->id, (int) $inventory->id);
        $year = $priceYear ?? PriceListCalendar::currentYear();
        PriceListYearSelection::assertAllowedLiveYear($year, 'price_year');

        if ($availableYears === [] || ! in_array($year, $availableYears, true)) {
            throw ValidationException::withMessages([
                'price_year' => "Keine aktive OA-TKP-Preisliste für Jahr {$year}.",
            ]);
        }

        $resolved = $this->priceResolver->resolveActive(
            $year,
            (int) $medium->id,
            (int) $inventory->id,
            (string) $medium->code,
        );

        return [
            'advertising_medium_id' => (int) $medium->id,
            'inventory_id' => (int) $inventory->id,
            'price_year' => (int) $resolved['year'],
            'available_price_years' => $availableYears,
            'expected_oa_tkp_price_list_id' => (int) $resolved['price_list_id'],
            'list_tkp' => Decimal::roundPrice((string) $resolved['list_tkp']),
            'minimum_tkp' => Decimal::roundPrice((string) $resolved['minimum_tkp']),
            'price_list_version' => (string) $resolved['version'],
            'price_list_revision' => (int) $resolved['revision_number'],
        ];
    }

    /**
     * Jahre mit genau einer aktiven OA-Liste und vorhandenem Medium×Inventar-Item.
     *
     * @return list<int>
     */
    public function availableYearsForPair(int $advertisingMediumId, int $inventoryId): array
    {
        $years = [];
        foreach ([PriceListCalendar::currentYear(), PriceListCalendar::nextYear()] as $year) {
            $actives = OaTkpPriceList::query()
                ->where('year', $year)
                ->where('status', PriceListStatus::Active)
                ->orderBy('revision_number')
                ->get();

            if ($actives->count() !== 1) {
                continue;
            }

            /** @var OaTkpPriceList $list */
            $list = $actives->first();
            $hasItem = $list->items()
                ->where('advertising_medium_id', $advertisingMediumId)
                ->where('inventory_id', $inventoryId)
                ->exists();

            if ($hasItem) {
                $years[] = $year;
            }
        }

        return $years;
    }

    private function assertMediumEligible(AdvertisingMedium $medium): void
    {
        $code = trim((string) $medium->code);
        if ($code === 'native_ad' || ! OaTkpCoreMedia::contains($code)) {
            throw ValidationException::withMessages([
                'advertising_medium_id' => 'Dieses Werbemittel ist für TKP nicht verfügbar.',
            ]);
        }

        $medium->loadMissing([
            'category.calculationMethodAssignments.calculationMethod',
            'calculationMethodAssignments.calculationMethod',
        ]);

        $category = $medium->category;
        if (! $medium->is_active || $category === null || ! $category->is_active) {
            throw ValidationException::withMessages([
                'advertising_medium_id' => 'Das Werbemittel ist deaktiviert oder hat keine aktive Oberkategorie.',
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
            if ($method === null || ! $method->is_active || $method->key !== OaTkpWriterPositionResolver::METHOD_KEY) {
                continue;
            }
            if (trim((string) $assignment->engine_profile_key) === EngineProfileRegistry::PROFILE_OA_TKP) {
                return;
            }
        }

        throw ValidationException::withMessages([
            'advertising_medium_id' => 'Dieses Werbemittel ist für TKP nicht zugeordnet.',
        ]);
    }
}
