<?php

namespace App\Services\DispoOrder;

use App\Enums\DispoOrderCommentType;
use App\Enums\DispoOrderStatus;
use App\Exceptions\DispoOrderConflictException;
use App\Models\DispoOrder;
use App\Models\DispoOrderComment;
use App\Models\DispoOrderStatusEvent;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Strukturierter Rückfrage-/Antwortprozess (BL-P8-02b / PO-BLP802B-1).
 * Getrennt vom operativen Statuskern und vom Freigabe-Service.
 */
final class DispoOrderSalesInquiryService
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    public function ask(
        DispoOrder $order,
        User $user,
        int $expectedLockVersion,
        string $question,
    ): DispoOrder {
        $trimmed = trim($question);
        if ($trimmed === '') {
            throw ValidationException::withMessages([
                'question' => 'Eine Rückfrage ist erforderlich.',
            ]);
        }

        if (mb_strlen($trimmed) > 2000) {
            throw ValidationException::withMessages([
                'question' => 'Die Rückfrage darf maximal 2000 Zeichen haben.',
            ]);
        }

        return DB::transaction(function () use ($order, $user, $expectedLockVersion, $trimmed): DispoOrder {
            $locked = $this->lockOrder($order);
            $this->assertLockVersion($locked, $expectedLockVersion);

            $from = $locked->status;
            $to = DispoOrderStatus::SalesInquiry;

            if (! DispoOrderStatusTransition::isSalesInquiryAsk($from, $to)) {
                DispoOrderStatusTransition::assertCanTransition($from, $to);
                throw new DispoOrderConflictException(
                    sprintf(
                        'Aus dem Status „%s“ kann keine Rückfrage gestellt werden.',
                        $from->label(),
                    ),
                );
            }

            DispoOrderStatusTransition::assertCanTransition($from, $to);

            if ($this->findOpenSalesInquiry($locked) !== null) {
                throw new DispoOrderConflictException(
                    'Es ist bereits eine offene Rückfrage vorhanden.',
                );
            }

            $locked->status = $to;
            $locked->lock_version = $locked->lock_version + 1;
            $locked->save();

            $comment = new DispoOrderComment;
            $comment->dispo_order_id = $locked->id;
            $comment->type = DispoOrderCommentType::SalesInquiry;
            $comment->body = $trimmed;
            $comment->created_by_id = $user->id;
            $comment->created_by_name = $user->name;
            $comment->parent_id = null;
            $comment->save();

            $event = $this->recordStatusEvent($locked, $from, $to, $user);

            $fresh = $this->reload($locked);

            $this->audit->record(
                $fresh,
                'dispo_order.sales_inquiry.created',
                $user,
                [
                    'status' => $from->value,
                    'lock_version' => $expectedLockVersion,
                ],
                [
                    'status' => $fresh->status->value,
                    'lock_version' => $fresh->lock_version,
                    'from_status' => $from->value,
                    'to_status' => $to->value,
                    'comment_id' => $comment->id,
                    'status_event_id' => $event->id,
                    'changed_at' => $event->changed_at->toIso8601String(),
                ],
            );

            return $fresh;
        });
    }

    public function answer(
        DispoOrder $order,
        DispoOrderComment $inquiry,
        User $user,
        int $expectedLockVersion,
        string $answer,
    ): DispoOrder {
        $trimmed = trim($answer);
        if ($trimmed === '') {
            throw ValidationException::withMessages([
                'answer' => 'Eine Antwort ist erforderlich.',
            ]);
        }

        if (mb_strlen($trimmed) > 2000) {
            throw ValidationException::withMessages([
                'answer' => 'Die Antwort darf maximal 2000 Zeichen haben.',
            ]);
        }

        return DB::transaction(function () use ($order, $inquiry, $user, $expectedLockVersion, $trimmed): DispoOrder {
            $locked = $this->lockOrder($order);
            $this->assertLockVersion($locked, $expectedLockVersion);

            if ((int) $inquiry->dispo_order_id !== (int) $locked->id) {
                throw new DispoOrderConflictException(
                    'Die Rückfrage gehört nicht zu diesem Dispoauftrag.',
                );
            }

            $inquiry = DispoOrderComment::query()
                ->whereKey($inquiry->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($inquiry->type !== DispoOrderCommentType::SalesInquiry) {
                throw new DispoOrderConflictException(
                    'Nur eine Rückfrage kann beantwortet werden.',
                );
            }

            if ($inquiry->response()->exists()) {
                throw new DispoOrderConflictException(
                    'Diese Rückfrage wurde bereits beantwortet.',
                );
            }

            $from = $locked->status;
            $to = DispoOrderStatus::AtDisposition;

            if (! DispoOrderStatusTransition::isSalesInquiryAnswer($from, $to)) {
                throw new DispoOrderConflictException(
                    sprintf(
                        'Im Status „%s“ kann keine Rückfrage beantwortet werden.',
                        $from->label(),
                    ),
                );
            }

            DispoOrderStatusTransition::assertCanTransition($from, $to);

            $locked->status = $to;
            $locked->lock_version = $locked->lock_version + 1;
            $locked->save();

            $response = new DispoOrderComment;
            $response->dispo_order_id = $locked->id;
            $response->type = DispoOrderCommentType::SalesInquiryResponse;
            $response->body = $trimmed;
            $response->created_by_id = $user->id;
            $response->created_by_name = $user->name;
            $response->parent_id = $inquiry->id;
            $response->save();

            $event = $this->recordStatusEvent($locked, $from, $to, $user);

            $fresh = $this->reload($locked);

            $this->audit->record(
                $fresh,
                'dispo_order.sales_inquiry.answered',
                $user,
                [
                    'status' => $from->value,
                    'lock_version' => $expectedLockVersion,
                ],
                [
                    'status' => $fresh->status->value,
                    'lock_version' => $fresh->lock_version,
                    'from_status' => $from->value,
                    'to_status' => $to->value,
                    'comment_id' => $response->id,
                    'inquiry_comment_id' => $inquiry->id,
                    'status_event_id' => $event->id,
                    'changed_at' => $event->changed_at->toIso8601String(),
                ],
            );

            return $fresh;
        });
    }

    public function findOpenSalesInquiry(DispoOrder $order): ?DispoOrderComment
    {
        return DispoOrderComment::query()
            ->where('dispo_order_id', $order->id)
            ->where('type', DispoOrderCommentType::SalesInquiry)
            ->whereDoesntHave('response')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function communicationProp(DispoOrder $order): array
    {
        $comments = $order->relationLoaded('comments')
            ? $order->comments
            : $order->comments()->orderBy('id')->get();

        return array_values($comments->map(fn (DispoOrderComment $comment): array => [
            'id' => $comment->id,
            'type' => $comment->type->value,
            'type_label' => $comment->type->label(),
            'body' => $comment->body,
            'created_by_name' => $comment->created_by_name,
            'created_at' => $comment->created_at?->toIso8601String(),
            'parent_id' => $comment->parent_id,
        ])->all());
    }

    private function recordStatusEvent(
        DispoOrder $order,
        DispoOrderStatus $from,
        DispoOrderStatus $to,
        User $user,
    ): DispoOrderStatusEvent {
        $event = new DispoOrderStatusEvent;
        $event->dispo_order_id = $order->id;
        $event->from_status = $from;
        $event->to_status = $to;
        $event->changed_by_id = $user->id;
        $event->changed_by_name = $user->name;
        $event->changed_at = now();
        $event->reason = null;
        $event->is_reopen = false;
        $event->lock_version_after = $order->lock_version;
        $event->save();

        return $event;
    }

    private function lockOrder(DispoOrder $order): DispoOrder
    {
        return DispoOrder::query()
            ->whereKey($order->id)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function assertLockVersion(DispoOrder $order, int $expectedLockVersion): void
    {
        if ($order->lock_version !== $expectedLockVersion) {
            throw new DispoOrderConflictException(
                'Der Dispoauftrag wurde parallel geändert. Bitte die Seite neu laden.',
            );
        }
    }

    private function reload(DispoOrder $order): DispoOrder
    {
        $order->refresh();
        $order->load([
            'positions',
            'creator',
            'approvalRequests',
            'pendingApprovalRequest',
            'latestApprovalRequest',
            'statusEvents',
            'comments',
        ]);

        return $order;
    }
}
