<?php

namespace Tests\Feature\Crm;

use App\Enums\CrmAccountType;
use App\Enums\CrmConflictType;
use App\Enums\Role;
use App\Models\CrmAccount;
use App\Models\CrmConflict;
use App\Models\CrmImport;
use App\Models\User;
use App\Services\Calculation\CalculationWriter;
use App\Services\Crm\CrmAccountService;
use App\Support\Crm\SalesforceAccountId;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Parallele Meridian-Nachträge / Import / Link: einheitliche Sperrreihenfolge, kein Deadlock, kein Duplikat.
 */
final class CrmOrderMeridianConflictConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    #[Test]
    public function concurrent_supplement_creates_exactly_one_open_order_meridian_conflict(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Parallele Supplement-Worker erfordern MySQL (GitHub-Job mysql).');
        }

        Storage::fake((string) config('dispo.files_disk'));
        $admin = User::factory()->role(Role::Admin)->create();
        $sales = User::factory()->role(Role::Sales)->create();

        $this->importAndApply($admin, [
            ['Kunde', 'M-KEEP', '001xx000003DGbq', 'a@race.test', 'Account KUNDE'],
        ]);
        $account = CrmAccount::query()->firstOrFail();

        $calc = app(CalculationWriter::class)->create($this->withLiveSchemaFingerprint([
            'planning_mode' => 'manual',
            'customer_account_id' => $account->id,
            'invoice_recipient' => 'customer',
            'campaign' => 'Race',
            'product_title' => 'T',
            'order_discount_percent' => '0',
            'ae_enabled' => false,
            'positions' => [],
        ]), $sales);
        DB::table('calculations')->where('id', $calc->id)->update([
            'customer_meridian_number' => 'M-OTHER',
        ]);

        $results = $this->runTwoWorkers(
            base_path('tests/concurrency/crm_order_meridian_conflict_worker.php'),
            json_encode([
                'account_id' => $account->id,
                'meridian' => 'M-KEEP',
                'actor_id' => $admin->id,
                'import_id' => null,
            ], JSON_THROW_ON_ERROR),
            json_encode([
                'account_id' => $account->id,
                'meridian' => 'M-KEEP',
                'actor_id' => $admin->id,
                'import_id' => null,
            ], JSON_THROW_ON_ERROR),
        );

        $ok = array_values(array_filter($results, fn (string $line): bool => str_starts_with($line, 'OK:')));
        $this->assertCount(2, $ok, 'Beide Worker sollen enden. Got: '.implode(' | ', $results));

        $created = array_map(
            static fn (string $line): int => (int) explode(':', $line)[2],
            $ok,
        );
        $this->assertSame(1, array_sum($created), 'Genau ein Worker darf conflicts_created=1 melden. Got: '.implode(' | ', $results));

        $this->assertSame(
            1,
            CrmConflict::query()
                ->where('type', CrmConflictType::OrderMeridianMismatch)
                ->where('status', 'open')
                ->count(),
        );
    }

    #[Test]
    public function concurrent_supplement_and_import_apply_both_succeed_without_duplicate_conflict(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Parallele Supplement/Import-Worker erfordern MySQL.');
        }

        // Echter Disk, damit Worker-Prozess die Importdatei sieht (kein Storage::fake).
        $admin = User::factory()->role(Role::Admin)->create();
        $sales = User::factory()->role(Role::Sales)->create();

        $this->importAndApply($admin, [
            ['Kunde', 'M-KEEP', '001xx000003DGbq', 'a@race-imp.test', 'Account KUNDE'],
        ]);
        $account = CrmAccount::query()->firstOrFail();

        $calc = app(CalculationWriter::class)->create($this->withLiveSchemaFingerprint([
            'planning_mode' => 'manual',
            'customer_account_id' => $account->id,
            'invoice_recipient' => 'customer',
            'campaign' => 'Race Imp',
            'product_title' => 'T',
            'order_discount_percent' => '0',
            'ae_enabled' => false,
            'positions' => [],
        ]), $sales);
        DB::table('calculations')->where('id', $calc->id)->update([
            'customer_meridian_number' => 'M-OTHER',
        ]);

        $upload = $this->actingAs($admin)->post('/administration/crm/import', [
            'file' => UploadedFile::fake()->createWithContent('sf.csv', $this->csv([
                ['Kunde', 'M-KEEP', '001xx000003DGbq', 'a@race-imp.test', 'Account KUNDE'],
            ])),
        ]);
        $upload->assertOk();
        $importId = (int) $upload->json('import.id');

        $results = $this->runTwoWorkers(
            base_path('tests/concurrency/crm_order_meridian_conflict_worker.php'),
            json_encode([
                'account_id' => $account->id,
                'meridian' => 'M-KEEP',
                'actor_id' => $admin->id,
                'import_id' => null,
            ], JSON_THROW_ON_ERROR),
            base_path('tests/concurrency/crm_import_apply_worker.php'),
            json_encode([
                'import_id' => $importId,
                'actor_id' => $admin->id,
                'fingerprint' => $upload->json('import.fingerprint'),
                'catalog_fingerprint' => $upload->json('import.catalog_fingerprint'),
            ], JSON_THROW_ON_ERROR),
        );

        $ok = array_values(array_filter($results, fn (string $line): bool => str_starts_with($line, 'OK:')));
        $this->assertCount(2, $ok, 'Supplement und Import müssen beide ok enden. Got: '.implode(' | ', $results));
        $this->assertSame(
            1,
            CrmConflict::query()
                ->where('type', CrmConflictType::OrderMeridianMismatch)
                ->where('status', 'open')
                ->count(),
        );
        $this->assertSame('M-OTHER', $calc->fresh()->customer_meridian_number);
    }

    #[Test]
    public function concurrent_supplement_and_manual_link_both_succeed_without_deadlock(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Parallele Supplement/Link-Worker erfordern MySQL.');
        }

        Storage::fake((string) config('dispo.files_disk'));
        $admin = User::factory()->role(Role::Admin)->create();
        $sales = User::factory()->role(Role::Sales)->create();
        $accounts = app(CrmAccountService::class);

        $this->importAndApply($admin, [
            ['SF Ziel', 'M-KEEP', '001xx000003DGbq', 'a@race-link.test', 'Account KUNDE'],
        ]);
        $sf = CrmAccount::query()
            ->where('salesforce_account_id_canonical', SalesforceAccountId::normalize('001xx000003DGbq')['canonical'])
            ->firstOrFail();

        $provisional = $accounts->createProvisional([
            'name' => 'Vorläufig Race',
            'type' => CrmAccountType::Customer,
            'matching_domain' => 'race-link-sup.test',
        ], $sales);

        $calc = app(CalculationWriter::class)->create($this->withLiveSchemaFingerprint([
            'planning_mode' => 'manual',
            'customer_account_id' => $provisional->id,
            'invoice_recipient' => 'customer',
            'campaign' => 'Race Link',
            'product_title' => 'T',
            'order_discount_percent' => '0',
            'ae_enabled' => false,
            'positions' => [],
        ]), $sales);
        DB::table('calculations')->where('id', $calc->id)->update([
            'customer_meridian_number' => 'M-OTHER',
        ]);

        // Zweites Calc am SF-Ziel für reinen Supplement-Pfad (Account-Sperre überschneidet Link).
        $calcSf = app(CalculationWriter::class)->create($this->withLiveSchemaFingerprint([
            'planning_mode' => 'manual',
            'customer_account_id' => $sf->id,
            'invoice_recipient' => 'customer',
            'campaign' => 'Race SF',
            'product_title' => 'T',
            'order_discount_percent' => '0',
            'ae_enabled' => false,
            'positions' => [],
        ]), $sales);
        DB::table('calculations')->where('id', $calcSf->id)->update([
            'customer_meridian_number' => 'M-SF-OTHER',
        ]);

        $results = $this->runTwoWorkers(
            base_path('tests/concurrency/crm_order_meridian_conflict_worker.php'),
            json_encode([
                'account_id' => $sf->id,
                'meridian' => 'M-KEEP',
                'actor_id' => $admin->id,
                'import_id' => null,
            ], JSON_THROW_ON_ERROR),
            base_path('tests/concurrency/crm_manual_link_worker.php'),
            json_encode([
                'provisional_id' => $provisional->id,
                'salesforce_id' => $sf->id,
                'actor_id' => $admin->id,
            ], JSON_THROW_ON_ERROR),
        );

        $ok = array_values(array_filter($results, fn (string $line): bool => str_starts_with($line, 'OK:')));
        $this->assertCount(2, $ok, 'Supplement und Link müssen beide ok enden. Got: '.implode(' | ', $results));
        $this->assertSame($sf->id, $provisional->fresh()->merged_into_account_id);
        $this->assertSame($sf->id, $calc->fresh()->customer_account_id);
        $this->assertGreaterThanOrEqual(
            1,
            CrmConflict::query()
                ->where('type', CrmConflictType::OrderMeridianMismatch)
                ->where('status', 'open')
                ->count(),
        );
    }

    /**
     * @return list<string>
     */
    private function runTwoWorkers(
        string $workerA,
        string $payloadA,
        string $workerBOrPayloadB,
        ?string $payloadB = null,
    ): array {
        $runDir = storage_path('framework/testing/concurrency-crm-order-meridian-'.Str::uuid());
        if (! mkdir($runDir, 0700, true) && ! is_dir($runDir)) {
            $this->fail('Run directory could not be created.');
        }

        if ($payloadB === null) {
            $workerB = $workerA;
            $payloadB = $workerBOrPayloadB;
        } else {
            $workerB = $workerBOrPayloadB;
        }

        $php = PHP_BINARY;
        $baseEnv = $this->workerEnvironment();

        $processA = new Process([$php, $workerA, $runDir, '0', $payloadA], base_path(), $baseEnv);
        $processB = new Process([$php, $workerB, $runDir, '1', $payloadB], base_path(), $baseEnv);
        $processA->setTimeout(120);
        $processB->setTimeout(120);
        $processA->start();
        $processB->start();
        $processA->wait();
        $processB->wait();

        $results = [];
        foreach ([0, 1] as $id) {
            $file = $runDir.'/worker-'.$id.'.result';
            $this->assertFileExists(
                $file,
                'Worker '.$id.' result missing. A='.$processA->getErrorOutput().' B='.$processB->getErrorOutput(),
            );
            $results[] = trim((string) file_get_contents($file));
        }

        return $results;
    }

    /**
     * @param  list<list<string>>  $rows
     */
    private function csv(array $rows): string
    {
        $lines = ['Accountname;Meridian-ID;Account-ID;Rechnungs-E-Mail;Account-Datensatztyp'];
        foreach ($rows as $row) {
            $lines[] = implode(';', $row);
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * @param  list<list<string>>  $rows
     */
    private function importAndApply(User $admin, array $rows): CrmImport
    {
        $upload = $this->actingAs($admin)->post('/administration/crm/import', [
            'file' => UploadedFile::fake()->createWithContent('sf.csv', $this->csv($rows)),
        ]);
        $upload->assertOk();
        $id = $upload->json('import.id');
        $apply = $this->actingAs($admin)->postJson("/administration/crm/import/{$id}/anwenden", [
            'fingerprint' => $upload->json('import.fingerprint'),
            'catalog_fingerprint' => $upload->json('import.catalog_fingerprint'),
        ]);
        $apply->assertOk();

        return CrmImport::query()->findOrFail($id);
    }

    /**
     * @return array<string, string>
     */
    private function workerEnvironment(): array
    {
        $vars = [
            'APP_KEY', 'APP_ENV', 'DB_CONNECTION', 'DB_HOST', 'DB_PORT',
            'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD', 'DB_URL',
        ];

        $env = [];
        foreach ($vars as $var) {
            $value = getenv($var);
            if ($value !== false) {
                $env[$var] = (string) $value;
            }
        }

        return $env;
    }
}
