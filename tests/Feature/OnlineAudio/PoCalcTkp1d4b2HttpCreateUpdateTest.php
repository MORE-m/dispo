<?php

namespace Tests\Feature\OnlineAudio;

use App\Enums\DayGroup;
use App\Enums\EngineCapabilityStatus;
use App\Enums\OaTargetingCategory;
use App\Enums\PriceListStatus;
use App\Enums\Role;
use App\Models\AdvertisingMedium;
use App\Models\Calculation;
use App\Models\CalculationPosition;
use App\Models\CalculationPositionOaPlatformAllocation;
use App\Models\Inventory;
use App\Models\InventoryMediumRule;
use App\Models\OaPlatform;
use App\Models\OaTargetingDefinition;
use App\Models\OaTkpPriceList;
use App\Models\OaTkpPriceListItem;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\User;
use App\Services\Calculation\CalculationWriter;
use App\Services\Calculation\Decimal;
use App\Services\OnlineAudio\Import\OaTkpImportDecimal;
use App\Services\StandardOffer\StandardOfferSpotClassicBoundary;
use App\Support\Calculation\EngineProfileRegistry;
use App\Support\Calculation\OaTkpHttpReleaseGuard;
use App\Support\Calculation\OaTkpWizardCapability;
use App\Support\DispoOrder\DispoPositionMethodClassifier;
use App\Support\PriceList\PriceListCalendar;
use Database\Seeders\InitialCatalogMatCoreSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * PO-CALC-TKP-1D4b-2: Positive HTTP Create/Update für OA-TKP über reguläre Controller.
 *
 * Release nur isoliert per D4b-1-Fake. Produktive Registry bleibt planned.
 */
class PoCalcTkp1d4b2HttpCreateUpdateTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        OaTkpWizardCapability::clearHttpMutationFakeForTests();
        parent::tearDown();
    }

    #[Test]
    public function registry_remains_planned_and_http_store_stays_closed_without_fake(): void
    {
        $this->assertSame(
            EngineCapabilityStatus::Planned,
            EngineProfileRegistry::pairStatus('oa_tkp', 'tkp'),
        );
        $this->assertNull(EngineProfileRegistry::currentReleasedVersion('oa_tkp', 'tkp'));
        $this->assertSame(
            EngineCapabilityStatus::Implemented,
            EngineProfileRegistry::statusForVersion('oa_tkp', 'tkp', 'v1'),
        );
        $this->assertFalse(OaTkpWizardCapability::allowsHttpMutation());

        $ctx = $this->seedContext();
        $response = $this->actingAs($ctx['user'])->postJson(
            route('calculations.store'),
            $this->oaStorePayload($ctx),
        );
        $response->assertStatus(422);
        $this->assertSame(
            OaTkpHttpReleaseGuard::MESSAGE,
            $response->json('errors.positions.0') ?? $response->json('message'),
        );
        $this->assertSame(0, Calculation::query()->count());
    }

    #[Test]
    public function http_create_persists_valid_oa_tkp_position_under_simulated_release(): void
    {
        $ctx = $this->seedContext();
        $this->simulateRelease();

        $response = $this->actingAs($ctx['user'])->post(
            route('calculations.store'),
            $this->oaStorePayload($ctx, [
                'basis_tkp' => '10.0000',
                'oa_targeting_selections' => [['targeting_code' => 'fixture_tgt_d4b2']],
            ]),
        );

        $calculation = Calculation::query()->firstOrFail();
        $response->assertRedirect(route('calculations.edit', $calculation));

        $position = $calculation->positions()->firstOrFail();
        $this->assertSame('oa_tkp', $position->engine_profile_key);
        $this->assertSame('tkp', $position->calculation_method_key);
        $this->assertSame('v1', $position->algorithm_version);
        $this->assertSame(
            DispoPositionMethodClassifier::KIND_OA_TKP,
            DispoPositionMethodClassifier::classify(
                (string) $position->engine_profile_key,
                (string) $position->calculation_method_key,
            ),
        );
        $this->assertSame(100_000, (int) $position->impressions);
        $this->assertSame(0, (int) $position->total_spot_count);
        $this->assertSame(0, (int) $position->length_seconds);
        $this->assertNull($position->price_list_id);
        $this->assertSame(0, OaTkpImportDecimal::compare((string) $position->basis_tkp, '10.0000'));
        $this->assertSame(0, OaTkpImportDecimal::compare((string) $position->list_tkp_snapshot, '12.0000'));
        $this->assertSame(0, OaTkpImportDecimal::compare((string) $position->minimum_tkp_snapshot, '8.0000'));
        $this->assertSame(0, OaTkpImportDecimal::compare((string) $position->targeting_surcharge_total, '2.0000'));
        $this->assertSame(0, OaTkpImportDecimal::compare((string) $position->final_tkp, '12.0000'));
        $this->assertSame('1200.00', (string) $position->media_gross);
        $this->assertSame((int) $ctx['list']->id, (int) $position->oa_tkp_price_list_id);
        $this->assertSame(PriceListCalendar::currentYear(), (int) $position->oa_tkp_price_list_year);
        $this->assertSame(1, (int) $position->oa_tkp_price_list_revision);
        $this->assertSame($ctx['list']->version, (string) $position->oa_tkp_price_list_version);
        $this->assertCount(1, $position->oaPlatformAllocations);
        $this->assertSame(100_000, (int) $position->oaPlatformAllocations->firstOrFail()->impressions);
        $this->assertSame('fixture_plat_d4b2', $position->oaPlatformAllocations->firstOrFail()->platform_code);
        $this->assertCount(1, $position->oaTargetingSelections);
        $this->assertSame(0, OaTkpImportDecimal::compare(
            (string) $position->oaTargetingSelections->firstOrFail()->tkp_surcharge_snapshot,
            '2.0000',
        ));
        $this->assertSame(0, $position->productionLines()->count());
        $this->assertSame('1200.00', (string) $calculation->media_gross);
    }

    #[Test]
    public function http_create_ignores_client_price_and_name_overrides(): void
    {
        $ctx = $this->seedContext();
        $this->simulateRelease();

        $payload = $this->oaStorePayload($ctx, [
            'list_tkp' => '99.0000',
            'minimum_tkp' => '1.0000',
            'final_tkp' => '1.0000',
            'list_tkp_snapshot' => '99.0000',
            'oa_targeting_selections' => [[
                'targeting_code' => 'fixture_tgt_d4b2',
                'tkp_surcharge' => '0.0000',
                'targeting_name' => 'Hacked',
            ]],
            'oa_platform_allocations' => [[
                'oa_platform_id' => $ctx['platform']->id,
                'platform_name' => 'Hacked',
                'impressions' => 100_000,
            ]],
        ]);

        $this->actingAs($ctx['user'])->post(route('calculations.store'), $payload)
            ->assertRedirect();

        $position = Calculation::query()->firstOrFail()->positions()->firstOrFail();
        $this->assertSame(0, OaTkpImportDecimal::compare((string) $position->list_tkp_snapshot, '12.0000'));
        $this->assertSame(0, OaTkpImportDecimal::compare((string) $position->minimum_tkp_snapshot, '8.0000'));
        $this->assertSame(0, OaTkpImportDecimal::compare((string) $position->final_tkp, '12.0000'));
        $this->assertSame('Fixture Platform D4b2', $position->oaPlatformAllocations->firstOrFail()->platform_name);
        $this->assertSame('Fixture Targeting D4b2', $position->oaTargetingSelections->firstOrFail()->targeting_name);
        $this->assertSame(0, OaTkpImportDecimal::compare(
            (string) $position->oaTargetingSelections->firstOrFail()->tkp_surcharge_snapshot,
            '2.0000',
        ));
    }

    #[Test]
    public function http_create_rejects_prohibited_engine_profile_key(): void
    {
        $ctx = $this->seedContext();
        $this->simulateRelease();

        $payload = $this->oaStorePayload($ctx);
        $payload['positions'][0]['engine_profile_key'] = 'spot_classic';

        $this->actingAs($ctx['user'])->postJson(route('calculations.store'), $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['positions.0.engine_profile_key']);
        $this->assertSame(0, Calculation::query()->count());
    }

    #[Test]
    public function http_update_reprices_and_refreshes_snapshots_under_valid_lock(): void
    {
        $ctx = $this->seedContext();
        $this->simulateRelease();
        $platformB = OaPlatform::query()->create([
            'code' => 'fixture_plat_d4b2_b',
            'name' => 'Fixture Platform D4b2 B',
            'is_active' => true,
            'sort' => 2,
            'is_adserver_relevant' => false,
        ]);

        $this->actingAs($ctx['user'])->post(route('calculations.store'), $this->oaStorePayload($ctx));
        $calculation = Calculation::query()->firstOrFail();
        $pinnedListId = (int) $calculation->positions->firstOrFail()->oa_tkp_price_list_id;

        $payload = $this->httpUpdatePayload($calculation);
        $payload['positions'][0]['basis_tkp'] = '11.0000';
        $payload['positions'][0]['impressions'] = 200_000;
        $payload['positions'][0]['oa_platform_allocations'] = [
            ['oa_platform_id' => $ctx['platform']->id, 'impressions' => 120_000],
            ['oa_platform_id' => $platformB->id, 'impressions' => 80_000],
        ];
        $payload['positions'][0]['oa_targeting_selections'] = [
            ['oa_targeting_definition_id' => $ctx['targeting']->id],
        ];

        $this->actingAs($ctx['user'])
            ->putJson(route('calculations.update', $calculation), $payload)
            ->assertRedirect(route('calculations.edit', $calculation));

        $fresh = $calculation->fresh(['positions.oaPlatformAllocations', 'positions.oaTargetingSelections']);
        $position = $fresh->positions->firstOrFail();
        $this->assertSame(2, (int) $fresh->lock_version);
        $this->assertSame($pinnedListId, (int) $position->oa_tkp_price_list_id);
        $this->assertSame(200_000, (int) $position->impressions);
        $this->assertSame(0, OaTkpImportDecimal::compare((string) $position->basis_tkp, '11.0000'));
        $this->assertSame(0, OaTkpImportDecimal::compare((string) $position->final_tkp, '13.0000'));
        $this->assertSame('2600.00', (string) $position->media_gross);
        $this->assertCount(2, $position->oaPlatformAllocations);
        $this->assertCount(1, $position->oaTargetingSelections);
        $this->assertSame(0, $position->productionLines()->count());
    }

    #[Test]
    public function http_update_stale_lock_version_rejects_without_persist(): void
    {
        $ctx = $this->seedContext();
        $this->simulateRelease();
        $this->actingAs($ctx['user'])->post(route('calculations.store'), $this->oaStorePayload($ctx));
        $calculation = Calculation::query()->firstOrFail();
        $nnBefore = (string) $calculation->nn_invest;

        $payload = $this->httpUpdatePayload($calculation);
        $payload['lock_version'] = ((int) $calculation->lock_version) + 9;
        $payload['positions'][0]['basis_tkp'] = '15.0000';

        $this->actingAs($ctx['user'])
            ->putJson(route('calculations.update', $calculation), $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['lock_version']);

        $fresh = $calculation->fresh();
        $this->assertSame(1, (int) $fresh->lock_version);
        $this->assertSame($nnBefore, (string) $fresh->nn_invest);
        $this->assertSame(0, OaTkpImportDecimal::compare(
            (string) $fresh->positions->firstOrFail()->basis_tkp,
            '10.0000',
        ));
    }

    #[Test]
    public function http_update_stale_expected_price_list_token_on_rebind_returns_409(): void
    {
        $ctx = $this->seedContext();
        $this->simulateRelease();
        $this->actingAs($ctx['user'])->post(route('calculations.store'), $this->oaStorePayload($ctx));
        $calculation = Calculation::query()->firstOrFail();
        $nnBefore = (string) $calculation->nn_invest;

        $nextYear = PriceListCalendar::currentYear() + 1;
        $nextList = $this->createActivePriceList(
            $nextYear,
            1,
            (int) $ctx['medium']->id,
            (int) $ctx['inventory']->id,
            '14.0000',
            '9.0000',
        );

        $payload = $this->httpUpdatePayload($calculation);
        $payload['positions'][0]['price_year'] = $nextYear;
        // Pin bricht durch Jahrwechsel → Token Pflicht; falsches Token → 409.
        $payload['positions'][0]['expected_oa_tkp_price_list_id'] = ((int) $ctx['list']->id);

        $this->actingAs($ctx['user'])
            ->putJson(route('calculations.update', $calculation), $payload)
            ->assertStatus(409);

        $this->assertSame($nnBefore, (string) $calculation->fresh()->nn_invest);
        $this->assertSame(1, (int) $calculation->fresh()->lock_version);
        $this->assertSame(
            (int) $ctx['list']->id,
            (int) $calculation->fresh()->positions->firstOrFail()->oa_tkp_price_list_id,
        );
        $this->assertNotSame((int) $nextList->id, (int) $calculation->fresh()->positions->firstOrFail()->oa_tkp_price_list_id);
    }

    #[Test]
    public function http_update_invalid_allocation_rolls_back(): void
    {
        $ctx = $this->seedContext();
        $this->simulateRelease();
        $this->actingAs($ctx['user'])->post(route('calculations.store'), $this->oaStorePayload($ctx));
        $calculation = Calculation::query()->firstOrFail();
        $allocBefore = CalculationPositionOaPlatformAllocation::query()->count();

        $payload = $this->httpUpdatePayload($calculation);
        $payload['positions'][0]['impressions'] = 100_000;
        $payload['positions'][0]['oa_platform_allocations'] = [
            ['oa_platform_id' => $ctx['platform']->id, 'impressions' => 40_000],
        ];

        $this->actingAs($ctx['user'])
            ->putJson(route('calculations.update', $calculation), $payload)
            ->assertStatus(422);

        $this->assertSame(1, (int) $calculation->fresh()->lock_version);
        $this->assertSame($allocBefore, CalculationPositionOaPlatformAllocation::query()->count());
        $this->assertSame(100_000, (int) $calculation->fresh()->positions->firstOrFail()->impressions);
    }

    #[Test]
    public function method_switch_spot_to_oa_and_oa_to_spot_remain_forbidden_on_existing_position(): void
    {
        $ctx = $this->seedContext();
        $this->simulateRelease();
        $spot = $this->createSpotFixture();

        $this->actingAs($ctx['user'])->post(
            route('calculations.store'),
            $this->withLiveSchemaFingerprint([
                'planning_mode' => 'manual',
                'order_discount_percent' => '0',
                'ae_enabled' => false,
                'positions' => [$this->spotPosition($spot)],
            ]),
        );
        $spotCalc = Calculation::query()->latest('id')->firstOrFail();
        $spotPayload = $this->httpUpdatePayload($spotCalc);
        $spotPayload['positions'][0]['calculation_method_key'] = 'tkp';
        $spotPayload['positions'][0]['impressions'] = 100_000;
        $spotPayload['positions'][0]['basis_tkp'] = '10.0000';
        $spotPayload['positions'][0]['expected_oa_tkp_price_list_id'] = $ctx['list']->id;
        $spotPayload['positions'][0]['price_year'] = PriceListCalendar::currentYear();
        $spotPayload['positions'][0]['oa_platform_allocations'] = [
            ['oa_platform_id' => $ctx['platform']->id, 'impressions' => 100_000],
        ];
        unset(
            $spotPayload['positions'][0]['spot_method'],
            $spotPayload['positions'][0]['length_seconds'],
            $spotPayload['positions'][0]['total_spot_count'],
            $spotPayload['positions'][0]['plan_rows'],
        );

        $spotSwitch = $this->actingAs($ctx['user'])
            ->putJson(route('calculations.update', $spotCalc), $spotPayload);
        $spotSwitch->assertStatus(422);
        $this->assertStringContainsString(
            'nicht auf TKP umgestellt',
            (string) json_encode($spotSwitch->json('errors')),
        );
        $this->assertSame('spot_classic', $spotCalc->fresh()->positions->firstOrFail()->engine_profile_key);

        $this->actingAs($ctx['user'])->post(route('calculations.store'), $this->oaStorePayload($ctx));
        $oaCalc = Calculation::query()->latest('id')->firstOrFail();
        $oaPayload = $this->httpUpdatePayload($oaCalc);
        $oaPayload['positions'][0]['calculation_method_key'] = 'average';
        $oaPayload['positions'][0]['spot_method'] = 'average';
        $oaPayload['positions'][0]['length_seconds'] = 30;
        $oaPayload['positions'][0]['total_spot_count'] = 10;
        $oaPayload['positions'][0]['plan_rows'] = [['hour' => 8, 'day_group' => 'mo_fr']];
        unset(
            $oaPayload['positions'][0]['impressions'],
            $oaPayload['positions'][0]['basis_tkp'],
            $oaPayload['positions'][0]['oa_platform_allocations'],
            $oaPayload['positions'][0]['oa_targeting_selections'],
            $oaPayload['positions'][0]['expected_oa_tkp_price_list_id'],
        );

        $response = $this->actingAs($ctx['user'])
            ->putJson(route('calculations.update', $oaCalc), $oaPayload);
        $response->assertStatus(422);
        $this->assertStringContainsString(
            'andere Berechnungsmethode',
            (string) json_encode($response->json('errors')),
        );
        $this->assertSame('oa_tkp', $oaCalc->fresh()->positions->firstOrFail()->engine_profile_key);
    }

    #[Test]
    public function http_mixed_spot_and_oa_persists_both_methods_and_sums(): void
    {
        $ctx = $this->seedContext();
        $this->simulateRelease();
        $spot = $this->createSpotFixture();

        $this->actingAs($ctx['user'])->post(
            route('calculations.store'),
            $this->withLiveSchemaFingerprint([
                'planning_mode' => 'manual',
                'order_discount_percent' => '0',
                'ae_enabled' => false,
                'positions' => [
                    $this->spotPosition($spot),
                    $this->oaPosition($ctx),
                ],
            ]),
        )->assertRedirect();

        $calculation = Calculation::query()->firstOrFail()->load([
            'positions.oaPlatformAllocations',
            'positions.productionLines',
        ]);
        $this->assertCount(2, $calculation->positions);
        $spotPos = $calculation->positions->first(
            fn (CalculationPosition $p): bool => $p->engine_profile_key === 'spot_classic',
        );
        $oaPos = $calculation->positions->first(
            fn (CalculationPosition $p): bool => $p->engine_profile_key === 'oa_tkp',
        );
        $this->assertNotNull($spotPos);
        $this->assertNotNull($oaPos);
        $this->assertSame(10, (int) $spotPos->total_spot_count);
        $this->assertNull($spotPos->impressions);
        $this->assertCount(0, $spotPos->oaPlatformAllocations);
        $this->assertSame(100_000, (int) $oaPos->impressions);
        $this->assertSame(0, (int) $oaPos->total_spot_count);
        $this->assertSame(0, $oaPos->productionLines()->count());
        $this->assertCount(1, $oaPos->oaPlatformAllocations);

        $this->assertSame(
            Decimal::roundMoney(Decimal::add((string) $spotPos->media_gross, (string) $oaPos->media_gross)),
            (string) $calculation->media_gross,
        );
        $this->assertSame(
            Decimal::roundMoney(Decimal::add((string) $spotPos->nn_invest, (string) $oaPos->nn_invest)),
            (string) $calculation->nn_invest,
        );
    }

    #[Test]
    public function unauthorized_user_cannot_store_even_with_simulated_release(): void
    {
        $ctx = $this->seedContext();
        $this->simulateRelease();
        $disposition = User::factory()->role(Role::Disposition)->create();

        $this->actingAs($disposition)
            ->postJson(route('calculations.store'), $this->oaStorePayload($ctx))
            ->assertForbidden();
        $this->assertSame(0, Calculation::query()->count());
    }

    #[Test]
    public function missing_active_price_list_and_ambiguous_actives_fail_closed(): void
    {
        $ctx = $this->seedContext();
        $this->simulateRelease();

        $ctx['list']->update(['status' => PriceListStatus::Archived, 'archived_at' => now()]);
        $this->actingAs($ctx['user'])
            ->postJson(route('calculations.store'), $this->oaStorePayload($ctx))
            ->assertStatus(422);
        $this->assertSame(0, Calculation::query()->count());

        // Archivierte Revision 1 belegt Unique(year, revision); Ambiguity über 2+3.
        $this->createActivePriceList(
            PriceListCalendar::currentYear(),
            2,
            (int) $ctx['medium']->id,
            (int) $ctx['inventory']->id,
            '12.0000',
            '8.0000',
        );
        $this->createActivePriceList(
            PriceListCalendar::currentYear(),
            3,
            (int) $ctx['medium']->id,
            (int) $ctx['inventory']->id,
            '13.0000',
            '9.0000',
        );

        $payload = $this->oaStorePayload($ctx);
        unset($payload['positions'][0]['expected_oa_tkp_price_list_id']);
        $response = $this->actingAs($ctx['user'])->postJson(route('calculations.store'), $payload);
        $response->assertStatus(422);
        $this->assertStringContainsString(
            'Mehrdeutige aktive OA-TKP-Preislistenrevisionen',
            (string) json_encode($response->json('errors')),
        );
        $this->assertSame(0, Calculation::query()->count());
    }

    #[Test]
    public function missing_platform_and_invalid_allocation_and_native_ad_are_rejected(): void
    {
        $ctx = $this->seedContext();
        $this->simulateRelease();

        $noPlatform = $this->oaStorePayload($ctx);
        $noPlatform['positions'][0]['oa_platform_allocations'] = [];
        $this->actingAs($ctx['user'])->postJson(route('calculations.store'), $noPlatform)
            ->assertStatus(422);
        $this->assertSame(0, Calculation::query()->count());

        $badSum = $this->oaStorePayload($ctx);
        $badSum['positions'][0]['oa_platform_allocations'] = [
            ['oa_platform_id' => $ctx['platform']->id, 'impressions' => 10],
        ];
        $this->actingAs($ctx['user'])->postJson(route('calculations.store'), $badSum)
            ->assertStatus(422);
        $this->assertSame(0, Calculation::query()->count());

        $native = AdvertisingMedium::query()->where('code', 'native_ad')->firstOrFail();
        $nativePayload = $this->oaStorePayload($ctx);
        $nativePayload['positions'][0]['advertising_medium_id'] = $native->id;
        $nativePayload = $this->withLiveSchemaFingerprint($nativePayload);
        $this->actingAs($ctx['user'])->postJson(route('calculations.store'), $nativePayload)
            ->assertStatus(422);
        $this->assertSame(0, Calculation::query()->count());
    }

    #[Test]
    public function budget_and_standard_offer_tkp_remain_blocked_with_simulated_release(): void
    {
        $ctx = $this->seedContext();
        $this->simulateRelease();

        $budgetPayload = $this->oaStorePayload($ctx);
        $budgetPayload['planning_mode'] = 'budget';
        $budgetPayload['target_budget_nn'] = '1000.00';
        $this->actingAs($ctx['user'])
            ->postJson(route('calculations.budget-propose'), $budgetPayload)
            ->assertStatus(422);

        $this->actingAs($ctx['user'])->post(route('calculations.store'), $this->oaStorePayload($ctx));
        $oaCalc = Calculation::query()->firstOrFail();
        try {
            app(StandardOfferSpotClassicBoundary::class)->assertSourceCalculation($oaCalc);
            $this->fail('Expected Standardangebote TKP rejection');
        } catch (ValidationException $e) {
            $this->assertStringContainsString(
                'nur Spot Classic',
                (string) json_encode($e->errors()),
            );
        }
    }

    #[Test]
    public function fake_reset_restores_planned_http_block_and_does_not_leak(): void
    {
        $ctx = $this->seedContext();
        $this->simulateRelease();
        $this->assertTrue(OaTkpWizardCapability::allowsHttpMutation());

        OaTkpWizardCapability::clearHttpMutationFakeForTests();
        $this->assertFalse(OaTkpWizardCapability::allowsHttpMutation());

        $this->actingAs($ctx['user'])
            ->postJson(route('calculations.store'), $this->oaStorePayload($ctx))
            ->assertStatus(422);
        $this->assertSame(0, Calculation::query()->count());
        $this->assertSame(
            EngineCapabilityStatus::Planned,
            EngineProfileRegistry::pairStatus('oa_tkp', 'tkp'),
        );
    }

    private function simulateRelease(): void
    {
        OaTkpWizardCapability::fakeHttpMutationAllowedForTests(true);
    }

    /**
     * @param  array<string, mixed>  $ctx
     * @param  array<string, mixed>  $override
     * @return array<string, mixed>
     */
    private function oaStorePayload(array $ctx, array $override = []): array
    {
        return $this->withLiveSchemaFingerprint([
            'planning_mode' => 'manual',
            'order_discount_percent' => '0',
            'ae_enabled' => false,
            'positions' => [$this->oaPosition($ctx, $override)],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function httpUpdatePayload(Calculation $calculation): array
    {
        $payload = app(CalculationWriter::class)->payloadFromCalculation($calculation->fresh());
        $payload['lock_version'] = $calculation->lock_version;
        foreach ($payload['positions'] as $index => $position) {
            unset(
                $payload['positions'][$index]['engine_profile_key'],
                $payload['positions'][$index]['calculation_method_name'],
                $payload['positions'][$index]['algorithm_version'],
                $payload['positions'][$index]['list_tkp_snapshot'],
                $payload['positions'][$index]['minimum_tkp_snapshot'],
                $payload['positions'][$index]['final_tkp'],
                $payload['positions'][$index]['targeting_surcharge_total'],
                $payload['positions'][$index]['below_minimum_tkp'],
                $payload['positions'][$index]['price_list_id'],
                $payload['positions'][$index]['price_list_version'],
                $payload['positions'][$index]['length_seconds'],
                $payload['positions'][$index]['total_spot_count'],
                $payload['positions'][$index]['plan_rows'],
                $payload['positions'][$index]['time_ranges'],
                $payload['positions'][$index]['planner_entries'],
            );
            if (empty($payload['positions'][$index]['client_key'])) {
                $payload['positions'][$index]['client_key'] = (string) str()->uuid();
            }
        }

        // Gen-3-Fingerprints aus dem Calc-Snapshot behalten (kein Live-Overwrite → 409).
        $payload['positions'] = $this->withPositionSchemaFingerprints($calculation, $payload['positions']);

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $ctx
     * @param  array<string, mixed>  $override
     * @return array<string, mixed>
     */
    private function oaPosition(array $ctx, array $override = []): array
    {
        return array_merge([
            'client_key' => (string) str()->uuid(),
            'inventory_id' => (int) $ctx['inventory']->id,
            'advertising_medium_id' => (int) $ctx['medium']->id,
            'calculation_method_key' => 'tkp',
            'price_year' => PriceListCalendar::currentYear(),
            'expected_oa_tkp_price_list_id' => (int) $ctx['list']->id,
            'impressions' => 100_000,
            'basis_tkp' => '10.0000',
            'oa_platform_allocations' => [
                ['oa_platform_id' => (int) $ctx['platform']->id, 'impressions' => 100_000],
            ],
            'oa_targeting_selections' => [],
            'position_discount_percent' => '0',
            'ae_percent' => '15',
        ], $override);
    }

    /**
     * @return array{medium: AdvertisingMedium, inventory: Inventory}
     */
    private function createSpotFixture(): array
    {
        $medium = AdvertisingMedium::query()->where('code', 'spot_classic')->firstOrFail();
        $inventory = Inventory::factory()->create(['name' => 'D4b2 Spot', 'code' => 'D4B2SPOT']);
        InventoryMediumRule::factory()->create([
            'inventory_id' => $inventory->id,
            'advertising_medium_id' => $medium->id,
        ]);
        $list = PriceList::factory()->create([
            'inventory_id' => $inventory->id,
            'status' => PriceListStatus::Active,
            'year' => PriceListCalendar::currentYear(),
            'version' => 'D4B2-SPOT',
            'valid_from' => now()->toDateString(),
        ]);
        foreach (range(0, 23) as $hour) {
            foreach ([DayGroup::MoFr, DayGroup::Sa, DayGroup::So] as $group) {
                PriceListItem::factory()->create([
                    'price_list_id' => $list->id,
                    'hour' => $hour,
                    'day_group' => $group,
                    'second_price' => '1.0000',
                ]);
            }
        }

        return compact('medium', 'inventory');
    }

    /**
     * @param  array{medium: AdvertisingMedium, inventory: Inventory}  $spot
     * @return array<string, mixed>
     */
    private function spotPosition(array $spot): array
    {
        return [
            'client_key' => (string) str()->uuid(),
            'inventory_id' => $spot['inventory']->id,
            'advertising_medium_id' => $spot['medium']->id,
            'spot_method' => 'average',
            'length_seconds' => 30,
            'total_spot_count' => 10,
            'position_discount_percent' => '0',
            'ae_percent' => '15',
            'plan_rows' => [['hour' => 8, 'day_group' => 'mo_fr']],
        ];
    }

    /**
     * @return array{
     *     user: User,
     *     medium: AdvertisingMedium,
     *     inventory: Inventory,
     *     list: OaTkpPriceList,
     *     targeting: OaTargetingDefinition,
     *     platform: OaPlatform
     * }
     */
    private function seedContext(): array
    {
        $this->seed(InitialCatalogMatCoreSeeder::class);
        $user = User::factory()->role(Role::Sales)->create();
        $medium = AdvertisingMedium::query()->where('code', 'pre_stream')->firstOrFail();
        $inventory = Inventory::query()->where('code', 'inv_more_kombi_online_audio')->firstOrFail();
        $list = $this->createActivePriceList(
            PriceListCalendar::currentYear(),
            1,
            (int) $medium->id,
            (int) $inventory->id,
            '12.0000',
            '8.0000',
        );
        $targeting = OaTargetingDefinition::query()->create([
            'code' => 'fixture_tgt_d4b2',
            'name' => 'Fixture Targeting D4b2',
            'category' => OaTargetingCategory::Technisch,
            'tkp_surcharge' => '2.0000',
            'revision_number' => 1,
            'is_active' => true,
        ]);
        $platform = OaPlatform::query()->create([
            'code' => 'fixture_plat_d4b2',
            'name' => 'Fixture Platform D4b2',
            'is_active' => true,
            'sort' => 1,
            'is_adserver_relevant' => false,
        ]);

        return compact('user', 'medium', 'inventory', 'list', 'targeting', 'platform');
    }

    private function createActivePriceList(
        int $year,
        int $revision,
        int $mediumId,
        int $inventoryId,
        string $listTkp,
        string $minimumTkp,
    ): OaTkpPriceList {
        $list = OaTkpPriceList::query()->create([
            'year' => $year,
            'name' => "Fixture D4b2 {$year}.{$revision}",
            'version' => "{$year}.{$revision}",
            'revision_number' => $revision,
            'lock_version' => 1,
            'status' => PriceListStatus::Active,
            'published_at' => now(),
        ]);

        OaTkpPriceListItem::query()->create([
            'oa_tkp_price_list_id' => $list->id,
            'advertising_medium_id' => $mediumId,
            'inventory_id' => $inventoryId,
            'list_tkp' => $listTkp,
            'minimum_tkp' => $minimumTkp,
        ]);

        return $list;
    }
}
