<?php

namespace Tests\Unit\Support;

use App\Enums\CalculationKind;
use App\Enums\CalculationMethodMode;
use App\Models\AdvertisingCategory;
use App\Models\AdvertisingCategoryCalculationMethod;
use App\Models\AdvertisingMedium;
use App\Models\CalculationMethod;
use App\Support\Advertising\AdvertisingMediumLiveBookability;
use App\Support\Advertising\CanonicalAdvertisingCategories;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADV-001c3a: zentrale Medium-Live-Buchbarkeit.
 */
class AdvertisingMediumLiveBookabilityTest extends TestCase
{
    use RefreshDatabase;

    private AdvertisingMediumLiveBookability $bookability;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bookability = new AdvertisingMediumLiveBookability;
    }

    public function test_null_kind_medium_is_not_bookable_with_german_reason(): void
    {
        $spots = $this->spots();
        $medium = AdvertisingMedium::factory()->create([
            'category_id' => $spots->id,
            'code' => 'null_kind_media',
            'kind' => null,
        ]);

        $result = $this->bookability->evaluate($medium);

        $this->assertFalse($result->isBookableForNewPositions);
        $this->assertSame(
            'Keine freigegebene Berechnungsmethode vorhanden.',
            $result->unbookableReason,
        );
    }

    public function test_configured_spot_classic_average_v1_is_bookable(): void
    {
        $medium = AdvertisingMedium::factory()->create([
            'code' => 'spot_classic_bookable',
            'kind' => CalculationKind::SpotClassic,
        ]);

        $result = $this->bookability->evaluate($medium);

        $this->assertTrue($result->isBookableForNewPositions);
        $this->assertNull($result->unbookableReason);
        $this->assertSame('spot_classic', $result->engineProfileKey);
        $this->assertSame('average', $result->calculationMethodKey);
        $this->assertSame('v1', $result->algorithmVersion);
    }

    public function test_planned_fixed_price_method_is_not_bookable(): void
    {
        $medium = AdvertisingMedium::factory()->create([
            'code' => 'spot_classic_planned',
            'kind' => CalculationKind::SpotClassic,
        ]);

        $result = $this->bookability->evaluate($medium, 'fixed_price');

        $this->assertFalse($result->isBookableForNewPositions);
        $this->assertNotNull($result->unbookableReason);
        $this->assertStringContainsString('noch nicht freigegeben', (string) $result->unbookableReason);
    }

    public function test_calendar_method_is_bookable(): void
    {
        $medium = AdvertisingMedium::factory()->create([
            'code' => 'spot_classic_calendar',
            'kind' => CalculationKind::SpotClassic,
        ]);

        $result = $this->bookability->evaluate($medium, 'calendar');

        $this->assertTrue($result->isBookableForNewPositions);
        $this->assertSame('calendar', $result->calculationMethodKey);
        $this->assertSame('v1', $result->algorithmVersion);
    }

    public function test_inactive_medium_is_not_bookable(): void
    {
        $medium = AdvertisingMedium::factory()->create([
            'code' => 'spot_inactive',
            'kind' => CalculationKind::SpotClassic,
            'is_active' => false,
        ]);

        $result = $this->bookability->evaluate($medium);

        $this->assertFalse($result->isBookableForNewPositions);
        $this->assertSame('Das Werbemittel ist im Katalog deaktiviert.', $result->unbookableReason);
    }

    public function test_inactive_category_is_not_bookable(): void
    {
        $spots = $this->spots();
        $medium = AdvertisingMedium::factory()->create([
            'category_id' => $spots->id,
            'code' => 'spot_cat_inactive',
            'kind' => CalculationKind::SpotClassic,
        ]);
        $spots->forceFill(['is_active' => false])->save();

        $result = $this->bookability->evaluate($medium->fresh(['category']));

        $this->assertFalse($result->isBookableForNewPositions);
        $this->assertSame(
            'Die Oberkategorie des Werbemittels ist unbekannt oder inaktiv.',
            $result->unbookableReason,
        );
    }

    public function test_inactive_method_is_not_bookable(): void
    {
        $medium = AdvertisingMedium::factory()->create([
            'code' => 'spot_method_inactive',
            'kind' => CalculationKind::SpotClassic,
        ]);
        CalculationMethod::query()->where('key', 'average')->update(['is_active' => false]);

        $result = $this->bookability->evaluate($medium->fresh());

        $this->assertFalse($result->isBookableForNewPositions);
        $this->assertSame('Die Berechnungsmethode ist unbekannt oder inaktiv.', $result->unbookableReason);
    }

    public function test_inactive_assignment_is_not_bookable(): void
    {
        $spots = $this->spots();
        $average = CalculationMethod::query()->where('key', 'average')->firstOrFail();
        AdvertisingCategoryCalculationMethod::query()
            ->where('advertising_category_id', $spots->id)
            ->where('calculation_method_id', $average->id)
            ->update(['is_active' => false]);

        $medium = AdvertisingMedium::factory()->create([
            'category_id' => $spots->id,
            'code' => 'spot_assignment_inactive',
            'kind' => CalculationKind::SpotClassic,
        ]);

        $result = $this->bookability->evaluate($medium);

        $this->assertFalse($result->isBookableForNewPositions);
        $this->assertStringContainsString(
            'nicht aktiv zugeordnet',
            (string) $result->unbookableReason,
        );
    }

    public function test_missing_engine_profile_key_is_not_bookable(): void
    {
        $spots = $this->spots();
        $average = CalculationMethod::query()->where('key', 'average')->firstOrFail();
        AdvertisingCategoryCalculationMethod::query()
            ->where('advertising_category_id', $spots->id)
            ->where('calculation_method_id', $average->id)
            ->update(['engine_profile_key' => null]);

        $medium = AdvertisingMedium::factory()->create([
            'category_id' => $spots->id,
            'code' => 'spot_missing_profile',
            'kind' => CalculationKind::SpotClassic,
        ]);

        $result = $this->bookability->evaluate($medium);

        $this->assertFalse($result->isBookableForNewPositions);
        $this->assertStringContainsString(
            'nicht aktiv zugeordnet',
            (string) $result->unbookableReason,
        );
    }

    public function test_override_without_default_is_not_bookable(): void
    {
        $medium = AdvertisingMedium::factory()->create([
            'code' => 'spot_override_empty',
            'kind' => CalculationKind::SpotClassic,
            'calculation_method_mode' => CalculationMethodMode::Override,
            'default_calculation_method_id' => null,
        ]);

        $result = $this->bookability->evaluate($medium);

        $this->assertFalse($result->isBookableForNewPositions);
        $this->assertSame(
            'Für dieses Werbemittel ist keine Standard-Berechnungsmethode hinterlegt.',
            $result->unbookableReason,
        );
    }

    public function test_payload_for_medium_always_includes_reason_when_blocked(): void
    {
        $medium = AdvertisingMedium::factory()->create([
            'code' => 'null_kind_payload',
            'kind' => null,
        ]);

        $payload = $this->bookability->payloadForMedium($medium);

        $this->assertFalse($payload['is_bookable_for_new_positions']);
        $this->assertIsString($payload['unbookable_reason']);
        $this->assertNotSame('', $payload['unbookable_reason']);
    }

    private function spots(): AdvertisingCategory
    {
        return AdvertisingCategory::query()
            ->where('key', CanonicalAdvertisingCategories::SPOTS)
            ->firstOrFail();
    }
}
