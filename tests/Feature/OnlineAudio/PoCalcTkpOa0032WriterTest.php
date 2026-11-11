<?php

namespace Tests\Feature\OnlineAudio;

use App\Enums\DayGroup;
use App\Enums\OaTargetingCategory;
use App\Enums\PriceListStatus;
use App\Enums\PricingSettlementMode;
use App\Enums\Role;
use App\Enums\SpecialApprovalReasonCode;
use App\Models\AdvertisingMedium;
use App\Models\Calculation;
use App\Models\CalculationPosition;
use App\Models\DispoOrderPosition;
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
use App\Services\DispoOrder\DispoOrderWriter;
use App\Support\Calculation\OaTkpWizardCapability;
use App\Support\OnlineAudio\OaTkpCoreMedia;
use App\Support\PriceList\PriceListCalendar;
use Database\Seeders\InitialCatalogMatCoreSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * PO-CALC-TKP-OA003-2: Backend-Verdrahtung OA-Festpreis (Writer + Preview + Persistenz + Dispo-Snapshot).
 *
 * Der Rechenkern selbst ist in OA003-1 getestet; hier geht es um die Writer-Wiring-Garantien.
 */
class PoCalcTkpOa0032WriterTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function preview_fixed_above_min_midroll(): void
    {
        $ctx = $this->seedContext();
        $preview = app(CalculationWriter::class)->preview(
            $this->payload([$this->fixedPosition($ctx, '5000.00')]),
            $ctx['user'],
        );

        $position = $preview->positions[0];
        $tkp = $position->methodResult['tkp'];

        $this->assertSame(PricingSettlementMode::FixedPrice, $position->pricingSettlementMode);
        $this->assertSame('fixed_price', $tkp['pricing_settlement_mode']);
        $this->assertSame('5000.00', $position->fixedPriceNn);
        $this->assertSame('5000.00', $tkp['fixed_price_nn']);
        $this->assertSame('50.0000', $tkp['basis_tkp']);
        $this->assertSame('50.0000', $tkp['final_tkp']);
        $this->assertSame('8000.00', $position->mediaGross);
        $this->assertSame('5000.00', $position->nnInvest);
        $this->assertFalse($tkp['below_minimum_tkp']);

        $this->assertSame('8000.00', $preview->mediaGross);
        $this->assertSame('5000.00', $preview->nnInvest);
        $this->assertTrue($preview->requiresSpecialApproval);
        $codes = array_column($preview->specialApprovalReasons, 'code');
        $this->assertSame([SpecialApprovalReasonCode::OaTkpFixedPrice->value], $codes);
        $this->assertSame('5000.00', $preview->specialApprovalReasons[0]['fixed_price_nn']);
    }

    #[Test]
    public function preview_fixed_with_dmp_targeting(): void
    {
        $ctx = $this->seedContext();
        $dmp = $this->createTargeting('dmp_fixed', '25.0000', '5.0000');

        $preview = app(CalculationWriter::class)->preview(
            $this->payload([$this->fixedPosition($ctx, '5000.00', [
                'oa_targeting_selections' => [['oa_targeting_definition_id' => (int) $dmp->id]],
            ])]),
            $ctx['user'],
        );

        $position = $preview->positions[0];
        $tkp = $position->methodResult['tkp'];

        // Referenz-TKP = 80 + 25 = 105 → 100k Impressions = 10.500,00 Brutto.
        $this->assertSame('10500.00', $position->mediaGross);
        $this->assertSame('105.0000', $tkp['reference_tkp']);
        // Effektives Minimum = 20 + 5.
        $this->assertSame('25.0000', $tkp['minimum_tkp']);
        $this->assertSame('5.0000', $tkp['targeting_minimum_surcharge_total']);
        // Fixed: kein Targeting-Aufschlag im Final-TKP.
        $this->assertSame('0.0000', $tkp['targeting_surcharge_total']);
        $this->assertSame('50.0000', $tkp['final_tkp']);
        $this->assertSame('50.0000', $tkp['basis_tkp']);
        $this->assertFalse($tkp['below_minimum_tkp']);
        $this->assertSame('5000.00', $position->nnInvest);
    }

    #[Test]
    public function preview_fixed_under_min_with_reasons(): void
    {
        $ctx = $this->seedContext();
        $dmp = $this->createTargeting('dmp_fixed', '25.0000', '5.0000');

        $preview = app(CalculationWriter::class)->preview(
            $this->payload([$this->fixedPosition($ctx, '2300.00', [
                'oa_targeting_selections' => [['oa_targeting_definition_id' => (int) $dmp->id]],
            ])]),
            $ctx['user'],
        );

        $tkp = $preview->positions[0]->methodResult['tkp'];
        $this->assertSame('23.0000', $tkp['final_tkp']);
        $this->assertSame('25.0000', $tkp['minimum_tkp']);
        $this->assertTrue($tkp['below_minimum_tkp']);
        $this->assertTrue($preview->requiresSpecialApproval);
        $this->assertSame(
            [
                SpecialApprovalReasonCode::OaTkpFixedPrice->value,
                SpecialApprovalReasonCode::OaTkpBasisBelowMinimum->value,
            ],
            array_column($preview->specialApprovalReasons, 'code'),
        );
    }

    #[Test]
    public function preview_and_persist_fixed_ae_15(): void
    {
        $ctx = $this->seedContext();
        $writer = app(CalculationWriter::class);
        $payload = $this->payload(
            [$this->fixedPosition($ctx, '5000.00', ['ae_percent' => '15'])],
            ['ae_enabled' => true],
        );

        $preview = $writer->preview($payload, $ctx['user']);
        $previewPosition = $preview->positions[0];
        $this->assertSame('5882.35', $previewPosition->afterOrderDiscount);
        $this->assertSame('882.35', $previewPosition->aeAmount);
        $this->assertSame('5000.00', $previewPosition->nnInvest);
        $this->assertSame('882.35', $preview->aeTotal);
        $this->assertSame('5000.00', $preview->nnInvest);

        $calculation = $writer->create($payload, $ctx['user']);
        $this->assertTrue((bool) $calculation->ae_enabled);
        $this->assertSame('882.35', (string) $calculation->ae_total);
        $this->assertSame('5000.00', (string) $calculation->nn_invest);
        $position = $calculation->positions->firstOrFail();
        $this->assertSame('882.35', (string) $position->ae_amount);
        $this->assertSame('5000.00', (string) $position->nn_invest);
        $this->assertSame('15.00', number_format((float) $position->ae_percent, 2, '.', ''));

        $reloaded = Calculation::query()->findOrFail($calculation->id);
        $reloadedPosition = $reloaded->positions->firstOrFail();
        $this->assertSame('882.35', (string) $reloaded->ae_total);
        $this->assertSame('5000.00', (string) $reloaded->nn_invest);
        $this->assertSame('882.35', (string) $reloadedPosition->ae_amount);
        $this->assertSame('5000.00', (string) $reloadedPosition->nn_invest);
        $this->assertSame('5000.00', (string) $reloadedPosition->fixed_price_nn);
    }

    #[Test]
    public function fixed_ignores_global_order_discount(): void
    {
        $ctx = $this->seedContext();
        $writer = app(CalculationWriter::class);
        $payload = $this->payload(
            [$this->fixedPosition($ctx, '5000.00')],
            ['order_discount_percent' => '20'],
        );

        $preview = $writer->preview($payload, $ctx['user']);
        $this->assertSame('0.00', $preview->positions[0]->orderDiscountAmount);
        $this->assertSame('5000.00', $preview->positions[0]->nnInvest);
        $this->assertSame('0.00', $preview->orderDiscountTotal);
        $this->assertSame('5000.00', $preview->nnInvest);
        $codes = array_column($preview->specialApprovalReasons, 'code');
        $this->assertNotContains(SpecialApprovalReasonCode::OrderDiscountExceedsLimit->value, $codes);
        $this->assertSame([SpecialApprovalReasonCode::OaTkpFixedPrice->value], $codes);

        $calculation = $writer->create($payload, $ctx['user']);
        $position = $calculation->positions->firstOrFail();
        $this->assertSame('0.00', (string) $position->order_discount_amount);
        $this->assertSame('5000.00', (string) $position->nn_invest);
        $this->assertSame('0.00', (string) $calculation->order_discount_total);
        $this->assertSame('5000.00', (string) $calculation->nn_invest);
        $persistedCodes = array_column($calculation->special_approval_reasons ?? [], 'code');
        $this->assertNotContains(SpecialApprovalReasonCode::OrderDiscountExceedsLimit->value, $persistedCodes);
        $this->assertContains(SpecialApprovalReasonCode::OaTkpFixedPrice->value, $persistedCodes);
    }

    #[Test]
    public function mixed_spot_and_oa_fixed_order_discount(): void
    {
        $ctx = $this->seedContext();
        $writer = app(CalculationWriter::class);
        $spot = $this->createSpotFixture();
        $payload = $this->payload(
            [$this->spotPosition($spot), $this->fixedPosition($ctx, '5000.00')],
            ['order_discount_percent' => '20'],
        );

        // Referenz: Spot allein mit 20 % Auftragsrabatt.
        $spotOnly = $writer->preview($this->payload(
            [$this->spotPosition($spot)],
            ['order_discount_percent' => '20'],
        ), $ctx['user']);
        $spotOrderDiscount = $spotOnly->orderDiscountTotal;
        $this->assertSame('300.00', $spotOnly->mediaGross);
        $this->assertSame('60.00', $spotOrderDiscount);

        $preview = $writer->preview($payload, $ctx['user']);
        $spotResult = $preview->positions[0];
        $oaResult = $preview->positions[1];

        $this->assertSame($spotOrderDiscount, $spotResult->orderDiscountAmount);
        $this->assertSame('0.00', $oaResult->orderDiscountAmount);
        $this->assertSame('5000.00', $oaResult->nnInvest);
        $this->assertSame($spotOrderDiscount, $preview->orderDiscountTotal);

        $expectedNn = bcadd($spotResult->nnInvest, '5000.00', 2);
        $this->assertSame($expectedNn, $preview->nnInvest);
        $this->assertSame(bcadd('300.00', '8000.00', 2), $preview->mediaGross);

        // Breakdown-Basis enthält kein OA-Fixed-Referenzbrutto (8000,00).
        $this->assertCount(1, $preview->orderDiscounts);
        $this->assertSame($preview->orderDiscountTotal, $preview->orderDiscounts[0]['amount']);

        $calculation = $writer->create($payload, $ctx['user']);
        $spotPosition = $calculation->positions->first(fn ($p): bool => $p->engine_profile_key === 'spot_classic');
        $oaPosition = $calculation->positions->first(fn ($p): bool => $p->engine_profile_key === 'oa_tkp');
        $this->assertSame($spotOrderDiscount, (string) $spotPosition->order_discount_amount);
        $this->assertSame('0.00', (string) $oaPosition->order_discount_amount);
        $this->assertSame('5000.00', (string) $oaPosition->nn_invest);
        $this->assertSame($spotOrderDiscount, (string) $calculation->order_discount_total);
        $this->assertSame(
            bcadd((string) $spotPosition->nn_invest, '5000.00', 2),
            (string) $calculation->nn_invest,
        );
    }

    #[Test]
    public function save_reload_roundtrip_fixed(): void
    {
        $ctx = $this->seedContext();
        $writer = app(CalculationWriter::class);
        $dmp = $this->createTargeting('dmp_fixed', '25.0000', '5.0000');
        $payload = $this->payload(
            [$this->fixedPosition($ctx, '5000.00', [
                'oa_targeting_selections' => [['oa_targeting_definition_id' => (int) $dmp->id]],
                'ae_percent' => '15',
            ])],
            ['ae_enabled' => true],
        );

        $preview = $writer->preview($payload, $ctx['user']);
        $calculation = $writer->create($payload, $ctx['user']);
        $reloaded = Calculation::query()->findOrFail($calculation->id);
        $position = $reloaded->positions->firstOrFail();

        // Preview == Persistenz.
        $this->assertSame($preview->mediaGross, (string) $reloaded->media_gross);
        $this->assertSame($preview->nnInvest, (string) $reloaded->nn_invest);
        $this->assertSame($preview->aeTotal, (string) $reloaded->ae_total);

        $this->assertSame(PricingSettlementMode::FixedPrice, $position->pricing_settlement_mode);
        $this->assertSame('5000.00', (string) $position->fixed_price_nn);
        $this->assertSame('50.0000', number_format((float) $position->basis_tkp, 4, '.', ''));
        $this->assertSame('50.0000', number_format((float) $position->final_tkp, 4, '.', ''));
        $this->assertSame('10500.00', (string) $position->media_gross);
        $this->assertSame((int) $ctx['list']->id, (int) $position->oa_tkp_price_list_id);
        $this->assertSame('25.0000', number_format((float) $position->minimum_tkp_snapshot, 4, '.', ''));
        $this->assertSame('80.0000', number_format((float) $position->list_tkp_snapshot, 4, '.', ''));
        $this->assertSame('882.35', (string) $position->ae_amount);

        $this->assertCount(1, $position->oaTargetingSelections);
        $selection = $position->oaTargetingSelections->firstOrFail();
        $this->assertSame((int) $dmp->id, (int) $selection->oa_targeting_definition_id);
        $this->assertSame('25.0000', number_format((float) $selection->tkp_surcharge_snapshot, 4, '.', ''));
        $this->assertSame('5.0000', number_format((float) $selection->minimum_tkp_surcharge_snapshot, 4, '.', ''));

        $this->assertTrue((bool) $reloaded->requires_special_approval);
        $codes = array_column($reloaded->special_approval_reasons ?? [], 'code');
        $this->assertSame([SpecialApprovalReasonCode::OaTkpFixedPrice->value], $codes);

        // Freeze.
        $this->assertSame('oa_tkp', $position->engine_profile_key);
        $this->assertSame('tkp', $position->calculation_method_key);
        $this->assertSame('v1', $position->algorithm_version);
        $this->assertNotNull($position->effective_configuration_snapshot_id);

        // payloadFromCalculation trägt den Settlement-Zustand.
        $payloadBack = $writer->payloadFromCalculation($reloaded);
        $this->assertSame('fixed_price', $payloadBack['positions'][0]['pricing_settlement_mode']);
        $this->assertSame('5000.00', $payloadBack['positions'][0]['fixed_price_nn']);
        $this->assertSame((int) $ctx['list']->id, $payloadBack['positions'][0]['expected_oa_tkp_price_list_id']);

        // Reload-Roundtrip ohne Änderung: gleiche Beträge.
        $payloadBack['lock_version'] = $reloaded->lock_version;
        $updated = $writer->update($reloaded, $payloadBack, $ctx['user']);
        $this->assertSame('5000.00', (string) $updated->nn_invest);
        $this->assertSame('10500.00', (string) $updated->media_gross);
        $this->assertSame(PricingSettlementMode::FixedPrice, $updated->positions->firstOrFail()->pricing_settlement_mode);
    }

    #[Test]
    public function normal_to_fixed_and_fixed_to_normal(): void
    {
        $ctx = $this->seedContext();
        $writer = app(CalculationWriter::class);

        $calculation = $writer->create($this->payload([
            $this->oaPosition($ctx, [
                'basis_tkp' => '60.0000',
                'position_discounts' => [['type' => 'quantity', 'percent' => '10']],
            ]),
        ]), $ctx['user']);
        $position = $calculation->positions->firstOrFail();
        $this->assertSame(PricingSettlementMode::Normal, $position->pricing_settlement_mode);
        $this->assertNull($position->fixed_price_nn);
        $this->assertSame(1, $position->discounts()->count());
        $this->assertFalse((bool) $calculation->requires_special_approval);
        $this->assertNotContains(
            SpecialApprovalReasonCode::OaTkpFixedPrice->value,
            array_column($calculation->special_approval_reasons ?? [], 'code'),
        );

        // normal → fixed
        $payload = $writer->payloadFromCalculation($calculation->fresh());
        $payload['lock_version'] = $calculation->lock_version;
        $payload['positions'][0]['pricing_settlement_mode'] = 'fixed_price';
        $payload['positions'][0]['fixed_price_nn'] = '5000.00';
        $fixed = $writer->update($calculation->fresh(), $payload, $ctx['user']);
        $fixedPosition = $fixed->positions->firstOrFail();

        $this->assertSame(PricingSettlementMode::FixedPrice, $fixedPosition->pricing_settlement_mode);
        $this->assertSame('5000.00', (string) $fixedPosition->fixed_price_nn);
        $this->assertSame('5000.00', (string) $fixedPosition->nn_invest);
        $this->assertSame('8000.00', (string) $fixedPosition->media_gross);
        $this->assertSame(0, $fixedPosition->discounts()->count(), 'Positionsrabatte werden bei Fixed geleert.');
        $this->assertSame('0.00', (string) $fixedPosition->position_discount_amount);
        $this->assertTrue((bool) $fixed->requires_special_approval);
        $this->assertContains(
            SpecialApprovalReasonCode::OaTkpFixedPrice->value,
            array_column($fixed->special_approval_reasons ?? [], 'code'),
        );

        // fixed → normal
        $payload = $writer->payloadFromCalculation($fixed->fresh());
        $payload['lock_version'] = $fixed->lock_version;
        $payload['positions'][0]['pricing_settlement_mode'] = 'normal';
        $payload['positions'][0]['fixed_price_nn'] = null;
        $payload['positions'][0]['basis_tkp'] = '60.0000';
        $normal = $writer->update($fixed->fresh(), $payload, $ctx['user']);
        $normalPosition = $normal->positions->firstOrFail();

        $this->assertSame(PricingSettlementMode::Normal, $normalPosition->pricing_settlement_mode);
        $this->assertNull($normalPosition->fixed_price_nn);
        $this->assertSame('6000.00', (string) $normalPosition->media_gross);
        $this->assertFalse((bool) $normal->requires_special_approval);
        $this->assertNotContains(
            SpecialApprovalReasonCode::OaTkpFixedPrice->value,
            array_column($normal->special_approval_reasons ?? [], 'code'),
        );
    }

    #[Test]
    public function header_only_update_preserves_fixed(): void
    {
        $ctx = $this->seedContext();
        $writer = app(CalculationWriter::class);
        $calculation = $writer->create($this->payload(
            [$this->fixedPosition($ctx, '5000.00')],
            ['campaign' => 'Alte Kampagne', 'product_title' => 'Alt-Produkt'],
        ), $ctx['user']);

        $payload = $writer->payloadFromCalculation($calculation->fresh());
        $payload['lock_version'] = $calculation->lock_version;
        // Hinweis: customer_name läuft über die CRM-Bindung (Konto-ID) und ist kein freier Header-Text.
        $payload['campaign'] = 'Header Only Kampagne';
        $payload['product_title'] = 'Neu-Produkt';
        $updated = $writer->update($calculation->fresh(), $payload, $ctx['user']);

        $this->assertSame('Header Only Kampagne', $updated->campaign);
        $this->assertSame('Neu-Produkt', $updated->product_title);
        $position = $updated->positions->firstOrFail();
        $this->assertSame(PricingSettlementMode::FixedPrice, $position->pricing_settlement_mode);
        $this->assertSame('5000.00', (string) $position->fixed_price_nn);
        $this->assertSame('5000.00', (string) $position->nn_invest);
        $this->assertSame('8000.00', (string) $position->media_gross);
        $this->assertSame('5000.00', (string) $updated->nn_invest);
        $this->assertTrue((bool) $updated->requires_special_approval);
        $this->assertContains(
            SpecialApprovalReasonCode::OaTkpFixedPrice->value,
            array_column($updated->special_approval_reasons ?? [], 'code'),
        );
    }

    #[Test]
    public function dispo_snapshot_fixed_after_special_approval(): void
    {
        // Der DispoOrderWriter gated nicht auf requires_special_approval (Freigabe läuft erst
        // über DispoOrderApprovalService im Dispo-Lifecycle) – wie bei Under-Min in 1d3a.
        $ctx = $this->seedContext();
        $dmp = $this->createTargeting('dmp_fixed', '25.0000', '5.0000');
        $calculation = app(CalculationWriter::class)->create($this->payload([
            $this->fixedPosition($ctx, '5000.00', [
                'oa_targeting_selections' => [['oa_targeting_definition_id' => (int) $dmp->id]],
            ]),
        ]), $ctx['user']);
        $this->assertTrue((bool) $calculation->requires_special_approval);

        $oa = $calculation->positions->firstOrFail();
        $order = app(DispoOrderWriter::class)
            ->createFromCalculation($calculation, [(int) $oa->id], $ctx['user'])
            ->order;

        $this->assertTrue((bool) $order->requires_special_approval);

        $snap = $order->positions()
            ->where('line_role', DispoOrderPosition::LINE_ROLE_MEDIA)
            ->firstOrFail();

        $this->assertSame('fixed_price', $snap->pricing_settlement_mode->value);
        $this->assertSame('5000.00', (string) $snap->fixed_price_nn);
        $this->assertSame('oa_tkp', $snap->engine_profile_key);
        $this->assertSame('tkp', $snap->calculation_method_key);
        $this->assertSame('v1', $snap->algorithm_version);
        $this->assertSame(100_000, (int) $snap->impressions);
        $this->assertSame('50.0000', (string) $snap->basis_tkp);
        $this->assertSame('50.0000', (string) $snap->final_tkp);
        $this->assertSame('80.0000', (string) $snap->list_tkp_snapshot);
        $this->assertSame('25.0000', (string) $snap->minimum_tkp_snapshot);
        $this->assertSame('0.0000', (string) $snap->targeting_surcharge_total);
        $this->assertFalse((bool) $snap->below_minimum_tkp);
        $this->assertSame('10500.00', (string) $snap->media_gross);
        $this->assertSame('0.00', (string) $snap->order_discount_amount);
        $this->assertSame('5000.00', (string) $snap->nn_invest);
        $this->assertSame((int) $oa->oa_tkp_price_list_id, (int) $snap->oa_tkp_price_list_id);
        $this->assertCount(1, $snap->oa_targeting_selections_snapshot);
        $this->assertSame('dmp_fixed', $snap->oa_targeting_selections_snapshot[0]['targeting_code']);
        $this->assertSame('25.0000', $snap->oa_targeting_selections_snapshot[0]['tkp_surcharge_snapshot']);
        $this->assertCount(1, $snap->oa_platform_allocations_snapshot);
    }

    #[Test]
    public function five_core_media_fixed_preview(): void
    {
        $ctx = $this->seedContext();
        $writer = app(CalculationWriter::class);

        foreach (OaTkpCoreMedia::codes() as $code) {
            $medium = AdvertisingMedium::query()->where('code', $code)->firstOrFail();
            InventoryMediumRule::query()->firstOrCreate(
                [
                    'inventory_id' => (int) $ctx['inventory']->id,
                    'advertising_medium_id' => (int) $medium->id,
                ],
                ['is_active' => true],
            );
            OaTkpPriceListItem::query()->firstOrCreate(
                [
                    'oa_tkp_price_list_id' => (int) $ctx['list']->id,
                    'advertising_medium_id' => (int) $medium->id,
                    'inventory_id' => (int) $ctx['inventory']->id,
                ],
                ['list_tkp' => '80.0000', 'minimum_tkp' => '20.0000'],
            );

            $preview = $writer->preview($this->payload([
                $this->fixedPosition($ctx, '5000.00', ['advertising_medium_id' => (int) $medium->id]),
            ]), $ctx['user']);

            $position = $preview->positions[0];
            $this->assertSame(PricingSettlementMode::FixedPrice, $position->pricingSettlementMode, $code);
            $this->assertSame('5000.00', $position->nnInvest, $code);
            $this->assertSame('8000.00', $position->mediaGross, $code);
            $this->assertSame('50.0000', $position->methodResult['tkp']['final_tkp'], $code);
            $this->assertTrue($preview->requiresSpecialApproval, $code);
        }
    }

    #[Test]
    public function native_ad_still_out(): void
    {
        config([OaTkpWizardCapability::CONFIG_KEY => true]);
        $ctx = $this->seedContext();
        $native = AdvertisingMedium::query()->where('code', 'native_ad')->firstOrFail();
        InventoryMediumRule::query()->firstOrCreate(
            [
                'inventory_id' => (int) $ctx['inventory']->id,
                'advertising_medium_id' => (int) $native->id,
            ],
            ['is_active' => true],
        );
        OaTkpPriceListItem::query()->create([
            'oa_tkp_price_list_id' => (int) $ctx['list']->id,
            'advertising_medium_id' => (int) $native->id,
            'inventory_id' => (int) $ctx['inventory']->id,
            'list_tkp' => '80.0000',
            'minimum_tkp' => '20.0000',
        ]);

        $payload = $this->payload([
            $this->fixedPosition($ctx, '5000.00', ['advertising_medium_id' => (int) $native->id]),
        ]);

        $this->actingAs($ctx['user'])
            ->postJson(route('calculations.store'), $payload)
            ->assertStatus(422);
        $this->assertSame(0, Calculation::query()->count());
        $this->assertSame(0, CalculationPosition::query()->count());
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
            'basis_tkp' => '50.0000',
            'oa_platform_allocations' => [
                ['oa_platform_id' => (int) $ctx['platform']->id, 'impressions' => 100_000],
            ],
            'oa_targeting_selections' => [],
            'position_discount_percent' => '0',
            'ae_percent' => '15',
        ], $override);
    }

    /**
     * @param  array<string, mixed>  $ctx
     * @param  array<string, mixed>  $override
     * @return array<string, mixed>
     */
    private function fixedPosition(array $ctx, string $fixedPriceNn, array $override = []): array
    {
        $position = $this->oaPosition($ctx, array_merge([
            'pricing_settlement_mode' => 'fixed_price',
            'fixed_price_nn' => $fixedPriceNn,
        ], $override));
        unset($position['basis_tkp']);

        return $position;
    }

    private function createTargeting(string $code, string $surcharge, string $minimumSurcharge): OaTargetingDefinition
    {
        return OaTargetingDefinition::query()->create([
            'code' => $code,
            'name' => strtoupper($code),
            'category' => OaTargetingCategory::Dmp,
            'tkp_surcharge' => $surcharge,
            'minimum_tkp_surcharge' => $minimumSurcharge,
            'revision_number' => 1,
            'is_active' => true,
        ]);
    }

    /**
     * Spot-Fixture: 30 s × 10 Spots × 1,00 €/s = 300,00 Brutto.
     *
     * @return array{medium: AdvertisingMedium, inventory: Inventory}
     */
    private function createSpotFixture(): array
    {
        $medium = AdvertisingMedium::query()->where('code', 'spot_classic')->firstOrFail();
        $inventory = Inventory::factory()->create(['name' => 'OA0032 Spot Sender', 'code' => 'OA0032SPOT']);
        InventoryMediumRule::factory()->create([
            'inventory_id' => $inventory->id,
            'advertising_medium_id' => $medium->id,
        ]);
        $list = PriceList::factory()->create([
            'inventory_id' => $inventory->id,
            'status' => PriceListStatus::Active,
            'year' => PriceListCalendar::currentYear(),
            'version' => 'OA0032-SPOT',
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
     * Kontext: Medium midroll_music_environment, Liste 80 / Minimum 20, 100k Impressions → Referenzbrutto 8.000,00.
     *
     * @return array{
     *     user: User,
     *     medium: AdvertisingMedium,
     *     inventory: Inventory,
     *     list: OaTkpPriceList,
     *     platform: OaPlatform
     * }
     */
    private function seedContext(): array
    {
        $this->seed(InitialCatalogMatCoreSeeder::class);
        $user = User::factory()->role(Role::Sales)->create(['discount_limit_percent' => '10']);
        $medium = AdvertisingMedium::query()->where('code', 'midroll_music_environment')->firstOrFail();
        $inventory = Inventory::query()->where('code', 'inv_more_kombi_online_audio')->firstOrFail();
        InventoryMediumRule::query()->firstOrCreate(
            [
                'inventory_id' => (int) $inventory->id,
                'advertising_medium_id' => (int) $medium->id,
            ],
            ['is_active' => true],
        );
        $list = $this->createActivePriceList(
            PriceListCalendar::currentYear(),
            1,
            (int) $medium->id,
            (int) $inventory->id,
            '80.0000',
            '20.0000',
        );
        $platform = OaPlatform::query()->create([
            'code' => 'fixture_oa0032_plat',
            'name' => 'Fixture OA003-2 Platform',
            'is_active' => true,
            'sort' => 1,
            'is_adserver_relevant' => false,
        ]);

        return compact('user', 'medium', 'inventory', 'list', 'platform');
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
            'name' => "Fixture OA003-2 {$year}.{$revision}",
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
