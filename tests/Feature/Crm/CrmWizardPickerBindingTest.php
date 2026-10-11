<?php

namespace Tests\Feature\Crm;

use App\Enums\InvoiceRecipient;
use App\Enums\Role;
use App\Models\CrmAccount;
use App\Models\User;
use App\Services\Calculation\CalculationWriter;
use App\Services\Crm\CrmAccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
    public function repeated_save_does_not_create_extra_provisional(): void
    {
        $sales = User::factory()->role(Role::Sales)->create();
        $writer = app(CalculationWriter::class);
        $calc = $writer->create($this->headerPayload('Wieder Firma'), $sales);
        $accountId = $calc->customer_account_id;
        $this->assertNotNull($accountId);
        $count = CrmAccount::query()->count();

        $payload = $writer->payloadFromCalculation($calc->fresh());
        $payload['customer_account_id'] = $accountId;
        $payload['invoice_recipient'] = 'customer';
        $payload['lock_version'] = $calc->fresh()->lock_version;
        $writer->update($calc->fresh(), $this->withLiveSchemaFingerprint($payload), $sales);

        $this->assertSame($count, CrmAccount::query()->count());
        $this->assertSame($accountId, $calc->fresh()->customer_account_id);
    }

    #[Test]
    public function legacy_freitext_without_account_key_stays_unlinked(): void
    {
        $sales = User::factory()->role(Role::Sales)->create();
        $writer = app(CalculationWriter::class);
        $payload = $this->withLiveSchemaFingerprint([
            'planning_mode' => 'manual',
            'customer_name' => 'Alt Freitext AG',
            'campaign' => 'Legacy',
            'product_title' => 'Titel',
            'order_discount_percent' => '0',
            'ae_enabled' => false,
            'positions' => [],
        ]);
        $calc = $writer->create($payload, $sales);
        $this->assertNull($calc->customer_account_id);
        $this->assertSame('Alt Freitext AG', $calc->customer_name);

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
        $payload = $this->withLiveSchemaFingerprint([
            'planning_mode' => 'manual',
            'customer_name' => 'Konvertier Mich',
            'campaign' => 'Convert',
            'product_title' => 'Titel',
            'order_discount_percent' => '0',
            'ae_enabled' => false,
            'positions' => [],
        ]);
        $calc = $writer->create($payload, $sales);
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
