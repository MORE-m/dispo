<?php

namespace Tests\Unit\OnlineAudio;

use App\Services\OnlineAudio\Import\OaTkpPriceListYearLock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * PO-CALC-TKP-1D4c-4c: YearLock muss für AdminWriter → DraftImportGuard nestbar sein.
 */
class OaTkpPriceListYearLockReentrantTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function same_year_acquire_is_reentrant_per_instance(): void
    {
        $lock = new OaTkpPriceListYearLock;
        $lock->acquire(2027);
        $lock->acquire(2027);
        $lock->release();
        $lock->release();

        // Nach vollständigem Release erneut erwerbbar.
        $lock->acquire(2027);
        $lock->release();

        $this->assertTrue(true);
    }

    #[Test]
    public function different_year_while_held_fails(): void
    {
        $lock = new OaTkpPriceListYearLock;
        $lock->acquire(2027);

        $this->expectException(\RuntimeException::class);
        try {
            $lock->acquire(2026);
        } finally {
            $lock->release();
        }
    }
}
