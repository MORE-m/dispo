<?php

namespace App\Services\DispoOrder;

use App\Enums\DispoOrderCommentType;
use App\Models\DispoOrder;
use App\Models\DispoOrderComment;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Allgemeine Kommentare (BL-P9-02a / CMT-001, CMT-002).
 * Kein Statuswechsel, keine Freigabeinvalidierung, keine Notifications.
 *
 * Textlimit: 2000 Zeichen – angeglichen an Rückfrage/Antwort (BL-P8-02b),
 * da CMT-001 kein eigenes Limit vorgibt.
 */
final class DispoOrderGeneralCommentService
{
    public const int BODY_MAX = 2000;

    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    public function add(DispoOrder $order, User $user, string $body): DispoOrderComment
    {
        $trimmed = trim($body);
        if ($trimmed === '') {
            throw ValidationException::withMessages([
                'body' => 'Ein Kommentartext ist erforderlich.',
            ]);
        }

        if (mb_strlen($trimmed) > self::BODY_MAX) {
            throw ValidationException::withMessages([
                'body' => sprintf(
                    'Der Kommentar darf maximal %d Zeichen haben.',
                    self::BODY_MAX,
                ),
            ]);
        }

        return DB::transaction(function () use ($order, $user, $trimmed): DispoOrderComment {
            $locked = DispoOrder::query()
                ->whereKey($order->id)
                ->lockForUpdate()
                ->firstOrFail();

            $statusBefore = $locked->status->value;
            $lockBefore = $locked->lock_version;

            $comment = new DispoOrderComment;
            $comment->dispo_order_id = $locked->id;
            $comment->type = DispoOrderCommentType::General;
            $comment->body = $trimmed;
            $comment->created_by_id = $user->id;
            $comment->created_by_name = $user->name;
            $comment->parent_id = null;
            $comment->save();

            $locked->refresh();

            $this->audit->record(
                $locked,
                'dispo_order.comment.created',
                $user,
                [
                    'status' => $statusBefore,
                    'lock_version' => $lockBefore,
                ],
                [
                    'comment_id' => $comment->id,
                    'type' => $comment->type->value,
                    'status' => $locked->status->value,
                    'lock_version' => $locked->lock_version,
                ],
            );

            return $comment;
        });
    }
}
