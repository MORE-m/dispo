<?php

namespace Tests\Feature\Calculation\FreePosition;

use App\Models\AdvertisingMedium;
use App\Models\Calculation;
use App\Models\CalculationPosition;
use App\Models\CalculationPositionProductionLine;
use App\Models\DispoOrder;
use App\Models\DispoOrderPosition;
use App\Models\Inventory;
use App\Models\InventoryMediumRule;
use App\Models\User;
use App\Services\Calculation\CalculationWriter;
use App\Services\DispoOrder\DispoOrderPositionAdoptionService;
use App\Services\DispoOrder\DispoOrderSnapshotMapper;
use App\Services\DispoOrder\DispoOrderWriter;
use App\Support\Calculation\EngineProfileRegistry;
use App\Support\DispoOrder\DispoPositionMethodClassifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesFreePositionCatalog;
use Tests\TestCase;

/**
 * NATIVE-4: Free Position wird korrekt klassifiziert und in die Disposition eingefroren.
 *
 * Das Pair bleibt im CATALOG planned/unreleased; Release wird nur über den Test-Fake simuliert.
 * Kein Medium-Code-Branching: Erkennung allein über das Freeze-Paar.
 */
class FreePositionDispoIntegrationTest extends TestCase
{
    use CreatesFreePositionCatalog;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        EngineProfileRegistry::clearFreePositionPairFakeForTests();
        parent::tearDown();
    }

    #[Test]
    public function main_case_freezes_exact_money_and_neutral_snapshot(): void
    {
        $ctx = $this->releasedFreeContext();
        $calc = $this->createCalc($ctx, [
            $this->freePosition($ctx, ['position_discount_percent' => '10']),
        ], ['order_discount_percent' => '20', 'ae_enabled' => true]);
        $free = $calc->positions->firstOrFail();

        $order = app(DispoOrderWriter::class)
            ->createFromCalculation($calc, [(int) $free->id], $ctx['user'])
            ->order;

        $snap = $order->positions()->firstOrFail();
        $this->assertSame(DispoOrderPosition::LINE_ROLE_MEDIA, $snap->line_role);
        $this->assertSame('free_position', $snap->engine_profile_key);
        $this->assertSame('free_position', $snap->calculation_method_key);
        $this->assertSame('Freie Preisposition', $snap->calculation_method_name);
        $this->assertSame('v1', $snap->algorithm_version);
        $this->assertSame('free_position', $snap->kind->value);
        $this->assertSame('free_position', $snap->spot_method->value);
        $this->assertSame(
            DispoPositionMethodClassifier::KIND_FREE_POSITION,
            DispoPositionMethodClassifier::classifyPosition($snap),
        );

        $this->assertSame('5000.00', (string) $snap->media_gross);
        $this->assertSame('500.00', (string) $snap->position_discount_amount);
        $this->assertSame('900.00', (string) $snap->order_discount_amount);
        $this->assertSame('540.00', (string) $snap->ae_amount);
        $this->assertSame('3060.00', (string) $snap->nn_invest);
        $this->assertSame('10.0000', (string) $snap->position_discount_percent);
        $this->assertSame('15.0000', (string) $snap->ae_percent);
        $this->assertTrue((bool) $snap->is_discountable);
        $this->assertTrue((bool) $snap->is_ae_eligible);
        $this->assertSame('normal', $snap->pricing_settlement_mode->value);
        $this->assertNull($snap->fixed_price_nn);

        // Keine Spot-/OA-/TKP-Scheinwerte.
        $this->assertSame(0, (int) $snap->length_seconds);
        $this->assertSame(0, (int) $snap->total_spot_count);
        $this->assertNull($snap->impressions);
        $this->assertNull($snap->basis_tkp);
        $this->assertNull($snap->list_tkp_snapshot);
        $this->assertNull($snap->minimum_tkp_snapshot);
        $this->assertNull($snap->final_tkp);
        $this->assertNull($snap->targeting_surcharge_total);
        $this->assertNull($snap->price_list_id);
        $this->assertNull($snap->oa_tkp_price_list_id);
        $this->assertSame([], $snap->plan_rows_snapshot);
        $this->assertSame([], $snap->time_ranges_snapshot);
        $this->assertSame([], $snap->planner_entries_snapshot);
        $this->assertSame([], $snap->components_snapshot);
        $this->assertCount(1, $snap->position_discounts_snapshot);
        $this->assertSame('10.0000', (string) $snap->position_discounts_snapshot[0]['percent']);
        $this->assertSame(0, $order->positions()->where('line_role', DispoOrderPosition::LINE_ROLE_PRODUCTION)->count());

        // Kopf entspricht den Calc-Werten.
        $this->assertSame('3060.00', (string) $order->nn_invest);
        $this->assertSame('5000.00', (string) $order->media_gross);
        $this->assertSame('540.00', (string) $order->ae_total);
    }

    #[Test]
    public function pair_stays_planned_in_catalog_while_dispo_works_with_test_fake(): void
    {
        $ctx = $this->releasedFreeContext();
        $calc = $this->createCalc($ctx, [$this->freePosition($ctx)]);

        EngineProfileRegistry::clearFreePositionPairFakeForTests();

        // Ohne Release-Fake: Katalog planned. Dispo-Freeze nutzt den eingefrorenen v1-Descriptor
        // (implemented) und braucht kein neues Release.
        $this->assertSame(
            'planned',
            EngineProfileRegistry::pairStatus(EngineProfileRegistry::PROFILE_FREE_POSITION, 'free_position')->value,
        );
        $order = app(DispoOrderWriter::class)
            ->createFromCalculation($calc, [(int) $calc->positions->firstOrFail()->id], $ctx['user'])
            ->order;
        $this->assertSame('free_position', $order->positions()->firstOrFail()->engine_profile_key);
    }

    #[Test]
    public function mixed_spot_and_free_preserves_order_and_isolates_paths(): void
    {
        $ctx = $this->releasedFreeContext();
        $spot = $this->createFreeSpotFixture();
        $calc = $this->createCalc($ctx, [
            $this->freeSpotPosition($spot),
            $this->freePosition($ctx, ['position_discount_percent' => '10']),
        ], ['order_discount_percent' => '20', 'ae_enabled' => true]);

        $order = $this->adoptAll($calc, $ctx);

        $media = $this->mediaRows($order);
        $this->assertSame(['spot_classic', 'free_position'], $media->pluck('engine_profile_key')->all());
        $this->assertSame(
            [DispoPositionMethodClassifier::KIND_SPOT_FAMILY, DispoPositionMethodClassifier::KIND_FREE_POSITION],
            $media->map(fn (DispoOrderPosition $p): string => DispoPositionMethodClassifier::classifyPosition($p))->all(),
        );
        $this->assertSame(10, (int) $media[0]->total_spot_count);
        $this->assertNotNull($media[0]->price_list_id);
        $this->assertSame(0, (int) $media[1]->total_spot_count);
        $this->assertNull($media[1]->price_list_id);
        $this->assertSame('3060.00', (string) $media[1]->nn_invest);
        $this->assertSame((string) $calc->nn_invest, (string) $order->nn_invest);
    }

    #[Test]
    public function mixed_oa_normal_and_free_preserves_order(): void
    {
        $ctx = $this->releasedFreeContext();
        $oa = $this->seedFreeOaContext();
        $calc = $this->createCalc($ctx, [
            $this->freeOaPosition($oa),
            $this->freePosition($ctx, ['position_discount_percent' => '10']),
        ], ['order_discount_percent' => '20', 'ae_enabled' => true]);

        $order = $this->adoptAll($calc, $ctx);

        $media = $this->mediaRows($order);
        $this->assertSame(['oa_tkp', 'free_position'], $media->pluck('engine_profile_key')->all());
        $this->assertSame(100_000, (int) $media[0]->impressions);
        $this->assertNotNull($media[0]->final_tkp);
        $this->assertNull($media[1]->impressions);
        $this->assertNull($media[1]->final_tkp);
        $this->assertNull($media[1]->oa_platform_allocations_snapshot);
        $this->assertNull($media[1]->oa_targeting_selections_snapshot);
        $this->assertSame('680.00', (string) $media[0]->nn_invest);
        $this->assertSame('3060.00', (string) $media[1]->nn_invest);
        $this->assertSame('3740.00', (string) $order->nn_invest);
    }

    #[Test]
    public function mixed_oa_fixed_and_free_preserves_order(): void
    {
        $ctx = $this->releasedFreeContext();
        $oa = $this->seedFreeOaContext();
        $calc = $this->createCalc($ctx, [
            $this->freeOaPosition($oa, [
                'pricing_settlement_mode' => 'fixed_price',
                'fixed_price_nn' => '1000.00',
                'basis_tkp' => null,
            ]),
            $this->freePosition($ctx),
        ], ['order_discount_percent' => '20', 'ae_enabled' => false]);

        $order = $this->adoptAll($calc, $ctx);

        $media = $this->mediaRows($order);
        $this->assertSame(['oa_tkp', 'free_position'], $media->pluck('engine_profile_key')->all());
        $this->assertSame('fixed_price', $media[0]->pricing_settlement_mode->value);
        $this->assertSame('1000.00', (string) $media[0]->fixed_price_nn);
        $this->assertSame('normal', $media[1]->pricing_settlement_mode->value);
        $this->assertNull($media[1]->fixed_price_nn);
        $this->assertSame('4000.00', (string) $media[1]->nn_invest);
        $this->assertSame('5000.00', (string) $order->nn_invest);
    }

    #[Test]
    public function mixed_spot_oa_and_free_preserves_payload_order(): void
    {
        $ctx = $this->releasedFreeContext();
        $oa = $this->seedFreeOaContext();
        $spot = $this->createFreeSpotFixture();
        $calc = $this->createCalc($ctx, [
            $this->freePosition($ctx, ['free_price' => '1000.00']),
            $this->freeSpotPosition($spot),
            $this->freeOaPosition($oa),
        ], ['order_discount_percent' => '10', 'ae_enabled' => false]);

        $order = $this->adoptAll($calc, $ctx);

        $media = $this->mediaRows($order);
        $this->assertSame(
            ['free_position', 'spot_classic', 'oa_tkp'],
            $media->pluck('engine_profile_key')->all(),
        );
        $this->assertSame([0, 1, 2], $media->pluck('sort')->map(fn ($s): int => (int) $s)->all());
        $this->assertSame('1000.00', (string) $media[0]->media_gross);
        $this->assertSame((string) $calc->nn_invest, (string) $order->nn_invest);
    }

    #[Test]
    public function partial_adoption_of_only_the_free_position(): void
    {
        $ctx = $this->releasedFreeContext();
        $spot = $this->createFreeSpotFixture();
        $calc = $this->createCalc($ctx, [
            $this->freeSpotPosition($spot),
            $this->freePosition($ctx, ['position_discount_percent' => '10']),
        ], ['order_discount_percent' => '20', 'ae_enabled' => true]);
        $free = $calc->positions->firstWhere('engine_profile_key', 'free_position');

        $order = app(DispoOrderWriter::class)
            ->createFromCalculation($calc, [(int) $free->id], $ctx['user'])
            ->order;

        $media = $this->mediaRows($order);
        $this->assertCount(1, $media);
        $this->assertSame('free_position', $media[0]->engine_profile_key);
        $this->assertSame('3060.00', (string) $media[0]->nn_invest);
        $this->assertSame('3060.00', (string) $order->nn_invest);
        $this->assertSame('5000.00', (string) $order->media_gross);
        // Teilübernahme: Zielbudget des Gesamtangebots wird nicht übernommen.
        $this->assertNull($order->target_budget_nn);
    }

    #[Test]
    public function historical_stability_dispo_is_frozen_against_live_changes(): void
    {
        $ctx = $this->releasedFreeContext();
        $calc = $this->createCalc($ctx, [
            $this->freePosition($ctx, ['position_discount_percent' => '10']),
        ], ['order_discount_percent' => '20', 'ae_enabled' => true]);
        $order = $this->adoptAll($calc, $ctx);

        // Live-Änderungen nach der Dispo: Medium-Flags, Regel, Kind, Zuordnung, Pair-Status.
        $ctx['medium']->update(['is_discountable' => false, 'is_ae_eligible' => false, 'name' => 'Geändert']);
        $ctx['rule']->update(['is_active' => false, 'booking_code' => 'Z', 'planning_responsibility_key' => 'oap']);
        EngineProfileRegistry::clearFreePositionPairFakeForTests();

        $snap = $order->fresh()->positions()->firstOrFail();
        $this->assertSame('5000.00', (string) $snap->media_gross);
        $this->assertSame('3060.00', (string) $snap->nn_invest);
        $this->assertTrue((bool) $snap->is_discountable);
        $this->assertTrue((bool) $snap->is_ae_eligible);
        $this->assertSame('L', $snap->booking_code);
        $this->assertSame('disposition', $snap->planning_responsibility_key);
        $this->assertSame(
            DispoPositionMethodClassifier::KIND_FREE_POSITION,
            DispoPositionMethodClassifier::classifyPosition($snap),
        );

        // Show liest den Snapshot, nicht den Live-Katalog.
        $this->actingAs($ctx['user'])
            ->get(route('dispo-orders.show', $order))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('dispo-orders/show')
                ->where('order.positions.0.method_classification', 'free_position')
                ->where('order.positions.0.method_inconsistency_message', null)
                ->where('order.positions.0.spot_method_label', 'Freie Preisposition')
                ->where('order.positions.0.media_gross', '5000.00')
                ->where('order.positions.0.nn_invest', '3060.00')
                ->where('order.positions.0.total_spot_count', 0)
                ->where('order.positions.0.length_seconds', 0)
                ->where('order.positions.0.impressions', null)
                ->where('order.positions.0.final_tkp', null));
    }

    #[Test]
    public function planning_responsibility_redaktion_is_frozen_from_the_rule(): void
    {
        $ctx = $this->releasedFreeContext();
        $ctx['rule']->update([
            'booking_code' => 'R',
            'planning_responsibility_key' => 'redaktion',
        ]);
        $calc = $this->createCalc($ctx, [$this->freePosition($ctx)]);
        $calcPosition = $calc->positions->firstOrFail();
        $this->assertSame('redaktion', $calcPosition->planning_responsibility_key);

        $order = $this->adoptAll($calc, $ctx);
        $snap = $order->positions()->firstOrFail();

        $this->assertSame('R', $snap->booking_code);
        $this->assertSame('redaktion', $snap->planning_responsibility_key);
        $this->assertSame($calcPosition->planning_responsibility_label, $snap->planning_responsibility_label);
        $this->assertSame('free_position', $snap->engine_profile_key);
    }

    #[Test]
    public function adoption_service_exposes_free_position_without_spot_values(): void
    {
        $ctx = $this->releasedFreeContext();
        $calc = $this->createCalc($ctx, [$this->freePosition($ctx)]);

        $rows = app(DispoOrderPositionAdoptionService::class)->selectablePositions($calc->fresh());

        $this->assertCount(1, $rows);
        $this->assertSame(DispoPositionMethodClassifier::KIND_FREE_POSITION, $rows[0]['method_classification']);
        $this->assertTrue($rows[0]['adoptable']);
        $this->assertNull($rows[0]['method_inconsistency_message']);
        $this->assertSame('free_position', $rows[0]['engine_profile_key']);
        $this->assertSame('free_position', $rows[0]['calculation_method_key']);
        $this->assertSame('5000.00', $rows[0]['media_gross']);
        $this->assertSame('5000.00', $rows[0]['nn_invest']);
        $this->assertNull($rows[0]['impressions']);
        $this->assertNull($rows[0]['final_tkp']);
        $this->assertSame(0, (int) $rows[0]['total_spot_count']);
        $this->assertSame(0, (int) $rows[0]['length_seconds']);
        $this->assertSame([], $rows[0]['time_ranges']);
        $this->assertSame([], $rows[0]['planner_entries']);
    }

    #[Test]
    public function partial_freeze_fails_closed_with_freeze_message(): void
    {
        $ctx = $this->releasedFreeContext();
        $calc = $this->createCalc($ctx, [$this->freePosition($ctx)]);
        $position = $calc->positions()->firstOrFail();

        $attrs = $position->getAttributes();
        $attrs['calculation_method_name'] = null;
        $position->setRawAttributes($attrs, true);

        try {
            app(DispoOrderSnapshotMapper::class)->positionFromCalculationPosition($position, 0);
            $this->fail('Erwartete ValidationException bei partiellem Freeze.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                ['Die eingefrorenen Berechnungsdaten der Position sind unvollständig und können nicht verwendet werden.'],
                $exception->errors()['positions'] ?? null,
            );
        }
    }

    #[Test]
    public function wrong_pairs_are_rejected_without_creating_an_order(): void
    {
        $ctx = $this->releasedFreeContext();
        $spot = $this->createFreeSpotFixture();
        $calc = $this->createCalc($ctx, [
            $this->freeSpotPosition($spot),
            $this->freePosition($ctx),
        ]);
        $free = $calc->positions->firstWhere('engine_profile_key', 'free_position');
        $spotPosition = $calc->positions->firstWhere('engine_profile_key', 'spot_classic');

        $wrongPairs = [
            // free × average
            ['free_position', 'average', 'Durchschnitt'],
            // free × tkp
            ['free_position', 'tkp', 'TKP'],
            // spot × free
            ['spot_classic', 'free_position', 'Freie Preisposition'],
            // oa × free
            ['oa_tkp', 'free_position', 'Freie Preisposition'],
        ];

        foreach ($wrongPairs as [$profile, $method, $name]) {
            $free->forceFill([
                'engine_profile_key' => $profile,
                'calculation_method_key' => $method,
                'calculation_method_name' => $name,
            ])->save();

            $beforeOrders = DispoOrder::query()->count();
            $beforePositions = DispoOrderPosition::query()->count();

            try {
                app(DispoOrderWriter::class)->createFromCalculation(
                    $calc->fresh(),
                    [(int) $spotPosition->id, (int) $free->id],
                    $ctx['user'],
                );
                $this->fail("Erwartete ValidationException für {$profile}×{$method}.");
            } catch (ValidationException $exception) {
                $this->assertSame(
                    [DispoPositionMethodClassifier::MESSAGE_INCONSISTENT],
                    $exception->errors()['position_ids'] ?? null,
                    "{$profile}×{$method}",
                );
            }

            $this->assertSame($beforeOrders, DispoOrder::query()->count(), "{$profile}×{$method}");
            $this->assertSame($beforePositions, DispoOrderPosition::query()->count(), "{$profile}×{$method}");
        }
    }

    #[Test]
    public function wrong_pair_is_flagged_inconsistent_in_adoption_list(): void
    {
        $ctx = $this->releasedFreeContext();
        $calc = $this->createCalc($ctx, [$this->freePosition($ctx)]);
        $calc->positions()->firstOrFail()->forceFill([
            'engine_profile_key' => 'free_position',
            'calculation_method_key' => 'average',
            'calculation_method_name' => 'Durchschnitt',
        ])->save();

        $rows = app(DispoOrderPositionAdoptionService::class)->selectablePositions($calc->fresh());

        $this->assertSame(DispoPositionMethodClassifier::KIND_INCONSISTENT, $rows[0]['method_classification']);
        $this->assertFalse($rows[0]['adoptable']);
        $this->assertSame(
            DispoPositionMethodClassifier::MESSAGE_INCONSISTENT,
            $rows[0]['method_inconsistency_message'],
        );
    }

    #[Test]
    public function production_line_for_free_carrier_is_rejected(): void
    {
        $ctx = $this->releasedFreeContext();
        $calc = $this->createCalc($ctx, [$this->freePosition($ctx)]);
        /** @var CalculationPosition $carrier */
        $carrier = $calc->positions()->firstOrFail();

        $line = new CalculationPositionProductionLine;
        $line->label = 'Produktion';

        try {
            app(DispoOrderSnapshotMapper::class)->productionFromLine($carrier, $line, 1);
            $this->fail('Free Position darf keine Produktionszeile erhalten.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString(
                'Freie Preispositionen besitzen keine Spotproduktion',
                (string) ($exception->errors()['position_ids'][0] ?? ''),
            );
        }
    }

    /**
     * @return array{user: User, medium: AdvertisingMedium, inventory: Inventory, rule: InventoryMediumRule}
     */
    private function releasedFreeContext(): array
    {
        EngineProfileRegistry::fakeFreePositionPairReleasedForTests();

        return $this->seedFreeContext();
    }

    /**
     * @param  array<string, mixed>  $ctx
     * @param  list<array<string, mixed>>  $positions
     * @param  array<string, mixed>  $overrides
     */
    private function createCalc(array $ctx, array $positions, array $overrides = []): Calculation
    {
        return app(CalculationWriter::class)->create($this->withLiveSchemaFingerprint(array_merge([
            'planning_mode' => 'manual',
            'order_discount_percent' => '0',
            'ae_enabled' => false,
            'positions' => $positions,
        ], $overrides)), $ctx['user']);
    }

    /**
     * @param  array<string, mixed>  $ctx
     */
    private function adoptAll(Calculation $calc, array $ctx): DispoOrder
    {
        $ids = $calc->positions()->orderBy('sort')->pluck('id')->map(fn ($id): int => (int) $id)->all();

        return app(DispoOrderWriter::class)
            ->createFromCalculation($calc->fresh(), $ids, $ctx['user'])
            ->order;
    }

    /**
     * @return Collection<int, DispoOrderPosition>
     */
    private function mediaRows(DispoOrder $order): Collection
    {
        return $order->positions()
            ->where('line_role', DispoOrderPosition::LINE_ROLE_MEDIA)
            ->orderBy('sort')
            ->get()
            ->values();
    }
}
