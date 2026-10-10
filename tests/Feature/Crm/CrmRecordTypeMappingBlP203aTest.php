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

/**
 * PO-BLP203-1 D1: Gesellschafter/Sonstiges → intern Kunde; Originaltyp erhalten.
 */
final class CrmRecordTypeMappingBlP203aTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function all_four_delivered_record_types_import_and_store_original(): void
    {
        Storage::fake((string) config('dispo.files_disk'));
        $admin = User::factory()->role(Role::Admin)->create();

        $this->importAndApply($admin, [
            ['Kunde A', 'M-1', '001xx000003DGbq', 'a@kunde-rt.test', 'Account KUNDE'],
            ['Gesellschafter B', 'M-2', '001xx000003DGBr', 'b@gesell-rt.test', 'Account GESELLSCHAFTER'],
            ['Sonstige C', '', '001xx000003DGBs', 'c@sonst-rt.test', 'Account SONSTIGE'],
            ['Agentur D', 'M-4', '001xx000003AGNC', 'd@agentur-rt.test', 'Account AGENTUR'],
        ]);

        $this->assertSame(3, CrmAccount::query()->where('type', CrmAccountType::Customer)->count());
        $this->assertSame(1, CrmAccount::query()->where('type', CrmAccountType::Agency)->count());

        $kunde = $this->accountBySf('001xx000003DGbq');
        $gesell = $this->accountBySf('001xx000003DGBr');
        $sonst = $this->accountBySf('001xx000003DGBs');
        $agency = $this->accountBySf('001xx000003AGNC');

        $this->assertSame('Account KUNDE', $kunde->currentVersion?->salesforce_record_type);
        $this->assertSame('Account GESELLSCHAFTER', $gesell->currentVersion?->salesforce_record_type);
        $this->assertSame('Account SONSTIGE', $sonst->currentVersion?->salesforce_record_type);
        $this->assertSame('Account AGENTUR', $agency->currentVersion?->salesforce_record_type);
        $this->assertNull($sonst->currentVersion?->meridian_number);
        $this->assertSame('M-2', $gesell->currentVersion?->meridian_number);

        $this->actingAs($admin)->get('/crm/accounts/'.$gesell->id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('crm/accounts/show')
                ->where('account.salesforce_record_type', 'Account GESELLSCHAFTER')
                ->where('account.type', 'customer')
                ->where('account.type_label', 'Kunde'));
    }

    #[Test]
    public function truly_unknown_type_still_blocks_and_is_not_mapped(): void
    {
        Storage::fake((string) config('dispo.files_disk'));
        $admin = User::factory()->role(Role::Admin)->create();
        $import = $this->uploadOnly($admin, $this->csv([
            ['X', '', '001xx000003DGbq', 'a@x.test', 'Account PARTNER'],
        ]));

        $this->assertSame(1, $import->error_count);
        $this->assertGreaterThan(0, (int) ($import->preview['stats']['blocking_errors'] ?? 0));
        $this->assertSame(0, CrmAccount::query()->count());
    }

    #[Test]
    public function record_type_change_within_customer_group_creates_version(): void
    {
        Storage::fake((string) config('dispo.files_disk'));
        $admin = User::factory()->role(Role::Admin)->create();
        $sf = '001xx000003DGbq';

        $this->importAndApply($admin, [
            ['Firma X', 'M-1', $sf, 'a@rt-change.test', 'Account KUNDE'],
        ]);
        $account = $this->accountBySf($sf);
        $this->assertSame(1, $account->versions()->count());

        $this->importAndApply($admin, [
            ['Firma X', 'M-1', $sf, 'a@rt-change.test', 'Account GESELLSCHAFTER'],
        ]);
        $account->refresh();
        $this->assertSame(2, $account->versions()->count());
        $this->assertSame(CrmAccountType::Customer, $account->type);
        $this->assertSame('Account GESELLSCHAFTER', $account->currentVersion?->salesforce_record_type);
        $this->assertSame('Account KUNDE', $account->versions()->orderBy('version_number')->first()?->salesforce_record_type);
    }

    #[Test]
    public function unchanged_reimport_including_record_type_creates_no_version(): void
    {
        Storage::fake((string) config('dispo.files_disk'));
        $admin = User::factory()->role(Role::Admin)->create();
        $sf = '001xx000003DGbq';

        $this->importAndApply($admin, [
            ['Firma Y', 'M-9', $sf, 'y@rt-same.test', 'Account SONSTIGE'],
        ]);
        $this->importAndApply($admin, [
            ['Firma Y', 'M-9', $sf, 'y@rt-same.test', 'Account SONSTIGE'],
        ]);

        $account = $this->accountBySf($sf);
        $this->assertSame(1, $account->versions()->count());
        $this->assertSame('Account SONSTIGE', $account->currentVersion?->salesforce_record_type);
    }

    #[Test]
    public function customer_to_agency_record_type_remains_type_conflict(): void
    {
        Storage::fake((string) config('dispo.files_disk'));
        $admin = User::factory()->role(Role::Admin)->create();
        $sf = '001xx000003DGbq';

        $this->importAndApply($admin, [
            ['Firma Z', 'M-1', $sf, 'z@rt-conflict.test', 'Account KUNDE'],
        ]);
        $this->importAndApply($admin, [
            ['Firma Z', 'M-1', $sf, 'z@rt-conflict.test', 'Account AGENTUR'],
        ]);

        $account = $this->accountBySf($sf);
        $this->assertSame(CrmAccountType::Customer, $account->type);
        $this->assertSame('Account KUNDE', $account->currentVersion?->salesforce_record_type);
        $this->assertTrue(
            CrmConflict::query()->where('type', CrmConflictType::TypeChange)->exists(),
        );
    }

    #[Test]
    public function domain_ambiguity_across_kunde_and_gesellschafter_blocks_auto_match(): void
    {
        Storage::fake((string) config('dispo.files_disk'));
        $admin = User::factory()->role(Role::Admin)->create();
        $sales = User::factory()->role(Role::Sales)->create();
        $accounts = app(CrmAccountService::class);

        $this->importAndApply($admin, [
            ['Kunde A', '', '001xx000003DGbq', 'a@group-ambig.test', 'Account KUNDE'],
            ['Gesell B', '', '001xx000003DGBr', 'b@group-ambig.test', 'Account GESELLSCHAFTER'],
        ]);

        $provisional = $accounts->createProvisional([
            'name' => 'Vorläufig Gruppe',
            'type' => CrmAccountType::Customer,
            'matching_domain' => 'group-ambig.test',
        ], $sales);

        $this->importAndApply($admin, [
            ['Kunde A', '', '001xx000003DGbq', 'a@group-ambig.test', 'Account KUNDE'],
        ]);

        $provisional->refresh();
        $this->assertTrue($provisional->is_provisional);
        $this->assertNull($provisional->merged_into_account_id);
        $this->assertTrue(CrmConflict::query()->where('type', CrmConflictType::AmbiguousDomain)->exists());
    }

    #[Test]
    public function gesellschafter_selectable_as_customer_and_meridian_supplements_orders(): void
    {
        Storage::fake((string) config('dispo.files_disk'));
        $admin = User::factory()->role(Role::Admin)->create();
        $sales = User::factory()->role(Role::Sales)->create();
        $accounts = app(CrmAccountService::class);

        $provisional = $accounts->createProvisional([
            'name' => 'Vorläufig Gesell',
            'type' => CrmAccountType::Customer,
            'billing_email' => 'x@gesell-select.test',
        ], $sales);

        $calc = Calculation::factory()->create([
            'advisor_id' => $sales->id,
            'customer_name' => 'Vorläufig Gesell',
            'customer_account_id' => $provisional->id,
            'customer_version_id' => $provisional->current_version_id,
            'invoice_recipient' => 'customer',
            'customer_meridian_number' => null,
        ]);

        Schema::disableForeignKeyConstraints();
        $orderId = DB::table('dispo_orders')->insertGetId([
            'calculation_id' => $calc->id,
            'configuration_snapshot_id' => 1,
            'number' => 'DA-2026-90011-01',
            'number_year' => 2026,
            'number_org_seq' => 90011,
            'number_calc_seq' => 1,
            'status' => DispoOrderStatus::Completed->value,
            'created_by_id' => $sales->id,
            'source_calculation_number' => $calc->number,
            'customer_name' => 'Vorläufig Gesell',
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
            ['Gesellschafter Select', 'M-777', $sf, 'billing@gesell-select.test', 'Account GESELLSCHAFTER'],
        ]);

        $provisional->refresh();
        $this->assertNotNull($provisional->merged_into_account_id);

        $sfAccount = $this->accountBySf($sf);
        $this->assertSame(CrmAccountType::Customer, $sfAccount->type);

        $search = $this->actingAs($sales)->getJson('/crm/accounts/suche?type=customer&q=Gesellschafter');
        $search->assertOk();
        $ids = collect($search->json('accounts'))->pluck('id')->all();
        $this->assertContains($sfAccount->id, $ids);

        $calc->refresh();
        $order = DispoOrder::query()->findOrFail($orderId);
        $this->assertSame('M-777', $calc->customer_meridian_number);
        $this->assertSame('M-777', $order->customer_meridian_number);
        $this->assertSame(DispoOrderStatus::Completed, $order->status);
    }

    #[Test]
    public function provisional_versions_have_null_salesforce_record_type(): void
    {
        $sales = User::factory()->role(Role::Sales)->create();
        $account = app(CrmAccountService::class)->createProvisional([
            'name' => 'Vorläufig ohne SF-Typ',
            'type' => CrmAccountType::Customer,
            'matching_domain' => 'prov-null-rt.test',
        ], $sales);

        $this->assertNull($account->currentVersion?->salesforce_record_type);
    }

    private function accountBySf(string $raw): CrmAccount
    {
        $canonical = SalesforceAccountId::normalize($raw)['canonical'];

        return CrmAccount::query()
            ->with('currentVersion')
            ->where('salesforce_account_id_canonical', $canonical)
            ->whereNull('merged_into_account_id')
            ->firstOrFail();
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
