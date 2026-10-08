<?php

namespace Tests\Feature\Calculation;

use App\Enums\PriceListStatus;
use App\Enums\Role;
use App\Enums\SpecialApprovalReasonCode;
use App\Models\AdvertisingMedium;
use App\Models\Calculation;
use App\Models\CalculationPosition;
use App\Models\CalculationPositionProductionLine;
use App\Models\DispoOrderPosition;
use App\Models\Inventory;
use App\Models\InventoryMediumRule;
use App\Models\ProductionPriceList;
use App\Models\StandardOffer;
use App\Models\User;
use App\Services\Calculation\CalculationWriter;
use App\Services\Calculation\SpecialApprovalAssessor;
use App\Services\DispoOrder\DispoOrderWriter;
use App\Services\StandardOffer\StandardOfferWriter;
use App\Support\PriceList\PriceListCalendar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesProductionPriceLists;
use Tests\Concerns\CreatesTrailerAverageCatalog;
use Tests\TestCase;

/**
 * BL-P5-02a / PO-BLP502-1 (Auflösungs- und Pin-Vertrag): Spotproduktion als Zusatzzeile.
 *
 * Fixture: Sender A (Ø 2,00 €/s) und B (Ø 1,50 €/s), Spot 30 s (Index 100).
 * A: 10 Spots → 600,00 · B: 5 Spots → 225,00 (Medienbrutto, ohne Rabatt/AE).
 */
class SpotProductionBlP502aTest extends TestCase
{
    use CreatesProductionPriceLists;
    use CreatesTrailerAverageCatalog;
    use RefreshDatabase;

    private function sales(): User
    {
        return User::factory()->role(Role::Sales)->create();
    }

    /**
     * @return array<string, mixed>
     */
    private function line(string $key, ?string $quantity = null, ?string $label = null, ?string $remark = null): array
    {
        $line = ['client_key' => $key, 'production_type' => 'spot_production'];
        if ($quantity !== null) {
            $line['quantity'] = $quantity;
        }
        if ($label !== null) {
            $line['label'] = $label;
        }
        if ($remark !== null) {
            $line['remark'] = $remark;
        }

        return $line;
    }

    /**
     * @param  list<array<string, mixed>>|null  $lines  null = Schlüssel nicht senden
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function spotPosition(Inventory $inventory, AdvertisingMedium $spot, int $spots, ?array $lines = null, array $overrides = []): array
    {
        $position = array_merge([
            'inventory_id' => $inventory->id,
            'advertising_medium_id' => $spot->id,
            'spot_method' => 'average',
            'length_seconds' => 30,
            'position_discount_percent' => '0',
            'ae_percent' => '0',
            'time_ranges' => [[
                'start_hour' => 8,
                'end_hour_exclusive' => 9,
                'day_group' => 'mo_fr',
                'spot_count' => $spots,
                'sort' => 0,
            ]],
        ], $overrides);

        if ($lines !== null) {
            $position['production_lines'] = $lines;
        }

        return $position;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function preview(User $user, array $payload): TestResponse
    {
        return $this->actingAs($user)->postJson(route('calculations.preview'), $payload);
    }

    /**
     * @param  callable(array<string, mixed>): array<string, mixed>  $mutate
     */
    private function updateFromStored(User $user, Calculation $calculation, callable $mutate): TestResponse
    {
        $fresh = $calculation->fresh();
        $payload = app(CalculationWriter::class)->payloadFromCalculation($fresh);
        $payload['lock_version'] = $fresh->lock_version;
        $payload = $mutate($payload);

        return $this->actingAs($user)->put(route('calculations.update', $calculation), $payload);
    }

    private function saved(): Calculation
    {
        return Calculation::query()->firstOrFail();
    }

    /**
     * Standardfall: A 150,00 × 2 und B 80,00 × 3 (Menge 2 bzw. 3).
     *
     * @return array{catalog: array<string, mixed>, user: User, payload: array<string, mixed>, listA: ProductionPriceList, listB: ProductionPriceList}
     */
    private function twoInventorySetup(): array
    {
        $catalog = $this->createTrailerAverageCatalog();
        $listA = $this->createActiveProductionPriceList($catalog['a'], '150.00');
        $listB = $this->createActiveProductionPriceList($catalog['b'], '80.00');
        $payload = $this->trailerPayload([
            $this->spotPosition($catalog['a'], $catalog['spot'], 10, [$this->line('11111111-1111-4111-8111-111111111111', '2')]),
            $this->spotPosition($catalog['b'], $catalog['spot'], 5, [$this->line('22222222-2222-4222-8222-222222222222', '3')]),
        ]);

        return ['catalog' => $catalog, 'user' => $this->sales(), 'payload' => $payload, 'listA' => $listA, 'listB' => $listB];
    }

    public function test_two_inventories_use_own_production_price_and_media_gross_stays_media_only(): void
    {
        $setup = $this->twoInventorySetup();

        $preview = $this->preview($setup['user'], $setup['payload']);
        $preview->assertOk();

        $this->assertSame('300.00', $preview->json('totals.positions.0.production_gross'));
        $this->assertSame('240.00', $preview->json('totals.positions.1.production_gross'));
        $this->assertSame('150.00', $preview->json('totals.positions.0.production_lines.0.unit_price'));
        $this->assertSame('80.00', $preview->json('totals.positions.1.production_lines.0.unit_price'));
        // media_gross enthält keine Produktion.
        $this->assertSame('600.00', $preview->json('totals.positions.0.media_gross'));
        $this->assertSame('225.00', $preview->json('totals.positions.1.media_gross'));
        $this->assertSame('825.00', $preview->json('totals.media_gross'));
        // nn_invest = Medien 825,00 + Produktion 540,00
        $this->assertSame('900.00', $preview->json('totals.positions.0.nn_invest'));
        $this->assertSame('465.00', $preview->json('totals.positions.1.nn_invest'));
        $this->assertSame('1365.00', $preview->json('totals.nn_invest'));
    }

    public function test_preview_save_reload_parity(): void
    {
        $setup = $this->twoInventorySetup();
        $preview = $this->preview($setup['user'], $setup['payload']);
        $preview->assertOk();

        $this->actingAs($setup['user'])->post(route('calculations.store'), $setup['payload'])->assertRedirect();
        $calculation = $this->saved()->load('positions.productionLines');

        $this->assertSame($preview->json('totals.nn_invest'), (string) $calculation->nn_invest);
        $this->assertSame($preview->json('totals.media_gross'), (string) $calculation->media_gross);
        $this->assertSame('300.00', (string) $calculation->positions[0]->production_gross);
        $this->assertSame('240.00', (string) $calculation->positions[1]->production_gross);
        $this->assertSame('300.00', (string) $calculation->positions[0]->production_nn_invest);
        $this->assertSame('900.00', (string) $calculation->positions[0]->nn_invest);
        $line = $calculation->positions[0]->productionLines->firstOrFail();
        $this->assertSame('150.00', (string) $line->unit_price);
        $this->assertSame('2.0000', (string) $line->quantity);
        $this->assertSame($setup['listA']->id, $line->production_price_list_id);
        $this->assertSame((string) $setup['listA']->version, $line->production_price_list_version);
        $this->assertFalse($line->is_discountable);
        $this->assertFalse($line->is_ae_eligible);

        // Reload (Edit-Seite) und Preview auf dem gespeicherten Stand bleiben paritätisch.
        $this->actingAs($setup['user'])->get(route('calculations.edit', $calculation))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('calculation.positions.0.production_lines.0.unit_price', '150.00')
                ->where('calculation.positions.0.production_lines.0.line_gross', '300.00')
                ->where('savedSummary.nn_invest', '1365.00'));

        $reloadPreview = $this->preview($setup['user'], array_merge(
            app(CalculationWriter::class)->payloadFromCalculation($calculation->fresh()),
            ['calculation_id' => $calculation->id],
        ));
        $reloadPreview->assertOk();
        $this->assertSame('1365.00', $reloadPreview->json('totals.nn_invest'));

        // Unveränderter Re-Save (Pin) ändert nichts.
        $this->updateFromStored($setup['user'], $calculation, fn (array $p): array => $p)->assertRedirect();
        $this->assertSame('1365.00', (string) $calculation->fresh()->nn_invest);
    }

    public function test_quantity_defaults_to_zero_and_zero_quantity_is_savable(): void
    {
        $catalog = $this->createTrailerAverageCatalog();
        $this->createActiveProductionPriceList($catalog['a'], '150.00');
        $user = $this->sales();
        $payload = $this->trailerPayload([
            $this->spotPosition($catalog['a'], $catalog['spot'], 10, [$this->line('33333333-3333-4333-8333-333333333333')]),
        ]);

        $preview = $this->preview($user, $payload);
        $preview->assertOk();
        $this->assertSame('0.00', $preview->json('totals.positions.0.production_lines.0.line_gross'));
        $this->assertSame('0.0000', $preview->json('totals.positions.0.production_lines.0.quantity'));
        $this->assertSame('600.00', $preview->json('totals.nn_invest'));

        $this->actingAs($user)->post(route('calculations.store'), $payload)->assertRedirect();
        $line = CalculationPositionProductionLine::query()->firstOrFail();
        $this->assertSame('0.00', (string) $line->line_gross);
        $this->assertSame('150.00', (string) $line->unit_price);
    }

    public function test_multiple_lines_on_one_position_add_up(): void
    {
        $catalog = $this->createTrailerAverageCatalog();
        $this->createActiveProductionPriceList($catalog['a'], '150.00');
        $user = $this->sales();
        $payload = $this->trailerPayload([
            $this->spotPosition($catalog['a'], $catalog['spot'], 10, [
                $this->line('44444444-4444-4444-8444-444444444441', '1', 'Spot Hauptversion'),
                $this->line('44444444-4444-4444-8444-444444444442', '2,5', 'Spot Variante', 'Mit Sprecher'),
            ]),
        ]);

        $preview = $this->preview($user, $payload);
        $preview->assertOk();
        // 1 × 150 + 2,5 × 150 = 525,00
        $this->assertSame('525.00', $preview->json('totals.positions.0.production_gross'));
        $this->assertSame('1125.00', $preview->json('totals.nn_invest'));

        $this->actingAs($user)->post(route('calculations.store'), $payload)->assertRedirect();
        $lines = CalculationPositionProductionLine::query()->orderBy('sort')->get();
        $this->assertCount(2, $lines);
        $this->assertSame('Spot Hauptversion', $lines[0]->label);
        $this->assertSame('Mit Sprecher', $lines[1]->remark);
    }

    public function test_production_is_independent_of_spot_count_and_length(): void
    {
        $catalog = $this->createTrailerAverageCatalog();
        $this->createActiveProductionPriceList($catalog['a'], '150.00');
        $user = $this->sales();
        $lines = [$this->line('55555555-5555-4555-8555-555555555555', '2')];

        foreach ([[1, 10], [10, 30], [50, 40]] as [$spots, $length]) {
            $payload = $this->trailerPayload([
                $this->spotPosition($catalog['a'], $catalog['spot'], $spots, $lines, ['length_seconds' => $length]),
            ]);
            $preview = $this->preview($user, $payload);
            $preview->assertOk();
            $this->assertSame('300.00', $preview->json('totals.positions.0.production_gross'));
        }
    }

    public function test_missing_active_list_blocks_only_with_lines(): void
    {
        $catalog = $this->createTrailerAverageCatalog();
        $user = $this->sales();

        $withoutLines = $this->trailerPayload([$this->spotPosition($catalog['a'], $catalog['spot'], 10, [])]);
        $preview = $this->preview($user, $withoutLines);
        $preview->assertOk();
        $this->assertSame('600.00', $preview->json('totals.nn_invest'));

        $absentKey = $this->trailerPayload([$this->spotPosition($catalog['a'], $catalog['spot'], 10)]);
        $this->preview($user, $absentKey)->assertOk();

        $withLines = $this->trailerPayload([
            $this->spotPosition($catalog['a'], $catalog['spot'], 10, [$this->line('66666666-6666-4666-8666-666666666666', '0')]),
        ]);
        // Menge 0 ändert nichts: fehlende Konfiguration blockiert fail-closed.
        $this->preview($user, $withLines)->assertStatus(422)->assertJsonValidationErrors(['positions.0.production_lines']);
        $this->actingAs($user)->post(route('calculations.store'), $withLines)->assertSessionHasErrors('positions.0.production_lines');
        $this->assertSame(0, Calculation::query()->count());

        // Nur archivierte/Entwurfsliste zählt nicht als aktiv.
        ProductionPriceList::factory()->archived()->create(['inventory_id' => $catalog['a']->id, 'unit_price' => '99.00']);
        ProductionPriceList::factory()->draft()->create(['inventory_id' => $catalog['a']->id, 'unit_price' => '98.00']);
        $this->preview($user, $withLines)->assertStatus(422);

        // Anderes Preisjahr hilft nicht.
        $this->createActiveProductionPriceList($catalog['a'], '150.00', year: PriceListCalendar::currentYear() + 1);
        $this->preview($user, $withLines)->assertStatus(422);
    }

    public function test_ambiguous_active_lists_fail_closed_even_at_quantity_zero(): void
    {
        $catalog = $this->createTrailerAverageCatalog();
        $this->createActiveProductionPriceList($catalog['a'], '150.00');
        $this->createActiveProductionPriceList($catalog['a'], '160.00');

        $payload = $this->trailerPayload([
            $this->spotPosition($catalog['a'], $catalog['spot'], 10, [$this->line('77777777-7777-4777-8777-777777777777', '0')]),
        ]);

        $this->preview($this->sales(), $payload)->assertStatus(422);
        $this->assertSame(0, Calculation::query()->count());
    }

    public function test_admin_price_change_after_save_does_not_move_the_pin(): void
    {
        $setup = $this->twoInventorySetup();
        $this->actingAs($setup['user'])->post(route('calculations.store'), $setup['payload'])->assertRedirect();
        $calculation = $this->saved();

        // Admin-Nachfolger: alte Liste archiviert, neue Active mit anderem Preis und Flags.
        $setup['listA']->update(['status' => PriceListStatus::Archived, 'archived_at' => now()]);
        $this->createActiveProductionPriceList($setup['catalog']['a'], '999.00', true, true);

        $this->updateFromStored($setup['user'], $calculation, fn (array $p): array => $p)->assertRedirect();
        $line = CalculationPositionProductionLine::query()->where('calculation_position_id', $calculation->positions()->orderBy('id')->value('id'))->firstOrFail();
        $this->assertSame('150.00', (string) $line->unit_price);
        $this->assertFalse($line->is_discountable);
        $this->assertSame($setup['listA']->id, $line->production_price_list_id);
        $this->assertSame('1365.00', (string) $calculation->fresh()->nn_invest);

        // Preview auf dem gespeicherten Stand bleibt beim Pin.
        $preview = $this->preview($setup['user'], array_merge(
            app(CalculationWriter::class)->payloadFromCalculation($calculation->fresh()),
            ['calculation_id' => $calculation->id],
        ));
        $this->assertSame('1365.00', $preview->json('totals.nn_invest'));
    }

    public function test_quantity_change_keeps_pinned_unit_price(): void
    {
        $setup = $this->twoInventorySetup();
        $this->actingAs($setup['user'])->post(route('calculations.store'), $setup['payload'])->assertRedirect();
        $calculation = $this->saved();

        $setup['listA']->update(['status' => PriceListStatus::Archived, 'archived_at' => now()]);
        $this->createActiveProductionPriceList($setup['catalog']['a'], '999.00');

        $this->updateFromStored($setup['user'], $calculation, function (array $p): array {
            $p['positions'][0]['production_lines'][0]['quantity'] = '4';

            return $p;
        })->assertRedirect();

        $line = CalculationPositionProductionLine::query()->orderBy('id')->firstOrFail();
        $this->assertSame('4.0000', (string) $line->quantity);
        $this->assertSame('150.00', (string) $line->unit_price);
        $this->assertSame('600.00', (string) $line->line_gross);
        // 600 + 600 (Medien A) + 225 + 240 (B)
        $this->assertSame('1665.00', (string) $calculation->fresh()->nn_invest);
    }

    public function test_inventory_change_rebinds_to_target_inventory_price(): void
    {
        $setup = $this->twoInventorySetup();
        $this->actingAs($setup['user'])->post(route('calculations.store'), $setup['payload'])->assertRedirect();
        $calculation = $this->saved();

        $this->updateFromStored($setup['user'], $calculation, function (array $p) use ($setup): array {
            $p['positions'] = [$p['positions'][0]];
            $p['positions'][0]['inventory_id'] = $setup['catalog']['b']->id;

            return $p;
        })->assertRedirect();

        $position = $calculation->fresh()->positions()->with('productionLines')->firstOrFail();
        $line = $position->productionLines->firstOrFail();
        $this->assertSame($setup['catalog']['b']->id, $position->inventory_id);
        $this->assertSame('80.00', (string) $line->unit_price);
        $this->assertSame($setup['listB']->id, $line->production_price_list_id);
        $this->assertSame('160.00', (string) $line->line_gross);
    }

    public function test_inventory_change_to_target_without_price_blocks_without_partial_mutation(): void
    {
        $catalog = $this->createTrailerAverageCatalog();
        $listA = $this->createActiveProductionPriceList($catalog['a'], '150.00');
        $user = $this->sales();
        $this->actingAs($user)->post(route('calculations.store'), $this->trailerPayload([
            $this->spotPosition($catalog['a'], $catalog['spot'], 10, [$this->line('88888888-8888-4888-8888-888888888888', '2')]),
        ]))->assertRedirect();
        $calculation = $this->saved();
        $before = [
            'lock' => $calculation->lock_version,
            'nn' => (string) $calculation->nn_invest,
            'inventory' => (int) $calculation->positions()->value('inventory_id'),
            'line' => CalculationPositionProductionLine::query()->first()->only(['unit_price', 'quantity', 'production_price_list_id']),
        ];

        $this->updateFromStored($user, $calculation, function (array $p) use ($catalog): array {
            $p['positions'][0]['inventory_id'] = $catalog['b']->id;
            $p['positions'][0]['production_lines'][0]['quantity'] = '9';

            return $p;
        })->assertSessionHasErrors('positions.0.production_lines');

        $fresh = $calculation->fresh();
        $this->assertSame($before['lock'], $fresh->lock_version);
        $this->assertSame($before['nn'], (string) $fresh->nn_invest);
        $this->assertSame($before['inventory'], (int) $fresh->positions()->value('inventory_id'));
        $this->assertSame(
            $before['line'],
            CalculationPositionProductionLine::query()->first()->only(['unit_price', 'quantity', 'production_price_list_id']),
        );
        $this->assertSame($listA->id, CalculationPositionProductionLine::query()->value('production_price_list_id'));
    }

    public function test_unsupported_method_blocks_but_keeps_lines_until_removed(): void
    {
        $catalog = $this->createTrailerAverageCatalog();
        $this->createActiveProductionPriceList($catalog['a'], '150.00');
        $user = $this->sales();
        $this->actingAs($user)->post(route('calculations.store'), $this->trailerPayload([
            $this->spotPosition($catalog['a'], $catalog['spot'], 10, [$this->line('99999999-9999-4999-8999-999999999999', '2')]),
        ]))->assertRedirect();
        $calculation = $this->saved();

        // Kalender: blockiert, Zeile bleibt unverändert in der DB.
        $this->updateFromStored($user, $calculation, function (array $p): array {
            $p['positions'][0]['spot_method'] = 'calendar';
            $p['positions'][0]['time_ranges'] = [];
            $p['positions'][0]['plan_rows'] = [];
            $p['positions'][0]['planner_entries'] = [['date' => now()->toDateString(), 'hour' => 8, 'spot_count' => 2]];

            return $p;
        })->assertSessionHasErrors('positions.0.production_lines');
        $this->assertSame(1, CalculationPositionProductionLine::query()->count());

        // Festpreis: blockiert.
        $this->updateFromStored($user, $calculation, function (array $p): array {
            $p['positions'][0]['pricing_settlement_mode'] = 'fixed_price';
            $p['positions'][0]['fixed_price_nn'] = '400.00';

            return $p;
        })->assertSessionHasErrors('positions.0.production_lines');

        // Trailer-Medium: blockiert.
        $this->updateFromStored($user, $calculation, function (array $p) use ($calculation, $catalog): array {
            $p['positions'][0]['advertising_medium_id'] = $catalog['trailer']->id;
            $p['positions'][0]['length_seconds'] = 20;
            $p['positions'][0]['expected_price_list_id'] = $catalog['listA']->id;
            $p['positions'][0]['price_year'] = PriceListCalendar::currentYear();
            $p['positions'] = $this->withPositionSchemaFingerprints($calculation, $p['positions']);

            return $p;
        })->assertSessionHasErrors('positions.0.production_lines');
        $this->assertSame(1, CalculationPositionProductionLine::query()->count());

        // Preview meldet denselben Fehler (Zeile bleibt im Formular, Nutzer kann sie entfernen).
        $preview = $this->preview($user, $this->trailerPayload([
            $this->spotPosition($catalog['a'], $catalog['trailer'], 10, [$this->line('99999999-9999-4999-8999-999999999999', '2')], ['length_seconds' => 20]),
        ]));
        $preview->assertStatus(422)->assertJsonValidationErrors(['positions.0.production_lines']);

        // Entfernen + Kalender: speicherbar, Zeilen weg.
        $this->updateFromStored($user, $calculation, function (array $p): array {
            $p['positions'][0]['spot_method'] = 'calendar';
            $p['positions'][0]['time_ranges'] = [];
            $p['positions'][0]['plan_rows'] = [];
            $p['positions'][0]['planner_entries'] = [['date' => now()->toDateString(), 'hour' => 8, 'spot_count' => 2]];
            $p['positions'][0]['production_lines'] = [];

            return $p;
        })->assertRedirect();
        $this->assertSame(0, CalculationPositionProductionLine::query()->count());
        $this->assertSame('0.00', (string) $calculation->fresh()->positions()->value('production_gross'));
    }

    public function test_deleting_carrier_removes_its_lines(): void
    {
        $setup = $this->twoInventorySetup();
        $this->actingAs($setup['user'])->post(route('calculations.store'), $setup['payload'])->assertRedirect();
        $calculation = $this->saved();
        $this->assertSame(2, CalculationPositionProductionLine::query()->count());

        // Über den Writer: zweite Position entfernen.
        $this->updateFromStored($setup['user'], $calculation, function (array $p): array {
            $p['positions'] = [$p['positions'][0]];

            return $p;
        })->assertRedirect();
        $this->assertSame(1, CalculationPositionProductionLine::query()->count());
        $this->assertSame('900.00', (string) $calculation->fresh()->nn_invest);

        // FK-Cascade direkt.
        CalculationPosition::query()->whereKey($calculation->positions()->value('id'))->delete();
        $this->assertSame(0, CalculationPositionProductionLine::query()->count());
    }

    public function test_dispo_creates_separate_s_lines_and_snapshot_is_stable(): void
    {
        $setup = $this->twoInventorySetup();
        $this->actingAs($setup['user'])->post(route('calculations.store'), $setup['payload'])->assertRedirect();
        $calculation = $this->saved()->load('positions');
        $positionA = $calculation->positions[0];

        // Nur Träger A wählen: dessen Produktion folgt automatisch, die von B nicht.
        $result = app(DispoOrderWriter::class)->createFromCalculation($calculation, [$positionA->id], $setup['user']);
        $order = $result->order->fresh(['positions', 'productionLines']);

        $this->assertCount(1, $order->positions);
        $this->assertCount(1, $order->productionLines);

        $carrier = $order->positions->first();
        $this->assertSame('media', $carrier->line_role);
        $this->assertSame('600.00', (string) $carrier->nn_invest);
        $this->assertSame('600.00', (string) $carrier->media_gross);

        $production = $order->productionLines->first();
        $this->assertSame(DispoOrderPosition::LINE_ROLE_PRODUCTION, $production->line_role);
        $this->assertSame('S', $production->booking_code);
        $this->assertSame($positionA->id, $production->calculation_position_id);
        $this->assertSame('0.00', (string) $production->media_gross);
        $this->assertSame('300.00', (string) $production->nn_invest);
        $this->assertSame('2.0000', (string) $production->production_quantity);
        $this->assertSame('150.00', (string) $production->production_unit_price);
        $this->assertSame('300.00', (string) $production->production_line_gross);
        $this->assertSame(0, $production->total_spot_count);
        $this->assertSame([], $production->time_ranges_snapshot);
        $this->assertSame([], $production->plan_rows_snapshot);
        $this->assertSame($carrier->kind, $production->kind);
        // Kopf: Summe aller Zeilen (Medien + Produktion).
        $this->assertSame('900.00', (string) $order->nn_invest);
        $this->assertSame($carrier->inventory_name, $production->inventory_name);

        // Admin-Preisänderung und Rekalkulation ändern den Dispo-Snapshot nicht.
        $setup['listA']->update(['unit_price' => '777.00']);
        $this->updateFromStored($setup['user'], $calculation, function (array $p): array {
            $p['positions'][0]['production_lines'][0]['quantity'] = '10';

            return $p;
        })->assertRedirect();
        $production->refresh();
        $this->assertSame('150.00', (string) $production->production_unit_price);
        $this->assertSame('300.00', (string) $production->nn_invest);
        $this->assertSame('900.00', (string) $order->fresh()->nn_invest);

        // Beide Träger: beide Produktionszeilen, Kopfsumme = Kalkulation.
        $calculation = $calculation->fresh('positions');
        $all = app(DispoOrderWriter::class)->createFromCalculation(
            $calculation,
            $calculation->positions->pluck('id')->all(),
            $setup['user'],
        )->order->fresh(['positions', 'productionLines']);
        $this->assertCount(2, $all->positions);
        $this->assertCount(2, $all->productionLines);
        $this->assertSame((string) $calculation->nn_invest, (string) $all->nn_invest);
    }

    public function test_sales_cannot_set_price_flags_or_amounts_via_payload(): void
    {
        $catalog = $this->createTrailerAverageCatalog();
        $this->createActiveProductionPriceList($catalog['a'], '150.00');
        $user = $this->sales();

        foreach ([
            'unit_price' => '1.00',
            'is_discountable' => true,
            'is_ae_eligible' => true,
            'line_gross' => '0.01',
            'nn_invest' => '0.01',
            'production_price_list_version' => 'x',
        ] as $field => $value) {
            $line = $this->line('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', '2') + [$field => $value];
            $payload = $this->trailerPayload([$this->spotPosition($catalog['a'], $catalog['spot'], 10, [$line])]);
            $this->preview($user, $payload)->assertStatus(422);
            $this->actingAs($user)->post(route('calculations.store'), $payload)->assertSessionHasErrors();
        }
        $this->assertSame(0, Calculation::query()->count());

        // Nur der Expected-Pin-Hinweis ist erlaubt und ändert den Preis nicht.
        $line = $this->line('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', '2') + ['production_price_list_id' => 999999];
        $payload = $this->trailerPayload([$this->spotPosition($catalog['a'], $catalog['spot'], 10, [$line])]);
        $this->preview($user, $payload)->assertOk()->assertJsonPath('totals.positions.0.production_gross', '300.00');
    }

    public function test_rights_for_sales_admin_module_and_disposition_view(): void
    {
        $setup = $this->twoInventorySetup();
        $this->actingAs($setup['user'])->post(route('calculations.store'), $setup['payload'])->assertRedirect();
        $calculation = $this->saved()->load('positions');

        $this->actingAs($setup['user'])->get(route('administration.production-prices.index'))->assertForbidden();
        $this->actingAs($setup['user'])->post(route('administration.production-prices.store'), [
            'inventory_id' => $setup['catalog']['a']->id,
            'year' => 2031,
            'name' => 'x',
            'unit_price' => '1.00',
        ])->assertForbidden();

        $result = app(DispoOrderWriter::class)->createFromCalculation(
            $calculation,
            $calculation->positions->pluck('id')->all(),
            $setup['user'],
        );
        $disposition = User::factory()->role(Role::Disposition)->create();
        $this->actingAs($disposition)->get(route('dispo-orders.show', $result->order))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('order.production_lines', 2)
                ->where('order.production_lines.0.booking_code', 'S')
                ->where('order.production_lines.0.line_gross', '300.00')
                ->has('order.positions', 2));
        $this->actingAs($disposition)->get(route('administration.production-prices.index'))->assertForbidden();
    }

    public function test_discountable_and_ae_flags_are_pinned_and_applied(): void
    {
        $catalog = $this->createTrailerAverageCatalog();
        $list = $this->createActiveProductionPriceList($catalog['a'], '150.00', true, true);
        $user = $this->sales();
        $payload = $this->trailerPayload([
            $this->spotPosition($catalog['a'], $catalog['spot'], 10, [$this->line('bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', '2')], [
                'position_discount_percent' => '10',
                'ae_percent' => '15',
            ]),
        ], ['ae_enabled' => true]);

        $preview = $this->preview($user, $payload);
        $preview->assertOk();
        // Medien: 600 → −10 % = 540 → AE 15 % = 81,00 → 459,00
        // Produktion: 300 → −10 % = 270 → AE 15 % = 40,50 → 229,50
        $this->assertSame('300.00', $preview->json('totals.positions.0.production_gross'));
        $this->assertSame('30.00', $preview->json('totals.positions.0.production_lines.0.position_discount_amount'));
        $this->assertSame('40.50', $preview->json('totals.positions.0.production_lines.0.ae_amount'));
        $this->assertSame('229.50', $preview->json('totals.positions.0.production_lines.0.nn_invest'));
        $this->assertSame('688.50', $preview->json('totals.positions.0.nn_invest'));
        $this->assertSame('459.00', $preview->json('totals.positions.0.media_nn_invest'));
        $this->assertSame('688.50', $preview->json('totals.nn_invest'));
        // Media-Rabatt bleibt medienrein (60), Auftrag addiert die Produktion (30).
        $this->assertSame('60.00', $preview->json('totals.positions.0.position_discount_amount'));
        $this->assertSame('90.00', $preview->json('totals.position_discount_total'));
        $this->assertSame('121.50', $preview->json('totals.ae_total'));

        $this->actingAs($user)->post(route('calculations.store'), $payload)->assertRedirect();
        $calculation = $this->saved();
        $line = CalculationPositionProductionLine::query()->firstOrFail();
        $this->assertTrue($line->is_discountable);
        $this->assertTrue($line->is_ae_eligible);
        $this->assertSame('688.50', (string) $calculation->nn_invest);
        $this->assertSame('90.00', (string) $calculation->position_discount_total);

        // Pin der Flags: Admin schaltet sie ab, Bestand bleibt.
        $list->update(['status' => PriceListStatus::Archived, 'archived_at' => now()]);
        $this->createActiveProductionPriceList($catalog['a'], '150.00', false, false);
        $this->updateFromStored($user, $calculation, fn (array $p): array => $p)->assertRedirect();
        $this->assertTrue((bool) $line->fresh()->is_discountable);
        $this->assertSame('688.50', (string) $calculation->fresh()->nn_invest);

        // Dispo-Kopf bleibt Summe der Zeilen (inkl. Produktions-Rabatt/AE).
        $calculation = $calculation->fresh('positions');
        $order = app(DispoOrderWriter::class)->createFromCalculation(
            $calculation,
            $calculation->positions->pluck('id')->all(),
            $user,
        )->order->fresh(['positions', 'productionLines']);
        $this->assertSame('688.50', (string) $order->nn_invest);
        $this->assertSame('90.00', (string) $order->position_discount_total);
        $this->assertSame('121.50', (string) $order->ae_total);
        $this->assertSame('229.50', (string) $order->productionLines->first()->nn_invest);
        $this->assertSame('459.00', (string) $order->positions->first()->nn_invest);
    }

    public function test_regression_spot_without_production_is_unchanged(): void
    {
        $catalog = $this->createTrailerAverageCatalog();
        $user = $this->sales();
        $payload = $this->trailerPayload([
            $this->spotPosition($catalog['a'], $catalog['spot'], 10, null, ['position_discount_percent' => '10', 'ae_percent' => '15']),
            $this->spotPosition($catalog['b'], $catalog['spot'], 5),
        ], ['ae_enabled' => true]);

        $preview = $this->preview($user, $payload);
        $preview->assertOk();
        // A: 600 → 540 → AE 81 → 459; B: 225 → 225 → AE 33,75 → 191,25
        $this->assertSame('825.00', $preview->json('totals.media_gross'));
        $this->assertSame('650.25', $preview->json('totals.nn_invest'));
        $this->assertSame('60.00', $preview->json('totals.position_discount_total'));
        $this->assertSame('114.75', $preview->json('totals.ae_total'));
        $this->assertSame('0.00', $preview->json('totals.positions.0.production_gross'));
        $this->assertSame([], $preview->json('totals.positions.0.production_lines'));

        $this->actingAs($user)->post(route('calculations.store'), $payload)->assertRedirect();
        $calculation = $this->saved();
        $this->assertSame('650.25', (string) $calculation->nn_invest);
        $this->assertSame(0, CalculationPositionProductionLine::query()->count());

        $order = app(DispoOrderWriter::class)->createFromCalculation(
            $calculation->load('positions'),
            $calculation->positions->pluck('id')->all(),
            $user,
        )->order->fresh(['positions', 'productionLines']);
        $this->assertCount(2, $order->positions);
        $this->assertCount(0, $order->productionLines);
        $this->assertSame('650.25', (string) $order->nn_invest);
        $this->assertSame('459.00', (string) $order->positions->first()->nn_invest);
    }

    /**
     * Träger-Flags über Regel und Medium setzen (CatalogResolver: rule ∧ medium).
     */
    private function setCarrierFlags(
        Inventory $inventory,
        AdvertisingMedium $medium,
        bool $discountable,
        bool $aeEligible,
    ): void {
        InventoryMediumRule::query()
            ->where('inventory_id', $inventory->id)
            ->where('advertising_medium_id', $medium->id)
            ->update([
                'is_discountable' => $discountable,
                'is_ae_eligible' => $aeEligible,
            ]);
        $medium->update([
            'is_discountable' => $discountable,
            'is_ae_eligible' => $aeEligible,
        ]);
    }

    public function test_review_production_position_discount_independent_of_carrier_flag(): void
    {
        $catalog = $this->createTrailerAverageCatalog();
        $this->setCarrierFlags($catalog['a'], $catalog['spot'], false, false);
        $this->createActiveProductionPriceList($catalog['a'], '150.00', true, false);
        $user = $this->sales();
        $payload = $this->trailerPayload([
            $this->spotPosition($catalog['a'], $catalog['spot'], 10, [
                $this->line('cccccccc-cccc-4ccc-8ccc-cccccccccccc', '2'),
            ], ['position_discount_percent' => '10']),
        ]);

        $preview = $this->preview($user, $payload);
        $preview->assertOk();
        // Produktion 300 −10 % = 270; Medien 600 unverändert (Träger nicht rabattfähig).
        $this->assertSame('300.00', $preview->json('totals.positions.0.production_gross'));
        $this->assertSame('30.00', $preview->json('totals.positions.0.production_lines.0.position_discount_amount'));
        $this->assertSame('270.00', $preview->json('totals.positions.0.production_lines.0.nn_invest'));
        $this->assertSame('600.00', $preview->json('totals.positions.0.media_gross'));
        $this->assertSame('0.00', $preview->json('totals.positions.0.position_discount_amount'));
        $this->assertSame('870.00', $preview->json('totals.positions.0.nn_invest'));

        // Umgekehrt: Träger rabattfähig, Produktion nicht → Produktion bleibt 300.
        $this->setCarrierFlags($catalog['a'], $catalog['spot'], true, false);
        ProductionPriceList::query()->where('inventory_id', $catalog['a']->id)->delete();
        $this->createActiveProductionPriceList($catalog['a'], '150.00', false, false);
        $previewReverse = $this->preview($user, $payload);
        $previewReverse->assertOk();
        $this->assertSame('300.00', $previewReverse->json('totals.positions.0.production_lines.0.nn_invest'));
        $this->assertSame('0.00', $previewReverse->json('totals.positions.0.production_lines.0.position_discount_amount'));
        $this->assertSame('60.00', $previewReverse->json('totals.positions.0.position_discount_amount'));
    }

    /**
     * @return list<array{0: bool, 1: bool, 2: string, 3: string}>
     */
    public static function discountFlagMatrix(): array
    {
        // carrierDisc, prodDisc, mediaPosDisc, prodPosDisc
        return [
            [false, false, '0.00', '0.00'],
            [false, true, '0.00', '30.00'],
            [true, false, '60.00', '0.00'],
            [true, true, '60.00', '30.00'],
        ];
    }

    #[DataProvider('discountFlagMatrix')]
    public function test_review_discount_flags_four_combinations(
        bool $carrierDisc,
        bool $prodDisc,
        string $mediaPosDisc,
        string $prodPosDisc,
    ): void {
        $catalog = $this->createTrailerAverageCatalog();
        $this->setCarrierFlags($catalog['a'], $catalog['spot'], $carrierDisc, false);
        $this->createActiveProductionPriceList($catalog['a'], '150.00', $prodDisc, false);
        $user = $this->sales();
        $payload = $this->trailerPayload([
            $this->spotPosition($catalog['a'], $catalog['spot'], 10, [
                $this->line('dddddddd-dddd-4ddd-8ddd-dddddddddddd', '2'),
            ], ['position_discount_percent' => '10']),
        ]);

        $preview = $this->preview($user, $payload)->assertOk();
        $this->assertSame($mediaPosDisc, $preview->json('totals.positions.0.position_discount_amount'));
        $this->assertSame($prodPosDisc, $preview->json('totals.positions.0.production_lines.0.position_discount_amount'));
    }

    public function test_review_stacked_position_and_order_discount_on_production_only(): void
    {
        $catalog = $this->createTrailerAverageCatalog();
        $this->setCarrierFlags($catalog['a'], $catalog['spot'], false, false);
        $this->createActiveProductionPriceList($catalog['a'], '150.00', true, false);
        $user = $this->sales();
        $payload = $this->trailerPayload([
            $this->spotPosition($catalog['a'], $catalog['spot'], 10, [
                $this->line('eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee', '2'),
            ], [
                'position_discounts' => [
                    ['type' => 'quantity', 'percent' => '10'],
                    ['type' => 'special', 'percent' => '5'],
                ],
            ]),
        ], [
            'order_discounts' => [['type' => 'quantity', 'percent' => '10']],
            'ae_enabled' => false,
        ]);

        $preview = $this->preview($user, $payload)->assertOk();
        // 300 → −10 % → 270 → −5 % → 256,50 → −10 % Auftrag → 230,85
        $this->assertSame('43.50', $preview->json('totals.positions.0.production_lines.0.position_discount_amount'));
        $this->assertSame('25.65', $preview->json('totals.positions.0.production_lines.0.order_discount_amount'));
        $this->assertSame('230.85', $preview->json('totals.positions.0.production_lines.0.nn_invest'));
        $this->assertSame('0.00', $preview->json('totals.positions.0.position_discount_amount'));
        $this->assertSame('0.00', $preview->json('totals.positions.0.order_discount_amount'));
        $this->assertSame('600.00', $preview->json('totals.media_gross'));
    }

    public function test_review_special_approval_for_production_order_discount_alone(): void
    {
        $catalog = $this->createTrailerAverageCatalog();
        $this->setCarrierFlags($catalog['a'], $catalog['spot'], false, false);
        $this->createActiveProductionPriceList($catalog['a'], '150.00', true, false);
        $user = User::factory()->role(Role::Sales)->create(['discount_limit_percent' => '10']);
        $payload = $this->trailerPayload([
            $this->spotPosition($catalog['a'], $catalog['spot'], 10, [
                $this->line('ffffffff-ffff-4fff-8fff-ffffffffffff', '2'),
            ]),
        ], [
            'order_discounts' => [['type' => 'quantity', 'percent' => '20']],
            'ae_enabled' => false,
        ]);

        $preview = $this->preview($user, $payload)->assertOk();
        $this->assertTrue($preview->json('totals.requires_special_approval'));
        $codes = collect($preview->json('totals.special_approval_reasons'))->pluck('code')->all();
        $this->assertContains(SpecialApprovalReasonCode::OrderDiscountExceedsLimit->value, $codes);
        // Produktion 300 −20 % = 240; Medien unverändert 600.
        $this->assertSame('60.00', $preview->json('totals.positions.0.production_lines.0.order_discount_amount'));
        $this->assertSame('240.00', $preview->json('totals.positions.0.production_lines.0.nn_invest'));
        $this->assertSame('0.00', $preview->json('totals.positions.0.order_discount_amount'));

        $this->actingAs($user)->post(route('calculations.store'), $payload)->assertRedirect();
        $calculation = $this->saved()->load(['positions.productionLines', 'positions.discounts', 'positions.inventory']);
        $this->assertTrue((bool) $calculation->requires_special_approval);

        $full = app(SpecialApprovalAssessor::class)->assessFromCalculation(
            $calculation,
            $calculation->positions,
        );
        $this->assertTrue($full->requiresSpecialApproval);

        $order = app(DispoOrderWriter::class)->createFromCalculation(
            $calculation,
            $calculation->positions->pluck('id')->all(),
            $user,
        )->order;
        $this->assertTrue((bool) $order->requires_special_approval);
    }

    public function test_review_special_approval_effective_stack_on_production(): void
    {
        $catalog = $this->createTrailerAverageCatalog();
        $this->setCarrierFlags($catalog['a'], $catalog['spot'], false, false);
        $this->createActiveProductionPriceList($catalog['a'], '150.00', true, false);
        $user = User::factory()->role(Role::Sales)->create(['discount_limit_percent' => '10']);
        $payload = $this->trailerPayload([
            $this->spotPosition($catalog['a'], $catalog['spot'], 10, [
                $this->line('11111111-aaaa-4aaa-8aaa-aaaaaaaaaaaa', '2'),
            ], ['position_discount_percent' => '8']),
        ], [
            'order_discounts' => [['type' => 'quantity', 'percent' => '8']],
            'ae_enabled' => false,
        ]);

        $preview = $this->preview($user, $payload)->assertOk();
        $this->assertTrue($preview->json('totals.requires_special_approval'));
        $codes = collect($preview->json('totals.special_approval_reasons'))->pluck('code')->all();
        $this->assertContains(SpecialApprovalReasonCode::EffectiveDiscountExceedsLimit->value, $codes);
        $this->assertNotContains(SpecialApprovalReasonCode::PositionDiscountExceedsLimit->value, $codes);
        $this->assertNotContains(SpecialApprovalReasonCode::OrderDiscountExceedsLimit->value, $codes);
    }

    public function test_review_special_approval_partial_dispo_and_non_discountable_production(): void
    {
        $catalog = $this->createTrailerAverageCatalog();
        $this->setCarrierFlags($catalog['a'], $catalog['spot'], false, false);
        $this->setCarrierFlags($catalog['b'], $catalog['spot'], false, false);
        $this->createActiveProductionPriceList($catalog['a'], '150.00', true, false);
        $this->createActiveProductionPriceList($catalog['b'], '80.00', false, false);
        $user = User::factory()->role(Role::Sales)->create(['discount_limit_percent' => '10']);
        $payload = $this->trailerPayload([
            $this->spotPosition($catalog['a'], $catalog['spot'], 10, [
                $this->line('22222222-bbbb-4bbb-8bbb-bbbbbbbbbbbb', '2'),
            ]),
            $this->spotPosition($catalog['b'], $catalog['spot'], 5, [
                $this->line('33333333-cccc-4ccc-8ccc-cccccccccccc', '3'),
            ]),
        ], [
            'order_discounts' => [['type' => 'quantity', 'percent' => '20']],
            'ae_enabled' => false,
        ]);

        $this->actingAs($user)->post(route('calculations.store'), $payload)->assertRedirect();
        $calculation = $this->saved()->load(['positions.productionLines', 'positions.discounts', 'positions.inventory']);
        $positions = $calculation->positions()->orderBy('sort')->get();
        $this->assertTrue((bool) $calculation->requires_special_approval);

        $onlyB = app(SpecialApprovalAssessor::class)->assessFromCalculation(
            $calculation,
            $positions->slice(1)->values(),
        );
        $this->assertFalse($onlyB->requiresSpecialApproval);

        $onlyA = app(SpecialApprovalAssessor::class)->assessFromCalculation(
            $calculation,
            $positions->slice(0, 1)->values(),
        );
        $this->assertTrue($onlyA->requiresSpecialApproval);

        // Produktion B nicht rabattfähig → keine produktionsbedingte Grenzüberschreitung allein.
        $payloadOnlyB = $this->trailerPayload([
            $this->spotPosition($catalog['b'], $catalog['spot'], 5, [
                $this->line('44444444-dddd-4ddd-8ddd-dddddddddddd', '3'),
            ]),
        ], [
            'order_discounts' => [['type' => 'quantity', 'percent' => '20']],
            'ae_enabled' => false,
        ]);
        $previewB = $this->preview($user, $payloadOnlyB)->assertOk();
        $this->assertFalse($previewB->json('totals.requires_special_approval'));
        $this->assertSame('0.00', $previewB->json('totals.positions.0.production_lines.0.order_discount_amount'));
        $this->assertSame('240.00', $previewB->json('totals.positions.0.production_lines.0.nn_invest'));
    }

    public function test_review_production_ae_percent_frozen_when_carrier_not_ae_eligible(): void
    {
        $catalog = $this->createTrailerAverageCatalog();
        $this->setCarrierFlags($catalog['a'], $catalog['spot'], false, false);
        $list = $this->createActiveProductionPriceList($catalog['a'], '150.00', false, true);
        $user = $this->sales();
        $payload = $this->trailerPayload([
            $this->spotPosition($catalog['a'], $catalog['spot'], 10, [
                $this->line('55555555-eeee-4eee-8eee-eeeeeeeeeeee', '2'),
            ]),
        ], ['ae_enabled' => true]);

        $preview = $this->preview($user, $payload)->assertOk();
        // 300 → AE 15 % = 45 → N/N 255; Medien ohne AE.
        $this->assertSame('45.00', $preview->json('totals.positions.0.production_lines.0.ae_amount'));
        $this->assertSame('15.0000', $preview->json('totals.positions.0.production_lines.0.ae_percent'));
        $this->assertSame('255.00', $preview->json('totals.positions.0.production_lines.0.nn_invest'));
        $this->assertSame('0.00', $preview->json('totals.positions.0.ae_amount'));
        $this->assertSame('600.00', $preview->json('totals.positions.0.media_nn_invest'));

        $this->actingAs($user)->post(route('calculations.store'), $payload)->assertRedirect();
        $calculation = $this->saved()->load('positions.productionLines');
        $line = $calculation->positions[0]->productionLines->firstOrFail();
        $this->assertSame('15.0000', (string) $line->ae_percent);
        $this->assertSame('45.00', (string) $line->ae_amount);
        $this->assertSame('0.0000', (string) $calculation->positions[0]->ae_percent);

        // Admin-Nachfolger ändert Flags nicht am Pin.
        $list->update(['status' => PriceListStatus::Archived, 'archived_at' => now()]);
        $this->createActiveProductionPriceList($catalog['a'], '999.00', false, false);
        $this->updateFromStored($user, $calculation, fn (array $p): array => $p)->assertRedirect();
        $line = $line->fresh();
        $this->assertTrue((bool) $line->is_ae_eligible);
        $this->assertSame('15.0000', (string) $line->ae_percent);
        $this->assertSame('150.00', (string) $line->unit_price);

        $order = app(DispoOrderWriter::class)->createFromCalculation(
            $calculation->fresh('positions'),
            $calculation->positions->pluck('id')->all(),
            $user,
        )->order->fresh('productionLines');
        $dispoLine = $order->productionLines->firstOrFail();
        $this->assertSame('15.0000', (string) $dispoLine->ae_percent);
        $this->assertSame('45.00', (string) $dispoLine->ae_amount);
        $this->assertSame('255.00', (string) $dispoLine->nn_invest);

        // Snapshot bleibt bei späteren Calc-Änderungen unverändert.
        $this->updateFromStored($user, $calculation, function (array $p): array {
            $p['ae_enabled'] = false;

            return $p;
        })->assertRedirect();
        $this->assertSame('15.0000', (string) $dispoLine->fresh()->ae_percent);
        $this->assertSame('45.00', (string) $dispoLine->fresh()->ae_amount);
    }

    /**
     * @return list<array{0: bool, 1: bool, 2: string, 3: string}>
     */
    public static function aeFlagMatrix(): array
    {
        // carrierAe, prodAe, mediaAe, prodAeAmount
        return [
            [false, false, '0.00', '0.00'],
            [false, true, '0.00', '45.00'],
            [true, false, '90.00', '0.00'],
            [true, true, '90.00', '45.00'],
        ];
    }

    #[DataProvider('aeFlagMatrix')]
    public function test_review_ae_flags_four_combinations(
        bool $carrierAe,
        bool $prodAe,
        string $mediaAe,
        string $prodAeAmount,
    ): void {
        $catalog = $this->createTrailerAverageCatalog();
        $this->setCarrierFlags($catalog['a'], $catalog['spot'], false, $carrierAe);
        $this->createActiveProductionPriceList($catalog['a'], '150.00', false, $prodAe);
        $user = $this->sales();
        $payload = $this->trailerPayload([
            $this->spotPosition($catalog['a'], $catalog['spot'], 10, [
                $this->line('66666666-ffff-4fff-8fff-ffffffffffff', '2'),
            ]),
        ], ['ae_enabled' => true]);

        $preview = $this->preview($user, $payload)->assertOk();
        $this->assertSame($mediaAe, $preview->json('totals.positions.0.ae_amount'));
        $this->assertSame($prodAeAmount, $preview->json('totals.positions.0.production_lines.0.ae_amount'));
    }

    public function test_review_ae_disabled_and_discount_plus_ae_on_production(): void
    {
        $catalog = $this->createTrailerAverageCatalog();
        $this->setCarrierFlags($catalog['a'], $catalog['spot'], false, false);
        $this->createActiveProductionPriceList($catalog['a'], '150.00', true, true);
        $user = $this->sales();

        $off = $this->trailerPayload([
            $this->spotPosition($catalog['a'], $catalog['spot'], 10, [
                $this->line('77777777-aaaa-4aaa-8aaa-aaaaaaaaaaaa', '2'),
            ], ['position_discount_percent' => '10']),
        ], ['ae_enabled' => false]);
        $previewOff = $this->preview($user, $off)->assertOk();
        $this->assertSame('0.00', $previewOff->json('totals.positions.0.production_lines.0.ae_amount'));
        $this->assertSame('0.0000', $previewOff->json('totals.positions.0.production_lines.0.ae_percent'));
        $this->assertSame('270.00', $previewOff->json('totals.positions.0.production_lines.0.nn_invest'));

        $on = $this->trailerPayload([
            $this->spotPosition($catalog['a'], $catalog['spot'], 10, [
                $this->line('88888888-bbbb-4bbb-8bbb-bbbbbbbbbbbb', '2'),
            ], ['position_discount_percent' => '10']),
        ], ['ae_enabled' => true]);
        $previewOn = $this->preview($user, $on)->assertOk();
        // 300 −10 % = 270 → AE 15 % = 40,50 → 229,50
        $this->assertSame('40.50', $previewOn->json('totals.positions.0.production_lines.0.ae_amount'));
        $this->assertSame('15.0000', $previewOn->json('totals.positions.0.production_lines.0.ae_percent'));
        $this->assertSame('229.50', $previewOn->json('totals.positions.0.production_lines.0.nn_invest'));
    }

    public function test_review_from_calc_rejects_production_lines_including_qty_zero(): void
    {
        $catalog = $this->createTrailerAverageCatalog();
        $this->createActiveProductionPriceList($catalog['a'], '150.00');
        $this->createActiveProductionPriceList($catalog['b'], '80.00');
        $user = $this->sales();

        $withProduction = $this->trailerPayload([
            $this->spotPosition($catalog['a'], $catalog['spot'], 10, [
                $this->line('99999999-cccc-4ccc-8ccc-cccccccccccc', '2'),
            ]),
        ]);
        $this->actingAs($user)->post(route('calculations.store'), $withProduction)->assertRedirect();
        $calcWith = $this->saved();
        $offersBefore = StandardOffer::query()->count();

        $this->from(route('calculations.edit', $calcWith))
            ->actingAs($user)
            ->post(route('standard-offers.from-calculation', $calcWith))
            ->assertRedirect(route('calculations.edit', $calcWith))
            ->assertSessionHasErrors('positions.0.production_lines');
        $this->assertSame($offersBefore, StandardOffer::query()->count());

        try {
            app(StandardOfferWriter::class)
                ->createFromCalculation($calcWith, $user);
            $this->fail('Expected ValidationException for production source');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('positions.0.production_lines', $e->errors());
        }
        $this->assertSame($offersBefore, StandardOffer::query()->count());

        // Menge 0 zählt als vorhandene Produktionszeile.
        $qtyZero = $this->trailerPayload([
            $this->spotPosition($catalog['a'], $catalog['spot'], 10, [
                $this->line('aaaaaaaa-dddd-4ddd-8ddd-dddddddddddd', '0'),
            ]),
        ]);
        $this->actingAs($user)->post(route('calculations.store'), $qtyZero)->assertRedirect();
        $calcZero = Calculation::query()->orderByDesc('id')->firstOrFail();
        $this->from(route('calculations.edit', $calcZero))
            ->actingAs($user)
            ->post(route('standard-offers.from-calculation', $calcZero))
            ->assertSessionHasErrors('positions.0.production_lines');
        $this->assertSame($offersBefore, StandardOffer::query()->count());

        // Gemischt: nur ein Träger mit Produktion → komplette Ablehnung.
        $mixed = $this->trailerPayload([
            $this->spotPosition($catalog['a'], $catalog['spot'], 10, [
                $this->line('bbbbbbbb-eeee-4eee-8eee-eeeeeeeeeeee', '1'),
            ]),
            $this->spotPosition($catalog['b'], $catalog['spot'], 5),
        ]);
        $this->actingAs($user)->post(route('calculations.store'), $mixed)->assertRedirect();
        $calcMixed = Calculation::query()->orderByDesc('id')->firstOrFail();
        $this->from(route('calculations.edit', $calcMixed))
            ->actingAs($user)
            ->post(route('standard-offers.from-calculation', $calcMixed))
            ->assertSessionHasErrors();
        $this->assertSame($offersBefore, StandardOffer::query()->count());

        // Ohne Produktion: bisheriger Flow bleibt erfolgreich.
        $without = $this->trailerPayload([
            $this->spotPosition($catalog['a'], $catalog['spot'], 10),
        ]);
        $this->actingAs($user)->post(route('calculations.store'), $without)->assertRedirect();
        $calcWithout = Calculation::query()->orderByDesc('id')->firstOrFail();
        $this->actingAs($user)
            ->post(route('standard-offers.from-calculation', $calcWithout))
            ->assertRedirect();
        $this->assertSame($offersBefore + 1, StandardOffer::query()->count());
    }

    public function test_review_preview_save_reload_qty_change_dispo_with_divergent_flags(): void
    {
        $catalog = $this->createTrailerAverageCatalog();
        $this->setCarrierFlags($catalog['a'], $catalog['spot'], false, false);
        $list = $this->createActiveProductionPriceList($catalog['a'], '150.00', true, true);
        $user = $this->sales();
        $payload = $this->trailerPayload([
            $this->spotPosition($catalog['a'], $catalog['spot'], 10, [
                $this->line('cccccccc-ffff-4fff-8fff-ffffffffffff', '2'),
            ], ['position_discount_percent' => '10']),
        ], ['ae_enabled' => true]);

        $preview = $this->preview($user, $payload)->assertOk();
        $this->assertSame('229.50', $preview->json('totals.positions.0.production_lines.0.nn_invest'));
        $this->assertSame('15.0000', $preview->json('totals.positions.0.production_lines.0.ae_percent'));

        $this->actingAs($user)->post(route('calculations.store'), $payload)->assertRedirect();
        $calculation = $this->saved();
        $this->assertSame($preview->json('totals.nn_invest'), (string) $calculation->nn_invest);

        $this->updateFromStored($user, $calculation, function (array $p): array {
            $p['positions'][0]['production_lines'][0]['quantity'] = '3';

            return $p;
        })->assertRedirect();
        $calculation = $calculation->fresh('positions.productionLines');
        $line = $calculation->positions[0]->productionLines->firstOrFail();
        // 450 −10 % = 405 → AE 15 % = 60,75 → 344,25; Pin Preis/Flags bleibt.
        $this->assertSame('150.00', (string) $line->unit_price);
        $this->assertTrue((bool) $line->is_discountable);
        $this->assertTrue((bool) $line->is_ae_eligible);
        $this->assertSame('15.0000', (string) $line->ae_percent);
        $this->assertSame('344.25', (string) $line->nn_invest);

        $list->update(['status' => PriceListStatus::Archived, 'archived_at' => now()]);
        $this->createActiveProductionPriceList($catalog['a'], '200.00', false, false);
        $this->updateFromStored($user, $calculation, fn (array $p): array => $p)->assertRedirect();
        $line = $line->fresh();
        $this->assertSame('150.00', (string) $line->unit_price);
        $this->assertTrue((bool) $line->is_discountable);
        $this->assertSame('15.0000', (string) $line->ae_percent);

        $order = app(DispoOrderWriter::class)->createFromCalculation(
            $calculation->fresh('positions'),
            $calculation->positions->pluck('id')->all(),
            $user,
        )->order->fresh('productionLines');
        $this->assertSame('15.0000', (string) $order->productionLines->first()->ae_percent);
        $this->assertSame('344.25', (string) $order->productionLines->first()->nn_invest);
    }

    public function test_review_p1_mixed_production_flags_special_approval_via_admin_successor_pin(): void
    {
        $catalog = $this->createTrailerAverageCatalog();
        $this->setCarrierFlags($catalog['a'], $catalog['spot'], false, false);
        $this->setCarrierFlags($catalog['b'], $catalog['spot'], false, false);
        $listOld = $this->createActiveProductionPriceList($catalog['a'], '150.00', true, false);
        $this->createActiveProductionPriceList($catalog['b'], '80.00', false, false);
        $user = User::factory()->role(Role::Sales)->create(['discount_limit_percent' => '10']);

        $payload = $this->trailerPayload([
            $this->spotPosition($catalog['a'], $catalog['spot'], 10, [
                $this->line('a1111111-aaaa-4aaa-8aaa-aaaaaaaaaaaa', '2', 'Alt pinnt'),
            ], ['position_discount_percent' => '8']),
            $this->spotPosition($catalog['b'], $catalog['spot'], 5),
        ], [
            'order_discounts' => [['type' => 'quantity', 'percent' => '8']],
            'ae_enabled' => false,
        ]);

        $this->actingAs($user)->post(route('calculations.store'), $payload)->assertRedirect();
        $calculation = $this->saved();

        // Admin-Nachfolger: nicht rabattfähig; alte Zeile behält Pin true.
        $listOld->update(['status' => PriceListStatus::Archived, 'archived_at' => now()]);
        $this->createActiveProductionPriceList($catalog['a'], '150.00', false, false);

        $this->updateFromStored($user, $calculation, function (array $p): array {
            $p['positions'][0]['production_lines'][] = $this->line(
                'a2222222-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
                '2',
                'Neu aus Nachfolger',
            );

            return $p;
        })->assertRedirect();

        $calculation = $calculation->fresh(['positions.productionLines', 'positions.discounts', 'positions.inventory']);
        $positionA = $calculation->positions()->orderBy('sort')->firstOrFail();
        $lines = $positionA->productionLines()->orderBy('sort')->get();
        $this->assertCount(2, $lines);
        $old = $lines->firstWhere('client_key', 'a1111111-aaaa-4aaa-8aaa-aaaaaaaaaaaa');
        $neu = $lines->firstWhere('client_key', 'a2222222-bbbb-4bbb-8bbb-bbbbbbbbbbbb');
        $this->assertNotNull($old);
        $this->assertNotNull($neu);
        $this->assertTrue((bool) $old->is_discountable);
        $this->assertFalse((bool) $neu->is_discountable);
        $this->assertSame('150.00', (string) $old->unit_price);
        $this->assertSame('150.00', (string) $neu->unit_price);
        // Alte Zeile: 300 → 276 → 253,92 (Rabatt 46,08 = 15,36 %); neue bleibt 300.
        $this->assertSame('24.00', (string) $old->position_discount_amount);
        $this->assertSame('22.08', (string) $old->order_discount_amount);
        $this->assertSame('46.08', bcadd((string) $old->position_discount_amount, (string) $old->order_discount_amount, 2));
        $this->assertSame('253.92', (string) $old->nn_invest);
        $this->assertSame('0.00', (string) $neu->position_discount_amount);
        $this->assertSame('0.00', (string) $neu->order_discount_amount);
        $this->assertSame('300.00', (string) $neu->nn_invest);

        $preview = $this->preview($user, array_merge(
            app(CalculationWriter::class)->payloadFromCalculation($calculation),
            ['calculation_id' => $calculation->id],
        ))->assertOk();
        $this->assertTrue($preview->json('totals.requires_special_approval'));
        $effective = collect($preview->json('totals.special_approval_reasons'))
            ->firstWhere('code', SpecialApprovalReasonCode::EffectiveDiscountExceedsLimit->value);
        $this->assertNotNull($effective);
        $this->assertSame('15.3600', $effective['actual_percent']);
        $this->assertNotSame('7.6800', $effective['actual_percent']);

        $this->assertTrue((bool) $calculation->requires_special_approval);
        $full = app(SpecialApprovalAssessor::class)->assessFromCalculation(
            $calculation,
            $calculation->positions,
        );
        $this->assertTrue($full->requiresSpecialApproval);
        $storedEffective = collect($full->reasons)
            ->firstWhere('code', SpecialApprovalReasonCode::EffectiveDiscountExceedsLimit->value);
        $this->assertNotNull($storedEffective);
        $this->assertSame('15.3600', $storedEffective['actual_percent']);

        $positions = $calculation->positions()->orderBy('sort')->get();
        $onlyB = app(SpecialApprovalAssessor::class)->assessFromCalculation(
            $calculation,
            $positions->slice(1)->values(),
        );
        $this->assertFalse($onlyB->requiresSpecialApproval);
        $onlyA = app(SpecialApprovalAssessor::class)->assessFromCalculation(
            $calculation,
            $positions->slice(0, 1)->values(),
        );
        $this->assertTrue($onlyA->requiresSpecialApproval);

        // Weitere nicht rabattfähige Zeile verändert die Freigabe nicht.
        $this->updateFromStored($user, $calculation, function (array $p): array {
            $p['positions'][0]['production_lines'][] = $this->line(
                'a3333333-cccc-4ccc-8ccc-cccccccccccc',
                '1',
                'Noch eine nicht rabattfähig',
            );

            return $p;
        })->assertRedirect();
        $calculation = $calculation->fresh(['positions.productionLines', 'positions.discounts', 'positions.inventory']);
        $this->assertTrue((bool) $calculation->requires_special_approval);
        $afterExtra = app(SpecialApprovalAssessor::class)->assessFromCalculation(
            $calculation,
            $calculation->positions()->orderBy('sort')->get()->slice(0, 1)->values(),
        );
        $extraEffective = collect($afterExtra->reasons)
            ->firstWhere('code', SpecialApprovalReasonCode::EffectiveDiscountExceedsLimit->value);
        $this->assertNotNull($extraEffective);
        $this->assertSame('15.3600', $extraEffective['actual_percent']);

        $positionAId = (int) $calculation->positions()->orderBy('sort')->value('id');
        $order = app(DispoOrderWriter::class)->createFromCalculation(
            $calculation,
            [$positionAId],
            $user,
        )->order;
        $this->assertTrue((bool) $order->requires_special_approval);

        // Ausschließlich nicht rabattfähige Produktion → keine produktionsbedingte Freigabe.
        $onlyNonDisc = $this->trailerPayload([
            $this->spotPosition($catalog['a'], $catalog['spot'], 10, [
                $this->line('a4444444-dddd-4ddd-8ddd-dddddddddddd', '2'),
            ], ['position_discount_percent' => '8']),
        ], [
            'order_discounts' => [['type' => 'quantity', 'percent' => '8']],
            'ae_enabled' => false,
        ]);
        // Nachfolgerliste ist Active und nicht rabattfähig.
        $previewNon = $this->preview($user, $onlyNonDisc)->assertOk();
        $this->assertFalse($previewNon->json('totals.requires_special_approval'));
        $this->assertSame('0.00', $previewNon->json('totals.positions.0.production_lines.0.position_discount_amount'));
        $this->assertSame('300.00', $previewNon->json('totals.positions.0.production_lines.0.nn_invest'));
    }

    public function test_review_p2_production_position_discounts_frozen_in_dispo_snapshot(): void
    {
        $catalog = $this->createTrailerAverageCatalog();
        $this->setCarrierFlags($catalog['a'], $catalog['spot'], false, false);
        $list = $this->createActiveProductionPriceList($catalog['a'], '150.00', true, false);
        $user = $this->sales();
        $payload = $this->trailerPayload([
            $this->spotPosition($catalog['a'], $catalog['spot'], 10, [
                $this->line('b1111111-aaaa-4aaa-8aaa-aaaaaaaaaaaa', '2'),
            ], ['position_discount_percent' => '10']),
        ], ['ae_enabled' => false]);

        $preview = $this->preview($user, $payload)->assertOk();
        $this->assertSame('30.00', $preview->json('totals.positions.0.production_lines.0.position_discount_amount'));
        $this->assertSame('10.0000', $preview->json('totals.positions.0.production_lines.0.position_discount_percent'));
        $this->assertSame('270.00', $preview->json('totals.positions.0.production_lines.0.nn_invest'));
        $this->assertSame('0.00', $preview->json('totals.positions.0.position_discount_amount'));
        $this->assertSame('600.00', $preview->json('totals.media_gross'));

        $this->actingAs($user)->post(route('calculations.store'), $payload)->assertRedirect();
        $calculation = $this->saved()->load('positions.productionLines');
        $line = $calculation->positions[0]->productionLines->firstOrFail();
        $this->assertSame('10.0000', (string) $line->position_discount_percent);
        $this->assertSame('30.00', (string) $line->position_discount_amount);
        $this->assertSame(
            [['type' => 'quantity', 'custom_label' => null, 'percent' => '10.0000']],
            $line->position_discounts_snapshot,
        );
        $this->assertSame('0.0000', (string) $calculation->positions[0]->position_discount_percent);

        $order = app(DispoOrderWriter::class)->createFromCalculation(
            $calculation,
            $calculation->positions->pluck('id')->all(),
            $user,
        )->order->fresh(['positions', 'productionLines']);
        $dispoLine = $order->productionLines->firstOrFail();
        $this->assertSame('10.0000', (string) $dispoLine->position_discount_percent);
        $this->assertSame('30.00', (string) $dispoLine->position_discount_amount);
        $this->assertSame('270.00', (string) $dispoLine->nn_invest);
        $this->assertSame(
            [['type' => 'quantity', 'custom_label' => null, 'percent' => '10.0000']],
            $dispoLine->position_discounts_snapshot,
        );
        $this->assertSame('0.0000', (string) $order->positions->first()->position_discount_percent);
        $this->assertSame('600.00', (string) $order->positions->first()->media_gross);

        // Snapshot stabil bei späteren Calc-/Admin-Änderungen.
        $frozenPercent = (string) $dispoLine->position_discount_percent;
        $frozenAmount = (string) $dispoLine->position_discount_amount;
        $frozenSnap = $dispoLine->position_discounts_snapshot;
        $this->updateFromStored($user, $calculation, function (array $p): array {
            $p['positions'][0]['position_discount_percent'] = '20';
            $p['positions'][0]['production_lines'][0]['quantity'] = '4';

            return $p;
        })->assertRedirect();
        $list->update(['status' => PriceListStatus::Archived, 'archived_at' => now()]);
        $this->createActiveProductionPriceList($catalog['a'], '999.00', false, false);
        $this->assertSame($frozenPercent, (string) $dispoLine->fresh()->position_discount_percent);
        $this->assertSame($frozenAmount, (string) $dispoLine->fresh()->position_discount_amount);
        $this->assertSame($frozenSnap, $dispoLine->fresh()->position_discounts_snapshot);

        // Gestapelte Positionsrabatte 10 % + 5 % → effektiv 14,5 %, Betrag 43,50.
        ProductionPriceList::query()
            ->where('inventory_id', $catalog['a']->id)
            ->where('status', PriceListStatus::Active)
            ->update(['status' => PriceListStatus::Archived, 'archived_at' => now()]);
        $this->setCarrierFlags($catalog['a'], $catalog['spot'], false, false);
        $this->createActiveProductionPriceList($catalog['a'], '150.00', true, false);
        $stackedPayload = $this->trailerPayload([
            $this->spotPosition($catalog['a'], $catalog['spot'], 10, [
                $this->line('b2222222-bbbb-4bbb-8bbb-bbbbbbbbbbbb', '2'),
            ], [
                'position_discounts' => [
                    ['type' => 'quantity', 'percent' => '10'],
                    ['type' => 'special', 'percent' => '5'],
                ],
            ]),
        ], ['ae_enabled' => false]);
        $stackedPreview = $this->preview($user, $stackedPayload)->assertOk();
        $this->assertSame('43.50', $stackedPreview->json('totals.positions.0.production_lines.0.position_discount_amount'));
        $this->assertSame('14.5000', $stackedPreview->json('totals.positions.0.production_lines.0.position_discount_percent'));
        $this->assertSame('256.50', $stackedPreview->json('totals.positions.0.production_lines.0.nn_invest'));

        $this->actingAs($user)->post(route('calculations.store'), $stackedPayload)->assertRedirect();
        $stackedCalc = Calculation::query()->orderByDesc('id')->firstOrFail()->load('positions.productionLines');
        $stackedOrder = app(DispoOrderWriter::class)->createFromCalculation(
            $stackedCalc,
            $stackedCalc->positions->pluck('id')->all(),
            $user,
        )->order->fresh('productionLines');
        $stackedDispo = $stackedOrder->productionLines->firstOrFail();
        $this->assertSame('14.5000', (string) $stackedDispo->position_discount_percent);
        $this->assertSame('43.50', (string) $stackedDispo->position_discount_amount);
        $this->assertSame(
            [
                ['type' => 'quantity', 'custom_label' => null, 'percent' => '10.0000'],
                ['type' => 'special', 'custom_label' => null, 'percent' => '5.0000'],
            ],
            $stackedDispo->position_discounts_snapshot,
        );

        // Träger rabattfähig, Produktion nicht → Produktionsrabatt 0.
        ProductionPriceList::query()
            ->where('inventory_id', $catalog['a']->id)
            ->where('status', PriceListStatus::Active)
            ->update(['status' => PriceListStatus::Archived, 'archived_at' => now()]);
        $this->setCarrierFlags($catalog['a'], $catalog['spot'], true, false);
        $this->createActiveProductionPriceList($catalog['a'], '150.00', false, false);
        $reverse = $this->trailerPayload([
            $this->spotPosition($catalog['a'], $catalog['spot'], 10, [
                $this->line('b3333333-cccc-4ccc-8ccc-cccccccccccc', '2'),
            ], ['position_discount_percent' => '10']),
        ]);
        $revPreview = $this->preview($user, $reverse)->assertOk();
        $this->assertSame('0.00', $revPreview->json('totals.positions.0.production_lines.0.position_discount_amount'));
        $this->assertSame('0.0000', $revPreview->json('totals.positions.0.production_lines.0.position_discount_percent'));
        $this->assertSame([], $revPreview->json('totals.positions.0.production_lines.0.position_discounts'));
        $this->assertSame('300.00', $revPreview->json('totals.positions.0.production_lines.0.nn_invest'));
        $this->assertSame('60.00', $revPreview->json('totals.positions.0.position_discount_amount'));
    }

    public function test_review_p2_mixed_pinned_flags_dispo_discount_snapshots(): void
    {
        $catalog = $this->createTrailerAverageCatalog();
        $this->setCarrierFlags($catalog['a'], $catalog['spot'], false, false);
        $listOld = $this->createActiveProductionPriceList($catalog['a'], '150.00', true, false);
        $user = $this->sales();
        $payload = $this->trailerPayload([
            $this->spotPosition($catalog['a'], $catalog['spot'], 10, [
                $this->line('c1111111-aaaa-4aaa-8aaa-aaaaaaaaaaaa', '2'),
            ], ['position_discount_percent' => '10']),
        ]);
        $this->actingAs($user)->post(route('calculations.store'), $payload)->assertRedirect();
        $calculation = $this->saved();

        $listOld->update(['status' => PriceListStatus::Archived, 'archived_at' => now()]);
        $this->createActiveProductionPriceList($catalog['a'], '150.00', false, false);
        $this->updateFromStored($user, $calculation, function (array $p): array {
            $p['positions'][0]['production_lines'][] = $this->line(
                'c2222222-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
                '2',
            );

            return $p;
        })->assertRedirect();

        $calculation = $calculation->fresh('positions.productionLines');
        $calcLines = $calculation->positions[0]->productionLines->keyBy('client_key');
        $order = app(DispoOrderWriter::class)->createFromCalculation(
            $calculation,
            $calculation->positions->pluck('id')->all(),
            $user,
        )->order->fresh('productionLines');

        $oldDispo = $order->productionLines->first(fn ($row) => (bool) $row->is_discountable);
        $newDispo = $order->productionLines->first(fn ($row) => ! (bool) $row->is_discountable);
        $this->assertNotNull($oldDispo);
        $this->assertNotNull($newDispo);
        $this->assertSame('10.0000', (string) $oldDispo->position_discount_percent);
        $this->assertSame('30.00', (string) $oldDispo->position_discount_amount);
        $this->assertNotEmpty($oldDispo->position_discounts_snapshot);
        $this->assertSame('0.0000', (string) $newDispo->position_discount_percent);
        $this->assertSame('0.00', (string) $newDispo->position_discount_amount);
        $this->assertSame([], $newDispo->position_discounts_snapshot ?? []);
        $this->assertTrue((bool) $calcLines['c1111111-aaaa-4aaa-8aaa-aaaaaaaaaaaa']->is_discountable);
        $this->assertFalse((bool) $calcLines['c2222222-bbbb-4bbb-8bbb-bbbbbbbbbbbb']->is_discountable);
    }
}
