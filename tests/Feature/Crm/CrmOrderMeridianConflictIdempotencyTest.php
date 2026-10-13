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
use App\Services\Crm\CrmMeridianSupplementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Idempotenz offener order_meridian_mismatch-Konflikte (Apply, Folgeimport, Seiten, Paare).
 */
final class CrmOrderMeridianConflictIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function apply_with_calc_mismatch_creates_exactly_one_open_conflict(): void
    {
        Storage::fake((string) config('dispo.files_disk'));
        $admin = User::factory()->role(Role::Admin)->create();
        $sales = User::factory()->role(Role::Sales)->create();

        $this->importAndApply($admin, [
            ['Kunde', 'M-KEEP', '001xx000003DGbq', 'a@idem.test', 'Account KUNDE'],
        ]);
        $account = CrmAccount::query()->firstOrFail();

        $calc = app(CalculationWriter::class)->create($this->withLiveSchemaFingerprint([
            'planning_mode' => 'manual',
            'customer_account_id' => $account->id,
            'invoice_recipient' => 'customer',
            'campaign' => 'Idem Calc',
            'product_title' => 'T',
            'order_discount_percent' => '0',
            'ae_enabled' => false,
            'positions' => [],
        ]), $sales);
        DB::table('calculations')->where('id', $calc->id)->update([
            'customer_meridian_number' => 'M-OTHER',
        ]);

        $import = $this->importAndApply($admin, [
            ['Kunde', 'M-KEEP', '001xx000003DGbq', 'a@idem.test', 'Account KUNDE'],
        ]);

        $open = CrmConflict::query()
            ->where('type', CrmConflictType::OrderMeridianMismatch)
            ->where('status', 'open')
            ->get();
        $this->assertCount(1, $open);
        $this->assertSame('calculation', $open[0]->details['entity'] ?? null);
        $this->assertSame($calc->id, (int) ($open[0]->details['entity_id'] ?? 0));
        $this->assertSame('customer_meridian_number', $open[0]->details['field'] ?? null);
        $this->assertSame('M-OTHER', $open[0]->details['existing'] ?? null);
        $this->assertSame('M-KEEP', $open[0]->details['incoming'] ?? null);

        $report = is_array($import->report) ? $import->report : [];
        $supplements = $report['supplements'] ?? $report['meridian_supplements'] ?? null;
        if (is_array($supplements)) {
            $this->assertSame(1, (int) ($supplements['conflicts'] ?? 0));
            $this->assertSame(1, (int) ($supplements['conflicts_created'] ?? 0));
        }

        $calc->refresh();
        $this->assertSame('M-OTHER', $calc->customer_meridian_number);
    }

    #[Test]
    public function apply_with_dispo_mismatch_creates_exactly_one_open_conflict(): void
    {
        Storage::fake((string) config('dispo.files_disk'));
        $admin = User::factory()->role(Role::Admin)->create();
        $sales = User::factory()->role(Role::Sales)->create();

        $this->importAndApply($admin, [
            ['Kunde', 'M-KEEP', '001xx000003DGbq', 'a@idem-d.test', 'Account KUNDE'],
        ]);
        $account = CrmAccount::query()->firstOrFail();

        $calc = app(CalculationWriter::class)->create($this->withLiveSchemaFingerprint([
            'planning_mode' => 'manual',
            'customer_account_id' => $account->id,
            'invoice_recipient' => 'customer',
            'campaign' => 'Idem Dispo',
            'product_title' => 'T',
            'order_discount_percent' => '0',
            'ae_enabled' => false,
            'positions' => [],
        ]), $sales);

        $order = $this->minimalDispo($calc, $sales, $account, 'D-IDEM-1');
        DB::table('dispo_orders')->where('id', $order->id)->update([
            'customer_meridian_number' => 'M-DISPO-OTHER',
        ]);
        // Calc bereits passend → nur Dispo-Widerspruch.
        DB::table('calculations')->where('id', $calc->id)->update([
            'customer_meridian_number' => 'M-KEEP',
        ]);

        $this->importAndApply($admin, [
            ['Kunde', 'M-KEEP', '001xx000003DGbq', 'a@idem-d.test', 'Account KUNDE'],
        ]);

        $open = CrmConflict::query()
            ->where('type', CrmConflictType::OrderMeridianMismatch)
            ->where('status', 'open')
            ->get();
        $this->assertCount(1, $open);
        $this->assertSame('dispo_order', $open[0]->details['entity'] ?? null);
        $this->assertSame($order->id, (int) ($open[0]->details['entity_id'] ?? 0));
        $this->assertSame('M-DISPO-OTHER', $open[0]->details['existing'] ?? null);

        $order->refresh();
        $this->assertSame('M-DISPO-OTHER', $order->customer_meridian_number);
        $this->assertSame(DispoOrderStatus::Draft, $order->status);
    }

    #[Test]
    public function repeated_service_call_and_unchanged_reimport_do_not_duplicate_open_conflict(): void
    {
        Storage::fake((string) config('dispo.files_disk'));
        $admin = User::factory()->role(Role::Admin)->create();
        $sales = User::factory()->role(Role::Sales)->create();

        $this->importAndApply($admin, [
            ['Kunde', 'M-KEEP', '001xx000003DGbq', 'a@idem-r.test', 'Account KUNDE'],
        ]);
        $account = CrmAccount::query()->firstOrFail();

        $calc = app(CalculationWriter::class)->create($this->withLiveSchemaFingerprint([
            'planning_mode' => 'manual',
            'customer_account_id' => $account->id,
            'invoice_recipient' => 'customer',
            'campaign' => 'Idem Repeat',
            'product_title' => 'T',
            'order_discount_percent' => '0',
            'ae_enabled' => false,
            'positions' => [],
        ]), $sales);
        DB::table('calculations')->where('id', $calc->id)->update([
            'customer_meridian_number' => 'M-OTHER',
        ]);

        $service = app(CrmMeridianSupplementService::class);
        $first = $service->supplementMissing($account, 'M-KEEP', $admin, null);
        $this->assertSame(1, $first['conflicts']);
        $this->assertSame(1, $first['conflicts_created']);

        $second = $service->supplementMissing($account, 'M-KEEP', $admin, 999);
        $this->assertSame(1, $second['conflicts']);
        $this->assertSame(0, $second['conflicts_created']);

        $this->importAndApply($admin, [
            ['Kunde', 'M-KEEP', '001xx000003DGbq', 'a@idem-r.test', 'Account KUNDE'],
        ]);

        $this->assertSame(
            1,
            CrmConflict::query()
                ->where('type', CrmConflictType::OrderMeridianMismatch)
                ->where('status', 'open')
                ->count(),
        );
    }

    #[Test]
    public function customer_agency_calc_dispo_and_different_pairs_stay_separate(): void
    {
        Storage::fake((string) config('dispo.files_disk'));
        $admin = User::factory()->role(Role::Admin)->create();
        $sales = User::factory()->role(Role::Sales)->create();

        $this->importAndApply($admin, [
            ['Kunde', 'M-CUST', '001xx000003DGbq', 'c@sides.test', 'Account KUNDE'],
            ['Agentur', 'M-AG', '001xx000003DGBr', 'a@sides.test', 'Account AGENTUR'],
        ]);
        $customer = CrmAccount::query()
            ->where('type', CrmAccountType::Customer)
            ->whereNull('merged_into_account_id')
            ->firstOrFail();
        $agency = CrmAccount::query()
            ->where('type', CrmAccountType::Agency)
            ->whereNull('merged_into_account_id')
            ->firstOrFail();

        $calc = app(CalculationWriter::class)->create($this->withLiveSchemaFingerprint([
            'planning_mode' => 'manual',
            'customer_account_id' => $customer->id,
            'agency_account_id' => $agency->id,
            'invoice_recipient' => 'customer',
            'campaign' => 'Sides',
            'product_title' => 'T',
            'order_discount_percent' => '0',
            'ae_enabled' => false,
            'positions' => [],
        ]), $sales);
        $order = $this->minimalDispo($calc, $sales, $customer, 'D-SIDES-1', $agency);

        DB::table('calculations')->where('id', $calc->id)->update([
            'customer_meridian_number' => 'M-C-OLD',
            'agency_meridian_number' => 'M-A-OLD',
        ]);
        DB::table('dispo_orders')->where('id', $order->id)->update([
            'customer_meridian_number' => 'M-DC-OLD',
            'agency_meridian_number' => 'M-DA-OLD',
        ]);

        $this->importAndApply($admin, [
            ['Kunde', 'M-CUST', '001xx000003DGbq', 'c@sides.test', 'Account KUNDE'],
            ['Agentur', 'M-AG', '001xx000003DGBr', 'a@sides.test', 'Account AGENTUR'],
        ]);

        $open = CrmConflict::query()
            ->where('type', CrmConflictType::OrderMeridianMismatch)
            ->where('status', 'open')
            ->get();
        $this->assertCount(4, $open);

        $keys = $open->map(fn (CrmConflict $c): string => implode('|', [
            $c->details['entity'] ?? '',
            (string) ($c->details['entity_id'] ?? ''),
            $c->details['field'] ?? '',
            $c->details['existing'] ?? '',
            $c->details['incoming'] ?? '',
        ]))->sort()->values()->all();

        $this->assertSame([
            'calculation|'.$calc->id.'|agency_meridian_number|M-A-OLD|M-AG',
            'calculation|'.$calc->id.'|customer_meridian_number|M-C-OLD|M-CUST',
            'dispo_order|'.$order->id.'|agency_meridian_number|M-DA-OLD|M-AG',
            'dispo_order|'.$order->id.'|customer_meridian_number|M-DC-OLD|M-CUST',
        ], $keys);
    }

    #[Test]
    public function resolved_conflict_reopens_as_new_open_row_per_r5(): void
    {
        Storage::fake((string) config('dispo.files_disk'));
        $admin = User::factory()->role(Role::Admin)->create();
        $sales = User::factory()->role(Role::Sales)->create();

        $this->importAndApply($admin, [
            ['Kunde', 'M-KEEP', '001xx000003DGbq', 'a@reopen.test', 'Account KUNDE'],
        ]);
        $account = CrmAccount::query()->firstOrFail();

        $calc = app(CalculationWriter::class)->create($this->withLiveSchemaFingerprint([
            'planning_mode' => 'manual',
            'customer_account_id' => $account->id,
            'invoice_recipient' => 'customer',
            'campaign' => 'Reopen',
            'product_title' => 'T',
            'order_discount_percent' => '0',
            'ae_enabled' => false,
            'positions' => [],
        ]), $sales);
        DB::table('calculations')->where('id', $calc->id)->update([
            'customer_meridian_number' => 'M-OTHER',
        ]);

        app(CrmMeridianSupplementService::class)
            ->supplementMissing($account, 'M-KEEP', $admin, null);

        $conflict = CrmConflict::query()
            ->where('type', CrmConflictType::OrderMeridianMismatch)
            ->where('status', 'open')
            ->firstOrFail();
        $conflictId = $conflict->id;

        $this->actingAs($admin)->postJson(route('crm.conflicts.resolve', $conflict), [
            'note' => 'Geprüft.',
        ])->assertOk();
        $conflict->refresh();
        $this->assertSame('resolved', $conflict->status);

        $again = app(CrmMeridianSupplementService::class)
            ->supplementMissing($account, 'M-KEEP', $admin, null);
        $this->assertSame(1, $again['conflicts']);
        $this->assertSame(1, $again['conflicts_created']);

        $open = CrmConflict::query()
            ->where('type', CrmConflictType::OrderMeridianMismatch)
            ->where('status', 'open')
            ->get();
        $this->assertCount(1, $open);
        $this->assertNotSame($conflictId, $open[0]->id);
        $this->assertSame('resolved', $conflict->fresh()->status);
    }

    #[Test]
    public function missing_meridian_is_still_supplemented_without_conflict(): void
    {
        Storage::fake((string) config('dispo.files_disk'));
        $admin = User::factory()->role(Role::Admin)->create();
        $sales = User::factory()->role(Role::Sales)->create();

        // Account zuerst ohne Meridian anlegen, damit Calc ohne Nummer startet.
        $this->importAndApply($admin, [
            ['Kunde', '', '001xx000003DGbq', 'a@fill.test', 'Account KUNDE'],
        ]);
        $account = CrmAccount::query()->firstOrFail();

        $calc = app(CalculationWriter::class)->create($this->withLiveSchemaFingerprint([
            'planning_mode' => 'manual',
            'customer_account_id' => $account->id,
            'invoice_recipient' => 'customer',
            'campaign' => 'Fill',
            'product_title' => 'T',
            'order_discount_percent' => '0',
            'ae_enabled' => false,
            'positions' => [],
        ]), $sales);
        $this->assertNull($calc->customer_meridian_number);

        // Stammdaten-Meridian nachziehen (wie Import), danach Auftragsnachtrag.
        $account->currentVersion?->forceFill(['meridian_number' => 'M-NEW'])->save();
        $account->refresh();

        $result = app(CrmMeridianSupplementService::class)
            ->supplementMissing($account, 'M-NEW', $admin, null);

        $this->assertSame(1, $result['calculations']);
        $this->assertSame(0, $result['conflicts']);
        $this->assertSame(0, $result['conflicts_created']);
        $calc->refresh();
        $this->assertSame('M-NEW', $calc->customer_meridian_number);
        $this->assertSame(
            0,
            CrmConflict::query()->where('type', CrmConflictType::OrderMeridianMismatch)->count(),
        );
    }

    private function minimalDispo(
        Calculation $calc,
        User $sales,
        CrmAccount $customer,
        string $number,
        ?CrmAccount $agency = null,
    ): DispoOrder {
        return DispoOrder::query()->create([
            'calculation_id' => $calc->id,
            'number' => $number,
            'number_year' => 2026,
            'number_org_seq' => 90001,
            'number_calc_seq' => 1,
            'status' => DispoOrderStatus::Draft,
            'created_by_id' => $sales->id,
            'source_calculation_number' => $calc->number,
            'approval_kind' => DispoOrderApprovalKind::Regular,
            'configuration_snapshot_id' => $calc->configuration_snapshot_id,
            'customer_name' => $calc->customer_name,
            'customer_account_id' => $customer->id,
            'customer_version_id' => $customer->current_version_id,
            'agency_name' => $agency?->currentVersion?->name,
            'agency_account_id' => $agency?->id,
            'agency_version_id' => $agency?->current_version_id,
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
