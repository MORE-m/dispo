<?php

namespace Tests\Unit\DispoOrder;

use App\Support\DispoOrder\OperationalFlightContract;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OperationalFlightContractTest extends TestCase
{
    public function test_unset_pair_is_allowed_for_persist(): void
    {
        $this->assertSame(
            ['start' => null, 'end' => null],
            OperationalFlightContract::canonicalizeForPersist(null, null),
        );
        $this->assertSame(
            ['start' => null, 'end' => null],
            OperationalFlightContract::canonicalizeForPersist('', '  '),
        );
    }

    public function test_valid_pair_persists(): void
    {
        $this->assertSame(
            ['start' => '2026-04-01', 'end' => '2026-04-30'],
            OperationalFlightContract::canonicalizeForPersist('2026-04-01', '2026-04-30'),
        );
        $this->assertSame(
            ['start' => '2026-04-01', 'end' => '2026-04-01'],
            OperationalFlightContract::canonicalizeForPersist('2026-04-01', '2026-04-01'),
        );
    }

    #[DataProvider('invalidPersistCases')]
    public function test_invalid_pairs_fail_closed(mixed $start, mixed $end): void
    {
        $this->expectException(ValidationException::class);
        OperationalFlightContract::canonicalizeForPersist($start, $end);
    }

    /**
     * @return array<string, array{0: mixed, 1: mixed}>
     */
    public static function invalidPersistCases(): array
    {
        return [
            'only_start' => ['2026-04-01', null],
            'only_end' => [null, '2026-04-30'],
            'end_before_start' => ['2026-04-30', '2026-04-01'],
            'invalid_start' => ['04.04.2026', '2026-04-30'],
            'invalid_end' => ['2026-04-01', 'not-a-date'],
        ];
    }

    public function test_is_valid_pair_matrix(): void
    {
        $this->assertFalse(OperationalFlightContract::isValidPair(null, null));
        $this->assertFalse(OperationalFlightContract::isValidPair('2026-04-01', null));
        $this->assertFalse(OperationalFlightContract::isValidPair(null, '2026-04-30'));
        $this->assertFalse(OperationalFlightContract::isValidPair('2026-04-30', '2026-04-01'));
        $this->assertTrue(OperationalFlightContract::isValidPair('2026-04-01', '2026-04-30'));
    }
}
