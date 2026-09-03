<?php

namespace Tests\Unit\DispoOrder;

use App\Enums\Role;
use App\Models\DispoOrder;
use App\Models\DispoOrderNumberSequence;
use App\Models\User;
use App\Services\DispoOrder\DispoOrderNumberSequencer;
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

    public function test_calculation_suffix_increments_per_calculation(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $calculation = $this->createSavedCalculation($catalog, [
            ['inventory_id' => $catalog['hamburg']->id],
        ]);
        $user = User::factory()->role(Role::Sales)->create();
        $sequencer = app(DispoOrderNumberSequencer::class);

        DispoOrder::query()->create([
            'calculation_id' => $calculation->id,
            'number' => 'DA-2026-000001-01',
            'number_year' => 2026,
            'number_org_seq' => 1,
            'number_calc_seq' => 1,
            'status' => 'draft',
            'created_by_id' => $user->id,
            'source_calculation_number' => $calculation->number,
        ]);

        [, , $calcSeq, $number] = $sequencer->next($calculation);

        $this->assertSame(2, $calcSeq);
        $this->assertStringEndsWith('-02', $number);
    }

    public function test_org_sequence_increments_globally(): void
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

        [, $firstOrgSeq] = $sequencer->next($first);
        [, $secondOrgSeq] = $sequencer->next($second);

        $this->assertSame(1, $firstOrgSeq);
        $this->assertSame(2, $secondOrgSeq);
        $this->assertSame(2, DispoOrderNumberSequence::query()->where('year', $year)->value('last_seq'));
    }
}
