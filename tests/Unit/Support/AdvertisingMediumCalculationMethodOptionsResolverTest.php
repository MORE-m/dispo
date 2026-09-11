<?php

namespace Tests\Unit\Support;

use App\Enums\CalculationKind;
use App\Enums\CalculationMethodMode;
use App\Models\AdvertisingCategory;
use App\Models\AdvertisingCategoryCalculationMethod;
use App\Models\AdvertisingMedium;
use App\Models\AdvertisingMediumCalculationMethod;
use App\Models\CalculationMethod;
use App\Support\Advertising\AdvertisingMediumCalculationMethodOptions;
use App\Support\Advertising\AdvertisingMediumCalculationMethodOptionsResolver;
use App\Support\Advertising\CanonicalAdvertisingCategories;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ADV-001c4a: auswählbare Berechnungsmethoden ohne parallele Selectability-Logik.
 */
class AdvertisingMediumCalculationMethodOptionsResolverTest extends TestCase
{
    use RefreshDatabase;

    private AdvertisingMediumCalculationMethodOptionsResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new AdvertisingMediumCalculationMethodOptionsResolver;
    }

    public function test_inherit_returns_only_released_average_sorted(): void
    {
        $medium = AdvertisingMedium::factory()->create([
            'code' => 'c4a_inherit_opts',
            'kind' => CalculationKind::SpotClassic,
            'calculation_method_mode' => CalculationMethodMode::Inherit,
        ]);

        $options = $this->resolver->resolve($medium);

        $this->assertSame($medium->id, $options->mediumId);
        $this->assertSame(AdvertisingMediumCalculationMethodOptions::SOURCE_CATEGORY, $options->source);
        $this->assertSame('average', $options->defaultCalculationMethodKey);
        $this->assertCount(1, $options->methods);
        $this->assertSame('average', $options->methods[0]['key']);
        $this->assertTrue($options->methods[0]['is_default']);
        $this->assertSame('Durchschnitt', $options->methods[0]['name']);
        $this->assertArrayNotHasKey('engine_profile_key', $options->toPayload());
        $this->assertArrayNotHasKey('algorithm_version', $options->methods[0]);
    }

    public function test_planned_calendar_and_fixed_price_are_excluded(): void
    {
        $medium = AdvertisingMedium::factory()->create([
            'code' => 'c4a_planned_excluded',
            'kind' => CalculationKind::SpotClassic,
        ]);

        $keys = array_column($this->resolver->resolve($medium)->methods, 'key');

        $this->assertSame(['average'], $keys);
        $this->assertNotContains('calendar', $keys);
        $this->assertNotContains('fixed_price', $keys);
    }

    public function test_override_does_not_fall_back_to_category(): void
    {
        $spots = $this->spots();
        $average = CalculationMethod::query()->where('key', 'average')->firstOrFail();

        $medium = AdvertisingMedium::factory()->create([
            'category_id' => $spots->id,
            'code' => 'c4a_override_empty',
            'kind' => CalculationKind::SpotClassic,
            'calculation_method_mode' => CalculationMethodMode::Override,
            'default_calculation_method_id' => null,
        ]);

        $options = $this->resolver->resolve($medium);

        $this->assertSame(AdvertisingMediumCalculationMethodOptions::SOURCE_MEDIUM_OVERRIDE, $options->source);
        $this->assertNull($options->defaultCalculationMethodKey);
        $this->assertSame([], $options->methods);

        AdvertisingMediumCalculationMethod::factory()->create([
            'advertising_medium_id' => $medium->id,
            'calculation_method_id' => $average->id,
            'engine_profile_key' => 'spot_classic',
            'is_active' => true,
            'sort' => 10,
        ]);
        $medium->forceFill(['default_calculation_method_id' => $average->id])->save();

        $options = $this->resolver->resolve($medium->fresh([
            'defaultCalculationMethod',
            'calculationMethodAssignments.calculationMethod',
            'category.calculationMethodAssignments.calculationMethod',
            'category.defaultCalculationMethod',
        ]));

        $this->assertSame(['average'], array_column($options->methods, 'key'));
        $this->assertSame('average', $options->defaultCalculationMethodKey);
    }

    public function test_inactive_method_and_assignment_and_missing_profile_are_excluded(): void
    {
        $spots = $this->spots();
        $average = CalculationMethod::query()->where('key', 'average')->firstOrFail();
        $medium = AdvertisingMedium::factory()->create([
            'category_id' => $spots->id,
            'code' => 'c4a_inactive_opts',
            'kind' => CalculationKind::SpotClassic,
        ]);

        AdvertisingCategoryCalculationMethod::query()
            ->where('advertising_category_id', $spots->id)
            ->where('calculation_method_id', $average->id)
            ->update(['is_active' => false]);

        $this->assertSame([], $this->resolver->resolve($medium->fresh([
            'category.calculationMethodAssignments.calculationMethod',
            'category.defaultCalculationMethod',
        ]))->methods);

        AdvertisingCategoryCalculationMethod::query()
            ->where('advertising_category_id', $spots->id)
            ->where('calculation_method_id', $average->id)
            ->update(['is_active' => true, 'engine_profile_key' => null]);

        $this->assertSame([], $this->resolver->resolve($medium->fresh([
            'category.calculationMethodAssignments.calculationMethod',
            'category.defaultCalculationMethod',
        ]))->methods);

        AdvertisingCategoryCalculationMethod::query()
            ->where('advertising_category_id', $spots->id)
            ->where('calculation_method_id', $average->id)
            ->update(['engine_profile_key' => 'spot_classic']);
        DB::table('calculation_methods')->where('id', $average->id)->update(['is_active' => false]);

        $this->assertSame([], $this->resolver->resolve($medium->fresh([
            'category.calculationMethodAssignments.calculationMethod',
            'category.defaultCalculationMethod',
        ]))->methods);
    }

    public function test_methods_are_unique_and_sorted_by_assignment_sort(): void
    {
        $spots = $this->spots();
        $average = CalculationMethod::query()->where('key', 'average')->firstOrFail();
        $calendar = CalculationMethod::query()->where('key', 'calendar')->firstOrFail();

        AdvertisingCategoryCalculationMethod::query()
            ->where('advertising_category_id', $spots->id)
            ->where('calculation_method_id', $calendar->id)
            ->update(['sort' => 5]);
        AdvertisingCategoryCalculationMethod::query()
            ->where('advertising_category_id', $spots->id)
            ->where('calculation_method_id', $average->id)
            ->update(['sort' => 20]);

        $medium = AdvertisingMedium::factory()->create([
            'category_id' => $spots->id,
            'code' => 'c4a_sort_opts',
            'kind' => CalculationKind::SpotClassic,
        ]);

        // calendar bleibt planned → nur average; Sortierung trotzdem deterministisch.
        $options = $this->resolver->resolve($medium);
        $this->assertSame(['average'], array_column($options->methods, 'key'));
        $this->assertSame(1, count(array_unique(array_column($options->methods, 'key'))));
    }

    public function test_null_kind_medium_has_no_selectable_methods(): void
    {
        $medium = AdvertisingMedium::factory()->create([
            'code' => 'c4a_null_kind_opts',
            'kind' => null,
        ]);

        $options = $this->resolver->resolve($medium);

        $this->assertSame([], $options->methods);
        $this->assertNull($options->defaultCalculationMethodKey);
    }

    private function spots(): AdvertisingCategory
    {
        return AdvertisingCategory::query()
            ->where('key', CanonicalAdvertisingCategories::SPOTS)
            ->firstOrFail();
    }
}
