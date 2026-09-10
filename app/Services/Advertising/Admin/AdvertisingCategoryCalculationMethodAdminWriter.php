<?php

namespace App\Services\Advertising\Admin;

use App\Exceptions\CatalogAdminConflictException;
use App\Models\AdvertisingCategory;
use App\Models\AdvertisingCategoryCalculationMethod;
use App\Models\CalculationMethod;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\Advertising\CalculationMethodAssignmentActivationGuard;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ADV-001c3b2: atomarer Desired-State-Apply für Kategorie-Methodenzuordnungen und Default.
 *
 * Lock-Reihenfolge: Kategorie → Methoden ID ASC → Kategorie-Assignments ID ASC.
 * Keine Medium-Parent-FOR-UPDATE.
 */
final class AdvertisingCategoryCalculationMethodAdminWriter
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly AdvertisingCategoryCalculationMethodImpactPreviewService $impact,
        private readonly CalculationMethodAssignmentActivationGuard $activationGuard,
        private readonly CatalogLifecycleLockCoordinator $locks,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array{
     *     category: AdvertisingCategory,
     *     has_changes: bool,
     *     message: string,
     *     preview: array<string, mixed>
     * }
     */
    public function replace(AdvertisingCategory $category, array $payload, User $actor): array
    {
        return DB::transaction(function () use ($category, $payload, $actor): array {
            // 1) Kategorie als Aggregate Root
            $lockedCategory = $this->locks->lockCategory((int) $category->id);
            $this->assertCategoryLock($lockedCategory, (int) $payload['lock_version']);

            $normalized = $this->impact->normalizeDesiredState($payload);

            // Method-IDs vor Method-Locks bestimmen (Assignments noch ohne FOR UPDATE lesen –
            // Membership wird nach Assignment-Locks erneut unter Sperre ausgewertet).
            $preLockAssignmentMethodIds = AdvertisingCategoryCalculationMethod::query()
                ->where('advertising_category_id', $lockedCategory->id)
                ->orderBy('id')
                ->pluck('calculation_method_id')
                ->map(static fn ($id): int => (int) $id)
                ->all();

            $methodIds = $preLockAssignmentMethodIds;
            foreach ($normalized['assignments'] as $row) {
                $methodIds[] = (int) $row['calculation_method_id'];
            }
            if ($lockedCategory->default_calculation_method_id !== null) {
                $methodIds[] = (int) $lockedCategory->default_calculation_method_id;
            }
            if ($normalized['default_calculation_method_id'] !== null) {
                $methodIds[] = (int) $normalized['default_calculation_method_id'];
            }

            // 2) Methoden gemeinsam ID ASC
            $lockedMethods = $this->activationGuard->lockMethodsByIdsAsc($methodIds);

            // 3) Kategorie-Assignments ID ASC
            /** @var Collection<int, AdvertisingCategoryCalculationMethod> $lockedAssignmentRows */
            $lockedAssignmentRows = AdvertisingCategoryCalculationMethod::query()
                ->where('advertising_category_id', $lockedCategory->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy(static fn (AdvertisingCategoryCalculationMethod $row): int => (int) $row->calculation_method_id);

            // Preview/Fingerprint erst unter vollständiger Lock-Menge
            $preview = $this->impact->preview($lockedCategory, $payload);
            $this->impact->assertFingerprint($preview, (string) $payload['fingerprint']);

            if (! $preview['can_proceed']) {
                $this->throwBookabilityBlocker($preview);
            }

            if (! ($preview['has_changes'] ?? false)) {
                return [
                    'category' => $lockedCategory,
                    'has_changes' => false,
                    'message' => 'Keine Änderungen',
                    'preview' => $preview,
                ];
            }

            $this->assertDesiredActiveAgainstLockedMethods($normalized, $lockedMethods);

            $effective = $this->impact->buildEffectiveAssignments(
                $lockedCategory,
                $lockedAssignmentRows,
                $normalized,
                $lockedMethods,
            );
            $this->impact->assertDefaultValid(
                $normalized['default_calculation_method_id'],
                $effective,
                $lockedMethods,
            );

            // Bookability unter Locks erneut absichern (Schutzmenge)
            $snapshot = $this->impact->buildSnapshot(
                $normalized['default_calculation_method_id'],
                $effective,
                $lockedMethods,
            );
            $mediaImpact = $this->impact->evaluateMediaImpact($lockedCategory, $snapshot);
            foreach ($mediaImpact['protected'] as $row) {
                if ($row['bookable_after'] === true) {
                    continue;
                }
                $reason = is_string($row['unbookable_reason_after'] ?? null)
                    ? $row['unbookable_reason_after']
                    : 'nach der Konfiguration nicht mehr buchbar';
                throw ValidationException::withMessages([
                    'assignments' => 'Das bisher buchbare Werbemittel „'.$row['name'].'“ ('
                        .$row['code'].') wäre nach dem Desired State nicht mehr buchbar: '.$reason,
                ]);
            }

            $before = $this->auditSnapshot($lockedCategory, $lockedAssignmentRows);

            foreach ($effective as $row) {
                if ($row->willCreate) {
                    /** @var CalculationMethod $method */
                    $method = $lockedMethods->get($row->calculationMethodId);
                    $this->activationGuard->assertAllowsActiveAssignment($method);

                    $created = new AdvertisingCategoryCalculationMethod;
                    $created->advertising_category_id = (int) $lockedCategory->id;
                    $created->calculation_method_id = $row->calculationMethodId;
                    $created->is_active = true;
                    $created->sort = $row->sort;
                    $created->setAttribute('engine_profile_key', null);
                    $created->lock_version = 1;
                    $created->save();

                    continue;
                }

                /** @var AdvertisingCategoryCalculationMethod|null $existing */
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

            $lockedCategory->default_calculation_method_id = $normalized['default_calculation_method_id'];
            $lockedCategory->lock_version = (int) $lockedCategory->lock_version + 1;
            $lockedCategory->save();

            $afterAssignments = AdvertisingCategoryCalculationMethod::query()
                ->where('advertising_category_id', $lockedCategory->id)
                ->orderBy('id')
                ->get()
                ->keyBy(static fn (AdvertisingCategoryCalculationMethod $row): int => (int) $row->calculation_method_id);

            $this->audit->record(
                $lockedCategory,
                'advertising_category.calculation_methods_replaced',
                $actor,
                $before,
                array_merge($this->auditSnapshot($lockedCategory, $afterAssignments), [
                    'impact_summary' => [
                        'protected_inherit_media' => $preview['protected_inherit_media'] ?? [],
                        'override_media_count' => $preview['override_media_count'] ?? 0,
                        'blocking_reasons' => $preview['blocking_reasons'] ?? [],
                        'effective_assignments' => $preview['effective_assignments'] ?? [],
                    ],
                    'fingerprint' => $preview['fingerprint'],
                ]),
            );

            return [
                'category' => $lockedCategory->fresh() ?? $lockedCategory,
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
     * @param  Collection<int, AdvertisingCategoryCalculationMethod>  $assignments
     * @return array<string, mixed>
     */
    private function auditSnapshot(AdvertisingCategory $category, Collection $assignments): array
    {
        return [
            'id' => (int) $category->id,
            'lock_version' => (int) $category->lock_version,
            'default_calculation_method_id' => $category->default_calculation_method_id !== null
                ? (int) $category->default_calculation_method_id
                : null,
            'assignments' => $assignments->sortBy('id')->values()->map(
                static fn (AdvertisingCategoryCalculationMethod $row): array => [
                    'id' => (int) $row->id,
                    'calculation_method_id' => (int) $row->calculation_method_id,
                    'is_active' => (bool) $row->is_active,
                    'sort' => (int) $row->sort,
                    'engine_profile_key' => $row->engine_profile_key,
                    'lock_version' => (int) $row->lock_version,
                ],
            )->all(),
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
        $message = $reasons[0]['message'] ?? 'Der Desired State würde bisher buchbare Werbemittel unbuchbar machen.';

        throw ValidationException::withMessages([
            'assignments' => $message,
        ]);
    }

    private function assertCategoryLock(AdvertisingCategory $category, int $expected): void
    {
        if ((int) $category->lock_version !== $expected) {
            throw new CatalogAdminConflictException(
                'Die Oberkategorie wurde parallel geändert. Bitte neu laden und erneut prüfen.',
            );
        }
    }
}
