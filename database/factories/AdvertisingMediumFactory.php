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
