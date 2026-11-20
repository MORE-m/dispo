<?php

namespace Tests\Feature\Calculation;

use App\Enums\InvoiceRecipient;
use App\Enums\Role;
use App\Models\CrmAccount;
use App\Models\DispoOrder;
use App\Models\User;
use App\Services\Calculation\CalculationWriter;
use App\Services\DynamicField\ConfigurationSnapshotFreezeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesSavedCalculation;
use Tests\Concerns\CreatesSpotClassicCatalog;
use Tests\TestCase;

/**
 * PO-CALC-DISPO-UX-1 PR4: Calc-Wizard bereinigen, Preserve-on-absent, RE=null.
 */
class PoCalcDispoUx1Pr4CalculationCleanupTest extends TestCase
{
    use CreatesSavedCalculation;
    use CreatesSpotClassicCatalog;
    use RefreshDatabase;

    #[Test]
    public function new_calculation_without_operative_fields_stores_null_agency_and_invoice_recipient(): void
    {
        $sales = User::factory()->role(Role::Sales)->create();
        $writer = app(CalculationWriter::class);

        $calc = $writer->create($this->withLiveSchemaFingerprint([
            'planning_mode' => 'manual',
            'customer_name' => 'PR4 Neu Kunde',
            'customer_account_id' => null,
            'campaign' => 'PR4 Kampagne',
            'product_title' => 'Titel',
            'order_discount_percent' => '0',
            'ae_enabled' => false,
            'positions' => [],
        ]), $sales);

        $this->assertSame('PR4 Neu Kunde', $calc->customer_name);
        $this->assertNotNull($calc->customer_account_id);
        $this->assertNull($calc->agency_name);
        $this->assertNull($calc->agency_account_id);
        $this->assertNull($calc->invoice_recipient);
        $this->assertNull($calc->briefing);
        $this->assertSame(0, CrmAccount::query()->where('type', 'agency')->count());
    }

    #[Test]
    public function update_without_operative_keys_preserves_historical_agency_invoice_and_briefing(): void
    {
        $sales = User::factory()->role(Role::Sales)->create();
        $writer = app(CalculationWriter::class);
        $catalog = $this->createSpotClassicCatalog();

        $calc = $this->createSavedCalculation(
            $catalog,
            [['inventory_id' => $catalog['hamburg']->id]],
            $sales,
            ['invoice_recipient' => 'agency'],
        );
        $calc->briefing = 'Historisches Briefing';
        $calc->save();

        $agencyId = $calc->agency_account_id;
        $agencyName = $calc->agency_name;
        $agencyVersion = $calc->agency_version_id;
        $agencyMeridian = $calc->agency_meridian_number;
        $agencySf = $calc->agency_salesforce_account_id;
        $this->assertNotNull($agencyId);
        $this->assertSame(InvoiceRecipient::Agency, $calc->invoice_recipient);

        $payload = $writer->payloadFromCalculation($calc->fresh(['positions']));
        unset($payload['agency_name'], $payload['agency_account_id'], $payload['invoice_recipient'], $payload['briefing']);
        $payload['campaign'] = 'Geänderter Titel-Pfad';
        $payload['lock_version'] = $calc->fresh()->lock_version;

        $writer->update($calc->fresh(['positions']), $payload, $sales);
        $fresh = $calc->fresh();

        $this->assertSame($agencyId, $fresh->agency_account_id);
        $this->assertSame($agencyName, $fresh->agency_name);
        $this->assertSame($agencyVersion, $fresh->agency_version_id);
        $this->assertSame($agencyMeridian, $fresh->agency_meridian_number);
        $this->assertSame($agencySf, $fresh->agency_salesforce_account_id);
        $this->assertSame(InvoiceRecipient::Agency, $fresh->invoice_recipient);
        $this->assertSame('Historisches Briefing', $fresh->briefing);
        $this->assertSame('Geänderter Titel-Pfad', $fresh->campaign);
    }

    #[Test]
    public function explicit_briefing_null_clears_value_while_absent_key_preserves(): void
    {
        $sales = User::factory()->role(Role::Sales)->create();
        $writer = app(CalculationWriter::class);
        $catalog = $this->createSpotClassicCatalog();
        $calc = $this->createSavedCalculation(
            $catalog,
            [['inventory_id' => $catalog['hamburg']->id]],
            $sales,
        );
        $calc->briefing = 'Bleibt';
        $calc->save();

        $absent = $writer->payloadFromCalculation($calc->fresh(['positions']));
        unset($absent['briefing']);
        $absent['lock_version'] = $calc->fresh()->lock_version;
        $writer->update($calc->fresh(['positions']), $absent, $sales);
        $this->assertSame('Bleibt', $calc->fresh()->briefing);

        $clear = $writer->payloadFromCalculation($calc->fresh(['positions']));
        $clear['briefing'] = null;
        $clear['lock_version'] = $calc->fresh()->lock_version;
        $writer->update($calc->fresh(['positions']), $clear, $sales);
        $this->assertNull($calc->fresh()->briefing);
    }

    #[Test]
    public function new_calc_to_dispo_create_keeps_operative_fields_empty(): void
    {
        $sales = User::factory()->role(Role::Sales)->create();
        $writer = app(CalculationWriter::class);
        $catalog = $this->createSpotClassicCatalog();

        $calc = $writer->create($this->withLiveSchemaFingerprint([
            'planning_mode' => 'manual',
            'customer_name' => 'PR4 Dispo Kunde',
            'customer_account_id' => null,
            'campaign' => 'PR4',
            'product_title' => 'Titel',
            'order_discount_percent' => '0',
            'ae_enabled' => false,
            'schema_fingerprint' => app(ConfigurationSnapshotFreezeService::class)
                ->resolveLiveSchemaForCalculationV3()['schema_fingerprint'],
            'positions' => [[
                'inventory_id' => $catalog['hamburg']->id,
                'advertising_medium_id' => $catalog['medium']->id,
                'schema_fingerprint' => app(ConfigurationSnapshotFreezeService::class)
                    ->resolveLivePositionSchema((int) $catalog['medium']->id)['schema_fingerprint'],
                'spot_method' => 'average',
                'length_seconds' => 30,
                'total_spot_count' => 10,
                'position_discount_percent' => '0',
                'ae_percent' => '15',
                'plan_rows' => [['hour' => 8, 'day_group' => 'mo_fr']],
            ]],
        ]), $sales);

        $this->assertNull($calc->invoice_recipient);
        $this->assertNull($calc->agency_name);

        $this->actingAs($sales)->post(route('dispo-orders.store', $calc), [
            'position_ids' => $calc->positions()->pluck('id')->all(),
        ])->assertRedirect();

        $order = DispoOrder::query()->latest('id')->firstOrFail();
        $this->assertSame('PR4 Dispo Kunde', $order->customer_name);
        $this->assertNull($order->agency_name);
        $this->assertNull($order->agency_account_id);
        $this->assertNull($order->invoice_recipient);
        $this->assertNull($order->briefing);
    }

    #[Test]
    public function historical_calc_to_dispo_create_copies_operative_values(): void
    {
        $sales = User::factory()->role(Role::Sales)->create();
        $catalog = $this->createSpotClassicCatalog();
        $calc = $this->createSavedCalculation(
            $catalog,
            [['inventory_id' => $catalog['hamburg']->id]],
            $sales,
            ['invoice_recipient' => 'agency'],
        );
        $calc->briefing = 'Hist Briefing';
        $calc->save();

        $this->actingAs($sales)->post(route('dispo-orders.store', $calc), [
            'position_ids' => $calc->positions()->pluck('id')->all(),
        ])->assertRedirect();

        $order = DispoOrder::query()->latest('id')->firstOrFail();
        $this->assertSame($calc->agency_name, $order->agency_name);
        $this->assertSame($calc->agency_account_id, $order->agency_account_id);
        $this->assertSame(InvoiceRecipient::Agency, $order->invoice_recipient);
        $this->assertSame('Hist Briefing', $order->briefing);
    }

    #[Test]
    public function repeated_save_without_operative_keys_does_not_wipe_historical_data(): void
    {
        $sales = User::factory()->role(Role::Sales)->create();
        $writer = app(CalculationWriter::class);
        $catalog = $this->createSpotClassicCatalog();
        $calc = $this->createSavedCalculation(
            $catalog,
            [['inventory_id' => $catalog['hamburg']->id]],
            $sales,
            ['invoice_recipient' => 'customer'],
        );
        $calc->briefing = 'Persistenz';
        $calc->save();

        for ($i = 0; $i < 3; $i++) {
            $payload = $writer->payloadFromCalculation($calc->fresh(['positions']));
            unset($payload['agency_name'], $payload['agency_account_id'], $payload['invoice_recipient'], $payload['briefing']);
            $payload['lock_version'] = $calc->fresh()->lock_version;
            $writer->update($calc->fresh(['positions']), $payload, $sales);
        }

        $fresh = $calc->fresh();
        $this->assertNotNull($fresh->agency_account_id);
        $this->assertSame(InvoiceRecipient::Customer, $fresh->invoice_recipient);
        $this->assertSame('Persistenz', $fresh->briefing);
    }
}
