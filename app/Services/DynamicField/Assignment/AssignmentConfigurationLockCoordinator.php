<?php

namespace App\Services\DynamicField\Assignment;

use App\Enums\FieldAppliesTo;
use App\Enums\FieldScope;
use App\Enums\FieldSetAssignmentTargetLayer;
use App\Models\AdvertisingCategory;
use App\Models\AdvertisingMedium;
use App\Models\FieldSet;
use App\Models\FieldSetAssignment;
use App\Services\DynamicField\Admin\AdminFieldSetCatalog;
use Illuminate\Support\Collection;

/**
 * DF-3.3a2α: wiederverwendbare, deterministische Lockreihenfolge für
 * Konfigurationsauflösungen (Admin-Activate und Runtime-Freeze).
 *
 * Reihenfolge ist global identisch, damit Freeze und Activate nicht
 * gegeneinander deadlocken:
 * 1. Prozess-Cores (Calculation-Core vor Dispo-Core)
 * 2. beitragende Assignments nach `id` ASC
 * 3. beteiligte Feldset-Container nach `id` ASC
 * 4. relevante Kategorie-/Werbemittelzeilen nach `id` ASC (nur Vollkontext)
 */
final class AssignmentConfigurationLockCoordinator
{
    /**
     * Runtime-Freeze (α): nur Core + globale Ebene, keine Kat-/Mediumlocks.
     *
     * @param  bool  $globalOnly  false bezieht Kat-/Werbemittelzeilen mit ein
     */
    public function lockForProcess(FieldAppliesTo $process, bool $globalOnly = true): void
    {
        $processes = $this->processesFor($process);
        $this->lockProcessCores($processes);

        $assignmentIds = $this->activeGlobalAssignmentIds($processes);
        $this->lockAssignmentsAndContainers($assignmentIds);

        if ($globalOnly) {
            return;
        }

        $this->lockAllTargetRows();
    }

    /**
     * Admin-Activate/Deactivate: Vollkontext inkl. Zielzeilen; liefert das
     * gesperrte Assignment zurück.
     */
    public function lockForAssignment(FieldSetAssignment $assignment): FieldSetAssignment
    {
        $processes = $this->processesFor($assignment->applies_to_process);
        $this->lockProcessCores($processes);

        $contributingIds = $this->contributingAssignmentIds($assignment, $processes);
        if (! in_array((int) $assignment->id, $contributingIds, true)) {
            $contributingIds[] = (int) $assignment->id;
        }
        sort($contributingIds);

        $lockedAssignments = $this->lockAssignmentsAndContainers($contributingIds);

        $this->lockTargetRows($assignment);

        /** @var FieldSetAssignment $locked */
        $locked = $lockedAssignments->firstWhere('id', $assignment->id)
            ?? FieldSetAssignment::query()->whereKey($assignment->id)->lockForUpdate()->firstOrFail();
        $locked->load(['fieldSet.activeVersion']);

        return $locked;
    }

    /**
     * @return list<array{scope: string, advertising_category_id: int|null, advertising_medium_id: int|null}>
     */
    public function affectedContexts(FieldSetAssignment $assignment): array
    {
        return match ($assignment->target_layer) {
            FieldSetAssignmentTargetLayer::Global => $this->globalContexts(),
            FieldSetAssignmentTargetLayer::AdvertisingCategory => $this->categoryContexts(
                (int) $assignment->advertising_category_id,
            ),
            FieldSetAssignmentTargetLayer::AdvertisingMedium => [[
                'scope' => FieldScope::Position->value,
                'advertising_category_id' => null,
                'advertising_medium_id' => (int) $assignment->advertising_medium_id,
            ]],
        };
    }

    /**
     * @return list<FieldAppliesTo>
     */
    public function processesFor(FieldAppliesTo $process): array
    {
        return match ($process) {
            FieldAppliesTo::Calculation => [FieldAppliesTo::Calculation],
            FieldAppliesTo::DispoOrder => [FieldAppliesTo::DispoOrder],
            FieldAppliesTo::Both => [FieldAppliesTo::Calculation, FieldAppliesTo::DispoOrder],
        };
    }

    /**
     * @param  list<FieldAppliesTo>  $processes
     */
    private function lockProcessCores(array $processes): void
    {
        $keys = [];
        foreach ($processes as $process) {
            $keys[] = $process === FieldAppliesTo::DispoOrder
                ? AdminFieldSetCatalog::DISPO_ORDER_CORE
                : AdminFieldSetCatalog::CALCULATION_CORE;
        }
        $keys = array_values(array_unique($keys));
        // Stabile Reihenfolge: Calculation-Core vor Dispo-Core
        usort($keys, static function (string $a, string $b): int {
            $rank = [
                AdminFieldSetCatalog::CALCULATION_CORE => 1,
                AdminFieldSetCatalog::DISPO_ORDER_CORE => 2,
            ];

            return $rank[$a] <=> $rank[$b];
        });

        foreach ($keys as $key) {
            FieldSet::query()->where('key', $key)->lockForUpdate()->firstOrFail();
        }
    }

    /**
     * @param  list<int>  $assignmentIds
     * @return Collection<int, FieldSetAssignment>
     */
    private function lockAssignmentsAndContainers(array $assignmentIds): Collection
    {
        if ($assignmentIds === []) {
            /** @var Collection<int, FieldSetAssignment> $empty */
            $empty = new Collection;

            return $empty;
        }

        /** @var Collection<int, FieldSetAssignment> $locked */
        $locked = FieldSetAssignment::query()
            ->whereIn('id', $assignmentIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $fieldSetIds = $locked->pluck('field_set_id')
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->sort()
            ->values()
            ->all();

        if ($fieldSetIds !== []) {
            FieldSet::query()
                ->whereIn('id', $fieldSetIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
        }

        return $locked;
    }

    /**
     * Aktive globale Assignments für die Prozesse (inkl. `both`).
     *
     * @param  list<FieldAppliesTo>  $processes
     * @return list<int>
     */
    private function activeGlobalAssignmentIds(array $processes): array
    {
        return array_values(
            FieldSetAssignment::query()
                ->whereIn('applies_to_process', $this->processValues($processes))
                ->where('target_layer', FieldSetAssignmentTargetLayer::Global->value)
                ->where('is_active', true)
                ->orderBy('id')
                ->pluck('id')
                ->map(static fn ($id): int => (int) $id)
                ->all(),
        );
    }

    /**
     * @param  list<FieldAppliesTo>  $processes
     * @return list<int>
     */
    private function contributingAssignmentIds(FieldSetAssignment $assignment, array $processes): array
    {
        $query = FieldSetAssignment::query()
            ->whereIn('applies_to_process', $this->processValues($processes))
            ->where(function ($q) use ($assignment): void {
                $q->where('is_active', true)
                    ->orWhere('id', $assignment->id);
            });

        // Layer-Filter: alles Globale plus betroffene Kat/Medien der Kontexte
        [$categoryIds, $mediumIds] = $this->contextTargetIds($assignment);

        $query->where(function ($q) use ($categoryIds, $mediumIds): void {
            $q->where('target_layer', FieldSetAssignmentTargetLayer::Global->value);
            if ($categoryIds !== []) {
                $q->orWhere(function ($inner) use ($categoryIds): void {
                    $inner->where('target_layer', FieldSetAssignmentTargetLayer::AdvertisingCategory->value)
                        ->whereIn('advertising_category_id', $categoryIds);
                });
            }
            if ($mediumIds !== []) {
                $q->orWhere(function ($inner) use ($mediumIds): void {
                    $inner->where('target_layer', FieldSetAssignmentTargetLayer::AdvertisingMedium->value)
                        ->whereIn('advertising_medium_id', $mediumIds);
                });
            }
        });

        return array_values($query->orderBy('id')->pluck('id')->map(static fn ($id): int => (int) $id)->all());
    }

    private function lockTargetRows(FieldSetAssignment $assignment): void
    {
        [$categoryIds, $mediumIds] = $this->contextTargetIds($assignment);

        if ($assignment->advertising_category_id !== null) {
            $categoryIds[] = (int) $assignment->advertising_category_id;
        }
        if ($assignment->advertising_medium_id !== null) {
            $mediumIds[] = (int) $assignment->advertising_medium_id;
        }

        $categoryIds = array_values(array_unique($categoryIds));
        $mediumIds = array_values(array_unique($mediumIds));
        sort($categoryIds);
        sort($mediumIds);

        if ($mediumIds !== []) {
            $media = AdvertisingMedium::query()
                ->whereIn('id', $mediumIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id', 'category_id']);
            foreach ($media as $medium) {
                $categoryIds[] = (int) $medium->category_id;
            }
            $categoryIds = array_values(array_unique($categoryIds));
            sort($categoryIds);
        }

        if ($categoryIds !== []) {
            AdvertisingCategory::query()
                ->whereIn('id', $categoryIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
        }
    }

    private function lockAllTargetRows(): void
    {
        AdvertisingMedium::query()->orderBy('id')->lockForUpdate()->get(['id', 'category_id']);
        AdvertisingCategory::query()->orderBy('id')->lockForUpdate()->get(['id']);
    }

    /**
     * @return array{list<int>, list<int>}
     */
    private function contextTargetIds(FieldSetAssignment $assignment): array
    {
        $categoryIds = [];
        $mediumIds = [];

        foreach ($this->affectedContexts($assignment) as $context) {
            if ($context['advertising_category_id'] !== null) {
                $categoryIds[] = (int) $context['advertising_category_id'];
            }
            if ($context['advertising_medium_id'] !== null) {
                $mediumIds[] = (int) $context['advertising_medium_id'];
            }
        }

        return [
            array_values(array_unique($categoryIds)),
            array_values(array_unique($mediumIds)),
        ];
    }

    /**
     * @param  list<FieldAppliesTo>  $processes
     * @return list<string>
     */
    private function processValues(array $processes): array
    {
        $values = array_map(
            static fn (FieldAppliesTo $process): string => $process->value,
            $processes,
        );
        $values[] = FieldAppliesTo::Both->value;

        return array_values(array_unique($values));
    }

    /**
     * @return list<array{scope: string, advertising_category_id: int|null, advertising_medium_id: int|null}>
     */
    private function globalContexts(): array
    {
        $contexts = [
            [
                'scope' => FieldScope::Header->value,
                'advertising_category_id' => null,
                'advertising_medium_id' => null,
            ],
        ];

        $categoryIds = AdvertisingCategory::query()->orderBy('id')->pluck('id');
        foreach ($categoryIds as $categoryId) {
            $contexts[] = [
                'scope' => FieldScope::Position->value,
                'advertising_category_id' => (int) $categoryId,
                'advertising_medium_id' => null,
            ];
        }

        $media = AdvertisingMedium::query()->orderBy('id')->get(['id', 'category_id']);
        foreach ($media as $medium) {
            $contexts[] = [
                'scope' => FieldScope::Position->value,
                'advertising_category_id' => (int) $medium->category_id,
                'advertising_medium_id' => (int) $medium->id,
            ];
        }

        return $contexts;
    }

    /**
     * @return list<array{scope: string, advertising_category_id: int|null, advertising_medium_id: int|null}>
     */
    private function categoryContexts(int $categoryId): array
    {
        $contexts = [
            [
                'scope' => FieldScope::Position->value,
                'advertising_category_id' => $categoryId,
                'advertising_medium_id' => null,
            ],
        ];

        $media = AdvertisingMedium::query()
            ->where('category_id', $categoryId)
            ->orderBy('id')
            ->pluck('id');

        foreach ($media as $mediumId) {
            $contexts[] = [
                'scope' => FieldScope::Position->value,
                'advertising_category_id' => $categoryId,
                'advertising_medium_id' => (int) $mediumId,
            ];
        }

        return $contexts;
    }
}
