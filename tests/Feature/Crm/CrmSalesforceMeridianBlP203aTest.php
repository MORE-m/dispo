<?php

namespace Tests\Feature\Crm;

use App\Enums\CrmAccountType;
use App\Enums\CrmConflictType;
use App\Enums\DispoOrderStatus;
use App\Enums\Role;
use App\Models\Calculation;
use App\Models\CrmAccount;
use App\Models\CrmConflict;
use App\Models\CrmImport;
use App\Models\DispoOrder;
use App\Models\User;
use App\Services\Crm\CrmAccountService;
use App\Support\Crm\SalesforceAccountId;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class CrmSalesforceMeridianBlP203aTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function csv_import_creates_customer_and_agency_with_and_without_meridian(): void
    {
        Storage::fake((string) config('dispo.files_disk'));
        $admin = User::factory()->role(Role::Admin)->create();
        $sfCustomer = '001xx000003DGbq';
        $sfAgency = '001xx000003AGNC';

        $csv = $this->csv([
            ['Kunde Alpha GmbH', '', $sfCustomer, 'billing@kunde-alpha.test', 'Account KUNDE'],
            ['Agentur Beta', 'M-9001', $sfAgency, 'desk@agentur-beta.test', 'Account AGENTUR'],
        ]);

        $response = $this->actingAs($admin)->post('/administration/crm/import', [
            'file' => UploadedFile::fake()->createWithContent('sf.csv', $csv),
        ]);
        $response->assertOk();
        $importId = $response->json('import.id');
        $fingerprint = $response->json('import.fingerprint');
        $catalogFp = $response->json('import.catalog_fingerprint');

        $apply = $this->actingAs($admin)->postJson("/administration/crm/import/{$importId}/anwenden", [
            'fingerprint' => $fingerprint,
            'catalog_fingerprint' => $catalogFp,
        ]);
        $apply->assertOk();

        $customer = CrmAccount::query()->where('salesforce_account_id_canonical', SalesforceAccountId::normalize($sfCustomer)['canonical'])->first();
        $agency = CrmAccount::query()->where('salesforce_account_id_canonical', SalesforceAccountId::normalize($sfAgency)['canonical'])->first();
        $this->assertNotNull($customer);
        $this->assertNotNull($agency);
        $this->assertSame(CrmAccountType::Customer, $customer->type);
        $this->assertSame(CrmAccountType::Agency, $agency->type);
        $this->assertNull($customer->currentVersion?->meridian_number);
        $this->assertSame('M-9001', $agency->currentVersion?->meridian_number);
    }

    #[Test]
    public function fifteen_and_eighteen_char_salesforce_ids_merge(): void
    {
        Storage::fake((string) config('dispo.files_disk'));
        $admin = User::factory()->role(Role::Admin)->create();
        $fifteen = '001xx000003DGbq';
        $eighteen = SalesforceAccountId::toEighteen($fifteen);

        $this->importAndApply($admin, [
            ['Kunde Eins', '', $fifteen, 'a@example-corp.test', 'Account KUNDE'],
        ]);
        $this->importAndApply($admin, [
            ['Kunde Eins', 'M-1', $eighteen, 'a@example-corp.test', 'Account KUNDE'],
        ]);

        $this->assertSame(1, CrmAccount::query()->whereNotNull('salesforce_account_id_canonical')->whereNull('merged_into_account_id')->count());
        $account = CrmAccount::query()->whereNull('merged_into_account_id')->first();
        $this->assertSame('M-1', $account?->currentVersion?->meridian_number);
        $this->assertSame(2, $account?->versions()->count());
    }

    #[Test]
    public function unchanged_reimport_creates_no_new_version_and_empty_meridian_does_not_clear(): void
    {
        Storage::fake((string) config('dispo.files_disk'));
        $admin = User::factory()->role(Role::Admin)->create();
        $sf = '001xx000003DGbq';

        $this->importAndApply($admin, [
            ['Kunde Eins', 'M-KEEP', $sf, 'a@example-corp.test', 'Account KUNDE'],
        ]);
        $account = CrmAccount::query()->first();
        $this->assertSame(1, $account?->versions()->count());

        $this->importAndApply($admin, [
            ['Kunde Eins', '', $sf, 'a@example-corp.test', 'Account KUNDE'],
        ]);
        $account->refresh();
        $this->assertSame(1, $account->versions()->count());
        $this->assertSame('M-KEEP', $account->currentVersion?->meridian_number);
    }

    #[Test]
    public function meridian_mismatch_opens_conflict_without_overwrite(): void
    {
        Storage::fake((string) config('dispo.files_disk'));
        $admin = User::factory()->role(Role::Admin)->create();
        $sf = '001xx000003DGbq';

        $this->importAndApply($admin, [
            ['Kunde Eins', 'M-OLD', $sf, 'a@example-corp.test', 'Account KUNDE'],
        ]);
        $this->importAndApply($admin, [
            ['Kunde Eins', 'M-NEW', $sf, 'a@example-corp.test', 'Account KUNDE'],
        ]);

        $account = CrmAccount::query()->first();
        $this->assertSame('M-OLD', $account?->currentVersion?->meridian_number);
        $this->assertTrue(CrmConflict::query()->where('type', CrmConflictType::MeridianMismatch)->exists());
    }

    #[Test]
    public function missing_export_account_is_not_deactivated(): void
    {
        Storage::fake((string) config('dispo.files_disk'));
        $admin = User::factory()->role(Role::Admin)->create();
        $sfA = '001xx000003DGbq';
        $sfB = '001xx000003DGBr';

        $this->importAndApply($admin, [
            ['Kunde A', 'M-1', $sfA, 'a@a-corp.test', 'Account KUNDE'],
            ['Kunde B', 'M-2', $sfB, 'b@b-corp.test', 'Account KUNDE'],
        ]);
        $this->importAndApply($admin, [
            ['Kunde A', 'M-1', $sfA, 'a@a-corp.test', 'Account KUNDE'],
        ]);

        $this->assertSame(2, CrmAccount::query()->whereNull('merged_into_account_id')->count());
    }

    #[Test]
    public function provisional_auto_links_on_unique_domain_and_type_and_supplements_meridian(): void
    {
        Storage::fake((string) config('dispo.files_disk'));
        $admin = User::factory()->role(Role::Admin)->create();
        $sales = User::factory()->role(Role::Sales)->create();
        /** @var CrmAccountService $accounts */
        $accounts = app(CrmAccountService::class);

        $provisional = $accounts->createProvisional([
            'name' => 'Vorläufig Alpha',
            'type' => CrmAccountType::Customer,
            'billing_email' => 'x@unique-alpha.test',
        ], $sales);

        $calc = Calculation::factory()->create([
            'advisor_id' => $sales->id,
            'customer_name' => 'Vorläufig Alpha',
            'customer_account_id' => $provisional->id,
            'customer_version_id' => $provisional->current_version_id,
            'invoice_recipient' => 'customer',
            'customer_meridian_number' => null,
        ]);

        // Minimal Dispo-Zeile ohne volle Snapshot-Fixtures (nur CRM-Nachtragspfad).
        Schema::disableForeignKeyConstraints();
        $orderId = DB::table('dispo_orders')->insertGetId([
            'calculation_id' => $calc->id,
            'configuration_snapshot_id' => 1,
            'number' => 'DA-2026-90001-01',
            'number_year' => 2026,
            'number_org_seq' => 90001,
            'number_calc_seq' => 1,
            'status' => DispoOrderStatus::Completed->value,
            'created_by_id' => $sales->id,
            'source_calculation_number' => $calc->number,
            'customer_name' => 'Vorläufig Alpha',
            'customer_account_id' => $provisional->id,
            'customer_version_id' => $provisional->current_version_id,
            'invoice_recipient' => 'customer',
            'customer_meridian_number' => null,
            'approval_kind' => 'regular',
            'requires_special_approval' => 0,
            'media_gross' => '0.00',
            'position_discount_total' => '0.00',
            'order_discount_total' => '0.00',
            'ae_total' => '0.00',
            'nn_invest' => '0.00',
            'order_discount_percent' => '0',
            'ae_enabled' => 0,
            'lock_version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        Schema::enableForeignKeyConstraints();

        $sf = '001xx000003DGbq';
        $this->importAndApply($admin, [
            ['Kunde Alpha GmbH', 'M-100', $sf, 'billing@unique-alpha.test', 'Account KUNDE'],
        ]);

        $provisional->refresh();
        $this->assertNotNull($provisional->merged_into_account_id);
        $calc->refresh();
        $order = DispoOrder::query()->findOrFail($orderId);
        $this->assertSame('M-100', $calc->customer_meridian_number);
        $this->assertSame('M-100', $order->customer_meridian_number);
        $this->assertSame(DispoOrderStatus::Completed, $order->status);
    }

    #[Test]
    public function same_domain_customer_and_agency_stay_separated(): void
    {
        Storage::fake((string) config('dispo.files_disk'));
        $admin = User::factory()->role(Role::Admin)->create();
        $this->importAndApply($admin, [
            ['Kunde', '', '001xx000003DGbq', 'shared@same-domain.test', 'Account KUNDE'],
            ['Agentur', '', '001xx000003AGNC', 'shared@same-domain.test', 'Account AGENTUR'],
        ]);

        $this->assertSame(1, CrmAccount::query()->where('type', CrmAccountType::Customer)->count());
        $this->assertSame(1, CrmAccount::query()->where('type', CrmAccountType::Agency)->count());
    }

    #[Test]
    public function ambiguity_across_existing_catalog_blocks_auto_match(): void
    {
        Storage::fake((string) config('dispo.files_disk'));
        $admin = User::factory()->role(Role::Admin)->create();
        $sales = User::factory()->role(Role::Sales)->create();
        $accounts = app(CrmAccountService::class);

        $this->importAndApply($admin, [
            ['Kunde A', '', '001xx000003DGbq', 'a@ambig.test', 'Account KUNDE'],
            ['Kunde B', '', '001xx000003DGBr', 'b@ambig.test', 'Account KUNDE'],
        ]);
        // Force both to same domain for ambiguity over full catalog
        CrmAccount::query()->update(['matching_domain' => 'ambig.test']);
        CrmAccount::query()->get()->each(function (CrmAccount $a): void {
            $a->currentVersion?->update(['matching_domain' => 'ambig.test']);
        });

        $provisional = $accounts->createProvisional([
            'name' => 'Vorläufig',
            'type' => CrmAccountType::Customer,
            'matching_domain' => 'ambig.test',
        ], $sales);

        $this->importAndApply($admin, [
            ['Kunde A', '', '001xx000003DGbq', 'a@ambig.test', 'Account KUNDE'],
        ]);

        $provisional->refresh();
        $this->assertTrue($provisional->is_provisional);
        $this->assertNull($provisional->merged_into_account_id);
        $this->assertTrue(CrmConflict::query()->where('type', CrmConflictType::AmbiguousDomain)->exists());
    }

    #[Test]
    public function shared_email_domain_and_divergent_domains_do_not_auto_match(): void
    {
        Storage::fake((string) config('dispo.files_disk'));
        $admin = User::factory()->role(Role::Admin)->create();
        $sales = User::factory()->role(Role::Sales)->create();
        $accounts = app(CrmAccountService::class);

        $provisional = $accounts->createProvisional([
            'name' => 'Gmail Kunde',
            'type' => CrmAccountType::Customer,
            'billing_email' => 'person@gmail.com',
        ], $sales);

        $this->importAndApply($admin, [
            ['Kunde Gmail', 'M-9', '001xx000003DGbq', 'other@gmail.com', 'Account KUNDE'],
        ]);
        $provisional->refresh();
        $this->assertTrue($provisional->is_provisional);

        $csv = "Accountname;Meridian-ID;Account-ID;Rechnungs-E-Mail;Account-Datensatztyp\n"
            ."Multi;;001xx000003DGBx;\"a@one.test; b@two.test\";Account KUNDE\n";
        $import = $this->uploadOnly($admin, $csv);
        $issues = collect($import->preview['issues'] ?? []);
        $this->assertTrue(
            $issues->contains(fn ($i) => str_contains((string) ($i['message'] ?? ''), 'unterschiedliche Domains')),
            'Expected divergent-domain warning, got: '.json_encode($issues->all()),
        );
    }

    #[Test]
    public function rights_gate_import_and_provisional(): void
    {
        Storage::fake((string) config('dispo.files_disk'));
        $sales = User::factory()->role(Role::Sales)->create();
        $pm = User::factory()->role(Role::ProductManagement)->create();
        $disposition = User::factory()->role(Role::Disposition)->create();

        $this->actingAs($sales)->post('/administration/crm/import', [
            'file' => UploadedFile::fake()->createWithContent('sf.csv', $this->csv([])),
        ])->assertForbidden();

        $this->actingAs($pm)->postJson('/crm/accounts/vorlaeufig', [
            'name' => 'X',
            'type' => 'customer',
        ])->assertForbidden();

        $this->actingAs($sales)->postJson('/crm/accounts/vorlaeufig', [
            'name' => 'Sales Vorläufig',
            'type' => 'customer',
            'billing_email' => 's@sales-prov.test',
        ])->assertCreated();

        $this->actingAs($disposition)->get('/crm/accounts')->assertOk();
    }

    #[Test]
    public function unknown_type_is_reported_and_not_silently_mapped_to_customer(): void
    {
        Storage::fake((string) config('dispo.files_disk'));
        $admin = User::factory()->role(Role::Admin)->create();
        $import = $this->uploadOnly($admin, $this->csv([
            ['X', '', '001xx000003DGbq', 'a@x.test', 'Account SONSTIGES'],
        ]));
        $this->assertGreaterThan(0, $import->error_count);
        $this->assertSame(0, CrmAccount::query()->count());
    }

    #[Test]
    public function repeated_apply_is_idempotent(): void
    {
        Storage::fake((string) config('dispo.files_disk'));
        $admin = User::factory()->role(Role::Admin)->create();
        $csv = $this->csv([
            ['Kunde', 'M-1', '001xx000003DGbq', 'a@idemp.test', 'Account KUNDE'],
        ]);
        $upload = $this->actingAs($admin)->post('/administration/crm/import', [
            'file' => UploadedFile::fake()->createWithContent('sf.csv', $csv),
        ])->assertOk();
        $id = $upload->json('import.id');
        $fp = $upload->json('import.fingerprint');
        $cfp = $upload->json('import.catalog_fingerprint');

        $this->actingAs($admin)->postJson("/administration/crm/import/{$id}/anwenden", [
            'fingerprint' => $fp,
            'catalog_fingerprint' => $cfp,
        ])->assertOk();

        // second apply on already applied import returns same
        $this->actingAs($admin)->postJson("/administration/crm/import/{$id}/anwenden", [
            'fingerprint' => $fp,
            'catalog_fingerprint' => $cfp,
        ])->assertOk();

        $this->assertSame(1, CrmAccount::query()->count());
        $this->assertSame(1, CrmAccount::query()->first()?->versions()->count());
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

    private function uploadOnly(User $admin, string $csv): CrmImport
    {
        $upload = $this->actingAs($admin)->post('/administration/crm/import', [
            'file' => UploadedFile::fake()->createWithContent('sf.csv', $csv),
        ]);
        $upload->assertOk();

        return CrmImport::query()->findOrFail($upload->json('import.id'));
    }
}
