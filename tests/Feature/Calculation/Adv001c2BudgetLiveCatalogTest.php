<?php

namespace Tests\Feature\Calculation;

use App\Enums\BudgetStrategy;
use App\Enums\DayGroup;
use App\Enums\PlanningMode;
use App\Enums\Role;
use App\Models\AdvertisingCategoryCalculationMethod;
use App\Models\AdvertisingMedium;
use App\Models\CalculationMethod;
use App\Models\User;
use App\Services\Calculation\BudgetSpotProposalService;
use App\Services\Calculation\CalculationWriter;
use App\Services\Calculation\CatalogResolver;
use App\Services\DynamicField\ConfigurationSnapshotFreezeService;
use App\Support\Calculation\CalculationMethodFreezeDescriptor;
use App\Support\Calculation\CalculationMethodFreezeResolver as FreezeResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreatesSpotClassicCatalog;
use Tests\TestCase;

/**
 * ADV-001c2: Budget-/Re-Optimierungspfad am Live-Katalogvertrag.
 */
class Adv001c2BudgetLiveCatalogTest extends TestCase
{
    use CreatesSpotClassicCatalog;
    use RefreshDatabase;

    public function test_successful_spot_classic_average_budget_proposal(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $proposal = app(BudgetSpotProposalService::class)->propose(
            $this->budgetPayload($catalog['hamburg']->id, '2000.00'),
            null,
        );

        $this->assertGreaterThan(0, $proposal['spots_per_sender']);
        $this->assertNotEmpty($proposal['positions']);
    }

    public function test_budget_rejects_inactive_medium(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        DB::table('advertising_media')->where('id', $catalog['medium']->id)->update(['is_active' => false]);

        $this->assertBudgetFieldRejection(
            $catalog['hamburg']->id,
            'Spot Classic ist nicht verfügbar.',
        );
    }

    public function test_budget_rejects_inactive_category(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        DB::table('advertising_categories')
            ->where('id', $catalog['medium']->category_id)
            ->update(['is_active' => false]);

        $this->assertBudgetFieldRejection(
            $catalog['hamburg']->id,
            'Oberkategorie',
        );
    }

    public function test_budget_rejects_inactive_average_method(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        DB::table('calculation_methods')->where('key', 'average')->update(['is_active' => false]);

        $this->assertBudgetFieldRejection(
            $catalog['hamburg']->id,
            'Berechnungsmethode',
        );
    }

    public function test_budget_rejects_inactive_assignment(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $average = CalculationMethod::query()->where('key', 'average')->firstOrFail();
        AdvertisingCategoryCalculationMethod::query()
            ->where('advertising_category_id', $catalog['medium']->category_id)
            ->where('calculation_method_id', $average->id)
            ->update(['is_active' => false]);

        $this->assertBudgetFieldRejection(
            $catalog['hamburg']->id,
            'nicht aktiv zugeordnet',
        );
    }

    public function test_budget_rejects_missing_assignment(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $average = CalculationMethod::query()->where('key', 'average')->firstOrFail();
        AdvertisingCategoryCalculationMethod::query()
            ->where('advertising_category_id', $catalog['medium']->category_id)
            ->where('calculation_method_id', $average->id)
            ->delete();

        $this->assertBudgetFieldRejection(
            $catalog['hamburg']->id,
            'nicht aktiv zugeordnet',
        );
    }

    public function test_budget_rejects_unknown_engine_profile(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $average = CalculationMethod::query()->where('key', 'average')->firstOrFail();
        AdvertisingCategoryCalculationMethod::query()
            ->where('advertising_category_id', $catalog['medium']->category_id)
            ->where('calculation_method_id', $average->id)
            ->update(['engine_profile_key' => 'unknown_engine']);

        $this->assertBudgetFieldRejection(
            $catalog['hamburg']->id,
            'technisch unbekannt',
        );
    }

    public function test_budget_rejects_planned_method_pair_with_budget_field(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        // calendar ist pair_status=planned; gleiche zentrale Live-Logik, Fehler unter Budget-Feld.
        $resolver = new CatalogResolver(new class extends FreezeResolver
        {
            public function resolveForNewCombination(
                AdvertisingMedium $medium,
                ?string $requestedMethodKey,
            ): CalculationMethodFreezeDescriptor {
                return parent::resolveForNewCombination($medium, 'calendar');
            }
        });

        try {
            $resolver->resolveInventoryForBudget(
                (int) $catalog['hamburg']->id,
                (int) $catalog['medium']->id,
            );
            $this->fail('Erwartete ValidationException bei geplanter Methode.');
        } catch (ValidationException $exception) {
            $this->assertSame(422, $exception->status);
            $message = implode(' ', $exception->errors()['budget_wish_inventory_ids'] ?? []);
            $this->assertStringContainsString('noch nicht freigegeben', $message);
            $this->assertArrayNotHasKey('positions', $exception->errors());
        }
    }

    public function test_budget_proposal_then_apply_does_not_surprise_reject(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $proposal = app(BudgetSpotProposalService::class)->propose(
            $this->budgetPayload($catalog['hamburg']->id, '2000.00', $catalog['rock']->id),
            null,
        );

        $this->assertGreaterThan(0, $proposal['spots_per_sender']);

        $freeze = app(ConfigurationSnapshotFreezeService::class);
        $baseFingerprint = $freeze->resolveLiveSchemaForCalculationV3()['schema_fingerprint'];
        $positionFingerprint = $freeze->resolveLivePositionSchema((int) $catalog['medium']->id)['schema_fingerprint'];

        // Dieselbe Live-Katalogbasis wie der Budgetvorschlag: Schreiben darf nicht
        // überraschend an Freeze-/Zuordnungsregeln scheitern.
        $calculation = app(CalculationWriter::class)->create([
            'planning_mode' => 'manual',
            'schema_fingerprint' => $baseFingerprint,
            'customer_name' => 'Budget Apply Live',
            'campaign' => 'C',
            'product_title' => 'P',
            'order_discount_percent' => '0',
            'ae_enabled' => false,
            'positions' => [
                [
                    'inventory_id' => $catalog['hamburg']->id,
                    'advertising_medium_id' => $catalog['medium']->id,
                    'schema_fingerprint' => $positionFingerprint,
                    'spot_method' => 'average',
                    'length_seconds' => 30,
                    'total_spot_count' => (int) $proposal['positions'][0]['total_spot_count'],
                    'position_discount_percent' => '0',
                    'ae_percent' => '15',
                    'plan_rows' => [['hour' => 8, 'day_group' => 'mo_fr']],
                ],
                [
                    'inventory_id' => $catalog['rock']->id,
                    'advertising_medium_id' => $catalog['medium']->id,
                    'schema_fingerprint' => $positionFingerprint,
                    'spot_method' => 'average',
                    'length_seconds' => 30,
                    'total_spot_count' => (int) $proposal['positions'][1]['total_spot_count'],
                    'position_discount_percent' => '0',
                    'ae_percent' => '15',
                    'plan_rows' => [['hour' => 10, 'day_group' => 'mo_fr']],
                ],
            ],
        ], $user);

        $this->assertCount(2, $calculation->positions);
        foreach ($calculation->positions as $stored) {
            $this->assertSame('spot_classic', $stored->engine_profile_key);
            $this->assertSame('average', $stored->calculation_method_key);
            $this->assertSame('v1', $stored->algorithm_version);
        }
    }

    public function test_resolve_inventory_for_budget_returns_freeze_descriptor(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $resolved = app(CatalogResolver::class)->resolveInventoryForBudget(
            (int) $catalog['hamburg']->id,
            (int) $catalog['medium']->id,
        );

        $this->assertInstanceOf(CalculationMethodFreezeDescriptor::class, $resolved['freeze']);
        $this->assertSame('spot_classic', $resolved['freeze']->engineProfileKey);
        $this->assertSame('average', $resolved['freeze']->calculationMethodKey);
        $this->assertSame('Durchschnitt', $resolved['freeze']->calculationMethodName);
        $this->assertSame('v1', $resolved['freeze']->algorithmVersion);
    }

    private function assertBudgetFieldRejection(int $inventoryId, string $messageFragment): void
    {
        try {
            app(BudgetSpotProposalService::class)->propose(
                $this->budgetPayload($inventoryId, '2000.00'),
                null,
            );
            $this->fail('Erwartete ValidationException im Budgetpfad.');
        } catch (ValidationException $exception) {
            $this->assertSame(422, $exception->status);
            $this->assertArrayHasKey('budget_wish_inventory_ids', $exception->errors());
            $message = implode(' ', $exception->errors()['budget_wish_inventory_ids']);
            $this->assertStringContainsString($messageFragment, $message);
            $this->assertArrayNotHasKey('positions', $exception->errors());
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function budgetPayload(int $inventoryId, string $budget, ?int $secondInventoryId = null): array
    {
        $wishIds = [$inventoryId];
        if ($secondInventoryId !== null) {
            $wishIds[] = $secondInventoryId;
        }

        return [
            'planning_mode' => PlanningMode::Budget->value,
            'target_budget_nn' => $budget,
            'budget_strategy' => BudgetStrategy::EqualSpotCount->value,
            'budget_wish_inventory_ids' => $wishIds,
            'budget_spot_length_seconds' => 30,
            'budget_distribution_ranges' => [
                [
                    'start_hour' => 6,
                    'end_hour_exclusive' => 18,
                    'day_group' => DayGroup::MoFr->value,
                ],
            ],
            'order_discount_percent' => '0',
            'order_discounts' => [],
            'ae_enabled' => false,
            'positions' => [],
        ];
    }
}
