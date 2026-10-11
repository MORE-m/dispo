<?php

namespace Tests\Feature\Crm;

use App\Enums\InvoiceRecipient;
use App\Enums\Role;
use App\Models\Calculation;
use App\Models\CrmAccount;
use App\Models\User;
use App\Services\Calculation\CalculationWriter;
use App\Services\Crm\CrmAccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * CRM-001 / PO-BLP203-1 Wizard-Picker: Freitext→Provisional, Bestandsschutz, Preview.
 */
final class CrmWizardPickerBindingTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function new_freitext_customer_creates_provisional_on_save_not_preview(): void
    {
        $sales = User::factory()->role(Role::Sales)->create();
        $writer = app(CalculationWriter::class);
        $base = $this->headerPayload('Neue Firma GmbH');

        $previewBefore = CrmAccount::query()->count();
        $writer->preview($base, $sales);
        $this->assertSame($previewBefore, CrmAccount::query()->count());

        $calc = $writer->create($base, $sales);
        $this->assertNotNull($calc->customer_account_id);
        $account = CrmAccount::query()->findOrFail($calc->customer_account_id);
        $this->assertTrue($account->is_provisional);
        $this->assertSame('Neue Firma GmbH', $calc->customer_name);
        $this->assertSame(InvoiceRecipient::Customer, $calc->invoice_recipient);
        $this->assertNull($calc->customer_meridian_number);
    }

    #[Test]
    public function new_freitext_without_account_key_creates_provisional(): void
    {
        $sales = User::factory()->role(Role::Sales)->create();
        $writer = app(CalculationWriter::class);
        $payload = $this->withLiveSchemaFingerprint([
            'planning_mode' => 'manual',
            'customer_name' => 'Ohne Account Key GmbH',
            'campaign' => 'Create',
            'product_title' => 'Titel',
            'order_discount_percent' => '0',
            'ae_enabled' => false,
            'positions' => [],
        ]);
        $calc = $writer->create($payload, $sales);
        $this->assertNotNull($calc->customer_account_id);
        $this->assertTrue(CrmAccount::query()->findOrFail($calc->customer_account_id)->is_provisional);
        $this->assertSame('Ohne Account Key GmbH', $calc->customer_name);
    }

    #[Test]
    public function repeated_save_does_not_create_extra_provisional(): void
    {
        $sales = User::factory()->role(Role::Sales)->create();
        $writer = app(CalculationWriter::class);
        $calc = $writer->create($this->headerPayload('Wieder Firma'), $sales);
        $accountId = $calc->customer_account_id;
        $versionId = $calc->customer_version_id;
        $this->assertNotNull($accountId);
        $count = CrmAccount::query()->count();

        $payload = $writer->payloadFromCalculation($calc->fresh());
        $payload['customer_account_id'] = $accountId;
        $payload['invoice_recipient'] = 'customer';
        $payload['lock_version'] = $calc->fresh()->lock_version;
        $writer->update($calc->fresh(), $this->withLiveSchemaFingerprint($payload), $sales);

        $this->assertSame($count, CrmAccount::query()->count());
        $fresh = $calc->fresh();
        $this->assertSame($accountId, $fresh->customer_account_id);
        $this->assertSame($versionId, $fresh->customer_version_id);
    }

    #[Test]
    public function freitext_save_then_bound_resave_is_idempotent(): void
    {
        $sales = User::factory()->role(Role::Sales)->create();
        $writer = app(CalculationWriter::class);
        $calc = $writer->create($this->headerPayload('Idempotent AG'), $sales);
        $accountId = $calc->customer_account_id;
        $versionId = $calc->customer_version_id;
        $count = CrmAccount::query()->count();

        // Zweiter Save wie nach Picker-Sync: Account-ID, nicht erneut null+create.
        $again = $this->headerPayload('Idempotent AG');
        $again['customer_account_id'] = $accountId;
        $again['invoice_recipient'] = 'customer';
        $again['lock_version'] = $calc->fresh()->lock_version;
        $writer->update($calc->fresh(), $again, $sales);

        $this->assertSame($count, CrmAccount::query()->count());
        $fresh = $calc->fresh();
        $this->assertSame($accountId, $fresh->customer_account_id);
        $this->assertSame($versionId, $fresh->customer_version_id);
    }

    #[Test]
    public function legacy_freitext_without_account_stays_unlinked_on_update(): void
    {
        $sales = User::factory()->role(Role::Sales)->create();
        $writer = app(CalculationWriter::class);
        $calc = $this->makeHistoricalFreitextCalc($sales, 'Alt Freitext AG');
        $this->assertNull($calc->customer_account_id);

        $again = $this->headerPayload('Alt Freitext AG');
        $again['lock_version'] = $calc->fresh()->lock_version;
        $before = CrmAccount::query()->count();
        $writer->update($calc->fresh(), $again, $sales);
        $this->assertSame($before, CrmAccount::query()->count());
        $fresh = $calc->fresh();
        $this->assertNull($fresh->customer_account_id);
        $this->assertSame('Alt Freitext AG', $fresh->customer_name);
    }

    #[Test]
    public function legacy_freitext_can_convert_to_provisional_explicitly(): void
    {
        $sales = User::factory()->role(Role::Sales)->create();
        $writer = app(CalculationWriter::class);
        $calc = $this->makeHistoricalFreitextCalc($sales, 'Konvertier Mich');
        $this->assertNull($calc->customer_account_id);

        $convert = $this->headerPayload('Konvertier Mich');
        $convert['customer_create_provisional'] = true;
        $convert['lock_version'] = $calc->fresh()->lock_version;
        $writer->update($calc->fresh(), $convert, $sales);
        $fresh = $calc->fresh();
        $this->assertNotNull($fresh->customer_account_id);
        $this->assertTrue(CrmAccount::query()->findOrFail($fresh->customer_account_id)->is_provisional);
    }

    #[Test]
    public function agency_freitext_as_invoice_recipient(): void
    {
        $sales = User::factory()->role(Role::Sales)->create();
        $writer = app(CalculationWriter::class);
        $payload = $this->headerPayload('Kunde Mit Agentur');
        $payload['agency_name'] = 'Agentur Neu';
        $payload['agency_account_id'] = null;
        $payload['agency_matching_domain'] = 'agentur-neu.test';
        $payload['invoice_recipient'] = 'agency';
        $calc = $writer->create($payload, $sales);
        $this->assertNotNull($calc->customer_account_id);
        $this->assertNotNull($calc->agency_account_id);
        $this->assertSame(InvoiceRecipient::Agency, $calc->invoice_recipient);
        $this->assertSame('Agentur Neu', $calc->agency_name);
    }

    #[Test]
    public function clear_agency_removes_binding_and_rejects_stale_agency_invoice(): void
    {
        $sales = User::factory()->role(Role::Sales)->create();
        $writer = app(CalculationWriter::class);
        $payload = $this->headerPayload('Kunde Bleibt');
        $payload['agency_name'] = 'Agentur Weg';
        $payload['agency_account_id'] = null;
        $payload['invoice_recipient'] = 'agency';
        $calc = $writer->create($payload, $sales);
        $this->assertNotNull($calc->agency_account_id);

        $clear = $this->headerPayload('Kunde Bleibt');
        $clear['customer_account_id'] = $calc->customer_account_id;
        $clear['agency_name'] = null;
        $clear['agency_account_id'] = null;
        $clear['invoice_recipient'] = 'agency';
        $clear['lock_version'] = $calc->fresh()->lock_version;

        try {
            $writer->update($calc->fresh(), $clear, $sales);
            $this->fail('Expected validation exception');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('invoice_recipient', $e->errors());
        }

        $clear['invoice_recipient'] = 'customer';
        $writer->update($calc->fresh(), $clear, $sales);
        $fresh = $calc->fresh();
        $this->assertNull($fresh->agency_account_id);
        $this->assertNull($fresh->agency_name);
        $this->assertSame(InvoiceRecipient::Customer, $fresh->invoice_recipient);
    }

    #[Test]
    public function switch_account_to_new_freitext_creates_new_provisional(): void
    {
        $sales = User::factory()->role(Role::Sales)->create();
        $accounts = app(CrmAccountService::class);
        $existing = $accounts->createProvisional([
            'name' => 'Bestehend',
            'type' => 'customer',
        ], $sales);
        $writer = app(CalculationWriter::class);
        $payload = $this->headerPayload('Bestehend');
        $payload['customer_account_id'] = $existing->id;
        $payload['invoice_recipient'] = 'customer';
        $calc = $writer->create($payload, $sales);
        $this->assertSame($existing->id, $calc->customer_account_id);

        $switch = $this->headerPayload('Komplett Neu');
        $switch['lock_version'] = $calc->fresh()->lock_version;
        $writer->update($calc->fresh(), $switch, $sales);
        $fresh = $calc->fresh();
        $this->assertNotNull($fresh->customer_account_id);
        $this->assertNotSame($existing->id, $fresh->customer_account_id);
        $this->assertSame('Komplett Neu', $fresh->customer_name);
    }

    #[Test]
    public function untouch_customer_preserves_binding_when_only_agency_or_invoice_changes(): void
    {
        $sales = User::factory()->role(Role::Sales)->create();
        $writer = app(CalculationWriter::class);
        $payload = $this->headerPayload('Gebundener Kunde');
        $payload['agency_name'] = 'Gebundene Agentur';
        $payload['agency_account_id'] = null;
        $payload['invoice_recipient'] = 'agency';
        $calc = $writer->create($payload, $sales);

        $customerId = $calc->customer_account_id;
        $customerVersion = $calc->customer_version_id;
        $customerName = $calc->customer_name;
        $customerSf = $calc->customer_salesforce_account_id;
        $customerMeridian = $calc->customer_meridian_number;
        $agencyId = $calc->agency_account_id;
        $agencyVersion = $calc->agency_version_id;
        $agencyName = $calc->agency_name;

        // a) nur Agentur wechseln (Kunde nicht senden)
        $agencyOnly = $this->withLiveSchemaFingerprint([
            'planning_mode' => 'manual',
            'agency_name' => 'Agentur Neu',
            'agency_account_id' => null,
            'invoice_recipient' => 'agency',
            'campaign' => 'CRM Picker',
            'product_title' => 'Titel',
            'order_discount_percent' => '0',
            'ae_enabled' => false,
            'positions' => [],
            'lock_version' => $calc->fresh()->lock_version,
        ]);
        $writer->update($calc->fresh(), $agencyOnly, $sales);
        $fresh = $calc->fresh();
        $this->assertSame($customerId, $fresh->customer_account_id);
        $this->assertSame($customerVersion, $fresh->customer_version_id);
        $this->assertSame($customerName, $fresh->customer_name);
        $this->assertSame($customerSf, $fresh->customer_salesforce_account_id);
        $this->assertSame($customerMeridian, $fresh->customer_meridian_number);
        $this->assertNotSame($agencyId, $fresh->agency_account_id);
        $this->assertSame('Agentur Neu', $fresh->agency_name);

        // b) nur Rechnungsempfänger (beide Seiten nicht senden)
        $invoiceOnly = $this->withLiveSchemaFingerprint([
            'planning_mode' => 'manual',
            'invoice_recipient' => 'customer',
            'campaign' => 'CRM Picker',
            'product_title' => 'Titel',
            'order_discount_percent' => '0',
            'ae_enabled' => false,
            'positions' => [],
            'lock_version' => $fresh->lock_version,
        ]);
        $writer->update($fresh, $invoiceOnly, $sales);
        $fresh = $calc->fresh();
        $this->assertSame($customerId, $fresh->customer_account_id);
        $this->assertSame($customerVersion, $fresh->customer_version_id);
        $this->assertSame('Agentur Neu', $fresh->agency_name);
        $this->assertNotNull($fresh->agency_account_id);
        $this->assertSame(InvoiceRecipient::Customer, $fresh->invoice_recipient);

        // c) nur Kunde wechseln (Agentur nicht senden)
        $customerOnly = $this->withLiveSchemaFingerprint([
            'planning_mode' => 'manual',
            'customer_name' => 'Kunde Neu',
            'customer_account_id' => null,
            'invoice_recipient' => 'customer',
            'campaign' => 'CRM Picker',
            'product_title' => 'Titel',
            'order_discount_percent' => '0',
            'ae_enabled' => false,
            'positions' => [],
            'lock_version' => $fresh->lock_version,
        ]);
        $agencyIdBefore = $fresh->agency_account_id;
        $agencyVersionBefore = $fresh->agency_version_id;
        $writer->update($fresh, $customerOnly, $sales);
        $fresh = $calc->fresh();
        $this->assertNotSame($customerId, $fresh->customer_account_id);
        $this->assertSame('Kunde Neu', $fresh->customer_name);
        $this->assertSame($agencyIdBefore, $fresh->agency_account_id);
        $this->assertSame($agencyVersionBefore, $fresh->agency_version_id);
        $this->assertSame('Agentur Neu', $fresh->agency_name);
        unset($agencyName, $agencyVersion);
    }

    #[Test]
    public function untouch_preserves_historical_freitext_customer(): void
    {
        $sales = User::factory()->role(Role::Sales)->create();
        $writer = app(CalculationWriter::class);
        $calc = $this->makeHistoricalFreitextCalc($sales, 'Hist Freitext');

        $invoiceOnly = $this->withLiveSchemaFingerprint([
            'planning_mode' => 'manual',
            'invoice_recipient' => null,
            'campaign' => 'Legacy',
            'product_title' => 'Titel',
            'order_discount_percent' => '0',
            'ae_enabled' => false,
            'positions' => [],
            'lock_version' => $calc->lock_version,
        ]);
        $before = CrmAccount::query()->count();
        $writer->update($calc, $invoiceOnly, $sales);
        $this->assertSame($before, CrmAccount::query()->count());
        $fresh = $calc->fresh();
        $this->assertNull($fresh->customer_account_id);
        $this->assertSame('Hist Freitext', $fresh->customer_name);
    }

    #[Test]
    public function failed_save_rolls_back_provisional_account(): void
    {
        $sales = User::factory()->role(Role::Sales)->create();
        $writer = app(CalculationWriter::class);
        $calc = $writer->create($this->headerPayload('Basis Kunde'), $sales);
        $before = CrmAccount::query()->count();

        // Freitext-Wechsel würde Provisional anlegen, scheitert aber an RE ohne Agentur.
        $switch = $this->headerPayload('Rollback Firma');
        $switch['invoice_recipient'] = 'agency';
        $switch['lock_version'] = $calc->fresh()->lock_version;

        try {
            $writer->update($calc->fresh(), $switch, $sales);
            $this->fail('Expected validation exception');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('invoice_recipient', $e->errors());
        }

        $this->assertSame($before, CrmAccount::query()->count());
        $fresh = $calc->fresh();
        $this->assertSame('Basis Kunde', $fresh->customer_name);
        $this->assertNotNull($fresh->customer_account_id);
    }

    #[Test]
    public function disposition_cannot_create_provisional_via_freitext(): void
    {
        $disposition = User::factory()->role(Role::Disposition)->create();
        $writer = app(CalculationWriter::class);

        try {
            $writer->create($this->headerPayload('Keine Rechte GmbH'), $disposition);
            $this->fail('Expected validation exception');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('customer_name', $e->errors());
        }

        $this->assertSame(0, CrmAccount::query()->count());
    }

    /**
     * Historischen Freitext-Bestand vorbereiten (nicht über Neuanlage-Bypass).
     */
    private function makeHistoricalFreitextCalc(User $advisor, string $name): Calculation
    {
        $writer = app(CalculationWriter::class);
        $calc = $writer->create($this->headerPayload($name), $advisor);
        $accountId = $calc->customer_account_id;
        $this->assertNotNull($accountId);

        DB::table('calculations')->where('id', $calc->id)->update([
            'customer_account_id' => null,
            'customer_version_id' => null,
            'customer_salesforce_account_id' => null,
            'customer_meridian_number' => null,
            'invoice_recipient' => null,
        ]);

        // Vorläufigen Account entfernen, der nur für die Fixture entstand.
        DB::table('crm_account_versions')->where('crm_account_id', $accountId)->delete();
        DB::table('crm_accounts')->where('id', $accountId)->delete();

        $fresh = $calc->fresh();
        $this->assertNull($fresh->customer_account_id);
        $this->assertSame($name, $fresh->customer_name);

        return $fresh;
    }

    /**
     * @return array<string, mixed>
     */
    private function headerPayload(string $customerName): array
    {
        return $this->withLiveSchemaFingerprint([
            'planning_mode' => 'manual',
            'customer_name' => $customerName,
            'customer_account_id' => null,
            'agency_name' => null,
            'agency_account_id' => null,
            'invoice_recipient' => null,
            'campaign' => 'CRM Picker',
            'product_title' => 'Titel',
            'order_discount_percent' => '0',
            'ae_enabled' => false,
            'positions' => [],
        ]);
    }
}
