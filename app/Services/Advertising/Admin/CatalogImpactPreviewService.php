<?php

namespace App\Services\Advertising\Admin;

use App\Enums\FieldSetAssignmentTargetLayer;
use App\Exceptions\CatalogAdminConflictException;
use App\Models\AdvertisingCategory;
use App\Models\AdvertisingMedium;
use App\Models\CalculationPosition;
use App\Models\DispoOrderPosition;
use App\Models\FieldSetAssignment;
use App\Models\InventoryMediumRule;
use App\Support\Advertising\AdvertisingKindCategoryCompatibility;
use Illuminate\Validation\ValidationException;

/**
 * ADV-001b: schlanke Auswirkungsvorschau – Zähler und Warnungen, keine zweite Snapshot-/Assignment-Auflösung.
 */
final class CatalogImpactPreviewService
{
    public const ACTION_CATEGORY_DEACTIVATE = 'category_deactivate';

    public const ACTION_MEDIUM_DEACTIVATE = 'medium_deactivate';

    public const ACTION_MEDIUM_CATEGORY_CHANGE = 'medium_category_change';

    /**
     * @param  array{target_category_id?: int|null}  $payload
     * @return array<string, mixed>
     */
    public function previewCategoryDeactivate(AdvertisingCategory $category, array $payload = []): array
    {
        // Wenn der Lock-Coordinator die Relation unter Kategorie-Sperre gesetzt hat,
        // diese nutzen (frischer Read ohne Media-FOR-UPDATE). Sonst frische Query.
        if ($category->relationLoaded('advertisingMedia')) {
            $media = $category->advertisingMedia;
        } else {
            $media = AdvertisingMedium::query()
                ->where('category_id', $category->id)
                ->orderBy('id')
                ->get();
            $category->setRelation('advertisingMedia', $media);
        }

        $activeMedia = $media->where('is_active', true)->values();
        $inactiveMedia = $media->where('is_active', false)->values();

        $assignmentCounts = $this->assignmentCountsForCategory((int) $category->id);
        $blocking = [];

        if ($activeMedia->isNotEmpty()) {
            $blocking[] = [
                'code' => 'active_media',
                'message' => 'Die Oberkategorie kann nicht deaktiviert werden, solange aktive Werbemittel zugeordnet sind (PO-ADV001b-3).',
            ];
        }

        $activeMediaList = $activeMedia->map(fn (AdvertisingMedium $m): array => [
            'id' => $m->id,
            'name' => $m->name,
            'code' => $m->code,
        ])->all();

        $body = [
            'entity' => 'advertising_category',
            'action' => self::ACTION_CATEGORY_DEACTIVATE,
            'entity_id' => (int) $category->id,
            'lock_version' => (int) $category->lock_version,
            'intended_change' => [
                'is_active' => false,
            ],
            'current' => $this->categorySnapshot($category),
            'active_media_count' => $activeMedia->count(),
            'inactive_media_count' => $inactiveMedia->count(),
            'active_media' => $activeMediaList,
            'assignments' => $assignmentCounts,
            'inventory_medium_rules_count' => 0,
            'calculation_positions_count' => CalculationPosition::query()
                ->where('advertising_category_id', $category->id)
                ->count(),
            'dispo_order_positions_count' => DispoOrderPosition::query()
                ->where('advertising_category_id', $category->id)
                ->count(),
            'historical_snapshots_note' => 'Historische Gen-3-Snapshots und eingefrorene Positionsnamen bleiben unverändert.',
            'new_processes_note' => 'Die Deaktivierung wirkt nur auf neue Vorgänge und neue Assignment-Bindungen.',
            'reactivation_assignment_warning' => $assignmentCounts['active'] > 0
                ? 'Es gibt weiterhin aktive Assignments auf dieses Ziel. Nach einer späteren Reaktivierung der Kategorie wirken diese Assignments wieder für neue Vorgänge (PO-ADV001b-4).'
                : null,
            'blocking_reasons' => $blocking,
            'can_proceed' => $blocking === [],
        ];

        $body['fingerprint'] = $this->fingerprint($body);

        return $body;
    }

    /**
     * @return array<string, mixed>
     */
    public function previewMediumDeactivate(AdvertisingMedium $medium): array
    {
        $medium->loadMissing('category');
        $assignmentCounts = $this->assignmentCountsForMedium((int) $medium->id);

        $body = [
            'entity' => 'advertising_medium',
            'action' => self::ACTION_MEDIUM_DEACTIVATE,
            'entity_id' => (int) $medium->id,
            'lock_version' => (int) $medium->lock_version,
            'intended_change' => [
                'is_active' => false,
            ],
            'current' => $this->mediumSnapshot($medium),
            'active_media_count' => null,
            'inactive_media_count' => null,
            'active_media' => [],
            'assignments' => $assignmentCounts,
            'inventory_medium_rules_count' => InventoryMediumRule::query()
                ->where('advertising_medium_id', $medium->id)
                ->count(),
            'calculation_positions_count' => CalculationPosition::query()
                ->where('advertising_medium_id', $medium->id)
                ->count(),
            'dispo_order_positions_count' => DispoOrderPosition::query()
                ->where('advertising_medium_id', $medium->id)
                ->count(),
            'historical_snapshots_note' => 'Historische Gen-3-Snapshots und bestehende Positionen bleiben lesbar und unverändert.',
            'new_processes_note' => 'Das Werbemittel erscheint danach nicht mehr in der Auswahl für neue Kalkulationspositionen.',
            'reactivation_assignment_warning' => $assignmentCounts['active'] > 0
                ? 'Es gibt weiterhin aktive Assignments auf dieses Werbemittel. Nach einer späteren Reaktivierung wirken diese Assignments wieder für neue Vorgänge (PO-ADV001b-4).'
                : null,
            'blocking_reasons' => [],
            'can_proceed' => true,
        ];

        $body['fingerprint'] = $this->fingerprint($body);

        return $body;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function previewMediumCategoryChange(AdvertisingMedium $medium, array $payload): array
    {
        $medium->loadMissing('category');
        $targetId = (int) ($payload['target_category_id'] ?? 0);
        if ($targetId < 1) {
            throw ValidationException::withMessages([
                'category_id' => 'Ziel-Oberkategorie ist erforderlich.',
            ]);
        }

        // Unter Mutation: gesperrte Zielkategorie übergeben – kein non-locking SELECT
        // (MySQL RR-Snapshot könnte sonst is_active veraltet lesen).
        $target = $payload['locked_target_category'] ?? null;
        if (! $target instanceof AdvertisingCategory) {
            $target = AdvertisingCategory::query()->whereKey($targetId)->first();
        }
        if ($target === null) {
            throw ValidationException::withMessages([
                'category_id' => 'Die Ziel-Oberkategorie wurde nicht gefunden.',
            ]);
        }

        $blocking = [];
        if (! $target->is_active) {
            $blocking[] = [
                'code' => 'inactive_target_category',
                'message' => 'Der Wechsel ist nur auf eine aktive Oberkategorie erlaubt.',
            ];
        }

        if ((int) $medium->category_id === (int) $target->id) {
            $blocking[] = [
                'code' => 'same_category',
                'message' => 'Das Werbemittel ist dieser Oberkategorie bereits zugeordnet.',
            ];
        }

        if (! AdvertisingKindCategoryCompatibility::isCompatible($medium->kind, $target->key)) {
            $blocking[] = [
                'code' => 'kind_incompatible',
                'message' => 'Berechnungsart und Ziel-Oberkategorie sind nicht kompatibel (PO-ADV001b-8).',
            ];
        }

        $oldCategoryAssignments = $this->assignmentCountsForCategory((int) $medium->category_id);
        $newCategoryAssignments = $this->assignmentCountsForCategory((int) $target->id);
        $mediumAssignments = $this->assignmentCountsForMedium((int) $medium->id);

        $body = [
            'entity' => 'advertising_medium',
            'action' => self::ACTION_MEDIUM_CATEGORY_CHANGE,
            'entity_id' => (int) $medium->id,
            'lock_version' => (int) $medium->lock_version,
            'intended_change' => [
                'category_id' => (int) $target->id,
                'category_key' => $target->key,
                'category_name' => $target->name,
            ],
            'current' => $this->mediumSnapshot($medium),
            'target_category' => $this->categorySnapshot($target),
            'active_media_count' => null,
            'inactive_media_count' => null,
            'active_media' => [],
            'assignments' => [
                'medium' => $mediumAssignments,
                'old_category' => $oldCategoryAssignments,
                'new_category' => $newCategoryAssignments,
            ],
            'inventory_medium_rules_count' => InventoryMediumRule::query()
                ->where('advertising_medium_id', $medium->id)
                ->count(),
            'calculation_positions_count' => CalculationPosition::query()
                ->where('advertising_medium_id', $medium->id)
                ->count(),
            'dispo_order_positions_count' => DispoOrderPosition::query()
                ->where('advertising_medium_id', $medium->id)
                ->count(),
            'historical_snapshots_note' => 'Historische Gen-3-Snapshots behalten den eingefrorenen Kategorie-Kontext. Bestehende Positionen werden nicht neu aufgelöst.',
            'new_processes_note' => 'Neue Vorgänge nutzen die neue Oberkategorie. Kategorie-Assignments der alten bzw. neuen Kategorie ändern das effektive Schema nur für neue Vorgänge. Medium-Assignments bleiben an der Medium-ID.',
            'reactivation_assignment_warning' => null,
            'schema_change_warning' => 'Das effektive Feldschema (Core → global → Oberkategorie → Werbemittel) kann sich für neue Positionen ändern.',
            'blocking_reasons' => $blocking,
            'can_proceed' => $blocking === [],
        ];

        $body['fingerprint'] = $this->fingerprint($body);

        return $body;
    }

    /**
     * @param  array<string, mixed>  $preview
     */
    public function fingerprint(array $preview): string
    {
        $canonical = $preview;
        unset($canonical['fingerprint']);

        return hash('sha256', json_encode(
            $this->canonicalize($canonical),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        ));
    }

    /**
     * @param  array<string, mixed>  $preview
     */
    public function assertFingerprint(array $preview, string $expected): void
    {
        $actual = $this->fingerprint($preview);
        if (! hash_equals($actual, $expected)) {
            throw new CatalogAdminConflictException(
                'Die Auswirkungsvorschau ist veraltet. Bitte Vorschau erneut laden und bestätigen.',
            );
        }
    }

    /**
     * @return array{active: int, inactive: int, total: int}
     */
    private function assignmentCountsForCategory(int $categoryId): array
    {
        $query = FieldSetAssignment::query()
            ->where('target_layer', FieldSetAssignmentTargetLayer::AdvertisingCategory->value)
            ->where('advertising_category_id', $categoryId);

        $total = (clone $query)->count();
        $active = (clone $query)->where('is_active', true)->count();

        return [
            'active' => $active,
            'inactive' => $total - $active,
            'total' => $total,
        ];
    }

    /**
     * @return array{active: int, inactive: int, total: int}
     */
    private function assignmentCountsForMedium(int $mediumId): array
    {
        $query = FieldSetAssignment::query()
            ->where('target_layer', FieldSetAssignmentTargetLayer::AdvertisingMedium->value)
            ->where('advertising_medium_id', $mediumId);

        $total = (clone $query)->count();
        $active = (clone $query)->where('is_active', true)->count();

        return [
            'active' => $active,
            'inactive' => $total - $active,
            'total' => $total,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function categorySnapshot(AdvertisingCategory $category): array
    {
        return [
            'id' => (int) $category->id,
            'key' => $category->key,
            'name' => $category->name,
            'sort' => (int) $category->sort,
            'is_active' => (bool) $category->is_active,
            'lock_version' => (int) $category->lock_version,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mediumSnapshot(AdvertisingMedium $medium): array
    {
        $medium->loadMissing('category');

        return [
            'id' => (int) $medium->id,
            'code' => $medium->code,
            'name' => $medium->name,
            'kind' => $medium->kind->value,
            'category_id' => (int) $medium->category_id,
            'category_key' => $medium->category?->key,
            'category_name' => $medium->category?->name,
            'default_length_seconds' => (int) $medium->default_length_seconds,
            'is_discountable' => (bool) $medium->is_discountable,
            'is_ae_eligible' => (bool) $medium->is_ae_eligible,
            'sort' => (int) $medium->sort,
            'is_active' => (bool) $medium->is_active,
            'lock_version' => (int) $medium->lock_version,
        ];
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
        }

        ksort($value);
        $out = [];
        foreach ($value as $key => $item) {
            $out[(string) $key] = $this->canonicalize($item);
        }

        return $out;
    }
}
