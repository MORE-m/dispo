<?php

namespace Tests\Feature\MasterDataTransfer;

use App\Enums\InventoryType;
use App\Enums\Role;
use App\Models\Inventory;
use App\Models\Organization;
use App\Models\User;
use App\Services\MasterDataTransfer\Import\MasterDataImporterV2;
use App\Services\MasterDataTransfer\MasterDataTransferContractV2;
use App\Services\MasterDataTransfer\MasterDataTransferException;
use App\Services\MasterDataTransfer\Support\MasterDataPackage;
use App\Services\MasterDataTransfer\Support\MasterDataPlannedWriteV2;
use App\Services\MasterDataTransfer\Support\MasterDataPriceListPinGuardV2;
use App\Services\MasterDataTransfer\Support\MasterDataProtectedStateSnapshotV2;
use App\Services\MasterDataTransfer\Support\MasterDataWriteWhitelistGuardV2;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * PO-Code-Review Slice 5: Fail-closed Whitelist, Schutzfelder, Plan-Bindung, Listener-Lebenszyklus.
 */
class MasterDataTransferImporterV2PhaseCPoReviewTest extends TestCase
{
    use RefreshDatabase;

    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir().'/mdt-v2-phase-c-po-'.uniqid('', true);
        File::ensureDirectoryExists($this->tmp);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tmp);
        parent::tearDown();
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    public static function forbiddenSqlProvider(): array
    {
        return [
            'delete' => ['DELETE FROM users WHERE id = 1', 'Whitelist-STOP'],
            'truncate' => ['TRUNCATE TABLE users', 'Whitelist-STOP'],
            'alter' => ['ALTER TABLE users ADD COLUMN x INT', 'Whitelist-STOP'],
            'drop' => ['DROP TABLE users', 'Whitelist-STOP'],
            'create_table' => ['CREATE TABLE evil (id INT)', 'Whitelist-STOP'],
            'mysql_fk_off' => ['SET FOREIGN_KEY_CHECKS=0', 'Whitelist-STOP'],
            'sqlite_fk_off' => ['PRAGMA foreign_keys = OFF', 'Whitelist-STOP'],
            'replace' => ['REPLACE INTO users (id, name) VALUES (1, "x")', 'Whitelist-STOP'],
            'unknown_mutating' => ['MERGE INTO users USING dual ON (1=1)', 'Whitelist-STOP'],
            'insert_no_table' => ['INSERT INTO', 'Whitelist-STOP'],
        ];
    }

    #[Test]
    #[DataProvider('forbiddenSqlProvider')]
    public function whitelist_guard_rejects_forbidden_sql_fail_closed(string $sql, string $needle): void
    {
        $guard = new MasterDataWriteWhitelistGuardV2;
        try {
            $guard->assertSqlAllowed($sql);
            $this->fail('Erwartete Exception für: '.$sql);
        } catch (MasterDataTransferException $e) {
            $this->assertStringContainsString($needle, $e->getMessage());
        }
    }

    #[Test]
    public function whitelist_guard_allows_reads_and_transactions_and_whitelisted_writes(): void
    {
        $guard = new MasterDataWriteWhitelistGuardV2;
        $guard->assertSqlAllowed('SELECT * FROM users');
        $guard->assertSqlAllowed('BEGIN');
        $guard->assertSqlAllowed('COMMIT');
        $guard->assertSqlAllowed('ROLLBACK');
        $guard->assertSqlAllowed('INSERT INTO inventories (code) VALUES (?)');
        $guard->assertSqlAllowed('UPDATE price_lists SET name = ? WHERE id = ?');
    }

    #[Test]
    public function whitelist_guard_rejects_insert_into_excluded_table(): void
    {
        $guard = new MasterDataWriteWhitelistGuardV2;
        $this->expectException(MasterDataTransferException::class);
        $this->expectExceptionMessage('außerhalb der Whitelist: users');
        $guard->assertSqlAllowed('INSERT INTO users (email) VALUES (?)');
    }

    #[Test]
    public function security_columns_drift_same_count_is_detected(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            if (! Schema::hasColumn('users', 'email_verified_at')) {
                $table->timestamp('email_verified_at')->nullable();
            }
            if (! Schema::hasColumn('users', 'two_factor_confirmed_at')) {
                $table->timestamp('two_factor_confirmed_at')->nullable();
            }
            if (! Schema::hasColumn('users', 'deleted_at')) {
                $table->softDeletes();
            }
        });

        $user = User::factory()->create([
            'email' => 'sec-cols@example.test',
            'role' => Role::Admin,
            'is_active' => true,
        ]);
        DB::table('users')->where('id', $user->id)->update([
            'email_verified_at' => null,
            'two_factor_confirmed_at' => null,
            'deleted_at' => null,
        ]);

        $snapshot = new MasterDataProtectedStateSnapshotV2;
        $before = $snapshot->capture();
        $countBefore = $before['tables']['users']['count'];

        DB::table('users')->where('id', $user->id)->update([
            'email_verified_at' => now()->toDateTimeString(),
        ]);
        $afterVerified = $snapshot->capture();
        $this->assertSame($countBefore, $afterVerified['tables']['users']['count']);
        $this->assertNotSame([], $snapshot->diff($before, $afterVerified));

        DB::table('users')->where('id', $user->id)->update([
            'email_verified_at' => null,
            'two_factor_confirmed_at' => now()->toDateTimeString(),
        ]);
        $after2fa = $snapshot->capture();
        $this->assertSame($countBefore, $after2fa['tables']['users']['count']);
        $this->assertNotSame([], $snapshot->diff($before, $after2fa));

        DB::table('users')->where('id', $user->id)->update([
            'two_factor_confirmed_at' => null,
            'deleted_at' => now()->toDateTimeString(),
        ]);
        $afterDeleted = $snapshot->capture();
        $this->assertSame($countBefore, $afterDeleted['tables']['users']['count']);
        $this->assertNotSame([], $snapshot->diff($before, $afterDeleted));

        $report = json_encode($before, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString($user->email, $report);
        $this->assertStringNotContainsString((string) $user->password, $report);
    }

    #[Test]
    public function ignored_columns_exclude_only_documented_technical_timestamps(): void
    {
        $ref = new \ReflectionClassConstant(MasterDataProtectedStateSnapshotV2::class, 'IGNORED_COLUMNS');
        /** @var list<string> $ignored */
        $ignored = $ref->getValue();
        $this->assertSame(['created_at', 'updated_at', 'remember_token'], $ignored);
        $this->assertNotContains('deleted_at', $ignored);
        $this->assertNotContains('email_verified_at', $ignored);
        $this->assertNotContains('two_factor_confirmed_at', $ignored);
    }

    #[Test]
    public function planned_write_on_excluded_table_is_stopped_before_execution(): void
    {
        $ran = false;
        $evil = new MasterDataPlannedWriteV2('create', 'users', function () use (&$ran): void {
            $ran = true;
            DB::table('users')->insert([
                'name' => 'Evil',
                'email' => 'evil-plan@example.test',
                'password' => 'x',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $guard = new MasterDataWriteWhitelistGuardV2;
        try {
            $guard->assertPlannedWritesAllowed([$evil]);
            $this->fail('Plan mit excluded Write muss STOP auslösen');
        } catch (MasterDataTransferException $e) {
            $this->assertStringContainsString('users', $e->getMessage());
        }
        $this->assertFalse($ran);
        $this->assertSame(0, User::query()->where('email', 'evil-plan@example.test')->count());
    }

    #[Test]
    public function apply_rejects_raw_callable_ops(): void
    {
        $importer = app(MasterDataImporterV2::class);
        $method = new \ReflectionMethod($importer, 'apply');
        $method->setAccessible(true);

        $ran = false;
        try {
            $method->invoke($importer, [function () use (&$ran): void {
                $ran = true;
            }]);
            $this->fail('Rohes Closure muss abgelehnt werden');
        } catch (MasterDataTransferException $e) {
            $this->assertStringContainsString('ungeprüfte Operation', $e->getMessage());
        }
        $this->assertFalse($ran);
    }

    #[Test]
    public function listener_lifecycle_does_not_accumulate_across_applies_and_failures(): void
    {
        $guard = new MasterDataWriteWhitelistGuardV2;

        $foreignHits = 0;
        Event::listen(QueryExecuted::class, function () use (&$foreignHits): void {
            $foreignHits++;
        });

        $guard->startMonitoring();
        $this->assertTrue($guard->isMonitoring());
        $this->assertSame(1, $guard->registeredOwnListenerCount());
        $guard->startMonitoring();
        $this->assertSame(1, $guard->registeredOwnListenerCount(), 'Kein zweiter Listener bei erneutem startMonitoring');
        DB::select('select 1');
        $guard->stopMonitoring();
        $this->assertFalse($guard->isMonitoring());
        $this->assertSame(0, $guard->registeredOwnListenerCount());

        try {
            $guard->startMonitoring();
            $this->assertSame(1, $guard->registeredOwnListenerCount());
            throw new MasterDataTransferException('simulierter Apply-Fehler');
        } catch (MasterDataTransferException) {
            $guard->stopMonitoring();
        }
        $this->assertFalse($guard->isMonitoring());
        $this->assertSame(0, $guard->registeredOwnListenerCount());

        User::factory()->create(['email' => 'listener@example.test', 'role' => Role::Admin, 'is_active' => true]);
        $package = $this->writeMinimalV2Package();
        $importer = new MasterDataImporterV2(
            app(MasterDataPackage::class),
            new MasterDataProtectedStateSnapshotV2,
            new MasterDataPriceListPinGuardV2,
            $guard,
        );

        $importer->executePhasesABCForIsolatedTests($package, dryRun: false);
        $this->assertSame(0, $guard->registeredOwnListenerCount());
        $this->assertFalse($guard->isMonitoring());

        Inventory::query()->delete();
        $packageWithCreate = $this->writeMinimalV2PackageWithInventory();
        try {
            Inventory::saved(function (): void {
                DB::table('users')->update(['name' => 'listener-mutated']);
            });
            $importer->executePhasesABCForIsolatedTests($packageWithCreate, dryRun: false);
            $this->fail('Erwarteter Schutz-/Whitelist-STOP');
        } catch (MasterDataTransferException) {
            // erwartet
        } finally {
            Inventory::flushEventListeners();
        }

        $this->assertSame(0, $guard->registeredOwnListenerCount());
        $this->assertFalse($guard->isMonitoring());
        $this->assertSame(0, Inventory::query()->where('code', 'po_review_inv')->count());

        $importer->executePhasesABCForIsolatedTests($package, dryRun: false);
        $this->assertSame(0, $guard->registeredOwnListenerCount());
        $this->assertFalse($guard->isMonitoring());
        $this->assertGreaterThan(0, $foreignHits, 'Fremder QueryExecuted-Listener muss erhalten bleiben');
    }

    private function writeMinimalV2PackageWithInventory(): string
    {
        return $this->writeMinimalV2Package([
            [
                'code' => 'po_review_inv',
                'name' => 'PO Review Inv',
                'type' => InventoryType::Sender->value,
                'is_active' => true,
                'sort' => 1,
            ],
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $inventories
     */
    private function writeMinimalV2Package(array $inventories = []): string
    {
        $dir = $this->tmp.'/pkg-'.uniqid('', true);
        $orgName = Organization::query()->orderBy('id')->value('name') ?? 'PO Review Org';
        if (Organization::query()->count() === 0) {
            Organization::query()->create(['name' => $orgName]);
        }

        $files = [];
        foreach (MasterDataTransferContractV2::DATA_FILES as $relative) {
            $files[$relative] = $relative === 'data/organization.json'
                ? ['name' => $orgName]
                : [];
        }
        $files['data/inventories.json'] = $inventories;

        $counts = [];
        foreach ($files as $relative => $payload) {
            $counts[$relative] = $relative === 'data/organization.json' ? 1 : count($payload);
        }

        $manifest = [
            'format' => MasterDataTransferContractV2::FORMAT,
            'schema_version' => MasterDataTransferContractV2::SCHEMA_VERSION,
            'epic' => MasterDataTransferContractV2::EPIC,
            'git_sha' => 'synthetic-phase-c-po',
            'exported_at' => '2026-11-25T09:00:00+00:00',
            'source_database' => 'fixture',
            'includes_users' => false,
            'includes_crm' => false,
            'includes_calculations' => false,
            'includes_dispo_orders' => false,
            'includes_online_audio' => true,
            'includes_social_events_barter' => true,
            'price_status_on_apply' => MasterDataTransferContractV2::PRICE_STATUS_ON_APPLY,
            'excluded_tables' => MasterDataTransferContractV2::excludedTablesSorted(),
            'counts' => $counts,
            'source_price_status_summary' => [
                'spot' => ['active' => 0, 'archived' => 0, 'draft' => 0],
                'production' => ['active' => 0, 'archived' => 0, 'draft' => 0],
                'oa_tkp' => ['active' => 0, 'archived' => 0, 'draft' => 0],
            ],
            'files' => [],
        ];

        app(MasterDataPackage::class)->write($dir, $manifest, $files);

        return $dir;
    }
}
