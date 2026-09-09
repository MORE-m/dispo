<?php

namespace App\Services\DynamicField\Assignment;

use App\Enums\FieldAppliesTo;
use App\Enums\FieldSetAssignmentTargetLayer;
use App\Enums\FieldSetVersionStatus;
use App\Exceptions\FieldSetAssignmentConflictException;
use App\Models\AdvertisingCategory;
use App\Models\AdvertisingMedium;
use App\Models\FieldSet;
use App\Models\FieldSetAssignment;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\DynamicField\Admin\AdminFieldSetCatalog;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * DF-3.3a1 / DF-3.3b / DYN-002 / AUD-001: Assignment CRUD, Activate/Deactivate.
 * Runtime-Wirkung freier Sets erfolgt über Freeze (a2α/β); diese Klasse bleibt die Admin-Autorität.
 */
final class FieldSetAssignmentAdminWriter
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly FieldSetAssignmentPreviewService $preview,
        private readonly AssignmentConfigurationLockCoordinator $locks,
    ) {}

    /**
     * @param  array{
     *     field_set_id: int,
     *     target_layer: FieldSetAssignmentTargetLayer|string,
     *     advertising_category_id?: int|null,
     *     advertising_medium_id?: int|null,
     *     applies_to_process: FieldAppliesTo|string,
     *     sort?: int
     * }  $payload
     */
    public function create(array $payload, User $actor): FieldSetAssignment
    {
        return DB::transaction(function () use ($payload, $actor): FieldSetAssignment {
            $normalized = $this->normalizeStructuralPayload($payload);
            $this->assertAssignableFieldSet($normalized['field_set_id'], $normalized['applies_to_process']);
            // PO-33b-1: neue Bindungen nur auf aktive Katalogziele.
            $this->assertSelectableActiveTargets($normalized);

            try {
                $assignment = new FieldSetAssignment;
                $assignment->field_set_id = $normalized['field_set_id'];
                $assignment->target_layer = $normalized['target_layer'];
                $assignment->advertising_category_id = $normalized['advertising_category_id'];
                $assignment->advertising_medium_id = $normalized['advertising_medium_id'];
                $assignment->target_identity = $normalized['target_identity'];
                $assignment->applies_to_process = $normalized['applies_to_process'];
                $assignment->sort = $normalized['sort'];
                $assignment->is_active = false;
                $assignment->lock_version = 1;
                $assignment->save();
            } catch (UniqueConstraintViolationException $exception) {
                throw $this->duplicateConflict($exception);
            } catch (QueryException $exception) {
                if ($this->isUniqueViolation($exception)) {
                    throw $this->duplicateConflict($exception);
                }
                if ($this->isXorViolation($exception)) {
                    throw ValidationException::withMessages([
                        'target_layer' => 'Ziel-Ebene und Ziel-Referenzen sind inkonsistent.',
                    ]);
                }
                throw $exception;
            }

            $this->audit->record(
                $assignment,
                'field_set_assignment.created',
                $actor,
                null,
                $this->auditPayload($assignment),
            );

            return $assignment->fresh(['fieldSet', 'advertisingCategory', 'advertisingMedium']) ?? $assignment;
        });
    }

    /**
     * @param  array{
     *     field_set_id?: int,
     *     target_layer?: FieldSetAssignmentTargetLayer|string,
     *     advertising_category_id?: int|null,
     *     advertising_medium_id?: int|null,
     *     applies_to_process?: FieldAppliesTo|string,
     *     sort?: int,
     *     lock_version: int
     * }  $payload
     */
    public function update(FieldSetAssignment $assignment, array $payload, User $actor): FieldSetAssignment
    {
        return DB::transaction(function () use ($assignment, $payload, $actor): FieldSetAssignment {
            /** @var FieldSetAssignment $locked */
            $locked = FieldSetAssignment::query()->whereKey($assignment->id)->lockForUpdate()->firstOrFail();
            $this->assertLock($locked, (int) $payload['lock_version']);

            if ($locked->is_active) {
                throw ValidationException::withMessages([
                    'assignment' => 'Aktive Assignments müssen vor strukturellen Änderungen deaktiviert werden.',
                ]);
            }

            $merged = [
                'field_set_id' => $payload['field_set_id'] ?? $locked->field_set_id,
                'target_layer' => $payload['target_layer'] ?? $locked->target_layer,
                'advertising_category_id' => array_key_exists('advertising_category_id', $payload)
                    ? $payload['advertising_category_id']
                    : $locked->advertising_category_id,
                'advertising_medium_id' => array_key_exists('advertising_medium_id', $payload)
                    ? $payload['advertising_medium_id']
                    : $locked->advertising_medium_id,
                'applies_to_process' => $payload['applies_to_process'] ?? $locked->applies_to_process,
                'sort' => $payload['sort'] ?? $locked->sort,
            ];
            $normalized = $this->normalizeStructuralPayload($merged);
            $this->assertAssignableFieldSet($normalized['field_set_id'], $normalized['applies_to_process']);

            $targetChanged = $locked->target_layer !== $normalized['target_layer']
                || (int) ($locked->advertising_category_id ?? 0) !== (int) ($normalized['advertising_category_id'] ?? 0)
                || (int) ($locked->advertising_medium_id ?? 0) !== (int) ($normalized['advertising_medium_id'] ?? 0);
            // PO-33b-1: Zielwechsel nur auf aktive Katalogziele; bestehende deaktivierte Ziele bleiben les-/haltbar.
            if ($targetChanged) {
                $this->assertSelectableActiveTargets($normalized);
            }

            $before = $this->auditPayload($locked);

            try {
                $locked->field_set_id = $normalized['field_set_id'];
                $locked->target_layer = $normalized['target_layer'];
                $locked->advertising_category_id = $normalized['advertising_category_id'];
                $locked->advertising_medium_id = $normalized['advertising_medium_id'];
                $locked->target_identity = $normalized['target_identity'];
                $locked->applies_to_process = $normalized['applies_to_process'];
                $locked->sort = $normalized['sort'];
                $locked->lock_version = $locked->lock_version + 1;
                $locked->save();
            } catch (UniqueConstraintViolationException $exception) {
                throw $this->duplicateConflict($exception);
            } catch (QueryException $exception) {
                if ($this->isUniqueViolation($exception)) {
                    throw $this->duplicateConflict($exception);
                }
                if ($this->isXorViolation($exception)) {
                    throw ValidationException::withMessages([
                        'target_layer' => 'Ziel-Ebene und Ziel-Referenzen sind inkonsistent.',
                    ]);
                }
                throw $exception;
            }

            $this->audit->record(
                $locked,
                'field_set_assignment.updated',
                $actor,
                $before,
                $this->auditPayload($locked),
            );

            return $locked->fresh(['fieldSet', 'advertisingCategory', 'advertisingMedium']) ?? $locked;
        });
    }

    /**
     * @param  array{lock_version: int, fingerprint: string}  $payload
     */
    public function activate(FieldSetAssignment $assignment, array $payload, User $actor): FieldSetAssignment
    {
        return DB::transaction(function () use ($assignment, $payload, $actor): FieldSetAssignment {
            $expectedFingerprint = (string) $payload['fingerprint'];
            $expectedLock = (int) $payload['lock_version'];

            $locked = $this->locks->lockForAssignment($assignment);

            $this->assertLock($locked, $expectedLock);

            if ($locked->is_active) {
                throw ValidationException::withMessages([
                    'assignment' => 'Das Assignment ist bereits aktiv.',
                ]);
            }

            // 1–2: Preview + Fingerprint erst nach allen Locks
            $previews = $this->previewAffectedContexts($locked, asCandidate: true);
            $canonicalFingerprint = $this->canonicalActivationFingerprint($previews);

            // 3–4: Drift immer 409 – auch wenn Konflikte entstanden sind
            if (! hash_equals($expectedFingerprint, $canonicalFingerprint)) {
                throw new FieldSetAssignmentConflictException(
                    'Preview-Fingerprint veraltet',
                );
            }

            // 5–6: Konflikte nur bei identischem Fingerprint
            foreach ($previews as $preview) {
                if ($preview['has_blocking_conflicts']) {
                    throw ValidationException::withMessages([
                        'assignment' => 'Aktivierung wegen blockierender Konflikte abgelehnt.',
                        'conflicts' => array_values(array_map(
                            static fn (array $conflict): string => (string) $conflict['message'],
                            $preview['conflicts'],
                        )),
                    ]);
                }
            }

            $before = $this->auditPayload($locked);
            $locked->is_active = true;
            $locked->lock_version = $locked->lock_version + 1;
            $locked->save();

            $after = $this->auditPayload($locked);
            $after['preview_fingerprint'] = $expectedFingerprint;

            $this->audit->record(
                $locked,
                'field_set_assignment.activated',
                $actor,
                $before,
                $after,
            );

            return $locked->fresh(['fieldSet', 'advertisingCategory', 'advertisingMedium']) ?? $locked;
        });
    }

    /**
     * @param  array{lock_version: int}  $payload
     */
    public function deactivate(FieldSetAssignment $assignment, array $payload, User $actor): FieldSetAssignment
    {
        return DB::transaction(function () use ($assignment, $payload, $actor): FieldSetAssignment {
            $locked = $this->locks->lockForAssignment($assignment);
            $this->assertLock($locked, (int) $payload['lock_version']);

            if (! $locked->is_active) {
                throw ValidationException::withMessages([
                    'assignment' => 'Das Assignment ist bereits inaktiv.',
                ]);
            }

            $before = $this->auditPayload($locked);
            $locked->is_active = false;
            $locked->lock_version = $locked->lock_version + 1;
            $locked->save();

            $this->audit->record(
                $locked,
                'field_set_assignment.deactivated',
                $actor,
                $before,
                $this->auditPayload($locked),
            );

            return $locked->fresh(['fieldSet', 'advertisingCategory', 'advertisingMedium']) ?? $locked;
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function previewAffectedContexts(FieldSetAssignment $assignment, bool $asCandidate): array
    {
        $processes = $this->locks->processesFor($assignment->applies_to_process);
        $contexts = $this->locks->affectedContexts($assignment);
        $results = [];
        $candidateId = $asCandidate ? $assignment->id : null;

        foreach ($processes as $process) {
            foreach ($contexts as $context) {
                $results[] = $this->preview->preview([
                    'process' => $process,
                    'scope' => $context['scope'],
                    'advertising_category_id' => $context['advertising_category_id'],
                    'advertising_medium_id' => $context['advertising_medium_id'],
                    'candidate_assignment_id' => $candidateId,
                    'strict' => true,
                ]);
            }
        }

        return $results;
    }

    /**
     * @param  list<array<string, mixed>>  $previews
     */
    public function canonicalActivationFingerprint(array $previews): string
    {
        $payload = [];
        foreach ($previews as $preview) {
            $payload[] = [
                'process' => $preview['process'],
                'scope' => $preview['scope'],
                'advertising_category_id' => $preview['advertising_category_id'],
                'advertising_medium_id' => $preview['advertising_medium_id'],
                'fingerprint' => $preview['fingerprint'],
                'has_blocking_conflicts' => $preview['has_blocking_conflicts'],
            ];
        }

        usort($payload, static function (array $a, array $b): int {
            return implode('|', [
                (string) $a['process'],
                (string) $a['scope'],
                (string) ($a['advertising_category_id'] ?? ''),
                (string) ($a['advertising_medium_id'] ?? ''),
            ]) <=> implode('|', [
                (string) $b['process'],
                (string) $b['scope'],
                (string) ($b['advertising_category_id'] ?? ''),
                (string) ($b['advertising_medium_id'] ?? ''),
            ]);
        });

        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{
     *     field_set_id: int,
     *     target_layer: FieldSetAssignmentTargetLayer,
     *     advertising_category_id: int|null,
     *     advertising_medium_id: int|null,
     *     target_identity: string,
     *     applies_to_process: FieldAppliesTo,
     *     sort: int
     * }
     */
    private function normalizeStructuralPayload(array $payload): array
    {
        $layer = $payload['target_layer'] instanceof FieldSetAssignmentTargetLayer
            ? $payload['target_layer']
            : FieldSetAssignmentTargetLayer::from((string) $payload['target_layer']);

        $process = $payload['applies_to_process'] instanceof FieldAppliesTo
            ? $payload['applies_to_process']
            : FieldAppliesTo::from((string) $payload['applies_to_process']);

        $categoryId = $this->nullablePositiveInt($payload['advertising_category_id'] ?? null);
        $mediumId = $this->nullablePositiveInt($payload['advertising_medium_id'] ?? null);

        match ($layer) {
            FieldSetAssignmentTargetLayer::Global => $this->assertGlobalTargets($categoryId, $mediumId),
            FieldSetAssignmentTargetLayer::AdvertisingCategory => $this->assertCategoryTargets($categoryId, $mediumId),
            FieldSetAssignmentTargetLayer::AdvertisingMedium => $this->assertMediumTargets($categoryId, $mediumId),
        };

        if ($layer === FieldSetAssignmentTargetLayer::AdvertisingCategory) {
            AdvertisingCategory::query()->whereKey($categoryId)->firstOrFail();
        }
        if ($layer === FieldSetAssignmentTargetLayer::AdvertisingMedium) {
            AdvertisingMedium::query()->whereKey($mediumId)->firstOrFail();
        }

        return [
            'field_set_id' => (int) $payload['field_set_id'],
            'target_layer' => $layer,
            'advertising_category_id' => $categoryId,
            'advertising_medium_id' => $mediumId,
            'target_identity' => FieldSetAssignment::buildTargetIdentity($layer, $categoryId, $mediumId),
            'applies_to_process' => $process,
            'sort' => max(0, (int) ($payload['sort'] ?? 0)),
        ];
    }

    private function assertAssignableFieldSet(int $fieldSetId, FieldAppliesTo $process): void
    {
        /** @var FieldSet $fieldSet */
        $fieldSet = FieldSet::query()->whereKey($fieldSetId)->lockForUpdate()->firstOrFail();
        $this->assertAssignableFieldSetModel($fieldSet, $process);
    }

    private function assertAssignableFieldSetModel(FieldSet $fieldSet, FieldAppliesTo $process): void
    {
        if ($fieldSet->is_system || AdminFieldSetCatalog::isCoreKey($fieldSet->key)) {
            throw ValidationException::withMessages([
                'field_set_id' => 'Core-Feldsets dürfen keine Assignment-Zeilen erhalten.',
            ]);
        }

        if (! $fieldSet->is_assignable) {
            throw ValidationException::withMessages([
                'field_set_id' => 'Nur assignierbare Feldsets können zugeordnet werden.',
            ]);
        }

        if ($fieldSet->active_version_id === null) {
            throw ValidationException::withMessages([
                'field_set_id' => 'Feldset benötigt eine aktive Version.',
            ]);
        }

        $active = $fieldSet->activeVersion;
        if ($active === null || $active->status !== FieldSetVersionStatus::Active) {
            throw ValidationException::withMessages([
                'field_set_id' => 'Aktive Feldset-Version muss Status active haben.',
            ]);
        }

        if (! $this->processIsSubsetOfFieldSet($process, $fieldSet->applies_to)) {
            throw ValidationException::withMessages([
                'applies_to_process' => 'Assignment-Prozessgültigkeit muss eine Teilmenge der Feldset-Gültigkeit sein.',
            ]);
        }
    }

    private function processIsSubsetOfFieldSet(FieldAppliesTo $assignment, FieldAppliesTo $fieldSet): bool
    {
        return match ($fieldSet) {
            FieldAppliesTo::Calculation => $assignment === FieldAppliesTo::Calculation,
            FieldAppliesTo::DispoOrder => $assignment === FieldAppliesTo::DispoOrder,
            FieldAppliesTo::Both => true,
        };
    }

    /**
     * @param  array{
     *     target_layer: FieldSetAssignmentTargetLayer,
     *     advertising_category_id: int|null,
     *     advertising_medium_id: int|null
     * }  $normalized
     */
    private function assertSelectableActiveTargets(array $normalized): void
    {
        if ($normalized['target_layer'] === FieldSetAssignmentTargetLayer::AdvertisingCategory) {
            $category = AdvertisingCategory::query()
                ->whereKey($normalized['advertising_category_id'])
                ->firstOrFail();
            if (! $category->is_active) {
                throw ValidationException::withMessages([
                    'advertising_category_id' => 'Deaktivierte Oberkategorien können nicht als neues Assignment-Ziel gewählt werden.',
                ]);
            }
        }

        if ($normalized['target_layer'] === FieldSetAssignmentTargetLayer::AdvertisingMedium) {
            $medium = AdvertisingMedium::query()
                ->whereKey($normalized['advertising_medium_id'])
                ->firstOrFail();
            if (! $medium->is_active) {
                throw ValidationException::withMessages([
                    'advertising_medium_id' => 'Deaktivierte Werbemittel können nicht als neues Assignment-Ziel gewählt werden.',
                ]);
            }
        }
    }

    private function assertGlobalTargets(?int $categoryId, ?int $mediumId): void
    {
        if ($categoryId !== null || $mediumId !== null) {
            throw ValidationException::withMessages([
                'target_layer' => 'Globale Assignments dürfen keine Kategorie-/Werbemittel-ID setzen.',
            ]);
        }
    }

    private function assertCategoryTargets(?int $categoryId, ?int $mediumId): void
    {
        if ($categoryId === null) {
            throw ValidationException::withMessages([
                'advertising_category_id' => 'Kategorie-Assignment erfordert eine Kategorie.',
            ]);
        }
        if ($mediumId !== null) {
            throw ValidationException::withMessages([
                'advertising_medium_id' => 'Kategorie-Assignment darf keine Werbemittel-ID setzen.',
            ]);
        }
    }

    private function assertMediumTargets(?int $categoryId, ?int $mediumId): void
    {
        if ($mediumId === null) {
            throw ValidationException::withMessages([
                'advertising_medium_id' => 'Werbemittel-Assignment erfordert ein Werbemittel.',
            ]);
        }
        if ($categoryId !== null) {
            throw ValidationException::withMessages([
                'advertising_category_id' => 'Werbemittel-Assignment darf keine Kategorie-ID setzen (wird über Medium ermittelt).',
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function auditPayload(FieldSetAssignment $assignment): array
    {
        $assignment->loadMissing('fieldSet');

        return [
            'assignment_id' => $assignment->id,
            'field_set_id' => $assignment->field_set_id,
            'field_set_key' => $assignment->fieldSet?->key,
            'target_layer' => $assignment->target_layer->value,
            'advertising_category_id' => $assignment->advertising_category_id,
            'advertising_medium_id' => $assignment->advertising_medium_id,
            'target_identity' => $assignment->target_identity,
            'applies_to_process' => $assignment->applies_to_process->value,
            'sort' => $assignment->sort,
            'is_active' => $assignment->is_active,
            'lock_version' => $assignment->lock_version,
        ];
    }

    private function assertLock(FieldSetAssignment $assignment, int $expected): void
    {
        if ((int) $assignment->lock_version !== $expected) {
            throw new FieldSetAssignmentConflictException(
                'Das Assignment wurde parallel geändert. Bitte neu laden und erneut prüfen.',
            );
        }
    }

    private function duplicateConflict(\Throwable $previous): ValidationException
    {
        return ValidationException::withMessages([
            'assignment' => 'Für dieses Feldset, denselben Prozess und denselben Zielkontext existiert bereits ein Assignment.',
        ]);
    }

    private function isUniqueViolation(QueryException $exception): bool
    {
        $code = (string) $exception->getCode();
        $message = $exception->getMessage();

        return in_array($code, ['23000', '19', '1062'], true)
            || str_contains($message, 'field_set_assignment_target_unique')
            || str_contains($message, 'UNIQUE constraint failed');
    }

    private function isXorViolation(QueryException $exception): bool
    {
        return str_contains($exception->getMessage(), 'field_set_assignment_target_xor')
            || str_contains($exception->getMessage(), 'CHECK constraint failed');
    }

    private function nullablePositiveInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $int = (int) $value;

        return $int > 0 ? $int : null;
    }
}
