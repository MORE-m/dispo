<?php

namespace Tests\Feature\DispoOrder;

use App\Enums\Role;
use App\Models\User;
use App\Support\DispoOrder\OperationalFlightContract;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\MysqlTestDatabaseGuard;
use Tests\TestCase;

/**
 * PO-CALC-TKP-1D3d-2b-1: MySQL-Schema für operative Flight-Spalten.
 */
class OperationalFlightMysqlTest extends TestCase
{
    use DatabaseMigrations;

    public function test_mysql_operational_flight_columns_exist_and_accept_dates(): void
    {
        $this->requireMysql('PO-CALC-TKP-1D3d-2b-1 operational flight columns');

        $this->assertTrue(Schema::hasColumn('dispo_order_positions', 'operational_flight_start'));
        $this->assertTrue(Schema::hasColumn('dispo_order_positions', 'operational_flight_end'));

        $canonical = OperationalFlightContract::canonicalizeForPersist('2026-05-01', '2026-05-31');
        $this->assertSame('2026-05-01', $canonical['start']);
        $this->assertSame('2026-05-31', $canonical['end']);

        // Smoke: Rolle nur um Factory/DB zu berühren; Spalten sind additiv nullable.
        User::factory()->role(Role::Disposition)->create();
        $this->assertTrue(DB::getDriverName() === 'mysql');
    }

    private function requireMysql(string $label): void
    {
        MysqlTestDatabaseGuard::assertSafeBeforeDestructiveTraits();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped($label.' erfordert MySQL (GitHub-Job mysql).');
        }
    }
}
