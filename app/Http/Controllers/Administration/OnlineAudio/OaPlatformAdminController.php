<?php

namespace App\Http\Controllers\Administration\OnlineAudio;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\OaPlatform;
use App\Models\User;
use App\Services\OnlineAudio\Admin\OaPlatformAdminWriter;
use App\Services\OnlineAudio\Import\OaTkpStammdatenImportException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * PO-CALC-TKP-1D4c-4c: JSON-Admin-API für OA-Plattformen (ohne UI, ohne Preise).
 */
class OaPlatformAdminController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('access-administration');

        $active = $request->query('is_active');
        $query = OaPlatform::query()->orderBy('sort')->orderBy('code');
        if ($active !== null && $active !== '') {
            $query->where('is_active', filter_var($active, FILTER_VALIDATE_BOOLEAN));
        }

        return response()->json([
            'platforms' => $query->get()->map(fn (OaPlatform $row): array => $this->serialize($row))->values()->all(),
            'notes' => [
                'no_pricing' => 'Plattformen tragen keine TKP-Preise.',
                'no_auto_seed' => 'Spotify/YouTube/Deezer werden durch diesen Endpoint nicht automatisch angelegt.',
            ],
        ]);
    }

    public function show(OaPlatform $oaPlatform): JsonResponse
    {
        $this->authorize('access-administration');

        return response()->json([
            'platform' => $this->serialize($oaPlatform),
        ]);
    }

    public function store(Request $request, OaPlatformAdminWriter $writer): JsonResponse
    {
        $this->authorize('access-administration');

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:64'],
            'name' => ['required', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
            'sort' => ['sometimes', 'integer', 'min:0', 'max:99999'],
            'is_adserver_relevant' => ['sometimes', 'boolean'],
        ]);

        try {
            $platform = $writer->upsert($validated, $this->actor($request));
        } catch (OaTkpStammdatenImportException $e) {
            return $this->importException($e);
        }

        return response()->json([
            'message' => 'Plattform gespeichert.',
            'platform' => $this->serialize($platform),
        ], 201);
    }

    public function update(
        Request $request,
        OaPlatform $oaPlatform,
        OaPlatformAdminWriter $writer,
    ): JsonResponse {
        $this->authorize('access-administration');

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
            'sort' => ['sometimes', 'integer', 'min:0', 'max:99999'],
            'is_adserver_relevant' => ['sometimes', 'boolean'],
        ]);

        if ($request->exists('code') && (string) $request->input('code') !== (string) $oaPlatform->code) {
            throw ValidationException::withMessages(['code' => 'code ist unveränderlich.']);
        }

        try {
            $platform = $writer->upsert([
                ...$validated,
                'code' => (string) $oaPlatform->code,
            ], $this->actor($request));
        } catch (OaTkpStammdatenImportException $e) {
            return $this->importException($e);
        }

        return response()->json([
            'message' => 'Plattform aktualisiert.',
            'platform' => $this->serialize($platform),
        ]);
    }

    public function activate(
        Request $request,
        OaPlatform $oaPlatform,
        OaPlatformAdminWriter $writer,
    ): JsonResponse {
        $this->authorize('access-administration');

        $platform = $writer->setActive($oaPlatform, true, $request->all(), $this->actor($request));

        return response()->json([
            'message' => 'Plattform aktiviert.',
            'platform' => $this->serialize($platform),
        ]);
    }

    public function deactivate(
        Request $request,
        OaPlatform $oaPlatform,
        OaPlatformAdminWriter $writer,
    ): JsonResponse {
        $this->authorize('access-administration');

        $platform = $writer->setActive($oaPlatform, false, $request->all(), $this->actor($request));

        return response()->json([
            'message' => 'Plattform deaktiviert.',
            'platform' => $this->serialize($platform),
        ]);
    }

    public function history(OaPlatform $oaPlatform): JsonResponse
    {
        $this->authorize('access-administration');

        $events = AuditEvent::query()
            ->where('auditable_type', OaPlatform::class)
            ->where('auditable_id', $oaPlatform->id)
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
                'created_at' => $event->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();

        return response()->json([
            'platform_id' => (int) $oaPlatform->id,
            'events' => $events,
        ]);
    }

    public function destroy(): JsonResponse
    {
        $this->authorize('access-administration');

        return response()->json([
            'message' => 'Hard-Delete ist für OA-Plattformen nicht erlaubt.',
        ], 405);
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(OaPlatform $platform): array
    {
        return [
            'id' => (int) $platform->id,
            'code' => (string) $platform->code,
            'name' => (string) $platform->name,
            'is_active' => (bool) $platform->is_active,
            'sort' => (int) $platform->sort,
            'is_adserver_relevant' => (bool) $platform->is_adserver_relevant,
        ];
    }

    private function actor(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }

    private function importException(OaTkpStammdatenImportException $e): JsonResponse
    {
        return response()->json([
            'message' => $e->getMessage(),
            'errors' => $e->errors,
        ], 422);
    }
}
