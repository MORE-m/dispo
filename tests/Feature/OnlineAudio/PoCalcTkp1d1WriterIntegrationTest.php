<?php

namespace Tests\Feature\OnlineAudio;

use App\Enums\BudgetProposalStatus;
use App\Enums\BudgetStrategy;
use App\Enums\DayGroup;
use App\Enums\OaTargetingCategory;
use App\Enums\PriceListStatus;
use App\Enums\Role;
use App\Enums\SpecialApprovalReasonCode;
use App\Exceptions\PriceListSelectionConflictException;
use App\Models\AdvertisingMedium;
use App\Models\BudgetProposal;
use App\Models\Calculation;
use App\Models\CalculationPosition;
use App\Models\CalculationPositionOaPlatformAllocation;
use App\Models\CalculationPositionOaTargetingSelection;
use App\Models\Inventory;
use App\Models\InventoryMediumRule;
use App\Models\OaPlatform;
use App\Models\OaTargetingDefinition;
use App\Models\OaTkpPriceList;
use App\Models\OaTkpPriceListItem;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\User;
use App\Services\Calculation\BudgetSpotProposalService;
use App\Services\Calculation\CalculationWriter;
use App\Services\Calculation\OaTkp\OaTkpWriterPositionResolver;
use App\Services\OnlineAudio\Import\OaTkpImportDecimal;
use App\Support\Calculation\EngineProfileRegistry;
use App\Support\PriceList\PriceListCalendar;
use Database\Seeders\InitialCatalogMatCoreSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * PO-CALC-TKP-1D1: Normal-TKP im CalculationWriter (Registry bleibt planned, HTTP fail-closed).
 */
class PoCalcTkp1d1WriterIntegrationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function registry_stays_planned_after_writer_integration(): void
    {
        $this->assertSame(
            'planned',
            EngineProfileRegistry::pairStatus(EngineProfileRegistry::PROFILE_OA_TKP, 'tkp')->value,
        );
        $this->assertNull(
            EngineProfileRegistry::currentReleasedVersion(EngineProfileRegistry::PROFILE_OA_TKP, 'tkp'),
        );
    }

    #[Test]
    public function writer_create_oa_only_resolves_prices_server_side_and_reloads(): void
    {
        $ctx = $this->seedContext();
        $writer = app(CalculationWriter::class);

        $calculation = $writer->create($this->payload([
            $this->oaPosition($ctx, [
                'oa_targeting_selections' => [['targeting_code' => 'fixture_tgt']],
            ]),
        ]), $ctx['user']);

        /** @var CalculationPosition $position */
        $position = $calculation->positions->firstOrFail();

        $this->assertSame('oa_tkp', $position->engine_profile_key);
        $this->assertSame('tkp', $position->calculation_method_key);
        $this->assertSame('v1', $position->algorithm_version);
        $this->assertNull($position->price_list_id);
        $this->assertSame(0, (int) $position->length_seconds);
        $this->assertSame(0, (int) $position->total_spot_count);
        $this->assertSame(100_000, (int) $position->impressions);
        $this->assertSame(0, OaTkpImportDecimal::compare((string) $position->list_tkp_snapshot, '12.0000'));
        $this->assertSame(0, OaTkpImportDecimal::compare((string) $position->minimum_tkp_snapshot, '8.0000'));
        $this->assertSame(0, OaTkpImportDecimal::compare((string) $position->final_tkp, '12.0000'));
        $this->assertSame('1200.00', (string) $position->media_gross);
        $this->assertSame((int) $ctx['list']->id, (int) $position->oa_tkp_price_list_id);
        $this->assertFalse((bool) $position->below_minimum_tkp);
        $this->assertFalse((bool) $calculation->requires_special_approval);
        $this->assertSame('1200.00', (string) $calculation->media_gross);
        $this->assertSame('1200.00', (string) $calculation->nn_invest);
        $this->assertNotNull($position->effective_configuration_snapshot_id);

        $this->assertCount(1, $position->oaPlatformAllocations);
        $allocation = $position->oaPlatformAllocations->first();
        $this->assertSame('Fixture Platform A', $allocation->platform_name);
        $this->assertSame('fixture_plat_a', $allocation->platform_code);
        $this->assertCount(1, $position->oaTargetingSelections);
        $selection = $position->oaTargetingSelections->first();
        $this->assertSame((int) $ctx['targeting']->id, (int) $selection->oa_targeting_definition_id);
        $this->assertSame(0, OaTkpImportDecimal::compare((string) $selection->tkp_surcharge_snapshot, '2.0000'));

        $reloaded = $writer->payloadFromCalculation(Calculation::query()->findOrFail($calculation->id));
        $reloadedPosition = $reloaded['positions'][0];
        $this->assertSame('tkp', $reloadedPosition['calculation_method_key']);
        $this->assertSame('oa_tkp', $reloadedPosition['engine_profile_key']);
        $this->assertSame(PriceListCalendar::currentYear(), $reloadedPosition['price_year']);
        $this->assertSame((int) $ctx['list']->id, $reloadedPosition['expected_oa_tkp_price_list_id']);
        $this->assertSame(100_000, $reloadedPosition['impressions']);
        $this->assertSame('10.0000', $reloadedPosition['basis_tkp']);
        $this->assertSame('12.0000', $reloadedPosition['list_tkp_snapshot']);
        $this->assertSame('8.0000', $reloadedPosition['minimum_tkp_snapshot']);
        $this->assertSame('12.0000', $reloadedPosition['final_tkp']);
        $this->assertSame((int) $ctx['platform']->id, $reloadedPosition['oa_platform_allocations'][0]['oa_platform_id']);
        $this->assertSame('fixture_plat_a', $reloadedPosition['oa_platform_allocations'][0]['platform_code']);
        $this->assertSame('Fixture Platform A', $reloadedPosition['oa_platform_allocations'][0]['platform_name']);
        $this->assertSame(100_000, $reloadedPosition['oa_platform_allocations'][0]['impressions']);
        $this->assertSame((int) $ctx['targeting']->id, $reloadedPosition['oa_targeting_selections'][0]['oa_targeting_definition_id']);
        $this->assertSame('fixture_tgt', $reloadedPosition['oa_targeting_selections'][0]['targeting_code']);
        $this->assertSame('Fixture Targeting', $reloadedPosition['oa_targeting_selections'][0]['targeting_name']);
        $this->assertSame('2.0000', $reloadedPosition['oa_targeting_selections'][0]['tkp_surcharge_snapshot']);

        // Reload-Roundtrip ohne Änderung: gleiche Beträge (Header-only-Pfad bleibt Pin).
        $reloaded['lock_version'] = $calculation->lock_version;
        $updated = $writer->update($calculation, $reloaded, $ctx['user']);
        $this->assertSame('1200.00', (string) $updated->media_gross);
        $this->assertSame('1200.00', (string) $updated->positions->firstOrFail()->media_gross);
    }

    #[Test]
    public function under_min_basis_sets_special_approval_after_persist_and_in_preview(): void
    {
        $ctx = $this->seedContext();
        $writer = app(CalculationWriter::class);
        $payload = $this->payload([
            $this->oaPosition($ctx, ['basis_tkp' => '7.9999']),
        ]);

        $preview = $writer->preview($payload, $ctx['user']);
        $this->assertTrue($preview->requiresSpecialApproval);
        $this->assertSame(
            SpecialApprovalReasonCode::OaTkpBasisBelowMinimum->value,
            $preview->specialApprovalReasons[0]['code'],
        );

        $calculation = $writer->create($payload, $ctx['user']);
        $this->assertTrue((bool) $calculation->requires_special_approval);
        $reasons = $calculation->special_approval_reasons;
        $this->assertIsArray($reasons);
        $this->assertCount(1, $reasons);
        $this->assertSame(SpecialApprovalReasonCode::OaTkpBasisBelowMinimum->value, $reasons[0]['code']);
        $this->assertSame('7.9999', $reasons[0]['basis_tkp']);
        $this->assertSame('8.0000', $reasons[0]['minimum_tkp']);
        $this->assertSame((int) $calculation->positions->firstOrFail()->id, $reasons[0]['position_id']);
        $this->assertTrue((bool) $calculation->positions->firstOrFail()->below_minimum_tkp);

        // Basis = Minimum ist keine Unterschreitung.
        $ok = $writer->create($this->payload([
            $this->oaPosition($ctx, ['basis_tkp' => '8.0000']),
        ]), $ctx['user']);
        $this->assertFalse((bool) $ok->requires_special_approval);
    }

    #[Test]
    public function multi_oa_positions_sum_totals(): void
    {
        $ctx = $this->seedContext();
        $writer = app(CalculationWriter::class);

        $calculation = $writer->create($this->payload([
            $this->oaPosition($ctx, ['basis_tkp' => '10.0000', 'impressions' => 100_000]),
            $this->oaPosition($ctx, [
                'basis_tkp' => '9.0000',
                'impressions' => 50_000,
                'oa_platform_allocations' => [['platform_code' => 'fixture_plat_a', 'impressions' => 50_000]],
            ]),
        ], ['order_discount_percent' => '10']), $ctx['user']);

        $this->assertCount(2, $calculation->positions);
        $gross = '0.00';
        $nn = '0.00';
        foreach ($calculation->positions as $position) {
            $gross = bcadd($gross, (string) $position->media_gross, 2);
            $nn = bcadd($nn, (string) $position->nn_invest, 2);
        }
        $this->assertSame('1450.00', $gross); // 1000.00 + 450.00
        $this->assertSame($gross, (string) $calculation->media_gross);
        $this->assertSame($nn, (string) $calculation->nn_invest);
        $this->assertSame('145.00', (string) $calculation->order_discount_total);
        $this->assertSame('1305.00', (string) $calculation->nn_invest);
    }

    #[Test]
    public function mixed_spot_average_and_oa_tkp_positions(): void
    {
        $ctx = $this->seedContext();
        $writer = app(CalculationWriter::class);
        $spot = $this->spotPosition($this->createSpotFixture());

        $calculation = $writer->create($this->payload([
            $spot,
            $this->oaPosition($ctx),
        ], ['target_budget_nn' => '5000']), $ctx['user']);

        $this->assertCount(2, $calculation->positions);
        $spotPosition = $calculation->positions->first(fn ($p): bool => $p->engine_profile_key === 'spot_classic');
        $oaPosition = $calculation->positions->first(fn ($p): bool => $p->engine_profile_key === 'oa_tkp');
        $this->assertNotNull($spotPosition);
        $this->assertNotNull($oaPosition);
        $this->assertNotNull($spotPosition->price_list_id);
        $this->assertSame(10, (int) $spotPosition->total_spot_count);
        $this->assertCount(0, $spotPosition->oaPlatformAllocations);
        $this->assertSame('1000.00', (string) $oaPosition->media_gross);
        $this->assertCount(1, $oaPosition->oaPlatformAllocations);

        $expectedGross = bcadd((string) $spotPosition->media_gross, (string) $oaPosition->media_gross, 2);
        $this->assertSame($expectedGross, (string) $calculation->media_gross);
        $expectedNn = bcadd((string) $spotPosition->nn_invest, (string) $oaPosition->nn_invest, 2);
        $this->assertSame($expectedNn, (string) $calculation->nn_invest);

        // Zielbudget wird über Spot + OA gebildet.
        $totals = $writer->preview($this->payload([$spot, $this->oaPosition($ctx)], ['target_budget_nn' => '5000']), $ctx['user']);
        $this->assertSame('5000.00', $totals->targetBudgetNn);
        $this->assertSame(bcsub('5000.00', $totals->nnInvest, 2), $totals->budgetDelta);
        $this->assertCount(2, $totals->positions);
        $this->assertSame(0, $totals->positions[1]->spotCount);
    }

    #[Test]
    public function update_changes_values_and_children_and_pins_until_year_rebind(): void
    {
        $ctx = $this->seedContext();
        $writer = app(CalculationWriter::class);
        $platformB = OaPlatform::query()->create([
            'code' => 'fixture_plat_b',
            'name' => 'Fixture Platform B',
            'is_active' => true,
            'sort' => 2,
            'is_adserver_relevant' => false,
        ]);

        $calculation = $writer->create($this->payload([$this->oaPosition($ctx)]), $ctx['user']);
        $position = $calculation->positions->firstOrFail();
        $pinnedListId = (int) $position->oa_tkp_price_list_id;

        // Neue aktive Revision im selben Jahr (andere Preise) — Pin muss bleiben.
        $ctx['list']->update(['status' => PriceListStatus::Archived, 'archived_at' => now()]);
        $revision2 = $this->createActivePriceList(
            PriceListCalendar::currentYear(),
            2,
            (int) $ctx['medium']->id,
            (int) $ctx['inventory']->id,
            '20.0000',
            '15.0000',
        );

        $payload = $writer->payloadFromCalculation($calculation->fresh());
        $payload['lock_version'] = $calculation->lock_version;
        $payload['positions'][0]['basis_tkp'] = '11.0000';
        $payload['positions'][0]['impressions'] = 200_000;
        $payload['positions'][0]['oa_platform_allocations'] = [
            ['oa_platform_id' => $ctx['platform']->id, 'impressions' => 120_000],
            ['platform_code' => 'fixture_plat_b', 'impressions' => 80_000],
        ];
        $payload['positions'][0]['oa_targeting_selections'] = [['oa_targeting_definition_id' => $ctx['targeting']->id]];

        $updated = $writer->update($calculation->fresh(), $payload, $ctx['user']);
        $position = $updated->positions->firstOrFail();

        $this->assertSame($pinnedListId, (int) $position->oa_tkp_price_list_id);
        $this->assertNotSame((int) $revision2->id, (int) $position->oa_tkp_price_list_id);
        $this->assertSame(0, OaTkpImportDecimal::compare((string) $position->list_tkp_snapshot, '12.0000'));
        $this->assertSame(0, OaTkpImportDecimal::compare((string) $position->minimum_tkp_snapshot, '8.0000'));
        $this->assertSame(200_000, (int) $position->impressions);
        $this->assertSame(0, OaTkpImportDecimal::compare((string) $position->basis_tkp, '11.0000'));
        $this->assertSame(0, OaTkpImportDecimal::compare((string) $position->final_tkp, '13.0000'));
        $this->assertSame('2600.00', (string) $position->media_gross);
        $this->assertCount(2, $position->oaPlatformAllocations);
        $this->assertCount(1, $position->oaTargetingSelections);
        $this->assertSame(2, CalculationPositionOaPlatformAllocation::query()->count());
        $this->assertSame(1, CalculationPositionOaTargetingSelection::query()->count());
        $this->assertSame((int) $platformB->id, (int) $position->oaPlatformAllocations->last()->oa_platform_id);

        // Targeting entfernen → Child-Zeile weg.
        $payload = $writer->payloadFromCalculation($updated->fresh());
        $payload['lock_version'] = $updated->lock_version;
        $payload['positions'][0]['oa_targeting_selections'] = [];
        $updated = $writer->update($updated->fresh(), $payload, $ctx['user']);
        $this->assertCount(0, $updated->positions->firstOrFail()->oaTargetingSelections);
        $this->assertSame(0, CalculationPositionOaTargetingSelection::query()->count());
        $this->assertSame('2200.00', (string) $updated->positions->firstOrFail()->media_gross);

        // Resolver-Unit: unverändert → Pin, anderes Jahr → Rebind.
        $existing = $updated->positions->firstOrFail();
        $resolver = app(OaTkpWriterPositionResolver::class);
        $pinned = $resolver->resolve(
            $writer->payloadFromCalculation($updated->fresh())['positions'][0],
            $existing,
            0,
        );
        $this->assertFalse($pinned['rebound']);
        $this->assertSame($pinnedListId, $pinned['oa_input']->oaTkpPriceListId);

        // Rebind auf Folgejahr (nur mit aktiver OA-Liste).
        $nextYear = PriceListCalendar::currentYear() + 1;
        $payload = $writer->payloadFromCalculation($updated->fresh());
        $payload['lock_version'] = $updated->lock_version;
        $payload['positions'][0]['price_year'] = $nextYear;
        try {
            $writer->update($updated->fresh(), $payload, $ctx['user']);
            $this->fail('Erwartete ValidationException ohne aktive Folgejahr-Liste.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('Keine aktive OA-TKP-Preislistenrevision', collect($exception->errors())->flatten()->implode(' '));
        }

        $nextList = $this->createActivePriceList(
            $nextYear,
            1,
            (int) $ctx['medium']->id,
            (int) $ctx['inventory']->id,
            '14.0000',
            '9.0000',
        );

        $payload['positions'][0]['expected_oa_tkp_price_list_id'] = $revision2->id; // falsches Token
        try {
            $writer->update($updated->fresh(), $payload, $ctx['user']);
            $this->fail('Erwartete PriceListSelectionConflictException.');
        } catch (PriceListSelectionConflictException) {
            // erwartet (409)
        }

        $payload['positions'][0]['expected_oa_tkp_price_list_id'] = $nextList->id;
        $rebound = $writer->update($updated->fresh(), $payload, $ctx['user']);
        $reboundPosition = $rebound->positions->firstOrFail();
        $this->assertSame((int) $nextList->id, (int) $reboundPosition->oa_tkp_price_list_id);
        $this->assertSame($nextYear, (int) $reboundPosition->oa_tkp_price_list_year);
        $this->assertSame(0, OaTkpImportDecimal::compare((string) $reboundPosition->list_tkp_snapshot, '14.0000'));
        $this->assertSame(0, OaTkpImportDecimal::compare((string) $reboundPosition->minimum_tkp_snapshot, '9.0000'));

        // Nicht wählbares Jahr wird abgelehnt.
        $payload = $writer->payloadFromCalculation($rebound->fresh());
        $payload['lock_version'] = $rebound->lock_version;
        $payload['positions'][0]['price_year'] = PriceListCalendar::currentYear() + 2;
        $this->expectException(ValidationException::class);
        $writer->update($rebound->fresh(), $payload, $ctx['user']);
    }

    #[Test]
    public function pinned_targeting_snapshot_survives_master_data_change_on_header_update(): void
    {
        $ctx = $this->seedContext();
        $writer = app(CalculationWriter::class);
        $calculation = $writer->create($this->payload([
            $this->oaPosition($ctx, ['oa_targeting_selections' => [['targeting_code' => 'fixture_tgt']]]),
        ]), $ctx['user']);

        $ctx['targeting']->update(['tkp_surcharge' => '5.0000']);
        OaPlatform::query()->whereKey($ctx['platform']->id)->update(['name' => 'Renamed', 'is_active' => false]);

        $payload = $writer->payloadFromCalculation($calculation->fresh());
        $payload['lock_version'] = $calculation->lock_version;
        $payload['order_discounts'] = [['type' => 'quantity', 'percent' => '10']];
        $updated = $writer->update($calculation->fresh(), $payload, $ctx['user']);

        $position = $updated->positions->firstOrFail();
        // Header-only-Pfad: Kalkulationssummen aus gepinnten Snapshots (Aufschlag 2.0000, nicht 5.0000).
        $this->assertSame('1200.00', (string) $updated->media_gross);
        $this->assertSame('1080.00', (string) $updated->nn_invest);
        $this->assertSame('1200.00', (string) $position->media_gross);
        $this->assertSame('Fixture Platform A', $position->oaPlatformAllocations->firstOrFail()->platform_name);
    }

    #[Test]
    public function client_supplied_prices_and_master_data_overrides_are_ignored(): void
    {
        $ctx = $this->seedContext();
        $writer = app(CalculationWriter::class);

        $calculation = $writer->create($this->payload([
            $this->oaPosition($ctx, [
                'list_tkp' => '99.0000',
                'minimum_tkp' => '1.0000',
                'final_tkp' => '1.0000',
                'list_tkp_snapshot' => '99.0000',
                'minimum_tkp_snapshot' => '1.0000',
                'oa_targeting_selections' => [[
                    'targeting_code' => 'fixture_tgt',
                    'tkp_surcharge' => '0.0000',
                    'targeting_name' => 'Hacked',
                ]],
                'oa_platform_allocations' => [[
                    'oa_platform_id' => $ctx['platform']->id,
                    'platform_name' => 'Hacked',
                    'impressions' => 100_000,
                ]],
            ]),
        ]), $ctx['user']);

        $position = $calculation->positions->firstOrFail();
        $this->assertSame(0, OaTkpImportDecimal::compare((string) $position->list_tkp_snapshot, '12.0000'));
        $this->assertSame(0, OaTkpImportDecimal::compare((string) $position->minimum_tkp_snapshot, '8.0000'));
        $this->assertSame(0, OaTkpImportDecimal::compare((string) $position->final_tkp, '12.0000'));
        $this->assertFalse((bool) $position->below_minimum_tkp);
        $this->assertSame('Fixture Targeting', $position->oaTargetingSelections->firstOrFail()->targeting_name);
        $this->assertSame('Fixture Platform A', $position->oaPlatformAllocations->firstOrFail()->platform_name);
    }

    #[Test]
    public function ae_defaults_to_disabled_and_applies_only_when_enabled(): void
    {
        $ctx = $this->seedContext();
        $writer = app(CalculationWriter::class);

        $payload = $this->payload([$this->oaPosition($ctx)]);
        unset($payload['ae_enabled']);
        $off = $writer->create($payload, $ctx['user']);
        $this->assertFalse((bool) $off->ae_enabled);
        $this->assertSame('0.00', (string) $off->ae_total);
        $this->assertSame('1000.00', (string) $off->nn_invest);

        $on = $writer->create($this->payload([$this->oaPosition($ctx)], ['ae_enabled' => true]), $ctx['user']);
        $this->assertTrue((bool) $on->ae_enabled);
        $this->assertSame('150.00', (string) $on->ae_total);
        $this->assertSame('850.00', (string) $on->nn_invest);
    }

    #[Test]
    public function invalid_inputs_roll_back_without_partial_data(): void
    {
        $ctx = $this->seedContext();
        $writer = app(CalculationWriter::class);

        $cases = [
            'unknown targeting' => ['oa_targeting_selections' => [['targeting_code' => 'does_not_exist']]],
            'unknown platform' => ['oa_platform_allocations' => [['platform_code' => 'nope', 'impressions' => 100_000]]],
            'platform sum mismatch' => ['oa_platform_allocations' => [['oa_platform_id' => $ctx['platform']->id, 'impressions' => 99_999]]],
            'missing platforms' => ['oa_platform_allocations' => []],
            'zero impressions' => ['impressions' => 0],
            'bad basis' => ['basis_tkp' => '1,5'],
        ];

        foreach ($cases as $label => $override) {
            try {
                $writer->create($this->payload([$this->oaPosition($ctx, $override)]), $ctx['user']);
                $this->fail("Erwartete ValidationException: {$label}");
            } catch (ValidationException) {
                // erwartet
            }
        }

        $this->assertSame(0, Calculation::query()->count());
        $this->assertSame(0, CalculationPosition::query()->count());
        $this->assertSame(0, CalculationPositionOaPlatformAllocation::query()->count());
        $this->assertSame(0, CalculationPositionOaTargetingSelection::query()->count());
    }

    #[Test]
    public function native_and_unassigned_media_cannot_be_used_as_tkp_via_writer(): void
    {
        $ctx = $this->seedContext();
        $writer = app(CalculationWriter::class);
        $native = AdvertisingMedium::query()->where('code', 'native_ad')->firstOrFail();

        $payload = $this->payload([$this->oaPosition($ctx)]);
        $payload['positions'][0]['advertising_medium_id'] = $native->id;
        $payload = $this->withLiveSchemaFingerprint($payload);

        try {
            $writer->create($payload, $ctx['user']);
            $this->fail('native_ad darf nicht als TKP buchbar sein.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('nicht zugeordnet', collect($exception->errors())->flatten()->implode(' '));
        }

        $spotFixture = $this->createSpotFixture();
        $payload['positions'][0]['advertising_medium_id'] = $spotFixture['medium']->id;
        $payload['positions'][0]['inventory_id'] = $spotFixture['inventory']->id;
        $payload = $this->withLiveSchemaFingerprint($payload);
        try {
            $writer->create($payload, $ctx['user']);
            $this->fail('Nicht zugeordnetes Medium darf nicht als TKP buchbar sein.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('nicht zugeordnet', collect($exception->errors())->flatten()->implode(' '));
        }

        $this->assertSame(0, Calculation::query()->count());
    }

    #[Test]
    public function http_preview_store_and_update_with_tkp_remain_fail_closed(): void
    {
        $ctx = $this->seedContext();

        $httpPosition = [
            'inventory_id' => $ctx['inventory']->id,
            'advertising_medium_id' => $ctx['medium']->id,
            'calculation_method_key' => 'tkp',
            'length_seconds' => 30,
            'total_spot_count' => 1,
            'position_discount_percent' => '0',
            'ae_percent' => '0',
            'plan_rows' => [['hour' => 8, 'day_group' => 'mo_fr']],
        ];

        $preview = $this->actingAs($ctx['user'])->postJson(route('calculations.preview'), [
            'planning_mode' => 'manual',
            'order_discount_percent' => '0',
            'positions' => [$httpPosition],
        ]);
        $preview->assertStatus(422);
        $this->assertStringContainsString('noch nicht freigegeben', (string) json_encode($preview->json('errors')));

        $store = $this->actingAs($ctx['user'])->postJson(route('calculations.store'), $this->withLiveSchemaFingerprint([
            'planning_mode' => 'manual',
            'order_discount_percent' => '0',
            'positions' => [$httpPosition],
        ]));
        $store->assertStatus(422);
        $this->assertStringContainsString('noch nicht freigegeben', (string) json_encode($store->json('errors')));
        $this->assertSame(0, Calculation::query()->count());

        // Update einer intern angelegten OA-Kalkulation über HTTP bleibt gesperrt.
        $calculation = app(CalculationWriter::class)->create($this->payload([$this->oaPosition($ctx)]), $ctx['user']);
        $before = (string) $calculation->nn_invest;
        $payload = app(CalculationWriter::class)->payloadFromCalculation($calculation->fresh());
        unset($payload['positions'][0]['engine_profile_key']);
        $payload['positions'][0]['length_seconds'] = 30;
        $payload['lock_version'] = $calculation->lock_version;
        $update = $this->actingAs($ctx['user'])->putJson(route('calculations.update', $calculation), $payload);
        $update->assertStatus(422);
        $this->assertSame($before, (string) $calculation->fresh()->nn_invest);
        $this->assertSame(1, (int) $calculation->fresh()->lock_version);
    }

    #[Test]
    public function budget_apply_and_propose_reject_calculations_with_tkp_positions(): void
    {
        $ctx = $this->seedContext();
        $writer = app(CalculationWriter::class);

        $calculation = $writer->create($this->payload([
            $this->spotPosition($this->createSpotFixture()),
            $this->oaPosition($ctx),
        ]), $ctx['user']);
        $before = (string) $calculation->nn_invest;
        $oaBefore = (string) $calculation->positions->firstWhere('engine_profile_key', 'oa_tkp')->media_gross;

        $proposal = BudgetProposal::query()->create([
            'calculation_id' => $calculation->id,
            'strategy' => BudgetStrategy::EqualBudget,
            'status' => BudgetProposalStatus::Current,
            'target_budget_nn' => '5000.00',
            'lock_version' => $calculation->lock_version,
            'payload' => ['strategy' => 'equal_budget', 'positions' => []],
            'created_by' => $ctx['user']->id,
        ]);

        try {
            $writer->applyBudgetProposal($calculation->fresh(), $proposal, $ctx['user']);
            $this->fail('Budget-Apply auf TKP-Kalkulation muss abgelehnt werden.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('positions', $exception->errors());
        }
        $fresh = $calculation->fresh();
        $this->assertSame($before, (string) $fresh->nn_invest);
        $this->assertSame(1, (int) $fresh->lock_version);
        $this->assertSame($oaBefore, (string) $fresh->positions()->where('engine_profile_key', 'oa_tkp')->firstOrFail()->media_gross);

        $this->expectException(ValidationException::class);
        app(BudgetSpotProposalService::class)->propose([
            'target_budget_nn' => '5000',
            'positions' => [['calculation_method_key' => 'tkp']],
        ], $fresh->load('positions'));
    }

    #[Test]
    public function create_with_explicit_price_year_requires_expected_token(): void
    {
        $ctx = $this->seedContext();
        $writer = app(CalculationWriter::class);
        $year = PriceListCalendar::currentYear();

        try {
            $writer->create($this->payload([
                $this->oaPosition($ctx, ['price_year' => $year]),
            ]), $ctx['user']);
            $this->fail('Create mit explizitem price_year ohne Expected-Token muss 422 sein.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('positions.0.expected_oa_tkp_price_list_id', $exception->errors());
        }

        try {
            $writer->create($this->payload([
                $this->oaPosition($ctx, [
                    'price_year' => $year,
                    'expected_oa_tkp_price_list_id' => 999_999_999,
                ]),
            ]), $ctx['user']);
            $this->fail('Create mit falschem Expected-Token muss Conflict sein.');
        } catch (PriceListSelectionConflictException) {
            // erwartet
        }

        $ok = $writer->create($this->payload([
            $this->oaPosition($ctx, [
                'price_year' => $year,
                'expected_oa_tkp_price_list_id' => (int) $ctx['list']->id,
            ]),
        ]), $ctx['user']);
        $this->assertSame((int) $ctx['list']->id, (int) $ok->positions->firstOrFail()->oa_tkp_price_list_id);
    }

    #[Test]
    public function medium_and_inventory_rebind_require_expected_token(): void
    {
        $ctx = $this->seedContext();
        $writer = app(CalculationWriter::class);
        $mediumB = AdvertisingMedium::query()->where('code', 'in_stream')->firstOrFail();
        $inventoryB = Inventory::factory()->create([
            'name' => 'D1 OA Inventory B',
            'code' => 'D1OA_INV_B',
            'is_active' => true,
        ]);
        InventoryMediumRule::factory()->create([
            'inventory_id' => (int) $ctx['inventory']->id,
            'advertising_medium_id' => (int) $mediumB->id,
        ]);
        InventoryMediumRule::factory()->create([
            'inventory_id' => $inventoryB->id,
            'advertising_medium_id' => (int) $mediumB->id,
        ]);

        // Eine Active-Revision/Jahr: zusätzliche Items auf derselben Liste.
        OaTkpPriceListItem::query()->create([
            'oa_tkp_price_list_id' => (int) $ctx['list']->id,
            'advertising_medium_id' => (int) $mediumB->id,
            'inventory_id' => (int) $ctx['inventory']->id,
            'list_tkp' => '18.0000',
            'minimum_tkp' => '11.0000',
        ]);
        OaTkpPriceListItem::query()->create([
            'oa_tkp_price_list_id' => (int) $ctx['list']->id,
            'advertising_medium_id' => (int) $mediumB->id,
            'inventory_id' => (int) $inventoryB->id,
            'list_tkp' => '21.0000',
            'minimum_tkp' => '13.0000',
        ]);

        $calculation = $writer->create($this->payload([$this->oaPosition($ctx)]), $ctx['user']);
        $activeListId = (int) $ctx['list']->id;
        $locked = (int) $calculation->lock_version;

        $payload = $writer->payloadFromCalculation($calculation->fresh());
        $payload['lock_version'] = $locked;
        $payload['positions'][0]['advertising_medium_id'] = (int) $mediumB->id;
        $payload['positions'][0]['schema_fingerprint'] = $this->positionSchemaFingerprintFor(
            $calculation->fresh(),
            (int) $mediumB->id,
        );
        unset($payload['positions'][0]['expected_oa_tkp_price_list_id']);

        try {
            $writer->update($calculation->fresh(), $payload, $ctx['user']);
            $this->fail('Medium-Rebind ohne Token muss 422 sein.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('positions.0.expected_oa_tkp_price_list_id', $exception->errors());
        }
        $this->assertSame(1, (int) $calculation->fresh()->lock_version);
        $this->assertSame($activeListId, (int) $calculation->fresh()->positions->firstOrFail()->oa_tkp_price_list_id);
        $this->assertSame(0, OaTkpImportDecimal::compare((string) $calculation->fresh()->positions->firstOrFail()->list_tkp_snapshot, '12.0000'));

        $payload['positions'][0]['expected_oa_tkp_price_list_id'] = 999_999_999;
        try {
            $writer->update($calculation->fresh(), $payload, $ctx['user']);
            $this->fail('Medium-Rebind mit falschem Token muss Conflict sein.');
        } catch (PriceListSelectionConflictException) {
            // erwartet
        }
        $this->assertSame(1, (int) $calculation->fresh()->lock_version);

        $payload['positions'][0]['expected_oa_tkp_price_list_id'] = $activeListId;
        $afterMedium = $writer->update($calculation->fresh(), $payload, $ctx['user']);
        $this->assertSame($activeListId, (int) $afterMedium->positions->firstOrFail()->oa_tkp_price_list_id);
        $this->assertSame('oa_tkp', $afterMedium->positions->firstOrFail()->engine_profile_key);
        $this->assertSame('tkp', $afterMedium->positions->firstOrFail()->calculation_method_key);
        $this->assertSame('v1', $afterMedium->positions->firstOrFail()->algorithm_version);
        $this->assertSame(0, OaTkpImportDecimal::compare((string) $afterMedium->positions->firstOrFail()->list_tkp_snapshot, '18.0000'));

        $payload = $writer->payloadFromCalculation($afterMedium->fresh());
        $payload['lock_version'] = $afterMedium->lock_version;
        $payload['positions'][0]['inventory_id'] = (int) $inventoryB->id;
        unset($payload['positions'][0]['expected_oa_tkp_price_list_id']);

        try {
            $writer->update($afterMedium->fresh(), $payload, $ctx['user']);
            $this->fail('Inventory-Rebind ohne Token muss 422 sein.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('positions.0.expected_oa_tkp_price_list_id', $exception->errors());
        }

        $payload['positions'][0]['expected_oa_tkp_price_list_id'] = 999_999_999;
        try {
            $writer->update($afterMedium->fresh(), $payload, $ctx['user']);
            $this->fail('Inventory-Rebind mit falschem Token muss Conflict sein.');
        } catch (PriceListSelectionConflictException) {
            // erwartet
        }

        $payload['positions'][0]['expected_oa_tkp_price_list_id'] = $activeListId;
        $afterInventory = $writer->update($afterMedium->fresh(), $payload, $ctx['user']);
        $this->assertSame($activeListId, (int) $afterInventory->positions->firstOrFail()->oa_tkp_price_list_id);
        $this->assertSame((int) $inventoryB->id, (int) $afterInventory->positions->firstOrFail()->inventory_id);
        $this->assertSame(0, OaTkpImportDecimal::compare((string) $afterInventory->positions->firstOrFail()->list_tkp_snapshot, '21.0000'));
    }

    #[Test]
    public function year_rebind_without_expected_token_is_rejected(): void
    {
        $ctx = $this->seedContext();
        $writer = app(CalculationWriter::class);
        $calculation = $writer->create($this->payload([$this->oaPosition($ctx)]), $ctx['user']);
        $nextYear = PriceListCalendar::currentYear() + 1;
        $nextList = $this->createActivePriceList(
            $nextYear,
            1,
            (int) $ctx['medium']->id,
            (int) $ctx['inventory']->id,
            '14.0000',
            '9.0000',
        );

        $payload = $writer->payloadFromCalculation($calculation->fresh());
        $payload['lock_version'] = $calculation->lock_version;
        $payload['positions'][0]['price_year'] = $nextYear;
        unset($payload['positions'][0]['expected_oa_tkp_price_list_id']);

        try {
            $writer->update($calculation->fresh(), $payload, $ctx['user']);
            $this->fail('Year-Rebind ohne Token muss 422 sein.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('positions.0.expected_oa_tkp_price_list_id', $exception->errors());
        }
        $this->assertSame(1, (int) $calculation->fresh()->lock_version);
        $this->assertSame((int) $ctx['list']->id, (int) $calculation->fresh()->positions->firstOrFail()->oa_tkp_price_list_id);
        $this->assertSame($nextYear, (int) $nextList->year);
    }

    #[Test]
    public function targeting_code_only_keeps_historical_revision_pin(): void
    {
        $ctx = $this->seedContext();
        $writer = app(CalculationWriter::class);
        $calculation = $writer->create($this->payload([
            $this->oaPosition($ctx, [
                'oa_targeting_selections' => [['targeting_code' => 'fixture_tgt']],
            ]),
        ]), $ctx['user']);

        $rev1Id = (int) $ctx['targeting']->id;
        $this->assertSame(0, OaTkpImportDecimal::compare(
            (string) $calculation->positions->firstOrFail()->final_tkp,
            '12.0000',
        ));

        $ctx['targeting']->update(['is_active' => false]);
        $rev2 = OaTargetingDefinition::query()->create([
            'code' => 'fixture_tgt',
            'name' => 'Fixture Targeting Rev2',
            'category' => OaTargetingCategory::Technisch,
            'tkp_surcharge' => '5.0000',
            'revision_number' => 2,
            'is_active' => true,
        ]);

        $payload = $writer->payloadFromCalculation($calculation->fresh());
        $payload['lock_version'] = $calculation->lock_version;
        $payload['positions'][0]['oa_targeting_selections'] = [['targeting_code' => 'fixture_tgt']];
        unset($payload['positions'][0]['oa_targeting_selections'][0]['oa_targeting_definition_id']);

        $updated = $writer->update($calculation->fresh(), $payload, $ctx['user']);
        $selection = $updated->positions->firstOrFail()->oaTargetingSelections->firstOrFail();
        $this->assertSame($rev1Id, (int) $selection->oa_targeting_definition_id);
        $this->assertSame(1, (int) $selection->targeting_revision_number);
        $this->assertSame(0, OaTkpImportDecimal::compare((string) $selection->tkp_surcharge_snapshot, '2.0000'));
        $this->assertSame(0, OaTkpImportDecimal::compare((string) $updated->positions->firstOrFail()->final_tkp, '12.0000'));

        // Bewusster Wechsel auf Revision 2 per ID.
        $payload = $writer->payloadFromCalculation($updated->fresh());
        $payload['lock_version'] = $updated->lock_version;
        $payload['positions'][0]['oa_targeting_selections'] = [
            ['oa_targeting_definition_id' => (int) $rev2->id, 'targeting_code' => 'fixture_tgt'],
        ];
        $switched = $writer->update($updated->fresh(), $payload, $ctx['user']);
        $this->assertSame((int) $rev2->id, (int) $switched->positions->firstOrFail()->oaTargetingSelections->firstOrFail()->oa_targeting_definition_id);
        $this->assertSame(0, OaTkpImportDecimal::compare((string) $switched->positions->firstOrFail()->final_tkp, '15.0000'));

        // ID/Code-Konflikt.
        $payload = $writer->payloadFromCalculation($switched->fresh());
        $payload['lock_version'] = $switched->lock_version;
        $payload['positions'][0]['oa_targeting_selections'] = [
            ['oa_targeting_definition_id' => (int) $rev2->id, 'targeting_code' => 'other_code'],
        ];
        $this->expectException(ValidationException::class);
        $writer->update($switched->fresh(), $payload, $ctx['user']);
    }

    #[Test]
    public function inactive_historical_targeting_pin_survives_code_only_update(): void
    {
        $ctx = $this->seedContext();
        $writer = app(CalculationWriter::class);
        $calculation = $writer->create($this->payload([
            $this->oaPosition($ctx, [
                'oa_targeting_selections' => [['oa_targeting_definition_id' => (int) $ctx['targeting']->id]],
            ]),
        ]), $ctx['user']);
        $ctx['targeting']->update(['is_active' => false]);

        $payload = $writer->payloadFromCalculation($calculation->fresh());
        $payload['lock_version'] = $calculation->lock_version;
        $payload['positions'][0]['oa_targeting_selections'] = [['targeting_code' => 'fixture_tgt']];
        $updated = $writer->update($calculation->fresh(), $payload, $ctx['user']);
        $this->assertSame((int) $ctx['targeting']->id, (int) $updated->positions->firstOrFail()->oaTargetingSelections->firstOrFail()->oa_targeting_definition_id);
        $this->assertSame(0, OaTkpImportDecimal::compare((string) $updated->positions->firstOrFail()->final_tkp, '12.0000'));
    }

    #[Test]
    public function lock_years_include_pinned_non_current_and_mixed_years(): void
    {
        $ctx = $this->seedContext();
        $nextYear = PriceListCalendar::currentYear() + 1;
        $current = PriceListCalendar::currentYear();
        $years = OaTkpWriterPositionResolver::yearsToLockForPayload([
            'positions' => [
                [
                    'id' => 1,
                    'calculation_method_key' => 'tkp',
                ],
                [
                    'calculation_method_key' => 'tkp',
                    'price_year' => $current,
                ],
            ],
        ], null);
        $this->assertSame([$current], $years);

        $writer = app(CalculationWriter::class);
        $nextList = $this->createActivePriceList(
            $nextYear,
            1,
            (int) $ctx['medium']->id,
            (int) $ctx['inventory']->id,
            '14.0000',
            '9.0000',
        );
        $calculation = $writer->create($this->payload([
            $this->oaPosition($ctx, [
                'price_year' => $nextYear,
                'expected_oa_tkp_price_list_id' => (int) $nextList->id,
            ]),
            $this->oaPosition($ctx, [
                'price_year' => $current,
                'expected_oa_tkp_price_list_id' => (int) $ctx['list']->id,
                'impressions' => 50_000,
                'oa_platform_allocations' => [['oa_platform_id' => (int) $ctx['platform']->id, 'impressions' => 50_000]],
            ]),
        ]), $ctx['user']);

        $payload = $writer->payloadFromCalculation($calculation->fresh());
        unset($payload['positions'][0]['price_year'], $payload['positions'][1]['price_year']);
        $lockedYears = OaTkpWriterPositionResolver::yearsToLockForPayload($payload, $calculation->fresh()->load('positions'));
        $this->assertSame([$current, $nextYear], $lockedYears);
    }

    #[Test]
    public function removing_last_oa_position_clears_under_min_reason_and_children(): void
    {
        $ctx = $this->seedContext();
        $writer = app(CalculationWriter::class);
        $spot = $this->createSpotFixture();

        $calculation = $writer->create($this->payload([
            $this->spotPosition($spot),
            $this->oaPosition($ctx, [
                'basis_tkp' => '5.0000',
                'oa_targeting_selections' => [['targeting_code' => 'fixture_tgt']],
            ]),
        ]), $ctx['user']);

        $this->assertTrue((bool) $calculation->requires_special_approval);
        $this->assertNotEmpty($calculation->special_approval_reasons);
        $oaId = (int) $calculation->positions->firstWhere('engine_profile_key', 'oa_tkp')->id;
        $this->assertGreaterThan(0, CalculationPositionOaPlatformAllocation::query()->where('calculation_position_id', $oaId)->count());
        $this->assertGreaterThan(0, CalculationPositionOaTargetingSelection::query()->where('calculation_position_id', $oaId)->count());

        $payload = $writer->payloadFromCalculation($calculation->fresh());
        $payload['lock_version'] = $calculation->lock_version;
        $payload['positions'] = array_values(array_filter(
            $payload['positions'],
            fn (array $position): bool => ($position['calculation_method_key'] ?? $position['spot_method'] ?? '') !== 'tkp',
        ));

        $updated = $writer->update($calculation->fresh(), $payload, $ctx['user']);
        $this->assertCount(1, $updated->positions);
        $this->assertSame('spot_classic', $updated->positions->firstOrFail()->engine_profile_key);
        $this->assertFalse((bool) $updated->requires_special_approval);
        $this->assertTrue(
            $updated->special_approval_reasons === null || $updated->special_approval_reasons === [],
        );
        $this->assertSame(0, CalculationPosition::query()->whereKey($oaId)->count());
        $this->assertSame(0, CalculationPositionOaPlatformAllocation::query()->where('calculation_position_id', $oaId)->count());
        $this->assertSame(0, CalculationPositionOaTargetingSelection::query()->where('calculation_position_id', $oaId)->count());
    }

    /**
     * @param  list<array<string, mixed>>  $positions
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $positions, array $overrides = []): array
    {
        return $this->withLiveSchemaFingerprint(array_merge([
            'planning_mode' => 'manual',
            'order_discount_percent' => '0',
            'ae_enabled' => false,
            'positions' => $positions,
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $ctx
     * @param  array<string, mixed>  $override
     * @return array<string, mixed>
     */
    private function oaPosition(array $ctx, array $override = []): array
    {
        return array_merge([
            'inventory_id' => (int) $ctx['inventory']->id,
            'advertising_medium_id' => (int) $ctx['medium']->id,
            'calculation_method_key' => 'tkp',
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
     * Spot-Fixture auf dem Seed-Medium `spot_classic` mit eigenem Inventar und Preisliste.
     *
     * @return array{medium: AdvertisingMedium, inventory: Inventory}
     */
    private function createSpotFixture(): array
    {
        $medium = AdvertisingMedium::query()->where('code', 'spot_classic')->firstOrFail();
        $inventory = Inventory::factory()->create(['name' => 'D1 Spot Sender', 'code' => 'D1SPOT']);
        InventoryMediumRule::factory()->create([
            'inventory_id' => $inventory->id,
            'advertising_medium_id' => $medium->id,
        ]);
        $list = PriceList::factory()->create([
            'inventory_id' => $inventory->id,
            'status' => PriceListStatus::Active,
            'year' => PriceListCalendar::currentYear(),
            'version' => 'D1-SPOT',
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
            'code' => 'fixture_tgt',
            'name' => 'Fixture Targeting',
            'category' => OaTargetingCategory::Technisch,
            'tkp_surcharge' => '2.0000',
            'revision_number' => 1,
            'is_active' => true,
        ]);
        $platform = OaPlatform::query()->create([
            'code' => 'fixture_plat_a',
            'name' => 'Fixture Platform A',
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
            'name' => "Fixture D1 {$year}.{$revision}",
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
