<?php

namespace Tests\Unit\Support;

use App\Enums\CalculationKind;
use App\Enums\CalculationMethodMode;
use App\Models\AdvertisingCategory;
use App\Models\AdvertisingCategoryCalculationMethod;
use App\Models\AdvertisingMedium;
use App\Models\AdvertisingMediumCalculationMethod;
use App\Models\CalculationMethod;
use App\Support\Advertising\AdvertisingMediumLiveBookability;
use App\Support\Advertising\CanonicalAdvertisingCategories;
use App\Support\Advertising\CategoryMethodCatalogSnapshot;
use App\Support\Advertising\MediumMethodCatalogSnapshot;
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

    public function test_override_medium_rejects_category_snapshot(): void
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

    public function test_inherit_medium_rejects_medium_snapshot(): void
    {
        $spots = $this->spots();
        $average = CalculationMethod::query()->where('key', 'average')->firstOrFail();
        $medium = AdvertisingMedium::factory()->create([
            'category_id' => $spots->id,
            'code' => 'sim_inherit_med_snap',
            'kind' => CalculationKind::SpotClassic,
            'calculation_method_mode' => CalculationMethodMode::Inherit,
        ]);

        $snapshot = $this->snapshotForMedium($average, collect());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('MediumMethodCatalogSnapshot darf nur für override-Medien verwendet werden.');
        $this->bookability->evaluate($medium, null, null, $snapshot);
    }

    public function test_both_snapshots_rejected(): void
    {
        $spots = $this->spots();
        $average = CalculationMethod::query()->where('key', 'average')->firstOrFail();
        $medium = AdvertisingMedium::factory()->create([
            'category_id' => $spots->id,
            'code' => 'sim_both_snap',
            'kind' => CalculationKind::SpotClassic,
            'calculation_method_mode' => CalculationMethodMode::Inherit,
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('dürfen nicht gleichzeitig');
        $this->bookability->evaluate(
            $medium,
            null,
            $this->snapshotForCategory($spots, null, $average),
            $this->snapshotForMedium($average, collect()),
        );
    }

    public function test_override_snapshot_ignores_category_and_uses_medium_config(): void
    {
        $spots = $this->spots();
        $average = CalculationMethod::query()->where('key', 'average')->firstOrFail();
        $medium = AdvertisingMedium::factory()->create([
            'category_id' => $spots->id,
            'code' => 'sim_override_snap',
            'kind' => CalculationKind::SpotClassic,
            'calculation_method_mode' => CalculationMethodMode::Override,
            'default_calculation_method_id' => null,
        ]);

        $assignment = new AdvertisingMediumCalculationMethod;
        $assignment->calculation_method_id = (int) $average->id;
        $assignment->is_active = true;
        $assignment->sort = 10;
        $assignment->setAttribute('engine_profile_key', 'spot_classic');
        $assignment->setRelation('calculationMethod', $average);

        $snapshot = new MediumMethodCatalogSnapshot($average, collect([$assignment]));
        $result = $this->bookability->evaluate($medium, null, null, $snapshot);

        $this->assertTrue($result->isBookableForNewPositions);
        $this->assertSame('average', $result->calculationMethodKey);

        // Persistierte Medium-Daten ohne Default bleiben unbookable.
        $this->assertFalse($this->bookability->evaluate($medium)->isBookableForNewPositions);
    }

    public function test_inherit_ignores_stored_medium_assignments(): void
    {
        $spots = $this->spots();
        $average = CalculationMethod::query()->where('key', 'average')->firstOrFail();
        $medium = AdvertisingMedium::factory()->create([
            'category_id' => $spots->id,
            'code' => 'sim_inherit_ignore',
            'kind' => CalculationKind::SpotClassic,
            'calculation_method_mode' => CalculationMethodMode::Inherit,
            'default_calculation_method_id' => $average->id,
        ]);
        AdvertisingMediumCalculationMethod::factory()->create([
            'advertising_medium_id' => $medium->id,
            'calculation_method_id' => $average->id,
            'engine_profile_key' => null,
            'is_active' => true,
        ]);

        $result = $this->bookability->evaluate($medium->fresh([
            'category.defaultCalculationMethod',
            'category.calculationMethodAssignments.calculationMethod',
            'defaultCalculationMethod',
            'calculationMethodAssignments.calculationMethod',
        ]));

        $this->assertTrue($result->isBookableForNewPositions);
        $this->assertSame('average', $result->calculationMethodKey);
        $this->assertSame('Oberkategorie', $this->bookability->configurationSource($medium));
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

    /**
     * @param  Collection<int, AdvertisingMediumCalculationMethod>  $assignments
     */
    private function snapshotForMedium(
        ?CalculationMethod $default,
        Collection $assignments,
    ): MediumMethodCatalogSnapshot {
        return new MediumMethodCatalogSnapshot($default, $assignments);
    }

    private function spots(): AdvertisingCategory
    {
        return AdvertisingCategory::query()
            ->where('key', CanonicalAdvertisingCategories::SPOTS)
            ->firstOrFail();
    }
}
