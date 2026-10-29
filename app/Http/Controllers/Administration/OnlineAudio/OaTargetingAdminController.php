<?php

namespace App\Http\Controllers\Administration\OnlineAudio;

use App\Enums\OaTargetingCategory;
use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\OaTargetingDefinition;
use App\Models\User;
use App\Services\OnlineAudio\Admin\OaTargetingAdminWriter;
use App\Services\OnlineAudio\Import\OaTkpStammdatenImportException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * PO-CALC-TKP-1D4c-4d: OA-Targeting-Admin (Inertia-UI + JSON-API aus 4c).
 */
class OaTargetingAdminController extends Controller
{
    public function index(Request $request): JsonResponse|Response
    {
        $this->authorize('manage-oa-tkp-master-data');

        $code = $request->query('code');
        $active = $request->query('is_active');

        $query = OaTargetingDefinition::query()
            ->orderBy('code')
            ->orderByDesc('revision_number');

        if (is_string($code) && $code !== '') {
            $query->where('code', $code);
        }
        if ($active !== null && $active !== '') {
            $query->where('is_active', filter_var($active, FILTER_VALIDATE_BOOLEAN));
        }

        $payload = [
            'targetings' => $query->get()->map(fn (OaTargetingDefinition $row): array => $this->serialize($row))->values()->all(),
            'categories' => array_map(
                static fn (OaTargetingCategory $c): array => ['value' => $c->value, 'label' => $c->label()],
                OaTargetingCategory::cases(),
            ),
            'notes' => [
                'geo_category' => 'Geo wird zunächst unter category=technisch geführt (keine neue Kategorie).',
                'revision_identity' => 'Identity = code + revision_number; Preiserhöhungen bevorzugt als neue Revision.',
            ],
        ];

        if ($request->expectsJson()) {
            return response()->json($payload);
        }

        return Inertia::render('administration/online-audio/targetings/index', $payload);
    }

    public function show(OaTargetingDefinition $oaTargetingDefinition): JsonResponse
    {
        $this->authorize('manage-oa-tkp-master-data');

        return response()->json([
            'targeting' => $this->serialize($oaTargetingDefinition),
        ]);
    }

    public function store(Request $request, OaTargetingAdminWriter $writer): JsonResponse
    {
        $this->authorize('manage-oa-tkp-master-data');

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:64'],
            'name' => ['required', 'string', 'max:255'],
            'category' => ['required', 'string', Rule::in(array_column(OaTargetingCategory::cases(), 'value'))],
            'tkp_surcharge' => ['required', 'string'],
            'minimum_tkp_surcharge' => ['sometimes', 'nullable', 'string'],
            'revision_number' => ['required', 'integer', 'min:1'],
            'is_active' => ['sometimes', 'boolean'],
            'valid_from' => ['sometimes', 'nullable', 'date'],
            'valid_to' => ['sometimes', 'nullable', 'date'],
        ]);

        try {
            $definition = $writer->upsert($validated, $this->actor($request));
        } catch (OaTkpStammdatenImportException $e) {
            return $this->importException($e);
        }

        return response()->json([
            'message' => 'Targeting gespeichert.',
            'targeting' => $this->serialize($definition),
        ], 201);
    }

    public function storeRevision(
        Request $request,
        string $code,
        OaTargetingAdminWriter $writer,
    ): JsonResponse {
        $this->authorize('manage-oa-tkp-master-data');

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'category' => ['sometimes', 'string', Rule::in(array_column(OaTargetingCategory::cases(), 'value'))],
            'tkp_surcharge' => ['sometimes', 'string'],
            'minimum_tkp_surcharge' => ['sometimes', 'nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
            'deactivate_previous' => ['sometimes', 'boolean'],
            'valid_from' => ['sometimes', 'nullable', 'date'],
            'valid_to' => ['sometimes', 'nullable', 'date'],
        ]);

        try {
            $definition = $writer->createNewRevision($code, $validated, $this->actor($request));
        } catch (OaTkpStammdatenImportException $e) {
            return $this->importException($e);
        }

        return response()->json([
            'message' => 'Neue Targeting-Revision angelegt.',
            'targeting' => $this->serialize($definition),
        ], 201);
    }

    public function history(OaTargetingDefinition $oaTargetingDefinition): JsonResponse
    {
        $this->authorize('manage-oa-tkp-master-data');

        $events = AuditEvent::query()
            ->where('auditable_type', OaTargetingDefinition::class)
            ->where('auditable_id', $oaTargetingDefinition->id)
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
            'targeting_id' => (int) $oaTargetingDefinition->id,
            'events' => $events,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(OaTargetingDefinition $definition): array
    {
        return [
            'id' => (int) $definition->id,
            'code' => (string) $definition->code,
            'name' => (string) $definition->name,
            'category' => $definition->category->value,
            'tkp_surcharge' => (string) $definition->tkp_surcharge,
            'minimum_tkp_surcharge' => (string) $definition->minimum_tkp_surcharge,
            'revision_number' => (int) $definition->revision_number,
            'is_active' => (bool) $definition->is_active,
            'valid_from' => $definition->valid_from?->toDateString(),
            'valid_to' => $definition->valid_to?->toDateString(),
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
