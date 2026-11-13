<?php

namespace Tests\Feature\Calculation\FreePosition;

use App\Enums\BudgetProposalStatus;
use App\Enums\BudgetStrategy;
use App\Enums\EngineCapabilityStatus;
use App\Enums\SpecialApprovalReasonCode;
use App\Models\AdvertisingMedium;
use App\Models\AdvertisingMediumCalculationMethod;
use App\Models\BudgetProposal;
use App\Models\Calculation;
use App\Models\CalculationPosition;
use App\Services\Calculation\BudgetSpotProposalService;
use App\Services\Calculation\CalculationWriter;
use App\Support\Calculation\EngineProfileRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesFreePositionCatalog;
use Tests\TestCase;

/**
 * NATIVE-2: Free-Position im CalculationWriter (Preview, Persistenz, Reload, Mixed).
 *
 * Pair bleibt im CATALOG planned; Release wird nur über den Test-Fake simuliert.
 */
class FreePositionWriterIntegrationTest extends TestCase
{
    use CreatesFreePositionCatalog;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        EngineProfileRegistry::clearFreePositionPairFakeForTests();
        parent::tearDown();
    }

    #[Test]
    public function catalog_pair_stays_planned_and_fake_is_isolated(): void
    {
        $this->assertSame(
            EngineCapabilityStatus::Planned,
            EngineProfileRegistry::pairStatus(EngineProfileRegistry::PROFILE_FREE_POSITION, 'free_position'),
        );

        EngineProfileRegistry::fakeFreePositionPairReleasedForTests();
        $this->assertSame(
            EngineCapabilityStatus::Released,
            EngineProfileRegistry::pairStatus(EngineProfileRegistry::PROFILE_FREE_POSITION, 'free_position'),
        );
        $this->assertSame('v1', EngineProfileRegistry::currentReleasedVersion(
            EngineProfileRegistry::PROFILE_FREE_POSITION,
            'free_position',
        ));
        // OA-Paar unverändert
        $this->assertSame(
            EngineCapabilityStatus::Released,
            EngineProfileRegistry::pairStatus(EngineProfileRegistry::PROFILE_OA_TKP, 'tkp'),
        );

        EngineProfileRegistry::clearFreePositionPairFakeForTests();
        $this->assertSame(
            EngineCapabilityStatus::Planned,
            EngineProfileRegistry::pairStatus(EngineProfileRegistry::PROFILE_FREE_POSITION, 'free_position'),
        );
    }

    #[Test]
    public function writer_without_registry_release_fails_closed(): void
    {
        $ctx = $this->seedFreeContext();
        $writer = app(CalculationWriter::class);

        try {
            $writer->preview($this->payload([$this->freePosition($ctx)]), $ctx['user']);
            $this->fail('Preview ohne Registry-Release muss fehlschlagen.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('positions.0', $exception->errors());
        }

        $this->expectException(ValidationException::class);
        $writer->create($this->payload([$this->freePosition($ctx)]), $ctx['user']);
    }

    #[Test]
    public function main_case_persists_and_reloads_5000_4500_3600_540_3060(): void
    {
        EngineProfileRegistry::fakeFreePositionPairReleasedForTests();
        $ctx = $this->seedFreeContext();
        $writer = app(CalculationWriter::class);
        $payload = $this->payload([
            $this->freePosition($ctx, ['position_discount_percent' => '10']),
        ], ['order_discount_percent' => '20', 'ae_enabled' => true]);

        $preview = $writer->preview($payload, $ctx['user']);
        $result = $preview->positions[0];
        $this->assertSame('5000.00', $result->mediaGross);
        $this->assertSame('4500.00', $result->afterPositionDiscount);
        $this->assertSame('3600.00', $result->afterOrderDiscount);
        $this->assertSame('540.00', $result->aeAmount);
        $this->assertSame('3060.00', $result->nnInvest);
        $this->assertSame('free_position', $result->methodResult['method']);
        $this->assertSame('5000.00', $result->methodResult['free_position']['free_price']);
        $this->assertSame('3060.00', $preview->nnInvest);
        $this->assertSame('5000.00', $preview->mediaGross);
        $this->assertSame('4500.00', $preview->afterPositionDiscountTotal);
        $this->assertSame('3600.00', $preview->afterOrderDiscountTotal);
        $this->assertSame('3600.00', $preview->aeEligibleBase);
        $this->assertSame('900.00', $preview->orderDiscounts[0]['amount']);
        $this->assertFalse($preview->requiresSpecialApproval);

        $calculation = $writer->create($payload, $ctx['user']);
        /** @var CalculationPosition $position */
        $position = $calculation->positions->firstOrFail();

        $this->assertSame('free_position', $position->engine_profile_key);
        $this->assertSame('free_position', $position->calculation_method_key);
        $this->assertSame('Freie Preisposition', $position->calculation_method_name);
        $this->assertSame('v1', $position->algorithm_version);
        $this->assertSame('free_position', $position->kind->value);
        $this->assertSame('free_position', $position->spot_method->value);
        $this->assertNull($position->price_list_id);
        $this->assertSame(0, (int) $position->length_seconds);
        $this->assertSame(0, (int) $position->total_spot_count);
        $this->assertSame('normal', $position->pricing_settlement_mode->value);
        $this->assertNull($position->fixed_price_nn);
        $this->assertSame('5000.00', (string) $position->media_gross);
        $this->assertSame('500.00', (string) $position->position_discount_amount);
        $this->assertSame('900.00', (string) $position->order_discount_amount);
        $this->assertSame('540.00', (string) $position->ae_amount);
        $this->assertSame('3060.00', (string) $position->nn_invest);
        $this->assertTrue((bool) $position->is_discountable);
        $this->assertTrue((bool) $position->is_ae_eligible);
        $this->assertNotNull($position->effective_configuration_snapshot_id);
        $this->assertSame((int) $ctx['rule']->id, (int) $position->inventory_medium_rule_id);
        $this->assertSame('5000.00', (string) $calculation->media_gross);
        $this->assertSame('3060.00', (string) $calculation->nn_invest);
        $this->assertSame('540.00', (string) $calculation->ae_total);

        $reloaded = $writer->payloadFromCalculation(Calculation::query()->findOrFail($calculation->id));
        $reloadedPosition = $reloaded['positions'][0];
        $this->assertSame('free_position', $reloadedPosition['calculation_method_key']);
        $this->assertSame('free_position', $reloadedPosition['engine_profile_key']);
        $this->assertSame('free_position', $reloadedPosition['spot_method']);
        $this->assertSame('5000.00', $reloadedPosition['free_price']);

        // Reload-Roundtrip ohne Änderung bleibt stabil.
        $reloaded['lock_version'] = $calculation->lock_version;
        $updated = $writer->update($calculation, $reloaded, $ctx['user']);
        $this->assertSame('3060.00', (string) $updated->nn_invest);
        $this->assertSame('5000.00', (string) $updated->positions->firstOrFail()->media_gross);
    }

    #[Test]
    public function flags_not_discountable_not_ae_eligible_and_ae_disabled_keep_the_price(): void
    {
        EngineProfileRegistry::fakeFreePositionPairReleasedForTests();
        $ctx = $this->seedFreeContext();
        $ctx['medium']->update(['is_discountable' => false, 'is_ae_eligible' => false]);
        $writer = app(CalculationWriter::class);

        $calculation = $writer->create($this->payload([
            $this->freePosition($ctx, [
                'position_discount_percent' => '10',
                // Client-Flags sind nie autoritativ.
                'is_discountable' => true,
                'is_ae_eligible' => true,
                'kind' => 'spot_classic',
                'engine_profile_key' => 'spot_classic',
            ]),
        ], ['order_discount_percent' => '20', 'ae_enabled' => false]), $ctx['user']);

        $position = $calculation->positions->firstOrFail();
        $this->assertFalse((bool) $position->is_discountable);
        $this->assertFalse((bool) $position->is_ae_eligible);
        $this->assertFalse((bool) $calculation->ae_enabled);
        $this->assertSame('5000.00', (string) $position->media_gross);
        $this->assertSame('0.00', (string) $position->position_discount_amount);
        $this->assertSame('0.00', (string) $position->order_discount_amount);
        $this->assertSame('0.00', (string) $position->ae_amount);
        $this->assertSame('5000.00', (string) $position->nn_invest);
        $this->assertSame('0.0000', (string) $position->ae_percent);
        $this->assertSame('free_position', $position->engine_profile_key);
        $this->assertSame('free_position', $position->kind->value);
    }

    #[Test]
    public function ae_disabled_on_eligible_position_skips_ae_and_rule_flag_narrows_medium_flag(): void
    {
        EngineProfileRegistry::fakeFreePositionPairReleasedForTests();
        $ctx = $this->seedFreeContext();
        $ctx['rule']->update(['is_discountable' => false]);
        $writer = app(CalculationWriter::class);

        $calculation = $writer->create($this->payload([
            $this->freePosition($ctx, ['position_discount_percent' => '10']),
        ], ['order_discount_percent' => '20', 'ae_enabled' => false]), $ctx['user']);

        $position = $calculation->positions->firstOrFail();
        // medium ∧ rule: Rule nicht rabattfähig → kein Rabatt.
        $this->assertFalse((bool) $position->is_discountable);
        $this->assertTrue((bool) $position->is_ae_eligible);
        $this->assertSame('5000.00', (string) $position->nn_invest);
        $this->assertSame('0.00', (string) $position->ae_amount);
    }

    #[Test]
    public function special_approval_uses_existing_rate_reason_without_new_codes(): void
    {
        EngineProfileRegistry::fakeFreePositionPairReleasedForTests();
        $ctx = $this->seedFreeContext('10');
        $writer = app(CalculationWriter::class);
        $payload = $this->payload([
            $this->freePosition($ctx, ['position_discount_percent' => '20']),
        ]);

        $preview = $writer->preview($payload, $ctx['user']);
        $this->assertTrue($preview->requiresSpecialApproval);
        $this->assertSame(
            [SpecialApprovalReasonCode::PositionDiscountExceedsLimit->value],
            array_column($preview->specialApprovalReasons, 'code'),
        );

        $calculation = $writer->create($payload, $ctx['user']);
        $this->assertTrue((bool) $calculation->requires_special_approval);
        $reasons = $calculation->special_approval_reasons;
        $this->assertIsArray($reasons);
        $this->assertCount(1, $reasons);
        $this->assertSame(SpecialApprovalReasonCode::PositionDiscountExceedsLimit->value, $reasons[0]['code']);
        $this->assertSame((int) $calculation->positions->firstOrFail()->id, $reasons[0]['position_id']);
        $this->assertSame('20.0000', $reasons[0]['actual_percent']);
        $this->assertSame('10.0000', $reasons[0]['limit_percent']);

        $ok = $writer->create($this->payload([
            $this->freePosition($ctx, ['position_discount_percent' => '10']),
        ]), $ctx['user']);
        $this->assertFalse((bool) $ok->requires_special_approval);
    }

    #[Test]
    public function mixed_spot_and_free(): void
    {
        EngineProfileRegistry::fakeFreePositionPairReleasedForTests();
        $ctx = $this->seedFreeContext();
        $spot = $this->createFreeSpotFixture();
        $writer = app(CalculationWriter::class);

        $payload = $this->payload([
            $this->freeSpotPosition($spot),
            $this->freePosition($ctx, ['position_discount_percent' => '10']),
        ], ['order_discount_percent' => '20', 'ae_enabled' => true, 'target_budget_nn' => '5000']);

        $preview = $writer->preview($payload, $ctx['user']);
        $this->assertCount(2, $preview->positions);
        $this->assertSame(10, $preview->positions[0]->spotCount);
        $this->assertSame('free_position', $preview->positions[1]->methodResult['method']);
        $this->assertSame('3060.00', $preview->positions[1]->nnInvest);
        $this->assertSame('5000.00', $preview->targetBudgetNn);
        $this->assertSame(bcsub('5000.00', $preview->nnInvest, 2), $preview->budgetDelta);

        $calculation = $writer->create($payload, $ctx['user']);
        $spotPosition = $calculation->positions->firstWhere('engine_profile_key', 'spot_classic');
        $freePosition = $calculation->positions->firstWhere('engine_profile_key', 'free_position');
        $this->assertNotNull($spotPosition);
        $this->assertNotNull($freePosition);
        $this->assertSame(0, (int) $freePosition->sort - 1);
        $this->assertNotNull($spotPosition->price_list_id);
        $this->assertNull($freePosition->price_list_id);
        $this->assertSame('3060.00', (string) $freePosition->nn_invest);
        $this->assertSame(
            bcadd((string) $spotPosition->nn_invest, '3060.00', 2),
            (string) $calculation->nn_invest,
        );
        $this->assertSame(
            bcadd((string) $spotPosition->media_gross, '5000.00', 2),
            (string) $calculation->media_gross,
        );
        $this->assertSame($preview->nnInvest, (string) $calculation->nn_invest);
        $this->assertSame($preview->aeTotal, (string) $calculation->ae_total);
        $this->assertSame($preview->orderDiscountTotal, (string) $calculation->order_discount_total);
    }

    #[Test]
    public function mixed_oa_normal_and_free(): void
    {
        EngineProfileRegistry::fakeFreePositionPairReleasedForTests();
        $ctx = $this->seedFreeContext();
        $oa = $this->seedFreeOaContext();
        $writer = app(CalculationWriter::class);

        $payload = $this->payload([
            $this->freeOaPosition($oa),
            $this->freePosition($ctx, ['position_discount_percent' => '10']),
        ], ['order_discount_percent' => '20', 'ae_enabled' => true]);

        $preview = $writer->preview($payload, $ctx['user']);
        $this->assertSame(['tkp', 'free_position'], array_column(
            array_map(fn ($p): array => (array) $p->methodResult, $preview->positions),
            'method',
        ));
        // OA: 100.000 Impr. × 10 TKP = 1.000 → 20 % Auftragsrabatt → 800 → AE 15 % = 120 → 680
        $this->assertSame('1000.00', $preview->positions[0]->mediaGross);
        $this->assertSame('680.00', $preview->positions[0]->nnInvest);
        $this->assertSame('3060.00', $preview->positions[1]->nnInvest);
        $this->assertSame('3740.00', $preview->nnInvest);
        $this->assertSame('6000.00', $preview->mediaGross);
        // Auftragsrabatt-Basis: OA 1000 + Free 4500 (Free nach Positionsrabatt)
        $this->assertSame('5500.00', $preview->afterPositionDiscountTotal);
        $this->assertSame('1100.00', $preview->orderDiscounts[0]['amount']);

        $calculation = $writer->create($payload, $ctx['user']);
        $this->assertSame('3740.00', (string) $calculation->nn_invest);
        $profiles = $calculation->positions->sortBy('sort')->pluck('engine_profile_key')->values()->all();
        $this->assertSame(['oa_tkp', 'free_position'], $profiles);
        $free = $calculation->positions->firstWhere('engine_profile_key', 'free_position');
        $this->assertCount(0, $free->oaPlatformAllocations);
        $this->assertCount(0, $free->oaTargetingSelections);
        $this->assertNull($free->impressions);
        $this->assertNull($free->basis_tkp);
    }

    #[Test]
    public function mixed_oa_fixed_and_free(): void
    {
        EngineProfileRegistry::fakeFreePositionPairReleasedForTests();
        $ctx = $this->seedFreeContext();
        $oa = $this->seedFreeOaContext();
        $writer = app(CalculationWriter::class);

        $payload = $this->payload([
            $this->freeOaPosition($oa, [
                'pricing_settlement_mode' => 'fixed_price',
                'fixed_price_nn' => '1000.00',
                'basis_tkp' => null,
            ]),
            $this->freePosition($ctx),
        ], ['order_discount_percent' => '20', 'ae_enabled' => false]);

        $preview = $writer->preview($payload, $ctx['user']);
        $this->assertSame('tkp', $preview->positions[0]->methodResult['method']);
        $this->assertSame('1000.00', $preview->positions[0]->nnInvest);
        $this->assertSame('free_position', $preview->positions[1]->methodResult['method']);
        // Free: 5000 → 20 % Auftragsrabatt → 4000; Fixed trägt keinen Auftragsrabatt.
        $this->assertSame('4000.00', $preview->positions[1]->nnInvest);
        $this->assertSame('5000.00', $preview->nnInvest);
        // Auftragsrabatt-Breakdown-Basis ohne OA-Fixed-Referenzbrutto: nur Free (5000).
        $this->assertSame('1000.00', $preview->orderDiscounts[0]['amount']);
        $this->assertTrue($preview->requiresSpecialApproval);
        $this->assertContains(
            SpecialApprovalReasonCode::OaTkpFixedPrice->value,
            array_column($preview->specialApprovalReasons, 'code'),
        );

        $calculation = $writer->create($payload, $ctx['user']);
        $this->assertSame('5000.00', (string) $calculation->nn_invest);
        $this->assertSame(
            ['oa_tkp', 'free_position'],
            $calculation->positions->sortBy('sort')->pluck('engine_profile_key')->values()->all(),
        );
        $fixed = $calculation->positions->firstWhere('engine_profile_key', 'oa_tkp');
        $this->assertSame('fixed_price', $fixed->pricing_settlement_mode->value);
        $free = $calculation->positions->firstWhere('engine_profile_key', 'free_position');
        $this->assertSame('normal', $free->pricing_settlement_mode->value);
    }

    #[Test]
    public function mixed_spot_oa_and_free_preserve_payload_order(): void
    {
        EngineProfileRegistry::fakeFreePositionPairReleasedForTests();
        $ctx = $this->seedFreeContext();
        $oa = $this->seedFreeOaContext();
        $spot = $this->createFreeSpotFixture();
        $writer = app(CalculationWriter::class);

        $payload = $this->payload([
            $this->freePosition($ctx, ['free_price' => '1000.00']),
            $this->freeSpotPosition($spot),
            $this->freeOaPosition($oa),
        ], ['order_discount_percent' => '10', 'ae_enabled' => false]);

        $preview = $writer->preview($payload, $ctx['user']);
        $this->assertSame('1000.00', $preview->positions[0]->mediaGross);
        $this->assertSame('free_position', $preview->positions[0]->methodResult['method']);
        $this->assertSame(10, $preview->positions[1]->spotCount);
        $this->assertNull($preview->positions[1]->methodResult);
        $this->assertSame('tkp', $preview->positions[2]->methodResult['method']);

        $calculation = $writer->create($payload, $ctx['user']);
        $this->assertSame(
            ['free_position', 'spot_classic', 'oa_tkp'],
            $calculation->positions->sortBy('sort')->pluck('engine_profile_key')->values()->all(),
        );
        $sum = '0.00';
        foreach ($calculation->positions as $position) {
            $sum = bcadd($sum, (string) $position->nn_invest, 2);
        }
        $this->assertSame($sum, (string) $calculation->nn_invest);
        $this->assertSame($preview->nnInvest, (string) $calculation->nn_invest);

        // Reload erhält Reihenfolge und Methode.
        $reloaded = $writer->payloadFromCalculation($calculation->fresh());
        $this->assertSame('free_position', $reloaded['positions'][0]['calculation_method_key']);
        $this->assertSame('1000.00', $reloaded['positions'][0]['free_price']);
        $this->assertSame('average', $reloaded['positions'][1]['spot_method']);
        $this->assertSame('tkp', $reloaded['positions'][2]['calculation_method_key']);
    }

    #[Test]
    public function client_media_gross_and_price_flags_are_not_authoritative(): void
    {
        EngineProfileRegistry::fakeFreePositionPairReleasedForTests();
        $ctx = $this->seedFreeContext();
        $writer = app(CalculationWriter::class);

        $calculation = $writer->create($this->payload([
            $this->freePosition($ctx, [
                'free_price' => '5000.00',
                'media_gross' => '1.00',
                'nn_invest' => '1.00',
                'algorithm_version' => 'v9',
            ]),
        ]), $ctx['user']);

        $position = $calculation->positions->firstOrFail();
        $this->assertSame('5000.00', (string) $position->media_gross);
        $this->assertSame('v1', $position->algorithm_version);
    }

    #[Test]
    public function free_price_is_required_and_invalid_values_are_rejected(): void
    {
        EngineProfileRegistry::fakeFreePositionPairReleasedForTests();
        $ctx = $this->seedFreeContext();
        $writer = app(CalculationWriter::class);

        $missing = $this->freePosition($ctx);
        unset($missing['free_price']);
        $cases = [
            'missing' => $missing,
            'blank' => $this->freePosition($ctx, ['free_price' => '']),
            'null' => $this->freePosition($ctx, ['free_price' => null]),
            'zero' => $this->freePosition($ctx, ['free_price' => '0']),
            'negative' => $this->freePosition($ctx, ['free_price' => '-5']),
            'text' => $this->freePosition($ctx, ['free_price' => 'abc']),
            'three decimals' => $this->freePosition($ctx, ['free_price' => '10.123']),
            'array' => $this->freePosition($ctx, ['free_price' => ['1']]),
        ];

        foreach ($cases as $label => $position) {
            try {
                $writer->preview($this->payload([$position]), $ctx['user']);
                $this->fail("free_price-Fall „{$label}“ muss abgelehnt werden.");
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('positions.0.free_price', $exception->errors(), $label);
            }
        }

        // Dezimalkomma wird akzeptiert.
        $preview = $writer->preview($this->payload([
            $this->freePosition($ctx, ['free_price' => '1234,5']),
        ]), $ctx['user']);
        $this->assertSame('1234.50', $preview->positions[0]->mediaGross);
        $this->assertSame(0, Calculation::query()->count());
    }

    #[Test]
    public function foreign_spot_and_oa_fields_are_rejected_on_free(): void
    {
        EngineProfileRegistry::fakeFreePositionPairReleasedForTests();
        $ctx = $this->seedFreeContext();
        $writer = app(CalculationWriter::class);

        $cases = [
            'plan_rows' => ['plan_rows' => [['hour' => 8, 'day_group' => 'mo_fr']]],
            'time_ranges' => ['time_ranges' => [['start_hour' => 8, 'end_hour_exclusive' => 9, 'day_group' => 'mo_fr', 'spot_count' => 1]]],
            'components' => ['components' => [['role' => 'main', 'length_seconds' => 30]]],
            'basis_tkp' => ['basis_tkp' => '10.0000'],
            'impressions' => ['impressions' => 1000],
            'oa_platform_allocations' => ['oa_platform_allocations' => [['platform_code' => 'x', 'impressions' => 1]]],
            'oa_targeting_selections' => ['oa_targeting_selections' => [['targeting_code' => 'x']]],
            'fixed_price_nn' => ['fixed_price_nn' => '100.00'],
            'pricing_settlement_mode' => ['pricing_settlement_mode' => 'fixed_price'],
            'total_spot_count' => ['total_spot_count' => 5],
        ];

        foreach ($cases as $field => $override) {
            try {
                $writer->preview($this->payload([$this->freePosition($ctx, $override)]), $ctx['user']);
                $this->fail("Feld {$field} muss auf Free abgelehnt werden.");
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey("positions.0.{$field}", $exception->errors(), $field);
            }
        }
    }

    #[Test]
    public function header_only_update_recalculates_from_stored_media_gross(): void
    {
        EngineProfileRegistry::fakeFreePositionPairReleasedForTests();
        $ctx = $this->seedFreeContext();
        $writer = app(CalculationWriter::class);

        $calculation = $writer->create($this->payload([
            $this->freePosition($ctx, ['free_price' => '5000.00', 'position_discount_percent' => '10']),
        ], ['order_discount_percent' => '0', 'ae_enabled' => false, 'campaign' => 'Alt']), $ctx['user']);
        $this->assertSame('4500.00', (string) $calculation->nn_invest);
        $this->assertSame(1, (int) $calculation->lock_version);

        // Reiner Kopf-Update ohne positions-Schlüssel.
        $updated = $writer->update($calculation->fresh(), [
            'lock_version' => $calculation->lock_version,
            'schema_fingerprint' => $writer->payloadFromCalculation($calculation->fresh())['schema_fingerprint'],
            'planning_mode' => 'manual',
            'order_discount_percent' => '0',
            'campaign' => 'Neu',
        ], $ctx['user']);

        $this->assertSame('Neu', $updated->campaign);
        $this->assertSame(2, (int) $updated->lock_version);
        $this->assertSame('5000.00', (string) $updated->media_gross);
        $this->assertSame('4500.00', (string) $updated->nn_invest);
        $position = $updated->positions->firstOrFail();
        $this->assertSame('5000.00', (string) $position->media_gross);
        $this->assertSame('free_position', $position->engine_profile_key);

        // Header-only mit Reload-Payload (free_price aus gespeichertem media_gross) bleibt identisch.
        $payload = $writer->payloadFromCalculation($updated->fresh());
        $payload['lock_version'] = $updated->lock_version;
        $payload['campaign'] = 'Neu2';
        $again = $writer->update($updated->fresh(), $payload, $ctx['user']);
        $this->assertSame('Neu2', $again->campaign);
        $this->assertSame('4500.00', (string) $again->nn_invest);
        $this->assertSame('5000.00', (string) $again->positions->firstOrFail()->media_gross);

        // Position ohne free_price im Payload (Header-/Merge-Pfad): gespeicherter Wert gilt.
        $payload = $writer->payloadFromCalculation($again->fresh());
        $payload['lock_version'] = $again->lock_version;
        unset($payload['positions'][0]['free_price']);
        $payload['order_discount_percent'] = '10';
        $third = $writer->update($again->fresh(), $payload, $ctx['user']);
        $this->assertSame('5000.00', (string) $third->positions->firstOrFail()->media_gross);
        $this->assertSame('4050.00', (string) $third->nn_invest);
    }

    #[Test]
    public function update_free_price_from_5000_to_7000_recomputes(): void
    {
        EngineProfileRegistry::fakeFreePositionPairReleasedForTests();
        $ctx = $this->seedFreeContext();
        $writer = app(CalculationWriter::class);

        $calculation = $writer->create($this->payload([
            $this->freePosition($ctx, ['free_price' => '5000.00', 'position_discount_percent' => '10']),
        ], ['order_discount_percent' => '20', 'ae_enabled' => true]), $ctx['user']);
        $this->assertSame('3060.00', (string) $calculation->nn_invest);
        $positionId = (int) $calculation->positions->firstOrFail()->id;

        $payload = $writer->payloadFromCalculation($calculation->fresh());
        $payload['lock_version'] = $calculation->lock_version;
        $payload['positions'][0]['free_price'] = '7000';
        $updated = $writer->update($calculation->fresh(), $payload, $ctx['user']);

        $position = $updated->positions->firstOrFail();
        $this->assertSame($positionId, (int) $position->id);
        $this->assertSame('7000.00', (string) $position->media_gross);
        $this->assertSame('700.00', (string) $position->position_discount_amount);
        $this->assertSame('1260.00', (string) $position->order_discount_amount);
        // 7000 → 6300 → 5040 → AE 756 → 4284
        $this->assertSame('756.00', (string) $position->ae_amount);
        $this->assertSame('4284.00', (string) $position->nn_invest);
        $this->assertSame('7000.00', (string) $updated->media_gross);
        $this->assertSame('4284.00', (string) $updated->nn_invest);
        $this->assertSame(
            '7000.00',
            $writer->payloadFromCalculation($updated->fresh())['positions'][0]['free_price'],
        );
    }

    #[Test]
    public function position_discounts_are_synced_and_removed_on_update(): void
    {
        EngineProfileRegistry::fakeFreePositionPairReleasedForTests();
        $ctx = $this->seedFreeContext();
        $writer = app(CalculationWriter::class);

        $calculation = $writer->create($this->payload([
            $this->freePosition($ctx, [
                'position_discounts' => [['type' => 'quantity', 'percent' => '10']],
            ]),
        ]), $ctx['user']);
        $this->assertCount(1, $calculation->positions->firstOrFail()->discounts);

        $payload = $writer->payloadFromCalculation($calculation->fresh());
        $payload['lock_version'] = $calculation->lock_version;
        $payload['positions'][0]['position_discounts'] = [];
        $payload['positions'][0]['position_discount_percent'] = '0';
        $updated = $writer->update($calculation->fresh(), $payload, $ctx['user']);

        $this->assertCount(0, $updated->positions->firstOrFail()->discounts()->get());
        $this->assertSame('5000.00', (string) $updated->nn_invest);
    }

    #[Test]
    public function method_switch_from_and_to_free_position_is_blocked(): void
    {
        EngineProfileRegistry::fakeFreePositionPairReleasedForTests();
        $ctx = $this->seedFreeContext();
        $spot = $this->createFreeSpotFixture();
        $writer = app(CalculationWriter::class);

        $calculation = $writer->create($this->payload([
            $this->freeSpotPosition($spot),
            $this->freePosition($ctx),
        ]), $ctx['user']);
        $spotPosition = $calculation->positions->firstWhere('engine_profile_key', 'spot_classic');
        $freePosition = $calculation->positions->firstWhere('engine_profile_key', 'free_position');

        // Free → Spot (average)
        $payload = $writer->payloadFromCalculation($calculation->fresh());
        $payload['lock_version'] = $calculation->lock_version;
        foreach ($payload['positions'] as $i => $row) {
            if ((int) $row['id'] === (int) $freePosition->id) {
                $payload['positions'][$i]['spot_method'] = 'average';
                $payload['positions'][$i]['calculation_method_key'] = 'average';
                $payload['positions'][$i]['length_seconds'] = 30;
                $payload['positions'][$i]['total_spot_count'] = 1;
                $payload['positions'][$i]['plan_rows'] = [['hour' => 8, 'day_group' => 'mo_fr']];
            }
        }
        try {
            $writer->update($calculation->fresh(), $payload, $ctx['user']);
            $this->fail('Free → Spot muss blockiert werden.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString(
                'Freie Preisposition',
                (string) collect($exception->errors())->flatten()->first(),
            );
        }

        // Spot → Free
        $payload = $writer->payloadFromCalculation($calculation->fresh());
        $payload['lock_version'] = $calculation->lock_version;
        foreach ($payload['positions'] as $i => $row) {
            if ((int) $row['id'] === (int) $spotPosition->id) {
                $payload['positions'][$i]['spot_method'] = 'free_position';
                $payload['positions'][$i]['calculation_method_key'] = 'free_position';
                $payload['positions'][$i]['free_price'] = '100.00';
            }
        }
        try {
            $writer->update($calculation->fresh(), $payload, $ctx['user']);
            $this->fail('Spot → Free muss blockiert werden.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('positions.0', $exception->errors());
        }

        // Free → TKP
        $payload = $writer->payloadFromCalculation($calculation->fresh());
        $payload['lock_version'] = $calculation->lock_version;
        foreach ($payload['positions'] as $i => $row) {
            if ((int) $row['id'] === (int) $freePosition->id) {
                $payload['positions'][$i]['spot_method'] = 'tkp';
                $payload['positions'][$i]['calculation_method_key'] = 'tkp';
            }
        }
        try {
            $writer->update($calculation->fresh(), $payload, $ctx['user']);
            $this->fail('Free → TKP muss blockiert werden.');
        } catch (ValidationException $exception) {
            $this->assertNotEmpty($exception->errors());
        }

        $fresh = $calculation->fresh('positions');
        $this->assertSame(1, (int) $fresh->lock_version);
        $this->assertSame(
            'free_position',
            $fresh->positions->firstWhere('id', $freePosition->id)->engine_profile_key,
        );
    }

    #[Test]
    public function medium_without_free_position_assignment_or_kind_is_rejected(): void
    {
        EngineProfileRegistry::fakeFreePositionPairReleasedForTests();
        $ctx = $this->seedFreeContext();
        $writer = app(CalculationWriter::class);

        $spotMedium = AdvertisingMedium::query()->where('code', 'spot_classic')->firstOrFail();
        try {
            $writer->preview($this->payload([
                $this->freePosition($ctx, ['advertising_medium_id' => (int) $spotMedium->id]),
            ]), $ctx['user']);
            $this->fail('Medium ohne free_position-Zuordnung muss abgelehnt werden.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('positions.0.advertising_medium_id', $exception->errors());
        }

        AdvertisingMediumCalculationMethod::query()
            ->where('advertising_medium_id', $ctx['medium']->id)
            ->update(['is_active' => false]);
        $this->expectException(ValidationException::class);
        $writer->preview($this->payload([$this->freePosition($ctx)]), $ctx['user']);
    }

    #[Test]
    public function budget_apply_and_propose_reject_free_positions(): void
    {
        EngineProfileRegistry::fakeFreePositionPairReleasedForTests();
        $ctx = $this->seedFreeContext();
        $writer = app(CalculationWriter::class);

        $calculation = $writer->create($this->payload([$this->freePosition($ctx)]), $ctx['user']);
        $before = (string) $calculation->nn_invest;

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
            $this->fail('Budget-Apply auf Free-Kalkulation muss abgelehnt werden.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('positions', $exception->errors());
        }
        $fresh = $calculation->fresh();
        $this->assertSame($before, (string) $fresh->nn_invest);
        $this->assertSame(1, (int) $fresh->lock_version);

        // Propose mit Free im Bestand (ohne Free im Payload).
        try {
            app(BudgetSpotProposalService::class)->propose([
                'target_budget_nn' => '5000',
                'positions' => [],
            ], $fresh->load('positions'));
            $this->fail('Budget-Propose mit Free-Bestand muss abgelehnt werden.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('positions', $exception->errors());
        }

        // Propose mit Free im Payload.
        $this->expectException(ValidationException::class);
        app(BudgetSpotProposalService::class)->propose([
            'target_budget_nn' => '5000',
            'positions' => [['calculation_method_key' => 'free_position']],
        ]);
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
}
