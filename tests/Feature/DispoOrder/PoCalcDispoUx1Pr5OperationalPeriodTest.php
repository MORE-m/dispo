<?php

namespace Tests\Feature\DispoOrder;

use App\Enums\DispoOrderStatus;
use App\Enums\InvoiceRecipient;
use App\Enums\Role;
use App\Models\Calculation;
use App\Models\DispoOrder;
use App\Models\User;
use App\Services\Calculation\CalculationWriter;
use App\Services\DispoOrder\DispoOrderWriter;
use App\Services\DynamicField\CalculationDynamicFieldWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesSavedCalculation;
use Tests\Concerns\CreatesSpotClassicCatalog;
use Tests\Concerns\EnsuresCustomerConfirmationException;
use Tests\TestCase;

/**
 * PO-CALC-DISPO-UX-1 PR5: operativer Kampagnenzeitraum am Dispo-Kopf.
 */
class PoCalcDispoUx1Pr5OperationalPeriodTest extends TestCase
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

    /**
     * @param  array{start: string, end: string}  $period
     */
    private function withCalcOriginPeriod(
        Calculation $calculation,
        array $period,
        User $actor,
    ): Calculation {
        $writer = app(CalculationWriter::class);
        $payload = $writer->payloadFromCalculation($calculation->fresh(['positions']));
        $payload['dynamic_field_values']['campaign_period'] = $period;
        $payload['lock_version'] = $calculation->fresh()->lock_version;

        return $writer->update($calculation->fresh(['positions']), $payload, $actor)
            ->fresh(['positions', 'fieldValues.snapshotFieldDefinition']);
    }

    #[Test]
    public function new_calc_create_leaves_operational_period_empty(): void
    {
        ['order' => $order] = $this->draftOrder();

        $this->assertNull($order->operational_campaign_starts_on);
        $this->assertNull($order->operational_campaign_ends_on);
    }

    #[Test]
    public function calc_with_origin_period_still_creates_operational_period_null(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $creator = User::factory()->role(Role::Sales)->create();
        $calculation = $this->createSavedCalculation(
            $catalog,
            [['inventory_id' => $catalog['hamburg']->id]],
            $creator,
        );
        $calculation = $this->withCalcOriginPeriod($calculation, [
            'start' => '2026-02-01',
            'end' => '2026-02-28',
        ], $creator);

        $order = app(DispoOrderWriter::class)
            ->createFromCalculation(
                $calculation,
                $calculation->positions()->pluck('id')->all(),
                $creator,
            )
            ->order
            ->fresh();

        $this->assertNull($order->operational_campaign_starts_on);
        $this->assertNull($order->operational_campaign_ends_on);

        $header = $order->fieldValues()->with('snapshotFieldDefinition')->get()
            ->keyBy(fn ($row) => $row->snapshotFieldDefinition->key);
        $this->assertArrayHasKey('campaign_period', $header->all());
        $this->assertSame('2026-02-01', $header['campaign_period']->value_period_start?->toDateString());
        $this->assertSame('2026-02-28', $header['campaign_period']->value_period_end?->toDateString());
    }

    #[Test]
    public function header_update_sets_clears_and_preserves_period(): void
    {
        ['order' => $order, 'creator' => $creator] = $this->draftOrder();

        $this->actingAs($creator)->patch(route('dispo-orders.update-header', $order), [
            'lock_version' => $order->lock_version,
            'agency_name' => $order->agency_name,
            'agency_account_id' => $order->agency_account_id,
            'invoice_recipient' => 'customer',
            'briefing' => $order->briefing,
            'operational_campaign_starts_on' => '2026-05-01',
            'operational_campaign_ends_on' => '2026-05-10',
        ])->assertRedirect();

        $order->refresh();
        $this->assertSame('2026-05-01', $order->operational_campaign_starts_on?->toDateString());
        $this->assertSame('2026-05-10', $order->operational_campaign_ends_on?->toDateString());
        $lock = $order->lock_version;

        // Fehlende Keys → Preserve
        $this->actingAs($creator)->patch(route('dispo-orders.update-header', $order), [
            'lock_version' => $lock,
            'agency_name' => $order->agency_name,
            'agency_account_id' => $order->agency_account_id,
            'invoice_recipient' => 'customer',
            'briefing' => 'Nur Briefing',
        ])->assertRedirect();

        $order->refresh();
        $this->assertSame('2026-05-01', $order->operational_campaign_starts_on?->toDateString());
        $this->assertSame('2026-05-10', $order->operational_campaign_ends_on?->toDateString());
        $this->assertSame('Nur Briefing', $order->briefing);

        // Explizit null/null → Clear
        $this->actingAs($creator)->patch(route('dispo-orders.update-header', $order), [
            'lock_version' => $order->lock_version,
            'agency_name' => $order->agency_name,
            'agency_account_id' => $order->agency_account_id,
            'invoice_recipient' => 'customer',
            'briefing' => $order->briefing,
            'operational_campaign_starts_on' => null,
            'operational_campaign_ends_on' => null,
        ])->assertRedirect();

        $order->refresh();
        $this->assertNull($order->operational_campaign_starts_on);
        $this->assertNull($order->operational_campaign_ends_on);
    }

    #[Test]
    public function header_update_rejects_partial_inverted_and_invalid_dates(): void
    {
        ['order' => $order, 'creator' => $creator] = $this->draftOrder();
        $base = [
            'lock_version' => $order->lock_version,
            'agency_name' => $order->agency_name,
            'agency_account_id' => $order->agency_account_id,
            'invoice_recipient' => 'customer',
            'briefing' => null,
        ];

        $this->actingAs($creator)->patch(route('dispo-orders.update-header', $order), array_merge($base, [
            'operational_campaign_starts_on' => '2026-06-01',
        ]))->assertSessionHasErrors(['operational_campaign_starts_on', 'operational_campaign_ends_on']);

        $this->actingAs($creator)->patch(route('dispo-orders.update-header', $order), array_merge($base, [
            'operational_campaign_ends_on' => '2026-06-01',
        ]))->assertSessionHasErrors(['operational_campaign_starts_on', 'operational_campaign_ends_on']);

        $this->actingAs($creator)->patch(route('dispo-orders.update-header', $order), array_merge($base, [
            'operational_campaign_starts_on' => '2026-06-10',
            'operational_campaign_ends_on' => '2026-06-01',
        ]))->assertSessionHasErrors('operational_campaign_ends_on');

        $this->actingAs($creator)->patch(route('dispo-orders.update-header', $order), array_merge($base, [
            'operational_campaign_starts_on' => 'not-a-date',
            'operational_campaign_ends_on' => '2026-06-01',
        ]))->assertSessionHasErrors('operational_campaign_starts_on');

        $order->refresh();
        $this->assertNull($order->operational_campaign_starts_on);
        $this->assertNull($order->operational_campaign_ends_on);
    }

    #[Test]
    public function same_day_period_is_allowed(): void
    {
        ['order' => $order, 'creator' => $creator] = $this->draftOrder();

        $this->actingAs($creator)->patch(route('dispo-orders.update-header', $order), [
            'lock_version' => $order->lock_version,
            'agency_name' => $order->agency_name,
            'agency_account_id' => $order->agency_account_id,
            'invoice_recipient' => 'customer',
            'briefing' => null,
            'operational_campaign_starts_on' => '2026-07-15',
            'operational_campaign_ends_on' => '2026-07-15',
        ])->assertRedirect();

        $order->refresh();
        $this->assertSame('2026-07-15', $order->operational_campaign_starts_on?->toDateString());
        $this->assertSame('2026-07-15', $order->operational_campaign_ends_on?->toDateString());
    }

    #[Test]
    public function submit_without_operational_period_is_allowed_when_invoice_recipient_set(): void
    {
        ['order' => $order, 'creator' => $creator] = $this->draftOrder();
        $order = $this->seedCustomerConfirmationException($order, $creator);

        $this->actingAs($creator)->patch(route('dispo-orders.update-header', $order), [
            'lock_version' => $order->lock_version,
            'agency_name' => $order->agency_name,
            'agency_account_id' => $order->agency_account_id,
            'invoice_recipient' => 'customer',
            'briefing' => null,
        ])->assertRedirect();

        $order->refresh();
        $this->assertNull($order->operational_campaign_starts_on);

        $this->actingAs($creator)->postJson(route('dispo-orders.submit', $order), [
            'lock_version' => $order->lock_version,
        ])->assertOk();

        $order->refresh();
        $this->assertSame(DispoOrderStatus::AwaitingSalesApproval, $order->status);
    }

    #[Test]
    public function revision_inherits_operational_period_including_null(): void
    {
        ['order' => $order, 'creator' => $creator, 'calculation' => $calculation] = $this->draftOrder();

        $this->actingAs($creator)->patch(route('dispo-orders.update-header', $order), [
            'lock_version' => $order->lock_version,
            'agency_name' => $order->agency_name,
            'agency_account_id' => $order->agency_account_id,
            'invoice_recipient' => 'customer',
            'briefing' => 'Rev Briefing',
            'operational_campaign_starts_on' => '2026-08-01',
            'operational_campaign_ends_on' => '2026-08-31',
        ])->assertRedirect();
        $order->refresh();

        $order->status = DispoOrderStatus::ApprovalRejected;
        $order->save();

        $revisionA = app(DispoOrderWriter::class)->createRevision(
            $order->fresh(),
            $calculation->fresh(['positions']),
            $calculation->positions()->pluck('id')->all(),
            $creator,
        )->order->fresh();

        $this->assertSame('2026-08-01', $revisionA->operational_campaign_starts_on?->toDateString());
        $this->assertSame('2026-08-31', $revisionA->operational_campaign_ends_on?->toDateString());
        $this->assertSame('Rev Briefing', $revisionA->briefing);

        $this->actingAs($creator)->patch(route('dispo-orders.update-header', $revisionA), [
            'lock_version' => $revisionA->lock_version,
            'agency_name' => $revisionA->agency_name,
            'agency_account_id' => $revisionA->agency_account_id,
            'invoice_recipient' => 'customer',
            'briefing' => $revisionA->briefing,
            'operational_campaign_starts_on' => '2026-09-01',
            'operational_campaign_ends_on' => '2026-09-15',
        ])->assertRedirect();
        $revisionA->refresh();
        $revisionA->status = DispoOrderStatus::ApprovalRejected;
        $revisionA->save();

        $revisionB = app(DispoOrderWriter::class)->createRevision(
            $revisionA->fresh(),
            $calculation->fresh(['positions']),
            $calculation->positions()->pluck('id')->all(),
            $creator,
        )->order->fresh();

        $this->assertSame('2026-09-01', $revisionB->operational_campaign_starts_on?->toDateString());
        $this->assertSame('2026-09-15', $revisionB->operational_campaign_ends_on?->toDateString());
        $this->assertSame('2026-08-01', $order->fresh()->operational_campaign_starts_on?->toDateString());
    }

    #[Test]
    public function revision_inherits_null_operational_period(): void
    {
        ['order' => $order, 'creator' => $creator, 'calculation' => $calculation] = $this->draftOrder();
        $this->assertNull($order->operational_campaign_starts_on);

        $order->status = DispoOrderStatus::ApprovalRejected;
        $order->invoice_recipient = InvoiceRecipient::Customer;
        $order->save();

        $revision = app(DispoOrderWriter::class)->createRevision(
            $order->fresh(),
            $calculation->fresh(['positions']),
            $calculation->positions()->pluck('id')->all(),
            $creator,
        )->order->fresh();

        $this->assertNull($revision->operational_campaign_starts_on);
        $this->assertNull($revision->operational_campaign_ends_on);
    }

    #[Test]
    public function show_serializes_operational_period(): void
    {
        ['order' => $order, 'creator' => $creator] = $this->draftOrder();

        $this->actingAs($creator)->patch(route('dispo-orders.update-header', $order), [
            'lock_version' => $order->lock_version,
            'agency_name' => $order->agency_name,
            'agency_account_id' => $order->agency_account_id,
            'invoice_recipient' => 'customer',
            'briefing' => null,
            'operational_campaign_starts_on' => '2026-10-01',
            'operational_campaign_ends_on' => '2026-10-31',
        ])->assertRedirect();

        $this->actingAs($creator)
            ->get(route('dispo-orders.show', $order->fresh()))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('dispo-orders/show')
                ->where('order.operational_campaign_starts_on', '2026-10-01')
                ->where('order.operational_campaign_ends_on', '2026-10-31'));
    }

    #[Test]
    public function calc_update_without_campaign_period_key_preserves_historical_value(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $creator = User::factory()->role(Role::Sales)->create();
        $calculation = $this->createSavedCalculation(
            $catalog,
            [['inventory_id' => $catalog['hamburg']->id]],
            $creator,
        );
        $calculation = $this->withCalcOriginPeriod($calculation, [
            'start' => '2026-01-05',
            'end' => '2026-01-20',
        ], $creator);

        $writer = app(CalculationWriter::class);
        $payload = $writer->payloadFromCalculation($calculation->fresh(['positions']));
        unset($payload['dynamic_field_values']['campaign_period']);
        $payload['campaign'] = 'Geändert ohne Period-Key';
        $payload['lock_version'] = $calculation->fresh()->lock_version;
        $updated = $writer->update($calculation->fresh(['positions']), $payload, $creator);

        $values = app(CalculationDynamicFieldWriter::class)
            ->headerValuesForPayload($updated->fresh(['positions', 'fieldValues']));
        $this->assertSame('2026-01-05', $values['campaign_period']['start'] ?? null);
        $this->assertSame('2026-01-20', $values['campaign_period']['end'] ?? null);
    }

    #[Test]
    public function operational_period_is_not_synced_from_calc_origin_after_create(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $creator = User::factory()->role(Role::Sales)->create();
        $calculation = $this->createSavedCalculation(
            $catalog,
            [['inventory_id' => $catalog['hamburg']->id]],
            $creator,
        );
        $calculation = $this->withCalcOriginPeriod($calculation, [
            'start' => '2026-03-01',
            'end' => '2026-03-31',
        ], $creator);

        $order = app(DispoOrderWriter::class)
            ->createFromCalculation(
                $calculation,
                $calculation->positions()->pluck('id')->all(),
                $creator,
            )
            ->order
            ->fresh();

        $this->assertNull($order->operational_campaign_starts_on);

        $this->actingAs($creator)->patch(route('dispo-orders.update-header', $order), [
            'lock_version' => $order->lock_version,
            'agency_name' => $order->agency_name,
            'agency_account_id' => $order->agency_account_id,
            'invoice_recipient' => 'customer',
            'briefing' => null,
            'operational_campaign_starts_on' => '2026-04-01',
            'operational_campaign_ends_on' => '2026-04-15',
        ])->assertRedirect();

        // Calc-Origin erneut ändern – operativer Dispo-Zeitraum bleibt unberührt.
        $calculation = $this->withCalcOriginPeriod($calculation->fresh(['positions']), [
            'start' => '2026-12-01',
            'end' => '2026-12-31',
        ], $creator);

        $order->refresh();
        $this->assertSame('2026-04-01', $order->operational_campaign_starts_on?->toDateString());
        $this->assertSame('2026-04-15', $order->operational_campaign_ends_on?->toDateString());

        $calcValues = app(CalculationDynamicFieldWriter::class)
            ->headerValuesForPayload($calculation->fresh(['positions', 'fieldValues']));
        $this->assertSame('2026-12-01', $calcValues['campaign_period']['start'] ?? null);
        $this->assertSame('2026-12-31', $calcValues['campaign_period']['end'] ?? null);
    }
}
