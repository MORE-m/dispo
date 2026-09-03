<?php

namespace Tests\Unit\DispoOrder;

use App\Enums\Role;
use App\Models\DispoOrder;
use App\Models\DispoOrderNumberSequence;
use App\Models\User;
use App\Services\DispoOrder\DispoOrderNumberSequencer;
use App\Support\DocumentNumber;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesSavedCalculation;
use Tests\Concerns\CreatesSpotClassicCatalog;
use Tests\TestCase;

class DispoOrderNumberSequencerTest extends TestCase
{
    use CreatesSavedCalculation;
    use CreatesSpotClassicCatalog;
    use RefreshDatabase;

    public function test_first_order_derives_stem_from_calculation_number(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $calculation = $this->createSavedCalculation($catalog, [
            ['inventory_id' => $catalog['hamburg']->id],
        ]);
        $sequencer = app(DispoOrderNumberSequencer::class);

        [$numberYear, $orgSeq, $calcSeq, $number] = $sequencer->next($calculation);

        $this->assertSame($calculation->number_year, $numberYear);
        $this->assertSame($calculation->number_seq, $orgSeq);
        $this->assertSame(1, $calcSeq);
        $this->assertSame(
            DocumentNumber::dispoOrder($calculation->number_year, $calculation->number_seq, 1),
            $number,
        );
        $this->assertSame(
            'DA-'.substr($calculation->number, 2).'-01',
            $number,
        );
        $this->assertNull(
            DispoOrderNumberSequence::query()->where('year', $calculation->number_year)->value('last_seq'),
        );
    }

    public function test_k_2026_00005_produces_da_2026_00005_01(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $calculation = $this->createSavedCalculation($catalog, [
            ['inventory_id' => $catalog['hamburg']->id],
        ]);
        $calculation->forceFill([
            'number' => 'K-2026-00005',
            'number_year' => 2026,
            'number_seq' => 5,
        ])->save();

        [$year, $orgSeq, $calcSeq, $number] = app(DispoOrderNumberSequencer::class)->next($calculation);

        $this->assertSame(2026, $year);
        $this->assertSame(5, $orgSeq);
        $this->assertSame(1, $calcSeq);
        $this->assertSame('DA-2026-00005-01', $number);
    }

    public function test_second_order_reuses_stem_and_only_increments_suffix(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $calculation = $this->createSavedCalculation($catalog, [
            ['inventory_id' => $catalog['hamburg']->id],
        ]);
        $user = User::factory()->role(Role::Sales)->create();
        $sequencer = app(DispoOrderNumberSequencer::class);

        DispoOrder::query()->create([
            'calculation_id' => $calculation->id,
            'number' => DocumentNumber::dispoOrder($calculation->number_year, $calculation->number_seq, 1),
            'number_year' => $calculation->number_year,
            'number_org_seq' => $calculation->number_seq,
            'number_calc_seq' => 1,
            'status' => 'draft',
            'created_by_id' => $user->id,
            'source_calculation_number' => $calculation->number,
        ]);

        [$year, $orgSeq, $calcSeq, $number] = $sequencer->next($calculation);

        $this->assertSame($calculation->number_year, $year);
        $this->assertSame($calculation->number_seq, $orgSeq);
        $this->assertSame(2, $calcSeq);
        $this->assertSame(
            DocumentNumber::dispoOrder($calculation->number_year, $calculation->number_seq, 2),
            $number,
        );
    }

    public function test_third_order_keeps_stem_and_ends_with_03(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $calculation = $this->createSavedCalculation($catalog, [
            ['inventory_id' => $catalog['hamburg']->id],
        ]);
        $user = User::factory()->role(Role::Sales)->create();
        $sequencer = app(DispoOrderNumberSequencer::class);

        foreach ([1, 2] as $seq) {
            DispoOrder::query()->create([
                'calculation_id' => $calculation->id,
                'number' => DocumentNumber::dispoOrder($calculation->number_year, $calculation->number_seq, $seq),
                'number_year' => $calculation->number_year,
                'number_org_seq' => $calculation->number_seq,
                'number_calc_seq' => $seq,
                'status' => 'draft',
                'created_by_id' => $user->id,
                'source_calculation_number' => $calculation->number,
            ]);
        }

        [, $orgSeq, $calcSeq, $number] = $sequencer->next($calculation);

        $this->assertSame($calculation->number_seq, $orgSeq);
        $this->assertSame(3, $calcSeq);
        $this->assertSame(
            DocumentNumber::dispoOrder($calculation->number_year, $calculation->number_seq, 3),
            $number,
        );
    }

    public function test_other_calculation_uses_its_own_calculation_number(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $first = $this->createSavedCalculation($catalog, [
            ['inventory_id' => $catalog['hamburg']->id],
        ]);
        $second = $this->createSavedCalculation($catalog, [
            ['inventory_id' => $catalog['rock']->id],
        ]);
        $sequencer = app(DispoOrderNumberSequencer::class);

        [, $firstOrgSeq, $firstCalcSeq, $firstNumber] = $sequencer->next($first);
        [, $secondOrgSeq, $secondCalcSeq, $secondNumber] = $sequencer->next($second);

        $this->assertSame($first->number_seq, $firstOrgSeq);
        $this->assertSame(1, $firstCalcSeq);
        $this->assertSame($second->number_seq, $secondOrgSeq);
        $this->assertSame(1, $secondCalcSeq);
        $this->assertSame(
            DocumentNumber::dispoOrder($first->number_year, $first->number_seq, 1),
            $firstNumber,
        );
        $this->assertSame(
            DocumentNumber::dispoOrder($second->number_year, $second->number_seq, 1),
            $secondNumber,
        );
        $this->assertNotSame($firstNumber, $secondNumber);
        $this->assertNull(
            DispoOrderNumberSequence::query()->where('year', $first->number_year)->value('last_seq'),
        );
    }

    public function test_year_change_keeps_existing_family_stem_from_calculation(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-12-31 12:00:00', 'Europe/Berlin'));

        $catalog = $this->createSpotClassicCatalog();
        $calculation = $this->createSavedCalculation($catalog, [
            ['inventory_id' => $catalog['hamburg']->id],
        ]);
        $user = User::factory()->role(Role::Sales)->create();
        $sequencer = app(DispoOrderNumberSequencer::class);

        DispoOrder::query()->create([
            'calculation_id' => $calculation->id,
            'number' => DocumentNumber::dispoOrder($calculation->number_year, $calculation->number_seq, 1),
            'number_year' => $calculation->number_year,
            'number_org_seq' => $calculation->number_seq,
            'number_calc_seq' => 1,
            'status' => 'draft',
            'created_by_id' => $user->id,
            'source_calculation_number' => $calculation->number,
        ]);

        Carbon::setTestNow(Carbon::parse('2027-01-02 09:00:00', 'Europe/Berlin'));

        [$year, $orgSeq, $calcSeq, $number] = $sequencer->next($calculation);

        $this->assertSame($calculation->number_year, $year);
        $this->assertSame($calculation->number_seq, $orgSeq);
        $this->assertSame(2, $calcSeq);
        $this->assertSame(
            DocumentNumber::dispoOrder($calculation->number_year, $calculation->number_seq, 2),
            $number,
        );

        Carbon::setTestNow();
    }

    public function test_legacy_family_keeps_six_digit_stem_and_only_increments_suffix(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $calculation = $this->createSavedCalculation($catalog, [
            ['inventory_id' => $catalog['hamburg']->id],
        ]);
        $user = User::factory()->role(Role::Sales)->create();
        $sequencer = app(DispoOrderNumberSequencer::class);

        DispoOrder::query()->create([
            'calculation_id' => $calculation->id,
            'number' => 'DA-2026-000008-01',
            'number_year' => 2026,
            'number_org_seq' => 8,
            'number_calc_seq' => 1,
            'status' => 'draft',
            'created_by_id' => $user->id,
            'source_calculation_number' => $calculation->number,
        ]);

        $yearBefore = DispoOrderNumberSequence::query()->where('year', 2026)->value('last_seq');

        [$year, $orgSeq, $calcSeq, $number] = $sequencer->next($calculation);

        $this->assertSame(2026, $year);
        $this->assertSame(8, $orgSeq);
        $this->assertSame(2, $calcSeq);
        $this->assertSame('DA-2026-000008-02', $number);
        $this->assertSame(
            $yearBefore,
            DispoOrderNumberSequence::query()->where('year', 2026)->value('last_seq'),
        );
    }

    public function test_legacy_family_third_order_ends_with_03(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $calculation = $this->createSavedCalculation($catalog, [
            ['inventory_id' => $catalog['hamburg']->id],
        ]);
        $user = User::factory()->role(Role::Sales)->create();
        $sequencer = app(DispoOrderNumberSequencer::class);

        foreach ([1, 2] as $seq) {
            DispoOrder::query()->create([
                'calculation_id' => $calculation->id,
                'number' => sprintf('DA-2026-000008-%02d', $seq),
                'number_year' => 2026,
                'number_org_seq' => 8,
                'number_calc_seq' => $seq,
                'status' => 'draft',
                'created_by_id' => $user->id,
                'source_calculation_number' => $calculation->number,
            ]);
        }

        [, $orgSeq, $calcSeq, $number] = $sequencer->next($calculation);

        $this->assertSame(8, $orgSeq);
        $this->assertSame(3, $calcSeq);
        $this->assertSame('DA-2026-000008-03', $number);
    }

    public function test_historical_legacy_numbers_are_not_changed(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $calculation = $this->createSavedCalculation($catalog, [
            ['inventory_id' => $catalog['hamburg']->id],
        ]);
        $user = User::factory()->role(Role::Sales)->create();

        $existing = DispoOrder::query()->create([
            'calculation_id' => $calculation->id,
            'number' => 'DA-2026-000008-01',
            'number_year' => 2026,
            'number_org_seq' => 8,
            'number_calc_seq' => 1,
            'status' => 'draft',
            'created_by_id' => $user->id,
            'source_calculation_number' => $calculation->number,
        ]);

        app(DispoOrderNumberSequencer::class)->next($calculation);

        $existing->refresh();
        $this->assertSame('DA-2026-000008-01', $existing->number);
        $this->assertSame(8, $existing->number_org_seq);
        $this->assertSame(1, $existing->number_calc_seq);
    }

    public function test_future_order_uses_earliest_family_stem_when_history_is_inconsistent(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $calculation = $this->createSavedCalculation($catalog, [
            ['inventory_id' => $catalog['hamburg']->id],
        ]);
        $user = User::factory()->role(Role::Sales)->create();
        $sequencer = app(DispoOrderNumberSequencer::class);

        DispoOrder::query()->create([
            'calculation_id' => $calculation->id,
            'number' => 'DA-2026-000002-01',
            'number_year' => 2026,
            'number_org_seq' => 2,
            'number_calc_seq' => 1,
            'status' => 'draft',
            'created_by_id' => $user->id,
            'source_calculation_number' => $calculation->number,
        ]);
        DispoOrder::query()->create([
            'calculation_id' => $calculation->id,
            'number' => 'DA-2026-000003-02',
            'number_year' => 2026,
            'number_org_seq' => 3,
            'number_calc_seq' => 2,
            'status' => 'draft',
            'created_by_id' => $user->id,
            'source_calculation_number' => $calculation->number,
        ]);

        [$year, $orgSeq, $calcSeq, $number] = $sequencer->next($calculation);

        $this->assertSame(2026, $year);
        $this->assertSame(2, $orgSeq);
        $this->assertSame(3, $calcSeq);
        $this->assertSame('DA-2026-000002-03', $number);
    }
}
