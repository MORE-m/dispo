<?php

namespace Tests\Unit\MasterDataTransfer;

use App\Services\MasterDataTransfer\Support\MasterDataExportReadSnapshot;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Ohne RefreshDatabase: prüft die äußere Snapshot-Transaktion.
 */
class MasterDataExportReadSnapshotTest extends TestCase
{
    #[Test]
    public function opens_readonly_transaction_and_rolls_back_when_idle(): void
    {
        $this->assertSame(0, DB::transactionLevel());

        $result = MasterDataExportReadSnapshot::run(function (): string {
            $this->assertGreaterThan(0, DB::transactionLevel());

            return 'snapshot-ok';
        });

        $this->assertSame('snapshot-ok', $result);
        $this->assertSame(0, DB::transactionLevel());
    }

    #[Test]
    public function rolls_back_and_rethrows_on_failure(): void
    {
        $this->assertSame(0, DB::transactionLevel());

        try {
            MasterDataExportReadSnapshot::run(function (): void {
                throw new RuntimeException('snapshot-fail');
            });
            $this->fail('Exception erwartet.');
        } catch (RuntimeException $e) {
            $this->assertSame('snapshot-fail', $e->getMessage());
        }

        $this->assertSame(0, DB::transactionLevel());
    }

    #[Test]
    public function reuses_existing_transaction_without_extra_rollback(): void
    {
        DB::beginTransaction();
        try {
            $level = DB::transactionLevel();
            $result = MasterDataExportReadSnapshot::run(function () use ($level): int {
                $this->assertSame($level, DB::transactionLevel());

                return $level;
            });
            $this->assertSame($level, $result);
            $this->assertSame($level, DB::transactionLevel());
        } finally {
            DB::rollBack();
        }

        $this->assertSame(0, DB::transactionLevel());
    }
}
