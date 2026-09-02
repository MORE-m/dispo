<?php

namespace Tests\Unit\Calculation;

use App\Services\Calculation\BudgetSpotAllocator;
use Tests\TestCase;

class BudgetSpotAllocatorTest extends TestCase
{
    private BudgetSpotAllocator $allocator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->allocator = new BudgetSpotAllocator;
    }

    public function test_distributes_evenly_when_more_spots_than_buckets(): void
    {
        $counts = $this->allocator->distribute(14, 12);

        $this->assertCount(12, $counts);
        $this->assertSame(14, array_sum($counts));
        $this->assertSame(1, max($counts) - min($counts));
    }

    public function test_distributes_spread_when_fewer_spots_than_buckets(): void
    {
        $counts = $this->allocator->distribute(3, 12);

        $this->assertSame(3, array_sum($counts));
        $this->assertSame([1, 0, 0, 0, 1, 0, 0, 0, 1, 0, 0, 0], $counts);
    }

    public function test_is_deterministic(): void
    {
        $first = $this->allocator->distribute(7, 5);
        $second = $this->allocator->distribute(7, 5);

        $this->assertSame($first, $second);
    }

    public function test_zero_spots_returns_zeros(): void
    {
        $counts = $this->allocator->distribute(0, 8);

        $this->assertSame(array_fill(0, 8, 0), $counts);
    }
}
