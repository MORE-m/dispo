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
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
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

    #[Test]
    public function competing_manual_links_cannot_split_orders_across_targets(): void
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
}
