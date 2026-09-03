<?php

namespace Tests\Unit\DispoOrder;

use App\Enums\Role;
use App\Models\DispoOrder;
use App\Models\DispoOrderNumberSequence;
use App\Models\User;
use App\Services\DispoOrder\DispoOrderNumberSequencer;
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

    public function test_next_assigns_org_and_calculation_sequence(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $calculation = $this->createSavedCalculation($catalog, [
            ['inventory_id' => $catalog['hamburg']->id],
        ]);
        $sequencer = app(DispoOrderNumberSequencer::class);
        $year = (int) now('Europe/Berlin')->format('Y');

        [$numberYear, $orgSeq, $calcSeq, $number] = $sequencer->next($calculation);

        $this->assertSame($year, $numberYear);
        $this->assertSame(1, $orgSeq);
        $this->assertSame(1, $calcSeq);
        $this->assertSame(sprintf('DA-%d-%06d-%02d', $year, 1, 1), $number);
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
            'number' => 'DA-2026-000123-01',
            'number_year' => 2026,
            'number_org_seq' => 123,
            'number_calc_seq' => 1,
            'status' => 'draft',
            'created_by_id' => $user->id,
            'source_calculation_number' => $calculation->number,
        ]);

        $yearBefore = DispoOrderNumberSequence::query()->where('year', 2026)->value('last_seq');

        [$year, $orgSeq, $calcSeq, $number] = $sequencer->next($calculation);

        $this->assertSame(2026, $year);
        $this->assertSame(123, $orgSeq);
        $this->assertSame(2, $calcSeq);
        $this->assertSame('DA-2026-000123-02', $number);
        $this->assertSame(
            $yearBefore,
            DispoOrderNumberSequence::query()->where('year', 2026)->value('last_seq'),
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
                'number' => sprintf('DA-2026-000123-%02d', $seq),
                'number_year' => 2026,
                'number_org_seq' => 123,
                'number_calc_seq' => $seq,
                'status' => 'draft',
                'created_by_id' => $user->id,
                'source_calculation_number' => $calculation->number,
            ]);
        }

        [, $orgSeq, $calcSeq, $number] = $sequencer->next($calculation);

        $this->assertSame(123, $orgSeq);
        $this->assertSame(3, $calcSeq);
        $this->assertSame('DA-2026-000123-03', $number);
    }

    public function test_other_calculation_receives_new_stem_starting_at_01(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $first = $this->createSavedCalculation($catalog, [
            ['inventory_id' => $catalog['hamburg']->id],
        ]);
        $second = $this->createSavedCalculation($catalog, [
            ['inventory_id' => $catalog['rock']->id],
        ]);
        $sequencer = app(DispoOrderNumberSequencer::class);
        $year = (int) now('Europe/Berlin')->format('Y');

        [, $firstOrgSeq, $firstCalcSeq, $firstNumber] = $sequencer->next($first);
        [, $secondOrgSeq, $secondCalcSeq, $secondNumber] = $sequencer->next($second);

        $this->assertSame(1, $firstOrgSeq);
        $this->assertSame(1, $firstCalcSeq);
        $this->assertSame(2, $secondOrgSeq);
        $this->assertSame(1, $secondCalcSeq);
        $this->assertSame(sprintf('DA-%d-%06d-01', $year, 1), $firstNumber);
        $this->assertSame(sprintf('DA-%d-%06d-01', $year, 2), $secondNumber);
        $this->assertSame(2, DispoOrderNumberSequence::query()->where('year', $year)->value('last_seq'));
    }

    public function test_year_change_keeps_existing_family_stem(): void
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
            'number' => 'DA-2026-000123-01',
            'number_year' => 2026,
            'number_org_seq' => 123,
            'number_calc_seq' => 1,
            'status' => 'draft',
            'created_by_id' => $user->id,
            'source_calculation_number' => $calculation->number,
        ]);

        Carbon::setTestNow(Carbon::parse('2027-01-02 09:00:00', 'Europe/Berlin'));

        [$year, $orgSeq, $calcSeq, $number] = $sequencer->next($calculation);

        $this->assertSame(2026, $year);
        $this->assertSame(123, $orgSeq);
        $this->assertSame(2, $calcSeq);
        $this->assertSame('DA-2026-000123-02', $number);

        Carbon::setTestNow();
    }

    public function test_new_family_in_new_year_starts_yearly_sequence(): void
    {
        Carbon::setTestNow(Carbon::parse('2027-01-02 09:00:00', 'Europe/Berlin'));

        $catalog = $this->createSpotClassicCatalog();
        $calculation = $this->createSavedCalculation($catalog, [
            ['inventory_id' => $catalog['hamburg']->id],
        ]);
        $sequencer = app(DispoOrderNumberSequencer::class);

        [$year, $orgSeq, $calcSeq, $number] = $sequencer->next($calculation);

        $this->assertSame(2027, $year);
        $this->assertSame(1, $orgSeq);
        $this->assertSame(1, $calcSeq);
        $this->assertSame('DA-2027-000001-01', $number);
        $this->assertSame(1, DispoOrderNumberSequence::query()->where('year', 2027)->value('last_seq'));

        Carbon::setTestNow();
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
