<?php

namespace Tests\Feature\Crm;

use App\Enums\CrmAccountType;
use App\Enums\Role;
use App\Models\CrmAccount;
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
 * Parallele manuelle Verknüpfung erfordert committed Daten (DatabaseMigrations)
 * und MySQL-Row-Locks über getrennte Verbindungen.
 */
final class CrmManualLinkConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    #[Test]
    public function concurrent_manual_links_serialize_to_one_target(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Parallele Link-Worker erfordern MySQL (GitHub-Job mysql).');
        }

        Storage::fake((string) config('dispo.files_disk'));
        $admin = User::factory()->role(Role::Admin)->create();
        $sales = User::factory()->role(Role::Sales)->create();
        $accounts = app(CrmAccountService::class);

        $this->importAndApply($admin, [
            ['SF Eins', 'M-1', '001xx000003DGbq', 'a@one.test', 'Account KUNDE'],
            ['SF Zwei', 'M-2', '001xx000003DGBr', 'b@two.test', 'Account KUNDE'],
        ]);
        $sf1 = CrmAccount::query()->where('salesforce_account_id_canonical', SalesforceAccountId::normalize('001xx000003DGbq')['canonical'])->firstOrFail();
        $sf2 = CrmAccount::query()->where('salesforce_account_id_canonical', SalesforceAccountId::normalize('001xx000003DGBr')['canonical'])->firstOrFail();

        $provisional = $accounts->createProvisional([
            'name' => 'Vorläufig Race',
            'type' => CrmAccountType::Customer,
            'matching_domain' => 'race-link.test',
        ], $sales);

        $calc = app(CalculationWriter::class)->create($this->withLiveSchemaFingerprint([
            'planning_mode' => 'manual',
            'customer_account_id' => $provisional->id,
            'invoice_recipient' => 'customer',
            'campaign' => 'Concurrent Link',
            'product_title' => 'Titel',
            'order_discount_percent' => '0',
            'ae_enabled' => false,
            'positions' => [],
        ]), $sales);

        $runDir = storage_path('framework/testing/concurrency-crm-link-'.Str::uuid());
        if (! mkdir($runDir, 0700, true) && ! is_dir($runDir)) {
            $this->fail('Run directory could not be created.');
        }

        $payloadA = json_encode([
            'provisional_id' => $provisional->id,
            'salesforce_id' => $sf1->id,
            'actor_id' => $admin->id,
        ], JSON_THROW_ON_ERROR);
        $payloadB = json_encode([
            'provisional_id' => $provisional->id,
            'salesforce_id' => $sf2->id,
            'actor_id' => $admin->id,
        ], JSON_THROW_ON_ERROR);

        $worker = base_path('tests/concurrency/crm_manual_link_worker.php');
        $php = PHP_BINARY;
        $baseEnv = $this->workerEnvironment();

        $processA = new Process([$php, $worker, $runDir, '0', $payloadA], base_path(), $baseEnv);
        $processB = new Process([$php, $worker, $runDir, '1', $payloadB], base_path(), $baseEnv);
        $processA->start();
        $processB->start();
        $processA->wait();
        $processB->wait();

        $results = [];
        foreach ([0, 1] as $id) {
            $file = $runDir.'/worker-'.$id.'.result';
            $this->assertFileExists($file);
            $results[] = trim((string) file_get_contents($file));
        }

        $ok = array_values(array_filter($results, fn (string $line): bool => str_starts_with($line, 'OK:')));
        $errors = array_values(array_filter($results, fn (string $line): bool => str_starts_with($line, 'ERROR:')));
        $this->assertCount(1, $ok, 'Genau ein Link darf gewinnen. Got: '.implode(' | ', $results));
        $this->assertCount(1, $errors, 'Der zweite Link muss scheitern. Got: '.implode(' | ', $results));

        $winnerId = (int) explode(':', $ok[0], 2)[1];
        $this->assertContains($winnerId, [$sf1->id, $sf2->id]);

        $calc->refresh();
        $this->assertSame($winnerId, $calc->customer_account_id);
        $this->assertSame($winnerId, $provisional->fresh()->merged_into_account_id);
        $other = $winnerId === $sf1->id ? $sf2->id : $sf1->id;
        $this->assertNotSame($other, $calc->customer_account_id);
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
