<?php

namespace Database\Factories;

use App\Enums\CalculationKind;
use App\Enums\CalculationMethodMode;
use App\Enums\SpotComponentProfile;
use App\Models\AdvertisingCategory;
use App\Models\AdvertisingMedium;
use App\Support\Advertising\CanonicalAdvertisingCategories;
use Illuminate\Database\Eloquent\Factories\Factory;
use RuntimeException;

/**
 * @extends Factory<AdvertisingMedium>
 */
class AdvertisingMediumFactory extends Factory
{
    public function definition(): array
    {
        return [
            'category_id' => fn (): int => $this->spotsCategoryId(),
            'name' => 'Spot Classic',
            'code' => 'spot_classic',
            'kind' => CalculationKind::SpotClassic,
            'calculation_method_mode' => CalculationMethodMode::Inherit,
            'default_calculation_method_id' => null,
            'default_length_seconds' => 30,
            'is_discountable' => true,
            'is_ae_eligible' => true,
            'is_active' => true,
            'sort' => 0,
            'lock_version' => 1,
            'component_profile' => null,
        ];
    }

    public function tandem(): static
    {
        return $this->state(fn (): array => [
            'component_profile' => SpotComponentProfile::Tandem,
            'kind' => CalculationKind::SpotClassic,
            'name' => 'Spot Tandem',
            'code' => 'spot_tandem',
        ]);
    }

    public function tridem(): static
    {
        return $this->state(fn (): array => [
            'component_profile' => SpotComponentProfile::Tridem,
            'kind' => CalculationKind::SpotClassic,
            'name' => 'Spot Tridem',
            'code' => 'spot_tridem',
        ]);
    }

    /**
     * PO-SPOT-SURCHARGE-1: Erstplatzierung (kind=spot_classic, Code spot_first).
     */
    public function spotFirst(): static
    {
        return $this->state(fn (): array => [
            'kind' => CalculationKind::SpotClassic,
            'name' => 'Werbespot erstplatziert',
            'code' => 'spot_first',
            'sort' => 20,
        ]);
    }

    /**
     * PO-SPOT-SURCHARGE-1: Letztplatzierung (kind=spot_classic, Code spot_last).
     */
    public function spotLast(): static
    {
        return $this->state(fn (): array => [
            'kind' => CalculationKind::SpotClassic,
            'name' => 'Werbespot letztplatziert',
            'code' => 'spot_last',
            'sort' => 30,
        ]);
    }

    /**
     * PO-SPOT-SINGLE-1: Single-Spot (kind=spot_classic, Code spot_single).
     */
    public function spotSingle(): static
    {
        return $this->state(fn (): array => [
            'kind' => CalculationKind::SpotClassic,
            'name' => 'Single-Spot',
            'code' => 'spot_single',
            'sort' => 40,
        ]);
    }

    /**
     * BL-P5-01a: Trailer (kind=swf_trailer) in Kategorie special_advertising_formats.
     */
    public function swfTrailer(): static
    {
        return $this->state(fn (): array => [
            'category_id' => fn (): int => $this->categoryId(CanonicalAdvertisingCategories::SPECIAL_ADVERTISING_FORMATS),
            'kind' => CalculationKind::SwfTrailer,
            'name' => 'Trailer/Vorpr. Element Station Voice',
            'code' => 'trailer_station_voice',
        ]);
    }

    /**
     * BL-P5-01a: weiteres SWF-Medium ohne Berechnungsart (kind=null → nicht buchbar).
     */
    /**
     * PO-ALLONGE-1: Allonge (kind=swf_allonge). Override-Zuordnung legt der Katalog-Helper an.
     */
    public function swfAllonge(): static
    {
        return $this->state(fn (): array => [
            'category_id' => fn (): int => $this->categoryId(CanonicalAdvertisingCategories::SPECIAL_ADVERTISING_FORMATS),
            'kind' => CalculationKind::SwfAllonge,
            'name' => 'Allonge',
            'code' => 'allonge',
        ]);
    }

    /**
     * PO-SWF-PROMO-1: Promo/Moderation (kind=swf_promo). Override-Zuordnung legt der Katalog-Helper an.
     */
    public function swfPromo(): static
    {
        return $this->state(fn (): array => [
            'category_id' => fn (): int => $this->categoryId(CanonicalAdvertisingCategories::SPECIAL_ADVERTISING_FORMATS),
            'kind' => CalculationKind::SwfPromo,
            'name' => 'Promo/Moderation',
            'code' => 'promo_moderation',
            'default_length_seconds' => 20,
            'sort' => 100,
        ]);
    }

    /**
     * PO-SWF-CONTEST-1: Gewinnspiel/Pay-Off (kind=swf_contest). Override-Zuordnung legt der Katalog-Helper an.
     */
    public function swfContest(): static
    {
        return $this->state(fn (): array => [
            'category_id' => fn (): int => $this->categoryId(CanonicalAdvertisingCategories::SPECIAL_ADVERTISING_FORMATS),
            'kind' => CalculationKind::SwfContest,
            'name' => 'Gewinnspiel/Pay-Off',
            'code' => 'contest_payoff',
            'default_length_seconds' => 25,
            'sort' => 210,
        ]);
    }

    public function swfWithoutKind(string $name = 'Preseller', string $code = 'preseller'): static
    {
        return $this->state(fn (): array => [
            'category_id' => fn (): int => $this->categoryId(CanonicalAdvertisingCategories::SPECIAL_ADVERTISING_FORMATS),
            'kind' => null,
            'name' => $name,
            'code' => $code,
        ]);
    }

    private function categoryId(string $key): int
    {
        $id = AdvertisingCategory::query()->where('key', $key)->value('id');

        if ($id === null) {
            throw new RuntimeException("AdvertisingMediumFactory: kanonische Kategorie „{$key}“ fehlt.");
        }

        return (int) $id;
    }

    private function spotsCategoryId(): int
    {
        $id = AdvertisingCategory::query()
            ->where('key', CanonicalAdvertisingCategories::SPOTS)
            ->value('id');

        if ($id === null) {
            throw new RuntimeException(
                'AdvertisingMediumFactory: kanonische Kategorie „spots“ fehlt (ADV-001a Migration/Seed).',
            );
        }

        return (int) $id;
    }
}
