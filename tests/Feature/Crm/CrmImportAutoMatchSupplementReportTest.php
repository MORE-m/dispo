<?php

namespace Tests\Feature\Crm;

use App\Enums\CrmAccountType;
use App\Enums\CrmConflictType;
use App\Enums\DerivedCampaignPeriodStatus;
use App\Enums\DispoOrderApprovalKind;
use App\Enums\DispoOrderStatus;
use App\Enums\Role;
use App\Models\Calculation;
use App\Models\CrmAccount;
use App\Models\CrmConflict;
use App\Models\CrmImport;
use App\Models\DispoOrder;
use App\Models\User;
use App\Services\Calculation\CalculationWriter;
use App\Services\Crm\CrmAccountService;
use App\Support\Crm\SalesforceAccountId;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Auto-Match-Nachträge müssen im Import-Report erscheinen (kein Skip nach applyAction).
 */
final class CrmImportAutoMatchSupplementReportTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function auto_match_supplements_calc_and_dispo_and_report_matches(): void
    {
        Storage::fake((string) config('dispo.files_disk'));
        $admin = User::factory()->role(Role::Admin)->create();
        $sales = User::factory()->role(Role::Sales)->create();

        $provisional = app(CrmAccountService::class)->createProvisional([
            'name' => 'Vorläufig Report',
            'type' => CrmAccountType::Customer,
            'matching_domain' => 'auto-report.test',
        ], $sales);

        $calc = app(CalculationWriter::class)->create($this->withLiveSchemaFingerprint([
            'planning_mode' => 'manual',
            'customer_account_id' => $provisional->id,
            'invoice_recipient' => 'customer',
            'campaign' => 'Auto Report',
            'product_title' => 'T',
            'order_discount_percent' => '0',
            'ae_enabled' => false,
            'positions' => [],
        ]), $sales);
        $this->assertNull($calc->customer_meridian_number);

        $order = $this->minimalDispo($calc, $sales, $provisional, 'D-AUTO-R1');
        $this->assertNull($order->customer_meridian_number);

        $import = $this->importAndApply($admin, [
            ['SF Ziel', 'M-AUTO', '001xx000003AUT1', 'a@auto-report.test', 'Account KUNDE'],
        ]);

        $sup = $import->report['supplements'] ?? [];
        $this->assertSame(1, (int) ($sup['calculations'] ?? -1));
        $this->assertSame(1, (int) ($sup['dispo_orders'] ?? -1));
        $this->assertSame(0, (int) ($sup['conflicts'] ?? -1));
        $this->assertSame(0, (int) ($sup['conflicts_created'] ?? -1));

        $matches = collect($import->report['auto_matches'] ?? []);
        $this->assertTrue($matches->contains(fn ($m) => ($m['mode'] ?? null) === 'auto'));

        $calc->refresh();
        $order->refresh();
        $this->assertSame('M-AUTO', $calc->customer_meridian_number);
        $this->assertSame('M-AUTO', $order->customer_meridian_number);
        $sf = CrmAccount::query()
            ->where('salesforce_account_id_canonical', SalesforceAccountId::normalize('001xx000003AUT1')['canonical'])
            ->firstOrFail();
        $this->assertSame($sf->id, $calc->customer_account_id);
        $this->assertSame($sf->id, $order->customer_account_id);
    }

    #[Test]
    public function auto_match_mismatch_creates_one_conflict_with_import_id_and_counters(): void
    {
        Storage::fake((string) config('dispo.files_disk'));
        $admin = User::factory()->role(Role::Admin)->create();
        $sales = User::factory()->role(Role::Sales)->create();

        $provisional = app(CrmAccountService::class)->createProvisional([
            'name' => 'Vorläufig Konflikt',
            'type' => CrmAccountType::Customer,
            'matching_domain' => 'auto-conflict.test',
        ], $sales);

        $calc = app(CalculationWriter::class)->create($this->withLiveSchemaFingerprint([
            'planning_mode' => 'manual',
            'customer_account_id' => $provisional->id,
            'invoice_recipient' => 'customer',
            'campaign' => 'Auto Conflict',
            'product_title' => 'T',
            'order_discount_percent' => '0',
            'ae_enabled' => false,
            'positions' => [],
        ]), $sales);
        DB::table('calculations')->where('id', $calc->id)->update([
            'customer_meridian_number' => 'M-OTHER',
        ]);

        $import = $this->importAndApply($admin, [
            ['SF Konflikt', 'M-KEEP', '001xx000003AUT2', 'a@auto-conflict.test', 'Account KUNDE'],
        ]);

        $sup = $import->report['supplements'] ?? [];
        // Calc zählt wegen fehlender SF-ID-Nachtrag (Meridian bleibt Konflikt).
        $this->assertSame(1, (int) ($sup['calculations'] ?? -1));
        $this->assertSame(1, (int) ($sup['conflicts'] ?? -1));
        $this->assertSame(1, (int) ($sup['conflicts_created'] ?? -1));

        $open = CrmConflict::query()
            ->where('type', CrmConflictType::OrderMeridianMismatch)
            ->where('status', 'open')
            ->get();
        $this->assertCount(1, $open);
        $this->assertSame($import->id, $open[0]->crm_import_id);
        $calc->refresh();
        $this->assertSame('M-OTHER', $calc->customer_meridian_number);
        $this->assertNotNull($calc->customer_salesforce_account_id);
    }

    #[Test]
    public function multiple_provisionals_to_same_target_all_supplemented_once_in_report(): void
    {
        Storage::fake((string) config('dispo.files_disk'));
        $admin = User::factory()->role(Role::Admin)->create();
        $sales = User::factory()->role(Role::Sales)->create();
        $accounts = app(CrmAccountService::class);

        $p1 = $accounts->createProvisional([
            'name' => 'Prov 1',
            'type' => CrmAccountType::Customer,
            'matching_domain' => 'multi-auto.test',
        ], $sales);
        $p2 = $accounts->createProvisional([
            'name' => 'Prov 2',
            'type' => CrmAccountType::Customer,
            'matching_domain' => 'multi-auto.test',
        ], $sales);

        $calc1 = app(CalculationWriter::class)->create($this->withLiveSchemaFingerprint([
            'planning_mode' => 'manual',
            'customer_account_id' => $p1->id,
            'invoice_recipient' => 'customer',
            'campaign' => 'Multi 1',
            'product_title' => 'T',
            'order_discount_percent' => '0',
            'ae_enabled' => false,
            'positions' => [],
        ]), $sales);
        $calc2 = app(CalculationWriter::class)->create($this->withLiveSchemaFingerprint([
            'planning_mode' => 'manual',
            'customer_account_id' => $p2->id,
            'invoice_recipient' => 'customer',
            'campaign' => 'Multi 2',
            'product_title' => 'T',
            'order_discount_percent' => '0',
            'ae_enabled' => false,
            'positions' => [],
        ]), $sales);

        $import = $this->importAndApply($admin, [
            ['SF Multi', 'M-MULTI', '001xx000003AUT3', 'a@multi-auto.test', 'Account KUNDE'],
        ]);

        $sup = $import->report['supplements'] ?? [];
        $this->assertSame(2, (int) ($sup['calculations'] ?? -1));
        $this->assertSame(0, (int) ($sup['conflicts_created'] ?? -1));

        $auto = collect($import->report['auto_matches'] ?? [])->where('mode', 'auto');
        $this->assertCount(2, $auto);

        $this->assertSame('M-MULTI', $calc1->fresh()->customer_meridian_number);
        $this->assertSame('M-MULTI', $calc2->fresh()->customer_meridian_number);
    }

    #[Test]
    public function unchanged_reimport_recognizes_open_conflict_without_recreate(): void
    {
        Storage::fake((string) config('dispo.files_disk'));
        $admin = User::factory()->role(Role::Admin)->create();
        $sales = User::factory()->role(Role::Sales)->create();

        $provisional = app(CrmAccountService::class)->createProvisional([
            'name' => 'Vorläufig Re',
            'type' => CrmAccountType::Customer,
            'matching_domain' => 'auto-re.test',
        ], $sales);

        $calc = app(CalculationWriter::class)->create($this->withLiveSchemaFingerprint([
            'planning_mode' => 'manual',
            'customer_account_id' => $provisional->id,
            'invoice_recipient' => 'customer',
            'campaign' => 'Re',
            'product_title' => 'T',
            'order_discount_percent' => '0',
            'ae_enabled' => false,
            'positions' => [],
        ]), $sales);
        DB::table('calculations')->where('id', $calc->id)->update([
            'customer_meridian_number' => 'M-OTHER',
        ]);

        $first = $this->importAndApply($admin, [
            ['SF Re', 'M-KEEP', '001xx000003AUT4', 'a@auto-re.test', 'Account KUNDE'],
        ]);
        $this->assertSame(1, (int) ($first->report['supplements']['conflicts_created'] ?? -1));

        $second = $this->importAndApply($admin, [
            ['SF Re', 'M-KEEP', '001xx000003AUT4', 'a@auto-re.test', 'Account KUNDE'],
        ]);
        $sup = $second->report['supplements'] ?? [];
        $this->assertSame(0, (int) ($sup['calculations'] ?? -1));
        $this->assertSame(0, (int) ($sup['dispo_orders'] ?? -1));
        $this->assertSame(1, (int) ($sup['conflicts'] ?? -1));
        $this->assertSame(0, (int) ($sup['conflicts_created'] ?? -1));
        $this->assertSame(
            1,
            CrmConflict::query()->where('type', CrmConflictType::OrderMeridianMismatch)->where('status', 'open')->count(),
        );
    }

    private function minimalDispo(
        Calculation $calc,
        User $sales,
        CrmAccount $customer,
        string $number,
    ): DispoOrder {
        return DispoOrder::query()->create([
            'calculation_id' => $calc->id,
            'number' => $number,
            'number_year' => 2026,
            'number_org_seq' => 91001,
            'number_calc_seq' => 1,
            'status' => DispoOrderStatus::Draft,
            'created_by_id' => $sales->id,
            'source_calculation_number' => $calc->number,
            'approval_kind' => DispoOrderApprovalKind::Regular,
            'configuration_snapshot_id' => $calc->configuration_snapshot_id,
            'customer_name' => $calc->customer_name,
            'customer_account_id' => $customer->id,
            'customer_version_id' => $customer->current_version_id,
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
