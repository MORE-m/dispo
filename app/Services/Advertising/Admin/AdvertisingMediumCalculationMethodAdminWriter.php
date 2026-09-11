<?php

namespace App\Services\Advertising\Admin;

use App\Enums\CalculationMethodMode;
use App\Exceptions\CatalogAdminConflictException;
use App\Models\AdvertisingCategory;
use App\Models\AdvertisingCategoryCalculationMethod;
use App\Models\AdvertisingMedium;
use App\Models\AdvertisingMediumCalculationMethod;
use App\Models\CalculationMethod;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\Advertising\CalculationMethodAssignmentActivationGuard;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ADV-001c3c: atomarer Desired-State-Apply für Medium-Methoden Mode/Default/Assignments.
 *
 * Lock-Reihenfolge: Medium → Kategorie → Methoden ID ASC → Cat-Assignments ID ASC
 * → Medium-Assignments ID ASC. Keine Parent-Locks danach.
 */
final class AdvertisingMediumCalculationMethodAdminWriter
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly AdvertisingMediumCalculationMethodImpactPreviewService $impact,
        private readonly CalculationMethodAssignmentActivationGuard $activationGuard,
        private readonly CatalogLifecycleLockCoordinator $locks,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array{
     *     medium: AdvertisingMedium,
     *     has_changes: bool,
     *     message: string,
     *     preview: array<string, mixed>
     * }
     */
    public function replace(AdvertisingMedium $medium, array $payload, User $actor): array
    {
        return DB::transaction(function () use ($medium, $payload, $actor): array {
            // 1) Medium, 2) Kategorie
            $lockedBundle = $this->locks->lockMediumAndCategories((int) $medium->id);
            $lockedMedium = $lockedBundle['medium'];
            $this->assertMediumLock($lockedMedium, (int) $payload['lock_version']);

            /** @var AdvertisingCategory|null $lockedCategory */
            $lockedCategory = $lockedBundle['categories']->firstWhere('id', (int) $lockedMedium->category_id);
            if ($lockedCategory === null) {
                $lockedCategory = $this->locks->lockCategory((int) $lockedMedium->category_id);
            }

            $normalized = $this->impact->normalizeDesiredState($payload);

            $preLockMediumAssignmentMethodIds = AdvertisingMediumCalculationMethod::query()
                ->where('advertising_medium_id', $lockedMedium->id)
                ->orderBy('id')
                ->pluck('calculation_method_id')
                ->map(static fn ($id): int => (int) $id)
                ->all();

            $preLockCategoryAssignmentMethodIds = AdvertisingCategoryCalculationMethod::query()
                ->where('advertising_category_id', $lockedCategory->id)
                ->orderBy('id')
                ->pluck('calculation_method_id')
                ->map(static fn ($id): int => (int) $id)
                ->all();

            $methodIds = $preLockMediumAssignmentMethodIds;
            foreach ($normalized['assignments'] as $row) {
                $methodIds[] = (int) $row['calculation_method_id'];
            }
            if ($lockedMedium->default_calculation_method_id !== null) {
                $methodIds[] = (int) $lockedMedium->default_calculation_method_id;
            }
            if ($normalized['default_calculation_method_id'] !== null) {
                $methodIds[] = (int) $normalized['default_calculation_method_id'];
            }
            if ($lockedCategory->default_calculation_method_id !== null) {
                $methodIds[] = (int) $lockedCategory->default_calculation_method_id;
            }
            foreach ($preLockCategoryAssignmentMethodIds as $id) {
                $methodIds[] = $id;
            }

            // 3) Methoden gemeinsam ID ASC
            $lockedMethods = $this->activationGuard->lockMethodsByIdsAsc($methodIds);

            // 4) Kategorie-Assignments ID ASC
            /** @var Collection<int, AdvertisingCategoryCalculationMethod> $lockedCategoryAssignments */
            $lockedCategoryAssignments = AdvertisingCategoryCalculationMethod::query()
                ->where('advertising_category_id', $lockedCategory->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $lockedCategory->setRelation('calculationMethodAssignments', $lockedCategoryAssignments);
            if ($lockedCategory->default_calculation_method_id !== null) {
                $lockedCategory->setRelation(
                    'defaultCalculationMethod',
                    $lockedMethods->get((int) $lockedCategory->default_calculation_method_id),
                );
            }

            // 5) Medium-Assignments ID ASC
            /** @var Collection<int, AdvertisingMediumCalculationMethod> $lockedAssignmentRows */
            $lockedAssignmentRows = AdvertisingMediumCalculationMethod::query()
                ->where('advertising_medium_id', $lockedMedium->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy(static fn (AdvertisingMediumCalculationMethod $row): int => (int) $row->calculation_method_id);

            $lockedMedium->setRelation('category', $lockedCategory);
            $lockedMedium->setRelation(
                'calculationMethodAssignments',
                $lockedAssignmentRows->values(),
            );
            if ($lockedMedium->default_calculation_method_id !== null) {
                $lockedMedium->setRelation(
                    'defaultCalculationMethod',
                    $lockedMethods->get((int) $lockedMedium->default_calculation_method_id),
                );
            }

            $preview = $this->impact->preview($lockedMedium, $payload);
            $this->impact->assertFingerprint($preview, (string) $payload['fingerprint']);

            if (! $preview['can_proceed']) {
                $this->throwBookabilityBlocker($preview);
            }

            if (! ($preview['has_changes'] ?? false)) {
                return [
                    'medium' => $lockedMedium,
                    'has_changes' => false,
                    'message' => 'Keine Änderungen',
                    'preview' => $preview,
                ];
            }

            $this->assertDesiredActiveAgainstLockedMethods($normalized, $lockedMethods);

            $effective = $this->impact->buildEffectiveAssignments(
                $lockedAssignmentRows,
                $normalized,
                $lockedMethods,
            );
            $this->impact->assertStoredDefaultMembership(
                $normalized['default_calculation_method_id'],
                $effective,
                $lockedMethods,
            );
            if ($normalized['calculation_method_mode'] === CalculationMethodMode::Override) {
                $this->impact->assertOverrideDefaultValid(
                    $normalized['default_calculation_method_id'],
                    $effective,
                    $lockedMethods,
                );
            }

            $evaluation = $this->impact->evaluateBookability(
                $lockedMedium,
                $lockedCategory,
                $normalized,
                $effective,
                $lockedMethods,
            );
            if ($lockedMedium->is_active
                && $evaluation['bookable_before'] === true
                && $evaluation['bookable_after'] !== true
            ) {
                $reason = is_string($evaluation['reason_after'] ?? null)
                    ? $evaluation['reason_after']
                    : 'nach der Konfiguration nicht mehr buchbar';
                throw ValidationException::withMessages([
                    'assignments' => 'Das bisher buchbare Werbemittel wäre nach dem Desired State nicht mehr buchbar: '.$reason,
                ]);
            }

            $before = $this->auditSnapshot($lockedMedium, $lockedAssignmentRows, $evaluation, true);

            foreach ($effective as $row) {
                if ($row->willCreate) {
                    /** @var CalculationMethod $method */
                    $method = $lockedMethods->get($row->calculationMethodId);
                    $this->activationGuard->assertAllowsActiveAssignment($method);

                    $created = new AdvertisingMediumCalculationMethod;
                    $created->advertising_medium_id = (int) $lockedMedium->id;
                    $created->calculation_method_id = $row->calculationMethodId;
                    $created->is_active = true;
                    $created->sort = $row->sort;
                    $created->setAttribute('engine_profile_key', null);
                    $created->lock_version = 1;
                    $created->save();

                    continue;
                }

                /** @var AdvertisingMediumCalculationMethod|null $existing */
                $existing = $lockedAssignmentRows->get($row->calculationMethodId);
                if ($existing === null || ! $row->willMutate) {
                    continue;
                }

                if ($row->isActive && ! $existing->is_active) {
                    /** @var CalculationMethod $method */
                    $method = $lockedMethods->get($row->calculationMethodId);
                    $this->activationGuard->assertAllowsActiveAssignment($method);
                }

                $existing->is_active = $row->isActive;
                $existing->sort = $row->sort;
                // engine_profile_key bewusst unverändert lassen
                $existing->lock_version = (int) $existing->lock_version + 1;
                $existing->save();
            }

            $lockedMedium->calculation_method_mode = $normalized['calculation_method_mode'];
            $lockedMedium->default_calculation_method_id = $normalized['default_calculation_method_id'];
            $lockedMedium->lock_version = (int) $lockedMedium->lock_version + 1;
            $lockedMedium->save();

            $afterAssignments = AdvertisingMediumCalculationMethod::query()
                ->where('advertising_medium_id', $lockedMedium->id)
                ->orderBy('id')
                ->get()
                ->keyBy(static fn (AdvertisingMediumCalculationMethod $row): int => (int) $row->calculation_method_id);

            $this->audit->record(
                $lockedMedium,
                'advertising_medium.calculation_methods_replaced',
                $actor,
                $before,
                array_merge($this->auditSnapshot($lockedMedium, $afterAssignments, $evaluation, false), [
                    'fingerprint' => $preview['fingerprint'],
                ]),
            );

            return [
                'medium' => $lockedMedium->fresh() ?? $lockedMedium,
                'has_changes' => true,
                'message' => 'Berechnungsmethoden gespeichert.',
                'preview' => $preview,
            ];
        });
    }

    /**
     * @param  array{assignments: list<array{calculation_method_id: int, is_active: bool}>}  $normalized
     * @param  Collection<int, CalculationMethod>  $lockedMethods
     */
    private function assertDesiredActiveAgainstLockedMethods(array $normalized, Collection $lockedMethods): void
    {
        foreach ($normalized['assignments'] as $row) {
            if (! $row['is_active']) {
                continue;
            }
            /** @var CalculationMethod|null $method */
            $method = $lockedMethods->get($row['calculation_method_id']);
            if ($method === null) {
                throw ValidationException::withMessages([
                    'calculation_method_id' => 'Die Berechnungsmethode wurde nicht gefunden.',
                ]);
            }
            $this->activationGuard->assertAllowsActiveAssignment($method);
        }
    }

    /**
     * @param  Collection<int, AdvertisingMediumCalculationMethod>  $assignments
     * @param  array<string, mixed>  $evaluation
     * @return array<string, mixed>
     */
    private function auditSnapshot(
        AdvertisingMedium $medium,
        Collection $assignments,
        array $evaluation,
        bool $before,
    ): array {
        return [
            'id' => (int) $medium->id,
            'lock_version' => (int) $medium->lock_version,
            'calculation_method_mode' => $medium->calculation_method_mode->value,
            'default_calculation_method_id' => $medium->default_calculation_method_id !== null
                ? (int) $medium->default_calculation_method_id
                : null,
            'assignments' => $assignments->sortBy('id')->values()->map(
                static fn (AdvertisingMediumCalculationMethod $row): array => [
                    'id' => (int) $row->id,
                    'calculation_method_id' => (int) $row->calculation_method_id,
                    'is_active' => (bool) $row->is_active,
                    'sort' => (int) $row->sort,
                    'engine_profile_key' => $row->engine_profile_key,
                    'lock_version' => (int) $row->lock_version,
                ],
            )->all(),
            'effective_source' => $before
                ? ($evaluation['source_before'] ?? null)
                : ($evaluation['source_after'] ?? null),
            'bookable' => $before
                ? ($evaluation['bookable_before'] ?? null)
                : ($evaluation['bookable_after'] ?? null),
        ];
    }

    /**
     * @param  array<string, mixed>  $preview
     */
    private function throwBookabilityBlocker(array $preview): never
    {
        /** @var list<array{message?: string}> $reasons */
        $reasons = is_array($preview['blocking_reasons'] ?? null)
            ? $preview['blocking_reasons']
            : [];
        $message = $reasons[0]['message'] ?? 'Der Desired State würde das bisher buchbare Werbemittel unbuchbar machen.';

        throw ValidationException::withMessages([
            'assignments' => $message,
        ]);
    }

    private function assertMediumLock(AdvertisingMedium $medium, int $expected): void
    {
        if ((int) $medium->lock_version !== $expected) {
            throw new CatalogAdminConflictException(
                'Das Werbemittel wurde parallel geändert. Bitte neu laden und erneut prüfen.',
            );
        }
    }
}
