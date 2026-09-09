<?php

namespace Tests\Feature\Calculation;

use App\Enums\Role;
use App\Models\Calculation;
use App\Models\CalculationNumberSequence;
use App\Models\User;
use App\Services\Calculation\CalculationWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreatesSpotClassicCatalog;
use Tests\TestCase;

class CalculationWriterCreateNumberTest extends TestCase
{
    use CreatesSpotClassicCatalog;
    use RefreshDatabase;

    public function test_failed_create_does_not_consume_sequence_number(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $writer = app(CalculationWriter::class);
        $year = (int) now('Europe/Berlin')->format('Y');

        $seqBefore = CalculationNumberSequence::query()->where('year', $year)->value('last_seq') ?? 0;

        $catalog['hamburg']->update(['is_active' => false]);
        $invalidPayload = $this->spotPayload($catalog);

        try {
            $writer->create($invalidPayload, $user);
            $this->fail('Erwartete ValidationException bei inaktivem Inventar.');
        } catch (ValidationException) {
            // erwartet
        }

        $seqAfterFail = CalculationNumberSequence::query()->where('year', $year)->value('last_seq') ?? 0;
        $this->assertSame($seqBefore, $seqAfterFail);
        $this->assertSame(0, Calculation::query()->count());

        $catalog['hamburg']->update(['is_active' => true]);
        $calculation = $writer->create($this->spotPayload($catalog), $user);
        $seqAfterSuccess = CalculationNumberSequence::query()->where('year', $year)->value('last_seq') ?? 0;

        $this->assertSame($seqBefore + 1, $seqAfterSuccess);
        $this->assertMatchesRegularExpression('/^K-\d{4}-\d{5}$/', $calculation->number);
    }

    public function test_two_sequential_creates_assign_consecutive_numbers(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $writer = app(CalculationWriter::class);
        $payload = $this->spotPayload($catalog);

        $first = $writer->create($payload, $user);
        $second = $writer->create($payload, $user);

        $this->assertSame(
            ((int) substr($first->number, -5)) + 1,
            (int) substr($second->number, -5),
        );
    }

    /**
     * @param  array{hamburg: mixed, medium: mixed}  $catalog
     * @return array<string, mixed>
     */
    private function spotPayload(array $catalog): array
    {
        return $this->withLiveSchemaFingerprint([
            'planning_mode' => 'manual',
            'order_discount_percent' => '0',
            'positions' => [[
                'inventory_id' => $catalog['hamburg']->id,
                'advertising_medium_id' => $catalog['medium']->id,
                'spot_method' => 'average',
                'length_seconds' => 30,
                'total_spot_count' => 1,
                'position_discount_percent' => '0',
                'ae_percent' => '0',
                'plan_rows' => [['hour' => 8, 'day_group' => 'mo_fr']],
            ]],
        ]);
    }
}
