<?php

namespace Tests\Feature\Crm;

use App\Enums\CrmConflictType;
use App\Enums\Role;
use App\Models\CrmAccount;
use App\Models\CrmConflict;
use App\Models\CrmImport;
use App\Models\User;
use App\Services\Calculation\CalculationWriter;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Parallele Meridian-Nachträge dürfen denselben offenen Order-Mismatch nicht verdoppeln.
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

        $runDir = storage_path('framework/testing/concurrency-crm-order-meridian-'.Str::uuid());
        if (! mkdir($runDir, 0700, true) && ! is_dir($runDir)) {
            $this->fail('Run directory could not be created.');
        }

        $payload = json_encode([
            'account_id' => $account->id,
            'meridian' => 'M-KEEP',
            'actor_id' => $admin->id,
            'import_id' => 1,
        ], JSON_THROW_ON_ERROR);

        $worker = base_path('tests/concurrency/crm_order_meridian_conflict_worker.php');
        $php = PHP_BINARY;
        $baseEnv = $this->workerEnvironment();

        $processA = new Process([$php, $worker, $runDir, '0', $payload], base_path(), $baseEnv);
        $processB = new Process([$php, $worker, $runDir, '1', $payload], base_path(), $baseEnv);
        $processA->start();
        $processB->start();
        $processA->wait();
        $processB->wait();

        $results = [];
        foreach ([0, 1] as $id) {
            $file = $runDir.'/worker-'.$id.'.result';
            $this->assertFileExists($file, 'Worker '.$id.' result missing. A='.$processA->getErrorOutput().' B='.$processB->getErrorOutput());
            $results[] = trim((string) file_get_contents($file));
        }

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
