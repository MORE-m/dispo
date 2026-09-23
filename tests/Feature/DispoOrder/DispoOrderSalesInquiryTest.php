<?php

namespace Tests\Feature\DispoOrder;

use App\Enums\DispoOrderCommentType;
use App\Enums\DispoOrderStatus;
use App\Enums\Role;
use App\Models\DispoOrder;
use App\Models\DispoOrderComment;
use App\Models\User;
use App\Services\DispoOrder\DispoOrderApprovalService;
use App\Services\DispoOrder\DispoOrderOperationalStatusService;
use App\Services\DispoOrder\DispoOrderSalesInquiryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use Tests\Concerns\CreatesSavedCalculation;
use Tests\Concerns\CreatesSpotClassicCatalog;
use Tests\TestCase;

class DispoOrderSalesInquiryTest extends TestCase
{
    use CreatesSavedCalculation;
    use CreatesSpotClassicCatalog;
    use RefreshDatabase;

    /**
     * @return array{order: DispoOrder, creator: User, disposition: User, sales: User}
     */
    private function orderInProgress(): array
    {
        $catalog = $this->createSpotClassicCatalog();
        $creator = User::factory()->role(Role::Sales)->create();
        $approver = User::factory()->role(Role::Sales)->create();
        $disposition = User::factory()->role(Role::Disposition)->create();

        $calculation = $this->createSavedCalculation($catalog, [
            ['inventory_id' => $catalog['hamburg']->id],
        ], $creator);

        $this->actingAs($creator)->post(route('dispo-orders.store', $calculation), [
            'position_ids' => [$calculation->positions()->first()->id],
        ])->assertRedirect();

        $order = DispoOrder::query()->latest('id')->firstOrFail();
        $approvals = app(DispoOrderApprovalService::class);
        $submitted = $approvals->submit($order, $creator, $order->lock_version);
        $approved = $approvals->approve($submitted, $approver, $submitted->lock_version);
        $inProgress = app(DispoOrderOperationalStatusService::class)->transition(
            $approved,
            $disposition,
            $approved->lock_version,
            DispoOrderStatus::InProgress,
        );

        return [
            'order' => $inProgress,
            'creator' => $creator,
            'disposition' => $disposition,
            'sales' => $creator,
        ];
    }

    public function test_disposition_admin_management_can_ask_sales_and_pm_cannot(): void
    {
        ['order' => $order, 'disposition' => $disposition] = $this->orderInProgress();

        $this->actingAs($disposition)->postJson(route('dispo-orders.sales-inquiry.ask', $order), [
            'lock_version' => $order->lock_version,
            'question' => 'Bitte Spotzeiten bestätigen.',
        ])->assertOk();

        $order->refresh();
        $this->assertSame(DispoOrderStatus::SalesInquiry, $order->status);

        $answered = app(DispoOrderSalesInquiryService::class)->answer(
            $order,
            DispoOrderComment::query()->where('dispo_order_id', $order->id)->firstOrFail(),
            User::factory()->role(Role::Sales)->create(),
            $order->lock_version,
            'Bestätigt.',
        );

        $admin = User::factory()->role(Role::Admin)->create();
        $this->actingAs($admin)->postJson(route('dispo-orders.sales-inquiry.ask', $answered), [
            'lock_version' => $answered->lock_version,
            'question' => 'Zweite Rückfrage vom Admin.',
        ])->assertOk();

        $answered->refresh();
        $mgmtAnswer = app(DispoOrderSalesInquiryService::class)->answer(
            $answered,
            DispoOrderComment::query()
                ->where('dispo_order_id', $answered->id)
                ->where('type', DispoOrderCommentType::SalesInquiry)
                ->whereDoesntHave('response')
                ->firstOrFail(),
            User::factory()->role(Role::Sales)->create(),
            $answered->lock_version,
            'Antwort GF-Vorbereitung.',
        );

        $management = User::factory()->role(Role::Management)->create();
        $this->actingAs($management)->postJson(route('dispo-orders.sales-inquiry.ask', $mgmtAnswer), [
            'lock_version' => $mgmtAnswer->lock_version,
            'question' => 'Dritte Rückfrage GF.',
        ])->assertOk();

        $mgmtAnswer->refresh();
        $this->actingAs(User::factory()->role(Role::Sales)->create())
            ->postJson(route('dispo-orders.sales-inquiry.ask', $mgmtAnswer), [
                'lock_version' => $mgmtAnswer->lock_version,
                'question' => 'Darf nicht.',
            ])->assertForbidden();

        $this->actingAs(User::factory()->role(Role::ProductManagement)->create())
            ->postJson(route('dispo-orders.sales-inquiry.ask', $mgmtAnswer), [
                'lock_version' => $mgmtAnswer->lock_version,
                'question' => 'Darf nicht.',
            ])->assertForbidden();
    }

    public function test_ask_validates_question_and_stale_lock_and_forbidden_status(): void
    {
        ['order' => $order, 'disposition' => $disposition] = $this->orderInProgress();

        $this->actingAs($disposition)->postJson(route('dispo-orders.sales-inquiry.ask', $order), [
            'lock_version' => $order->lock_version,
            'question' => '',
        ])->assertStatus(422);

        $this->actingAs($disposition)->postJson(route('dispo-orders.sales-inquiry.ask', $order), [
            'lock_version' => $order->lock_version,
            'question' => '   ',
        ])->assertStatus(422);

        $this->actingAs($disposition)->postJson(route('dispo-orders.sales-inquiry.ask', $order), [
            'lock_version' => $order->lock_version - 1,
            'question' => 'Stale.',
        ])->assertStatus(409);

        $disposed = app(DispoOrderOperationalStatusService::class)->transition(
            $order,
            $disposition,
            $order->lock_version,
            DispoOrderStatus::Disposed,
        );

        $this->actingAs($disposition)->postJson(route('dispo-orders.sales-inquiry.ask', $disposed), [
            'lock_version' => $disposed->lock_version,
            'question' => 'Aus Disponiert nicht erlaubt.',
        ])->assertStatus(409);
    }

    public function test_ask_persists_comment_status_event_audit_atomically(): void
    {
        ['order' => $order, 'disposition' => $disposition] = $this->orderInProgress();
        $before = $order->lock_version;

        $this->actingAs($disposition)->postJson(route('dispo-orders.sales-inquiry.ask', $order), [
            'lock_version' => $order->lock_version,
            'question' => 'Bitte bestätige die finalen Spotzeiten.',
        ])->assertOk();

        $order->refresh();
        $this->assertSame(DispoOrderStatus::SalesInquiry, $order->status);
        $this->assertSame($before + 1, $order->lock_version);

        $this->assertDatabaseHas('dispo_order_comments', [
            'dispo_order_id' => $order->id,
            'type' => DispoOrderCommentType::SalesInquiry->value,
            'body' => 'Bitte bestätige die finalen Spotzeiten.',
            'created_by_id' => $disposition->id,
            'parent_id' => null,
        ]);
        $this->assertDatabaseHas('dispo_order_status_events', [
            'dispo_order_id' => $order->id,
            'from_status' => DispoOrderStatus::InProgress->value,
            'to_status' => DispoOrderStatus::SalesInquiry->value,
            'changed_by_id' => $disposition->id,
            'lock_version_after' => $order->lock_version,
        ]);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'dispo_order.sales_inquiry.created',
            'auditable_type' => DispoOrder::class,
            'auditable_id' => $order->id,
        ]);
    }

    public function test_sales_admin_management_can_answer_disposition_and_pm_cannot(): void
    {
        ['order' => $order, 'disposition' => $disposition] = $this->orderInProgress();
        $asked = app(DispoOrderSalesInquiryService::class)->ask(
            $order,
            $disposition,
            $order->lock_version,
            'Frage an Vertrieb.',
        );
        $inquiry = DispoOrderComment::query()
            ->where('dispo_order_id', $asked->id)
            ->where('type', DispoOrderCommentType::SalesInquiry)
            ->firstOrFail();

        $sales = User::factory()->role(Role::Sales)->create();
        $this->actingAs($sales)->postJson(
            route('dispo-orders.sales-inquiry.answer', [$asked, $inquiry]),
            [
                'lock_version' => $asked->lock_version,
                'answer' => 'Finale Spotzeiten sind vom Kunden bestätigt.',
            ],
        )->assertOk();

        $asked->refresh();
        $this->assertSame(DispoOrderStatus::AtDisposition, $asked->status);

        $askedAgain = app(DispoOrderSalesInquiryService::class)->ask(
            $asked,
            $disposition,
            $asked->lock_version,
            'Noch eine Frage.',
        );
        $inquiry2 = DispoOrderComment::query()
            ->where('dispo_order_id', $askedAgain->id)
            ->whereDoesntHave('response')
            ->firstOrFail();

        $this->actingAs(User::factory()->role(Role::Admin)->create())->postJson(
            route('dispo-orders.sales-inquiry.answer', [$askedAgain, $inquiry2]),
            [
                'lock_version' => $askedAgain->lock_version,
                'answer' => 'Admin-Antwort.',
            ],
        )->assertOk();

        $askedAgain->refresh();
        $asked3 = app(DispoOrderSalesInquiryService::class)->ask(
            $askedAgain,
            $disposition,
            $askedAgain->lock_version,
            'GF-Frage.',
        );
        $inquiry3 = DispoOrderComment::query()
            ->where('dispo_order_id', $asked3->id)
            ->whereDoesntHave('response')
            ->firstOrFail();

        $this->actingAs(User::factory()->role(Role::Management)->create())->postJson(
            route('dispo-orders.sales-inquiry.answer', [$asked3, $inquiry3]),
            [
                'lock_version' => $asked3->lock_version,
                'answer' => 'GF-Antwort.',
            ],
        )->assertOk();

        $asked3->refresh();
        $asked4 = app(DispoOrderSalesInquiryService::class)->ask(
            $asked3,
            $disposition,
            $asked3->lock_version,
            'Negativtest.',
        );
        $inquiry4 = DispoOrderComment::query()
            ->where('dispo_order_id', $asked4->id)
            ->whereDoesntHave('response')
            ->firstOrFail();

        $this->actingAs($disposition)->postJson(
            route('dispo-orders.sales-inquiry.answer', [$asked4, $inquiry4]),
            [
                'lock_version' => $asked4->lock_version,
                'answer' => 'Darf nicht.',
            ],
        )->assertForbidden();

        $this->actingAs(User::factory()->role(Role::ProductManagement)->create())->postJson(
            route('dispo-orders.sales-inquiry.answer', [$asked4, $inquiry4]),
            [
                'lock_version' => $asked4->lock_version,
                'answer' => 'Darf nicht.',
            ],
        )->assertForbidden();
    }

    public function test_answer_validation_open_inquiry_and_append_only(): void
    {
        ['order' => $order, 'disposition' => $disposition] = $this->orderInProgress();
        $asked = app(DispoOrderSalesInquiryService::class)->ask(
            $order,
            $disposition,
            $order->lock_version,
            'Offene Frage.',
        );
        $inquiry = DispoOrderComment::query()
            ->where('dispo_order_id', $asked->id)
            ->firstOrFail();
        $sales = User::factory()->role(Role::Sales)->create();
        $before = $asked->lock_version;

        $this->actingAs($sales)->postJson(
            route('dispo-orders.sales-inquiry.answer', [$asked, $inquiry]),
            ['lock_version' => $asked->lock_version, 'answer' => ''],
        )->assertStatus(422);

        $this->actingAs($sales)->postJson(
            route('dispo-orders.sales-inquiry.answer', [$asked, $inquiry]),
            ['lock_version' => $asked->lock_version, 'answer' => '   '],
        )->assertStatus(422);

        $this->actingAs($sales)->postJson(
            route('dispo-orders.sales-inquiry.answer', [$asked, $inquiry]),
            ['lock_version' => $asked->lock_version - 1, 'answer' => 'Stale.'],
        )->assertStatus(409);

        $this->actingAs($sales)->postJson(
            route('dispo-orders.sales-inquiry.answer', [$asked, $inquiry]),
            [
                'lock_version' => $asked->lock_version,
                'answer' => 'Finale Spotzeiten sind vom Kunden bestätigt.',
            ],
        )->assertOk();

        $asked->refresh();
        $this->assertSame(DispoOrderStatus::AtDisposition, $asked->status);
        $this->assertSame($before + 1, $asked->lock_version);

        $response = DispoOrderComment::query()
            ->where('parent_id', $inquiry->id)
            ->firstOrFail();
        $this->assertSame(DispoOrderCommentType::SalesInquiryResponse, $response->type);
        $this->assertSame($inquiry->id, $response->parent_id);

        $this->assertDatabaseHas('dispo_order_status_events', [
            'dispo_order_id' => $asked->id,
            'from_status' => DispoOrderStatus::SalesInquiry->value,
            'to_status' => DispoOrderStatus::AtDisposition->value,
        ]);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'dispo_order.sales_inquiry.answered',
            'auditable_id' => $asked->id,
        ]);

        $this->expectException(LogicException::class);
        $inquiry->body = 'geändert';
        $inquiry->save();
    }

    public function test_comments_cannot_be_deleted(): void
    {
        ['order' => $order, 'disposition' => $disposition] = $this->orderInProgress();
        $asked = app(DispoOrderSalesInquiryService::class)->ask(
            $order,
            $disposition,
            $order->lock_version,
            'Löschtest.',
        );
        $inquiry = DispoOrderComment::query()
            ->where('dispo_order_id', $asked->id)
            ->firstOrFail();

        $this->expectException(LogicException::class);
        $inquiry->delete();
    }

    public function test_show_exposes_capabilities_and_communication(): void
    {
        ['order' => $order, 'disposition' => $disposition, 'sales' => $sales] = $this->orderInProgress();

        $this->actingAs($disposition)->get(route('dispo-orders.show', $order))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('canAskSalesInquiry', true)
                ->where('canAnswerSalesInquiry', false)
                ->where('canTransitionOperationalStatus', true));

        $asked = app(DispoOrderSalesInquiryService::class)->ask(
            $order,
            $disposition,
            $order->lock_version,
            'Sichtbare Frage.',
        );

        $this->actingAs($disposition)->get(route('dispo-orders.show', $asked))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('canAskSalesInquiry', false)
                ->where('canAnswerSalesInquiry', false)
                ->where('order.status', DispoOrderStatus::SalesInquiry->value)
                ->where('operationalStatusTargets', [])
                ->has('order.communication', 1)
                ->has('openSalesInquiry'));

        $this->actingAs($sales)->get(route('dispo-orders.show', $asked))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('canAskSalesInquiry', false)
                ->where('canAnswerSalesInquiry', true));
    }

    public function test_answer_without_open_inquiry_conflicts(): void
    {
        ['order' => $order, 'disposition' => $disposition] = $this->orderInProgress();
        $asked = app(DispoOrderSalesInquiryService::class)->ask(
            $order,
            $disposition,
            $order->lock_version,
            'Einmalig.',
        );
        $inquiry = DispoOrderComment::query()
            ->where('dispo_order_id', $asked->id)
            ->firstOrFail();
        $sales = User::factory()->role(Role::Sales)->create();

        app(DispoOrderSalesInquiryService::class)->answer(
            $asked,
            $inquiry,
            $sales,
            $asked->lock_version,
            'Erste Antwort.',
        );

        $asked->refresh();
        $this->actingAs($sales)->postJson(
            route('dispo-orders.sales-inquiry.answer', [$asked, $inquiry]),
            [
                'lock_version' => $asked->lock_version,
                'answer' => 'Zweite Antwort.',
            ],
        )->assertStatus(409);
    }
}
