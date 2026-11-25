<?php

namespace Tests\Feature\MasterDataTransfer;

use App\Enums\Role;
use App\Models\User;
use App\Services\MasterDataTransfer\MasterDataTransferContractV2;
use App\Services\MasterDataTransfer\MasterDataTransferException;
use App\Services\MasterDataTransfer\Support\MasterDataOperationalDatabaseGuardV2;
use App\Services\MasterDataTransfer\Support\MasterDataOperationalDryRunReadGuardV2;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\MysqlTestDatabaseGuard;
use Tests\TestCase;

/**
 * EPIC-03-F Slice 6a: MySQL Read-only-Lebenszyklus ohne Laravel-Parent-Transaktion.
 *
 * connectionsToTransact=[] erzwingt den operativen START TRANSACTION READ ONLY-Pfad
 * (nicht den nested Write-Block unter RefreshDatabase).
 *
 * @group mysql
 */
class MasterDataTransferImporterV2Slice6aMysqlReadOnlyStandaloneTest extends TestCase
{
    use RefreshDatabase;

    private bool $skipBecauseNotMysql = false;

    public static function tearDownAfterClass(): void
    {
        RefreshDatabaseState::$migrated = false;
        parent::tearDownAfterClass();
    }

    /**
     * @return list<string>
     */
    protected function connectionsToTransact(): array
    {
        return [];
    }

    /**
     * @return array<int, string>
     */
    protected function setUpTraits(): array
    {
        MysqlTestDatabaseGuard::assertSafeBeforeDestructiveTraits();

        $driver = (string) config('database.connections.'.config('database.default').'.driver');
        if ($driver !== 'mysql') {
            $this->skipBecauseNotMysql = true;

            return [];
        }

        return parent::setUpTraits();
    }

    protected function setUp(): void
    {
        parent::setUp();

        if ($this->skipBecauseNotMysql) {
            $this->markTestSkipped('MySQL-only: nicht verschachtelter Read-only-Lebenszyklus');
        }

        $this->assertSame(
            MysqlTestDatabaseGuard::ALLOWED_MYSQL_DATABASE,
            (new MasterDataOperationalDatabaseGuardV2)->actualDatabaseNameFromConnection(),
            'Standalone-RO-Tests dürfen nur gegen dispo_test laufen',
        );

        config([
            'dispo.master_data_transfer_testing_operational_database' => MasterDataTransferContractV2::OPERATIONAL_DATABASE,
        ]);
    }

    protected function tearDown(): void
    {
        config(['dispo.master_data_transfer_testing_operational_database' => null]);
        parent::tearDown();
    }

    #[Test]
    public function non_nested_read_only_transaction_lifecycle_closes_cleanly(): void
    {
        $this->assertSame(0, DB::transactionLevel(), 'Vorbedingung: keine Laravel-Parent-TX');

        $levelBefore = DB::transactionLevel();
        $guard = new MasterDataOperationalDryRunReadGuardV2;

        $guard->enter();
        $this->assertTrue($guard->isActive());
        $this->assertTrue($guard->hasStartedReadOnlyTransaction());
        $this->assertSame(0, DB::transactionLevel(), 'unprepared START TRANSACTION erhöht Laravel-Level nicht');
        $this->assertWriteBlockedByReadOnly('Schreibversuch in Read-only-TX muss scheitern');

        $guard->leave();

        $this->assertFalse($guard->isActive());
        $this->assertFalse($guard->hasStartedReadOnlyTransaction());
        $this->assertSame($levelBefore, DB::transactionLevel());
        // Verhaltensprobe: offene RO-TX würde Schreibzugriff weiter blockieren.
        $this->assertConnectionAcceptsWrites();

        $user = User::factory()->create([
            'email' => 'ro-lifecycle@example.test',
            'name' => 'Writable After Leave',
            'role' => Role::Admin,
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('users', ['id' => $user->id, 'name' => 'Writable After Leave']);
    }

    #[Test]
    public function non_nested_read_only_closes_after_exception_path(): void
    {
        $this->assertSame(0, DB::transactionLevel());
        $levelBefore = DB::transactionLevel();
        $guard = new MasterDataOperationalDryRunReadGuardV2;

        $guard->enter();
        $this->assertWriteBlockedByReadOnly('RO muss vor Exception-Pfad aktiv sein');

        try {
            throw new MasterDataTransferException('simulierter Dry-Run-Fehler');
        } catch (MasterDataTransferException) {
            $guard->leave();
        }

        $this->assertFalse($guard->isActive());
        $this->assertFalse($guard->hasStartedReadOnlyTransaction());
        $this->assertSame($levelBefore, DB::transactionLevel());
        $this->assertConnectionAcceptsWrites();
    }

    #[Test]
    public function testing_override_requires_actual_isolated_mysql_database(): void
    {
        $guard = new MasterDataOperationalDatabaseGuardV2;
        $this->assertTrue($guard->isIsolatedTestDatabase());
        $this->assertSame(
            MasterDataTransferContractV2::OPERATIONAL_DATABASE,
            $guard->assertDryRunAllowed(MasterDataTransferContractV2::OPERATIONAL_DATABASE),
        );

        $connection = (string) config('database.default');
        $original = config('database.connections.'.$connection.'.database');

        // Config behauptet Isolation, Active Connection bleibt dispo_test — Override-Pfad
        // prüft tatsächliche DB; hier bleibt sie isoliert. Negativ: Config auf Nicht-Isolation.
        config([
            'database.connections.'.$connection.'.database' => 'dispo_mat_core',
            'dispo.master_data_transfer_testing_operational_database' => MasterDataTransferContractV2::OPERATIONAL_DATABASE,
        ]);

        try {
            $this->assertFalse($guard->isIsolatedTestDatabase());
            $guard->assertDryRunAllowed(MasterDataTransferContractV2::OPERATIONAL_DATABASE);
            $this->fail('Override ohne isolierte Config-DB muss STOP auslösen');
        } catch (MasterDataTransferException $e) {
            $this->assertStringContainsString('isolierter Testdatenbank', $e->getMessage());
        } finally {
            config([
                'database.connections.'.$connection.'.database' => $original,
                'dispo.master_data_transfer_testing_operational_database' => MasterDataTransferContractV2::OPERATIONAL_DATABASE,
            ]);
        }
    }

    private function assertWriteBlockedByReadOnly(string $failureMessage): void
    {
        try {
            DB::table('users')->where('id', 0)->update(['name' => 'should-fail-ro']);
            $this->fail($failureMessage);
        } catch (\Throwable $e) {
            $blob = $e->getMessage();
            $this->assertTrue(
                str_contains($blob, 'read only')
                || str_contains($blob, 'READ ONLY')
                || str_contains($blob, 'readonly')
                || str_contains($blob, '1792')
                || str_contains($blob, 'Cannot execute'),
                $blob,
            );
        }
    }

    private function assertConnectionAcceptsWrites(): void
    {
        // No-op-Update: würde bei noch offener READ ONLY-TX fehlschlagen.
        DB::table('users')->where('id', 0)->update(['name' => 'noop-after-leave']);
    }
}
