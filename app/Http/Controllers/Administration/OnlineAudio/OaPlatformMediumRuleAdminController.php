<?php

namespace App\Http\Controllers\Administration\OnlineAudio;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\OaPlatformMediumRule;
use App\Models\User;
use App\Services\OnlineAudio\Admin\OaPlatformMediumRuleAdminWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * PO-CALC-TKP-1D4c-4j-2/3: Admin-Matrix Plattform × OA-Kernmedium.
 */
class OaPlatformMediumRuleAdminController extends Controller
{
    public function index(Request $request, OaPlatformMediumRuleAdminWriter $writer): JsonResponse|Response
    {
        $this->authorize('manage-oa-tkp-master-data');

        $payload = $writer->snapshot();

        if ($request->expectsJson()) {
            return response()->json($payload);
        }

        return Inertia::render('administration/online-audio/platform-medium-rules/index', $payload);
    }

    public function update(Request $request, OaPlatformMediumRuleAdminWriter $writer): JsonResponse
    {
        $this->authorize('manage-oa-tkp-master-data');

        $result = $writer->replace($request->all(), $this->actor($request));

        return response()->json($result);
    }

    public function history(): JsonResponse
    {
        $this->authorize('manage-oa-tkp-master-data');

        $events = AuditEvent::query()
            ->where('auditable_type', OaPlatformMediumRule::class)
            ->orderByDesc('id')
            ->limit(100)
            ->get()
            ->map(static fn (AuditEvent $event): array => [
                'id' => (int) $event->id,
                'action' => (string) $event->action,
                'user_id' => $event->user_id !== null ? (int) $event->user_id : null,
                'old_values' => $event->old_values,
                'new_values' => $event->new_values,
                'correlation_id' => $event->correlation_id,
                'created_at' => $event->created_at->toIso8601String(),
            ])
            ->values()
            ->all();

        return response()->json([
            'auditable_type' => OaPlatformMediumRule::class,
            'events' => $events,
        ]);
    }

    public function destroy(): JsonResponse
    {
        $this->authorize('manage-oa-tkp-master-data');

        return response()->json([
            'message' => 'Hard-Delete ist für Plattform-Medien-Zuordnungen nicht erlaubt.',
        ], 405);
    }

    private function actor(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
