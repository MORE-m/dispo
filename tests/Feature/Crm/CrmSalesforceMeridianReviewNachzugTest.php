<?php

namespace Tests\Feature\Crm;

use App\Enums\CrmAccountType;
use App\Enums\CrmConflictType;
use App\Enums\DerivedCampaignPeriodStatus;
use App\Enums\DispoOrderApprovalKind;
use App\Enums\DispoOrderStatus;
use App\Enums\Role;
use App\Models\CrmAccount;
use App\Models\CrmConflict;
use App\Models\CrmImport;
use App\Models\DispoOrder;
use App\Models\User;
use App\Services\Calculation\CalculationWriter;
use App\Services\Crm\CrmAccountService;
use App\Services\DispoOrder\DispoOrderSnapshotMapper;
use App\Support\Crm\SalesforceAccountId;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class CrmSalesforceMeridianReviewNachzugTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function calc_save_keeps_historical_snapshot_after_master_data_version_change(): void
    {
        Storage::fake((string) config('dispo.files_disk'));
        $admin = User::factory()->role(Role::Admin)->create();
        $sales = User::factory()->role(Role::Sales)->create();
        $sf = '001xx000003DGbq';

        $this->importAndApply($admin, [
            ['Kunde Historisch', '', $sf, 'a@hist.test', 'Account KUNDE'],
        ]);
        $account = CrmAccount::query()->whereNull('merged_into_account_id')->firstOrFail();
        $v1 = $account->currentVersion;
        $this->assertNotNull($v1);
        $v1Id = $v1->id;

        $calc = app(CalculationWriter::class)->create($this->withLiveSchemaFingerprint([
            'planning_mode' => 'manual',
            'customer_account_id' => $account->id,
            'invoice_recipient' => 'customer',
            'campaign' => 'CRM Snapshot',
            'product_title' => 'Titel',
            'order_discount_percent' => '0',
            'ae_enabled' => false,
            'positions' => [],
        ]), $sales);

        $this->assertSame($v1Id, $calc->customer_version_id);
        $this->assertSame('Kunde Historisch', $calc->customer_name);

        $this->importAndApply($admin, [
            ['Kunde Neu Firma', 'M-NEW', $sf, 'a@hist.test', 'Account KUNDE'],
        ]);
        $account->refresh();
        $this->assertSame(2, $account->versions()->count());
        $this->assertSame('Kunde Neu Firma', $account->currentVersion?->name);

        $payload = app(CalculationWriter::class)->payloadFromCalculation($calc->fresh());
        $payload['lock_version'] = $calc->fresh()->lock_version;
        $payload['customer_account_id'] = $account->id;
        $payload['invoice_recipient'] = 'customer';

        $this->actingAs($sales)->put(route('calculations.update', $calc), $payload)
            ->assertRedirect(route('calculations.edit', $calc));

        $calc->refresh();
        $this->assertSame($v1Id, $calc->customer_version_id);
        $this->assertSame('Kunde Historisch', $calc->customer_name);
        $this->assertNotSame($account->current_version_id, $calc->customer_version_id);

        // Direkter Snapshot-Mapper-Pfad Calc→Dispo.
        $header = app(DispoOrderSnapshotMapper::class)
            ->headerFromCalculation($calc->fresh(), collect());
        $this->assertSame('Kunde Historisch', $header['customer_name'] ?? null);
        $this->assertSame($v1Id, $header['customer_version_id'] ?? null);
    }

    /**
     * Sequenzielle Absicherung: zweite Zuordnung auf anderes Ziel wird abgelehnt.
     * Keine echte Parallelität — siehe {@see concurrent_manual_links_serialize_to_one_target}.
     */
    #[Test]
    public function sequential_second_manual_link_to_other_target_is_rejected(): void
    {
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
            'name' => 'Vorläufig',
            'type' => CrmAccountType::Customer,
            'matching_domain' => 'vorl.test',
        ], $sales);

        $calc = app(CalculationWriter::class)->create($this->withLiveSchemaFingerprint([
            'planning_mode' => 'manual',
            'customer_account_id' => $provisional->id,
            'invoice_recipient' => 'customer',
            'campaign' => 'Link Race',
            'product_title' => 'Titel',
            'order_discount_percent' => '0',
            'ae_enabled' => false,
            'positions' => [],
        ]), $sales);

        $first = $accounts->linkProvisionalToSalesforce($provisional, $sf1, $admin);
        $this->assertSame($sf1->id, $first->id);

        try {
            $accounts->linkProvisionalToSalesforce($provisional->fresh(), $sf2, $admin);
            $this->fail('Zweite Zuordnung auf anderes Ziel muss scheitern.');
        } catch (ValidationException) {
            // erwartet
        }

        $calc->refresh();
        $this->assertSame($sf1->id, $calc->customer_account_id);
        $this->assertNotSame($sf2->id, $calc->customer_account_id);
        $this->assertSame($sf1->id, $provisional->fresh()->merged_into_account_id);
    }

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

    #[Test]
    public function manual_link_supplements_meridian_and_followup_import_backfills_unchanged(): void
    {
        Storage::fake((string) config('dispo.files_disk'));
        $admin = User::factory()->role(Role::Admin)->create();
        $sales = User::factory()->role(Role::Sales)->create();
        $accounts = app(CrmAccountService::class);

        $this->importAndApply($admin, [
            ['SF Mit Meridian', 'M-42', '001xx000003DGbq', 'a@manual.test', 'Account KUNDE'],
        ]);
        $sf = CrmAccount::query()->whereNull('merged_into_account_id')->firstOrFail();

        $provisional = $accounts->createProvisional([
            'name' => 'Ohne Domain',
            'type' => CrmAccountType::Customer,
        ], $sales);

        $calc = app(CalculationWriter::class)->create($this->withLiveSchemaFingerprint([
            'planning_mode' => 'manual',
            'customer_account_id' => $provisional->id,
            'invoice_recipient' => 'customer',
            'campaign' => 'Manual Link',
            'product_title' => 'Titel',
            'order_discount_percent' => '0',
            'ae_enabled' => false,
            'positions' => [],
        ]), $sales);
        $this->assertNull($calc->customer_meridian_number);

        $order = DispoOrder::query()->create([
            'calculation_id' => $calc->id,
            'number' => 'D-CRM-1',
            'number_year' => 2026,
            'number_org_seq' => 1,
            'number_calc_seq' => 1,
            'status' => DispoOrderStatus::Completed,
            'created_by_id' => $sales->id,
            'source_calculation_number' => $calc->number,
            'approval_kind' => DispoOrderApprovalKind::Regular,
            'configuration_snapshot_id' => $calc->configuration_snapshot_id,
            'customer_name' => $calc->customer_name,
            'customer_account_id' => $calc->customer_account_id,
            'customer_version_id' => $calc->customer_version_id,
            'invoice_recipient' => $calc->invoice_recipient,
            'lock_version' => 1,
            'media_gross' => '0',
            'position_discount_total' => '0',
            'order_discount_total' => '0',
            'ae_total' => '0',
            'nn_invest' => '0',
            'order_discount_percent' => '0',
            'ae_enabled' => false,
            'derived_campaign_period_status' => DerivedCampaignPeriodStatus::Open,
        ]);

        $this->actingAs($admin)->postJson(route('crm.accounts.link', $provisional), [
            'salesforce_account_id' => $sf->id,
        ])->assertOk();

        $calc->refresh();
        $order->refresh();
        $this->assertSame($sf->id, $calc->customer_account_id);
        $this->assertSame('M-42', $calc->customer_meridian_number);
        $this->assertSame('M-42', $order->customer_meridian_number);
        $this->assertSame($sf->salesforce_account_id_raw, $calc->customer_salesforce_account_id);
        $this->assertSame(DispoOrderStatus::Completed, $order->status);

        // Folgeimport unverändert: Nachtrag bleibt idempotent, Snapshot unverändert.
        $nameBefore = $calc->customer_name;
        $versionBefore = $calc->customer_version_id;
        $this->importAndApply($admin, [
            ['SF Mit Meridian', 'M-42', '001xx000003DGbq', 'a@manual.test', 'Account KUNDE'],
        ]);
        $calc->refresh();
        $this->assertSame($nameBefore, $calc->customer_name);
        $this->assertSame($versionBefore, $calc->customer_version_id);
        $this->assertSame('M-42', $calc->customer_meridian_number);
    }

    #[Test]
    public function stale_preview_is_persisted_on_409_and_can_be_reapplied(): void
    {
        Storage::fake((string) config('dispo.files_disk'));
        $admin = User::factory()->role(Role::Admin)->create();

        $upload = $this->actingAs($admin)->post('/administration/crm/import', [
            'file' => UploadedFile::fake()->createWithContent('sf.csv', $this->csv([
                ['Kunde A', '', '001xx000003DGbq', 'a@stale.test', 'Account KUNDE'],
            ])),
        ])->assertOk();
        $id = $upload->json('import.id');
        $oldFp = $upload->json('import.fingerprint');
        $oldCatalog = $upload->json('import.catalog_fingerprint');

        // Bestand ändern → Catalog-Fingerprint ändert sich.
        app(CrmAccountService::class)->createProvisional([
            'name' => 'Parallel',
            'type' => CrmAccountType::Customer,
            'matching_domain' => 'parallel.test',
        ], $admin);

        $conflict = $this->actingAs($admin)->postJson("/administration/crm/import/{$id}/anwenden", [
            'fingerprint' => $oldFp,
            'catalog_fingerprint' => $oldCatalog,
        ]);
        $conflict->assertStatus(409);
        $conflict->assertJsonPath('preview_refreshed', true);
        $newFp = $conflict->json('import.fingerprint');
        $newCatalog = $conflict->json('import.catalog_fingerprint');
        $this->assertNotSame($oldCatalog, $newCatalog);
        $this->assertNotNull($conflict->json('import.preview'));

        $fresh = CrmImport::query()->findOrFail($id);
        $this->assertSame($newFp, $fresh->fingerprint);
        $this->assertSame($newCatalog, $fresh->catalog_fingerprint);

        $this->actingAs($admin)->postJson("/administration/crm/import/{$id}/anwenden", [
            'fingerprint' => $newFp,
            'catalog_fingerprint' => $newCatalog,
        ])->assertOk();

        $this->assertTrue(
            CrmAccount::query()->where('salesforce_account_id_canonical', SalesforceAccountId::normalize('001xx000003DGbq')['canonical'])->exists(),
        );
    }

    #[Test]
    public function preview_exposes_effects_and_planned_matches(): void
    {
        Storage::fake((string) config('dispo.files_disk'));
        $admin = User::factory()->role(Role::Admin)->create();
        $sales = User::factory()->role(Role::Sales)->create();

        app(CrmAccountService::class)->createProvisional([
            'name' => 'Vorläufig Match',
            'type' => CrmAccountType::Customer,
            'matching_domain' => 'preview-match.test',
        ], $sales);

        $upload = $this->actingAs($admin)->post('/administration/crm/import', [
            'file' => UploadedFile::fake()->createWithContent('sf.csv', $this->csv([
                ['Neuer Kunde', '', '001xx000003DGbq', 'x@preview-match.test', 'Account KUNDE'],
            ])),
        ])->assertOk();

        $actions = $upload->json('import.preview.actions');
        $this->assertSame('create', $actions[0]['effect']);
        $matches = $upload->json('import.preview.planned_auto_matches');
        $this->assertNotEmpty($matches);
        $this->assertSame('auto', $matches[0]['mode']);
    }

    #[Test]
    public function domain_change_preview_creates_unique_match_aligned_with_apply(): void
    {
        Storage::fake((string) config('dispo.files_disk'));
        $admin = User::factory()->role(Role::Admin)->create();
        $sales = User::factory()->role(Role::Sales)->create();
        $sf = '001xx000003DGbq';

        $this->importAndApply($admin, [
            ['SF Alt Domain', '', $sf, 'a@old-domain.test', 'Account KUNDE'],
        ]);
        $account = CrmAccount::query()->whereNull('merged_into_account_id')->firstOrFail();

        $provisional = app(CrmAccountService::class)->createProvisional([
            'name' => 'Vorläufig Neu',
            'type' => CrmAccountType::Customer,
            'matching_domain' => 'new-domain.test',
        ], $sales);

        $upload = $this->actingAs($admin)->post('/administration/crm/import', [
            'file' => UploadedFile::fake()->createWithContent('sf.csv', $this->csv([
                ['SF Alt Domain', '', $sf, 'a@new-domain.test', 'Account KUNDE'],
            ])),
        ])->assertOk();

        $matches = $upload->json('import.preview.planned_auto_matches');
        $this->assertCount(1, $matches);
        $this->assertSame('auto', $matches[0]['mode']);
        $this->assertSame($provisional->id, $matches[0]['provisional_id']);
        $this->assertSame($account->id, $matches[0]['target_account_id']);
        $canonical = SalesforceAccountId::normalize($sf)['canonical'];
        $this->assertSame($canonical, $matches[0]['target_salesforce_canonical']);

        $apply = $this->actingAs($admin)->postJson('/administration/crm/import/'.$upload->json('import.id').'/anwenden', [
            'fingerprint' => $upload->json('import.fingerprint'),
            'catalog_fingerprint' => $upload->json('import.catalog_fingerprint'),
        ])->assertOk();

        $reportMatches = collect($apply->json('import.report.auto_matches') ?? []);
        $this->assertNotEmpty($reportMatches);
        $this->assertSame('auto', $reportMatches->first()['mode']);
        $this->assertSame($account->id, $reportMatches->first()['linked_account_id']);
        $this->assertSame($account->id, $provisional->fresh()->merged_into_account_id);
        $this->assertSame('new-domain.test', $account->fresh()->matching_domain);
    }

    #[Test]
    public function domain_change_preview_removes_previous_match(): void
    {
        Storage::fake((string) config('dispo.files_disk'));
        $admin = User::factory()->role(Role::Admin)->create();
        $sales = User::factory()->role(Role::Sales)->create();
        $sf = '001xx000003DGBr';

        $this->importAndApply($admin, [
            ['SF Lose Domain', '', $sf, 'a@keep-domain.test', 'Account KUNDE'],
        ]);

        app(CrmAccountService::class)->createProvisional([
            'name' => 'Vorläufig Keep',
            'type' => CrmAccountType::Customer,
            'matching_domain' => 'keep-domain.test',
        ], $sales);

        $upload = $this->actingAs($admin)->post('/administration/crm/import', [
            'file' => UploadedFile::fake()->createWithContent('sf.csv', $this->csv([
                ['SF Lose Domain', '', $sf, 'a@other-domain.test', 'Account KUNDE'],
            ])),
        ])->assertOk();

        $matches = $upload->json('import.preview.planned_auto_matches') ?? [];
        $this->assertSame([], $matches);

        $this->actingAs($admin)->postJson('/administration/crm/import/'.$upload->json('import.id').'/anwenden', [
            'fingerprint' => $upload->json('import.fingerprint'),
            'catalog_fingerprint' => $upload->json('import.catalog_fingerprint'),
        ])->assertOk();

        $this->assertTrue(
            CrmAccount::query()
                ->where('is_provisional', true)
                ->where('matching_domain', 'keep-domain.test')
                ->whereNull('merged_into_account_id')
                ->exists(),
        );
    }

    #[Test]
    public function domain_change_preview_creates_and_clears_ambiguity(): void
    {
        Storage::fake((string) config('dispo.files_disk'));
        $admin = User::factory()->role(Role::Admin)->create();
        $sales = User::factory()->role(Role::Sales)->create();

        $this->importAndApply($admin, [
            ['SF A', '', '001xx000003DGbq', 'a@ambig-a.test', 'Account KUNDE'],
            ['SF B', '', '001xx000003DGBr', 'b@ambig-b.test', 'Account KUNDE'],
        ]);

        $provisional = app(CrmAccountService::class)->createProvisional([
            'name' => 'Vorläufig Ambig',
            'type' => CrmAccountType::Customer,
            'matching_domain' => 'shared-now.test',
        ], $sales);

        $ambiguousUpload = $this->actingAs($admin)->post('/administration/crm/import', [
            'file' => UploadedFile::fake()->createWithContent('sf.csv', $this->csv([
                ['SF A', '', '001xx000003DGbq', 'a@shared-now.test', 'Account KUNDE'],
                ['SF B', '', '001xx000003DGBr', 'b@shared-now.test', 'Account KUNDE'],
            ])),
        ])->assertOk();

        $ambiguous = $ambiguousUpload->json('import.preview.planned_auto_matches');
        $this->assertCount(1, $ambiguous);
        $this->assertSame('ambiguous', $ambiguous[0]['mode']);
        $this->assertSame(2, $ambiguous[0]['candidates']);

        $this->actingAs($admin)->postJson('/administration/crm/import/'.$ambiguousUpload->json('import.id').'/anwenden', [
            'fingerprint' => $ambiguousUpload->json('import.fingerprint'),
            'catalog_fingerprint' => $ambiguousUpload->json('import.catalog_fingerprint'),
        ])->assertOk();
        $this->assertNull($provisional->fresh()->merged_into_account_id);

        $clearUpload = $this->actingAs($admin)->post('/administration/crm/import', [
            'file' => UploadedFile::fake()->createWithContent('sf.csv', $this->csv([
                ['SF A', '', '001xx000003DGbq', 'a@shared-now.test', 'Account KUNDE'],
                ['SF B', '', '001xx000003DGBr', 'b@ambig-b.test', 'Account KUNDE'],
            ])),
        ])->assertOk();

        $clear = $clearUpload->json('import.preview.planned_auto_matches');
        $this->assertCount(1, $clear);
        $this->assertSame('auto', $clear[0]['mode']);
        $sfA = CrmAccount::query()
            ->where('salesforce_account_id_canonical', SalesforceAccountId::normalize('001xx000003DGbq')['canonical'])
            ->firstOrFail();
        $this->assertSame($sfA->id, $clear[0]['target_account_id']);

        $this->actingAs($admin)->postJson('/administration/crm/import/'.$clearUpload->json('import.id').'/anwenden', [
            'fingerprint' => $clearUpload->json('import.fingerprint'),
            'catalog_fingerprint' => $clearUpload->json('import.catalog_fingerprint'),
        ])->assertOk();
        $this->assertSame($sfA->id, $provisional->fresh()->merged_into_account_id);
    }

    #[Test]
    public function manual_search_link_without_domain_and_conflict_resolve(): void
    {
        Storage::fake((string) config('dispo.files_disk'));
        $admin = User::factory()->role(Role::Admin)->create();
        $sales = User::factory()->role(Role::Sales)->create();

        $this->importAndApply($admin, [
            ['Ziel', 'M-9', '001xx000003DGbq', 'z@ziel.test', 'Account KUNDE'],
        ]);
        $sf = CrmAccount::query()->whereNull('merged_into_account_id')->firstOrFail();
        $provisional = app(CrmAccountService::class)->createProvisional([
            'name' => 'Ohne Domain',
            'type' => CrmAccountType::Customer,
        ], $sales);

        $search = $this->actingAs($admin)->getJson('/crm/accounts/suche?salesforce_only=1&type=customer&q=Ziel');
        $search->assertOk();
        $this->assertTrue(collect($search->json('accounts'))->contains(fn ($a) => $a['id'] === $sf->id));

        $this->actingAs($admin)->postJson(route('crm.accounts.link', $provisional), [
            'salesforce_account_id' => $sf->id,
        ])->assertOk();

        $conflict = CrmConflict::query()->create([
            'crm_account_id' => $sf->id,
            'type' => CrmConflictType::MeridianMismatch,
            'status' => 'open',
            'details' => ['existing_meridian' => 'M-9', 'incoming_meridian' => 'M-X'],
        ]);

        $this->actingAs($admin)->postJson(route('crm.conflicts.resolve', $conflict), [
            'note' => 'Geprüft, keine Überschreibung.',
        ])->assertOk();

        $conflict->refresh();
        $this->assertSame('resolved', $conflict->status);
        $this->assertSame('acknowledged_without_overwrite', $conflict->details['resolution'] ?? null);
    }

    #[Test]
    public function order_meridian_mismatch_opens_conflict_without_overwrite(): void
    {
        Storage::fake((string) config('dispo.files_disk'));
        $admin = User::factory()->role(Role::Admin)->create();
        $sales = User::factory()->role(Role::Sales)->create();

        $this->importAndApply($admin, [
            ['Kunde', 'M-KEEP', '001xx000003DGbq', 'a@mm.test', 'Account KUNDE'],
        ]);
        $account = CrmAccount::query()->firstOrFail();

        $calc = app(CalculationWriter::class)->create($this->withLiveSchemaFingerprint([
            'planning_mode' => 'manual',
            'customer_account_id' => $account->id,
            'invoice_recipient' => 'customer',
            'campaign' => 'MM',
            'product_title' => 'T',
            'order_discount_percent' => '0',
            'ae_enabled' => false,
            'positions' => [],
        ]), $sales);
        DB::table('calculations')->where('id', $calc->id)->update([
            'customer_meridian_number' => 'M-OTHER',
        ]);

        $this->importAndApply($admin, [
            ['Kunde', 'M-KEEP', '001xx000003DGbq', 'a@mm.test', 'Account KUNDE'],
        ]);

        $calc->refresh();
        $this->assertSame('M-OTHER', $calc->customer_meridian_number);
        $this->assertTrue(
            CrmConflict::query()->where('type', CrmConflictType::OrderMeridianMismatch)->exists(),
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
