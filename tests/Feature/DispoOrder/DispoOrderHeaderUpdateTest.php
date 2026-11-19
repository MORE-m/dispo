<?php

namespace Tests\Feature\DispoOrder;

use App\Enums\DispoOrderStatus;
use App\Enums\InvoiceRecipient;
use App\Enums\Role;
use App\Models\Calculation;
use App\Models\CrmAccount;
use App\Models\DispoOrder;
use App\Models\User;
use App\Services\DispoOrder\DispoOrderWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesSavedCalculation;
use Tests\Concerns\CreatesSpotClassicCatalog;
use Tests\Concerns\EnsuresCustomerConfirmationException;
use Tests\TestCase;

class DispoOrderHeaderUpdateTest extends TestCase
{
    use CreatesSavedCalculation;
    use CreatesSpotClassicCatalog;
    use EnsuresCustomerConfirmationException;
    use RefreshDatabase;

    /**
     * @return array{calculation: Calculation, order: DispoOrder, creator: User}
     */
    private function draftOrder(?User $creator = null): array
    {
        $catalog = $this->createSpotClassicCatalog();
        $creator ??= User::factory()->role(Role::Sales)->create();
        $calculation = $this->createSavedCalculation(
            $catalog,
            [['inventory_id' => $catalog['hamburg']->id]],
            $creator,
        );

        $this->actingAs($creator)->post(route('dispo-orders.store', $calculation), [
            'position_ids' => $calculation->positions()->pluck('id')->all(),
        ])->assertRedirect();

        return [
            'calculation' => $calculation->fresh(),
            'order' => DispoOrder::query()->latest('id')->firstOrFail(),
            'creator' => $creator,
        ];
    }

    public function test_sales_can_set_agency_invoice_recipient_and_briefing(): void
    {
        ['order' => $order, 'creator' => $creator] = $this->draftOrder();
        $agency = CrmAccount::query()->where('type', 'agency')->firstOrFail();

        $this->actingAs($creator)->patch(route('dispo-orders.update-header', $order), [
            'lock_version' => $order->lock_version,
            'agency_name' => $agency->currentVersion?->name ?? 'Agentur',
            'agency_account_id' => $agency->id,
            'invoice_recipient' => 'agency',
            'briefing' => 'Operatives Briefing',
        ])->assertRedirect(route('dispo-orders.show', $order));

        $order->refresh();
        $this->assertSame($agency->id, $order->agency_account_id);
        $this->assertSame(InvoiceRecipient::Agency, $order->invoice_recipient);
        $this->assertSame('Operatives Briefing', $order->briefing);
        $this->assertSame(2, $order->lock_version);
    }

    public function test_draft_allows_null_invoice_recipient(): void
    {
        ['order' => $order, 'creator' => $creator] = $this->draftOrder();

        $this->actingAs($creator)->patch(route('dispo-orders.update-header', $order), [
            'lock_version' => $order->lock_version,
            'agency_name' => $order->agency_name,
            'agency_account_id' => $order->agency_account_id,
            'invoice_recipient' => null,
            'briefing' => $order->briefing,
        ])->assertRedirect();

        $order->refresh();
        $this->assertNull($order->invoice_recipient);
    }

    public function test_sales_can_create_provisional_agency_via_header(): void
    {
        ['order' => $order, 'creator' => $creator] = $this->draftOrder();
        $before = CrmAccount::query()->count();
        $name = 'PR3 Provisional Agentur '.uniqid('', true);

        $this->actingAs($creator)->patch(route('dispo-orders.update-header', $order), [
            'lock_version' => $order->lock_version,
            'agency_name' => $name,
            'agency_account_id' => null,
            'invoice_recipient' => 'agency',
            'briefing' => null,
        ])->assertRedirect();

        $order->refresh();
        $this->assertSame($before + 1, CrmAccount::query()->count());
        $this->assertNotNull($order->agency_account_id);
        $account = CrmAccount::query()->findOrFail($order->agency_account_id);
        $this->assertTrue($account->is_provisional);
        $this->assertSame($name, $order->agency_name);
        $this->assertSame(InvoiceRecipient::Agency, $order->invoice_recipient);

        // Wiederholtes Speichern darf keinen zweiten Provisional anlegen.
        $this->actingAs($creator)->patch(route('dispo-orders.update-header', $order), [
            'lock_version' => $order->lock_version,
            'agency_name' => $name,
            'agency_account_id' => $order->agency_account_id,
            'invoice_recipient' => 'agency',
            'briefing' => null,
        ])->assertRedirect();
        $this->assertSame($before + 1, CrmAccount::query()->count());
    }

    public function test_clearing_agency_with_agency_recipient_sets_recipient_null(): void
    {
        ['order' => $order, 'creator' => $creator] = $this->draftOrder();
        $agency = CrmAccount::query()->where('type', 'agency')->firstOrFail();

        $this->actingAs($creator)->patch(route('dispo-orders.update-header', $order), [
            'lock_version' => $order->lock_version,
            'agency_name' => $agency->currentVersion?->name ?? 'Agentur',
            'agency_account_id' => $agency->id,
            'invoice_recipient' => 'agency',
            'briefing' => null,
        ])->assertRedirect();

        $order->refresh();
        $this->actingAs($creator)->patch(route('dispo-orders.update-header', $order), [
            'lock_version' => $order->lock_version,
            'agency_name' => null,
            'agency_account_id' => null,
            'invoice_recipient' => null,
            'briefing' => null,
        ])->assertRedirect();

        $order->refresh();
        $this->assertNull($order->agency_account_id);
        $this->assertNull($order->agency_name);
        $this->assertNull($order->agency_version_id);
        $this->assertNull($order->agency_meridian_number);
        $this->assertNull($order->agency_salesforce_account_id);
        $this->assertNull($order->invoice_recipient);
        $this->assertNotNull($order->customer_account_id);
    }

    public function test_clearing_agency_keeps_customer_invoice_recipient(): void
    {
        ['order' => $order, 'creator' => $creator] = $this->draftOrder();
        $this->assertSame(InvoiceRecipient::Customer, $order->invoice_recipient);

        $this->actingAs($creator)->patch(route('dispo-orders.update-header', $order), [
            'lock_version' => $order->lock_version,
            'agency_name' => null,
            'agency_account_id' => null,
            'invoice_recipient' => 'customer',
            'briefing' => null,
        ])->assertRedirect();

        $order->refresh();
        $this->assertNull($order->agency_account_id);
        $this->assertSame(InvoiceRecipient::Customer, $order->invoice_recipient);
    }

    public function test_agency_recipient_without_agency_is_rejected(): void
    {
        ['order' => $order, 'creator' => $creator] = $this->draftOrder();

        $this->actingAs($creator)->patch(route('dispo-orders.update-header', $order), [
            'lock_version' => $order->lock_version,
            'agency_name' => null,
            'agency_account_id' => null,
            'invoice_recipient' => 'agency',
            'briefing' => null,
        ])->assertSessionHasErrors('invoice_recipient');
    }

    public function test_customer_cannot_be_changed_via_header_update(): void
    {
        ['order' => $order, 'creator' => $creator] = $this->draftOrder();
        $originalCustomer = $order->customer_name;

        $this->actingAs($creator)->from(route('dispo-orders.show', $order))
            ->patch(route('dispo-orders.update-header', $order), [
                'lock_version' => $order->lock_version,
                'customer_name' => 'Hacker GmbH',
                'customer_account_id' => null,
                'agency_name' => $order->agency_name,
                'agency_account_id' => $order->agency_account_id,
                'invoice_recipient' => 'customer',
                'briefing' => null,
            ])->assertSessionHasErrors('customer_name');

        $order->refresh();
        $this->assertSame($originalCustomer, $order->customer_name);
    }

    public function test_disposition_cannot_update_header(): void
    {
        ['order' => $order] = $this->draftOrder();
        $disposition = User::factory()->role(Role::Disposition)->create();

        $this->actingAs($disposition)->patch(route('dispo-orders.update-header', $order), [
            'lock_version' => $order->lock_version,
            'agency_name' => null,
            'agency_account_id' => null,
            'invoice_recipient' => null,
            'briefing' => 'x',
        ])->assertForbidden();
    }

    public function test_non_draft_status_rejects_header_update(): void
    {
        ['order' => $order, 'creator' => $creator] = $this->draftOrder();
        $order = $this->seedCustomerConfirmationException($order, $creator);

        $this->actingAs($creator)->postJson(route('dispo-orders.submit', $order), [
            'lock_version' => $order->lock_version,
        ])->assertOk();

        $order->refresh();
        $this->assertSame(DispoOrderStatus::AwaitingSalesApproval, $order->status);

        $this->actingAs($creator)->patch(route('dispo-orders.update-header', $order), [
            'lock_version' => $order->lock_version,
            'agency_name' => $order->agency_name,
            'agency_account_id' => $order->agency_account_id,
            'invoice_recipient' => 'customer',
            'briefing' => 'blocked',
        ])->assertForbidden();
    }

    public function test_approval_rejected_rejects_in_place_header_update(): void
    {
        ['order' => $order, 'creator' => $creator] = $this->draftOrder();
        $order->status = DispoOrderStatus::ApprovalRejected;
        $order->save();

        $this->actingAs($creator)->patch(route('dispo-orders.update-header', $order), [
            'lock_version' => $order->lock_version,
            'agency_name' => $order->agency_name,
            'agency_account_id' => $order->agency_account_id,
            'invoice_recipient' => 'customer',
            'briefing' => 'blocked',
        ])->assertForbidden();
    }

    public function test_stale_lock_version_conflicts(): void
    {
        ['order' => $order, 'creator' => $creator] = $this->draftOrder();

        $this->actingAs($creator)->patch(route('dispo-orders.update-header', $order), [
            'lock_version' => $order->lock_version + 5,
            'agency_name' => $order->agency_name,
            'agency_account_id' => $order->agency_account_id,
            'invoice_recipient' => 'customer',
            'briefing' => 'x',
        ])->assertStatus(409);
    }

    public function test_submit_requires_invoice_recipient(): void
    {
        ['order' => $order, 'creator' => $creator] = $this->draftOrder();
        $order = $this->seedCustomerConfirmationException($order, $creator);

        $this->actingAs($creator)->patch(route('dispo-orders.update-header', $order), [
            'lock_version' => $order->lock_version,
            'agency_name' => $order->agency_name,
            'agency_account_id' => $order->agency_account_id,
            'invoice_recipient' => null,
            'briefing' => null,
        ])->assertRedirect();

        $order->refresh();
        $this->actingAs($creator)->postJson(route('dispo-orders.submit', $order), [
            'lock_version' => $order->lock_version,
        ])->assertStatus(422)->assertJsonValidationErrors(['invoice_recipient']);

        $order->refresh();
        $this->assertSame(DispoOrderStatus::Draft, $order->status);
    }

    public function test_revision_inherits_operative_header_from_predecessor(): void
    {
        ['order' => $order, 'creator' => $creator, 'calculation' => $calculation] = $this->draftOrder();
        $agency = CrmAccount::query()->where('type', 'agency')->firstOrFail();

        $this->actingAs($creator)->patch(route('dispo-orders.update-header', $order), [
            'lock_version' => $order->lock_version,
            'agency_name' => $agency->currentVersion?->name ?? 'Agentur',
            'agency_account_id' => $agency->id,
            'invoice_recipient' => 'customer',
            'briefing' => 'Dispo-Briefing nach Edit',
        ])->assertRedirect();
        $order->refresh();

        // Calc ändern: andere Agentur/Briefing – Revision darf diese nicht übernehmen.
        $calculation->agency_name = 'Calc-Agentur-alt';
        $calculation->briefing = 'Calc-Briefing';
        $calculation->invoice_recipient = InvoiceRecipient::Customer;
        $calculation->save();

        $order->status = DispoOrderStatus::ApprovalRejected;
        $order->save();

        $result = app(DispoOrderWriter::class)->createRevision(
            $order->fresh(),
            $calculation->fresh(['positions']),
            $calculation->positions()->pluck('id')->all(),
            $creator,
        );
        $revision = $result->order;

        $this->assertSame($agency->id, $revision->agency_account_id);
        $this->assertSame('Dispo-Briefing nach Edit', $revision->briefing);
        $this->assertSame(InvoiceRecipient::Customer, $revision->invoice_recipient);
        $this->assertNotSame('Calc-Briefing', $revision->briefing);
        $this->assertNotSame('Calc-Agentur-alt', $revision->agency_name);
    }

    public function test_revision_preserves_null_invoice_recipient_from_predecessor(): void
    {
        ['order' => $order, 'creator' => $creator, 'calculation' => $calculation] = $this->draftOrder();

        $this->actingAs($creator)->patch(route('dispo-orders.update-header', $order), [
            'lock_version' => $order->lock_version,
            'agency_name' => $order->agency_name,
            'agency_account_id' => $order->agency_account_id,
            'invoice_recipient' => null,
            'briefing' => 'x',
        ])->assertRedirect();
        $order->refresh();

        $order->status = DispoOrderStatus::ApprovalRejected;
        $order->save();

        $result = app(DispoOrderWriter::class)->createRevision(
            $order->fresh(),
            $calculation->fresh(['positions']),
            $calculation->positions()->pluck('id')->all(),
            $creator,
        );

        $this->assertNull($result->order->invoice_recipient);
        $this->assertSame('x', $result->order->briefing);
    }
}
