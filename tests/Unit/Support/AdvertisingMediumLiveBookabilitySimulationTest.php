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
use App\Support\Advertising\CategoryMethodCatalogSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * ADV-001c3b2: CategoryMethodCatalogSnapshot-Simulation ohne DB-Mutation.
 */
class AdvertisingMediumLiveBookabilitySimulationTest extends TestCase
{
    use RefreshDatabase;

    private AdvertisingMediumLiveBookability $bookability;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bookability = new AdvertisingMediumLiveBookability;
    }

    public function test_snapshot_deactivating_average_makes_spot_bookable_medium_unbookable(): void
    {
        $spots = $this->spots();
        $average = CalculationMethod::query()->where('key', 'average')->firstOrFail();
        $medium = AdvertisingMedium::factory()->create([
            'category_id' => $spots->id,
            'code' => 'sim_avg_off',
            'kind' => CalculationKind::SpotClassic,
            'calculation_method_mode' => CalculationMethodMode::Inherit,
        ]);

        $this->assertTrue($this->bookability->evaluate($medium)->isBookableForNewPositions);

        $snapshot = $this->snapshotForCategory($spots, function (AdvertisingCategoryCalculationMethod $row) use ($average): void {
            if ((int) $row->calculation_method_id === (int) $average->id) {
                $row->is_active = false;
            }
        }, $average);

        $result = $this->bookability->evaluate($medium, null, $snapshot);

        $this->assertFalse($result->isBookableForNewPositions);
        $this->assertNotNull($result->unbookableReason);
    }

    public function test_restoring_snapshot_makes_bookable_again(): void
    {
        $spots = $this->spots();
        $average = CalculationMethod::query()->where('key', 'average')->firstOrFail();
        $medium = AdvertisingMedium::factory()->create([
            'category_id' => $spots->id,
            'code' => 'sim_restore',
            'kind' => CalculationKind::SpotClassic,
            'calculation_method_mode' => CalculationMethodMode::Inherit,
        ]);

        $broken = $this->snapshotForCategory($spots, function (AdvertisingCategoryCalculationMethod $row) use ($average): void {
            if ((int) $row->calculation_method_id === (int) $average->id) {
                $row->is_active = false;
            }
        }, $average);
        $this->assertFalse($this->bookability->evaluate($medium, null, $broken)->isBookableForNewPositions);

        $restored = $this->snapshotForCategory($spots, null, $average);
        $result = $this->bookability->evaluate($medium, null, $restored);

        $this->assertTrue($result->isBookableForNewPositions);
        $this->assertNull($result->unbookableReason);
        $this->assertSame('average', $result->calculationMethodKey);
    }

    public function test_snapshot_default_vs_db_default_are_distinguished(): void
    {
        $spots = $this->spots();
        $average = CalculationMethod::query()->where('key', 'average')->firstOrFail();
        $this->assertSame((int) $average->id, (int) $spots->default_calculation_method_id);

        $medium = AdvertisingMedium::factory()->create([
            'category_id' => $spots->id,
            'code' => 'sim_default_diff',
            'kind' => CalculationKind::SpotClassic,
            'calculation_method_mode' => CalculationMethodMode::Inherit,
        ]);

        $fromDb = $this->bookability->evaluate($medium);
        $this->assertTrue($fromDb->isBookableForNewPositions);
        $this->assertSame('average', $fromDb->calculationMethodKey);

        $snapshotNullDefault = $this->snapshotForCategory($spots, null, null);
        $fromSnapshot = $this->bookability->evaluate($medium, null, $snapshotNullDefault);

        $this->assertFalse($fromSnapshot->isBookableForNewPositions);
        $this->assertSame(
            'Für die Oberkategorie ist keine Standard-Berechnungsmethode hinterlegt.',
            $fromSnapshot->unbookableReason,
        );
        $this->assertTrue($this->bookability->evaluate($medium)->isBookableForNewPositions);
    }

    public function test_override_medium_rejects_snapshot(): void
    {
        $spots = $this->spots();
        $average = CalculationMethod::query()->where('key', 'average')->firstOrFail();
        $medium = AdvertisingMedium::factory()->create([
            'category_id' => $spots->id,
            'code' => 'sim_override',
            'kind' => CalculationKind::SpotClassic,
            'calculation_method_mode' => CalculationMethodMode::Override,
            'default_calculation_method_id' => $average->id,
        ]);

        $snapshot = $this->snapshotForCategory($spots, null, $average);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('CategoryMethodCatalogSnapshot darf nur für inherit-Medien verwendet werden.');
        $this->bookability->evaluate($medium, null, $snapshot);
    }

    /**
     * @param  callable(AdvertisingCategoryCalculationMethod): void|null  $mutateAssignment
     */
    private function snapshotForCategory(
        AdvertisingCategory $category,
        ?callable $mutateAssignment,
        ?CalculationMethod $default,
    ): CategoryMethodCatalogSnapshot {
        $category->loadMissing([
            'calculationMethodAssignments.calculationMethod',
            'defaultCalculationMethod',
        ]);

        /** @var Collection<int, AdvertisingCategoryCalculationMethod> $assignments */
        $assignments = new Collection;
        foreach ($category->calculationMethodAssignments as $existing) {
            $model = $existing->replicate();
            $model->id = $existing->id;
            $model->exists = true;
            $model->setRelation('calculationMethod', $existing->calculationMethod);
            if ($mutateAssignment !== null) {
                $mutateAssignment($model);
            }
            $assignments->push($model);
        }

        return new CategoryMethodCatalogSnapshot($default, $assignments);
    }

    private function spots(): AdvertisingCategory
    {
        return AdvertisingCategory::query()
            ->where('key', CanonicalAdvertisingCategories::SPOTS)
            ->firstOrFail();
    }
}
