<?php

namespace App\Services\Advertising\Admin;

use App\Enums\CalculationMethodMode;
use App\Enums\EngineCapabilityStatus;
use App\Exceptions\CatalogAdminConflictException;
use App\Models\AdvertisingCategory;
use App\Models\AdvertisingCategoryCalculationMethod;
use App\Models\AdvertisingMedium;
use App\Models\CalculationMethod;
use App\Support\Advertising\AdvertisingMediumLiveBookability;
use App\Support\Advertising\CategoryMethodCatalogSnapshot;
use App\Support\Calculation\EngineProfileRegistry;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * ADV-001c3b2: gemeinsame Normalisierung, Preview und Fingerprint für
 * Kategorie-Methoden-Desired-State (Preview und Apply identisch).
 */
final class AdvertisingCategoryCalculationMethodImpactPreviewService
{
    public const ACTION_CATEGORY_CALCULATION_METHODS_REPLACE = 'category_calculation_methods_replace';

    /** @var list<string> */
    public const PROHIBITED_TOP_LEVEL = [
        'key',
        'engine_profile_key',
        'algorithm_version',
        'registry_status',
        'pair_status',
        'current_released_version',
        'is_active',
        'calculation_method_mode',
        'medium_assignments',
        'medium_defaults',
        'default_calculation_method_key',
        'handler',
        'service',
        'php_class',
        'class',
    ];

    /** @var list<string> */
    public const ALLOWED_ASSIGNMENT_KEYS = [
        'calculation_method_id',
        'is_active',
        'sort',
    ];

    public function __construct(
        private readonly AdvertisingMediumLiveBookability $bookability,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function preview(AdvertisingCategory $category, array $payload): array
    {
        $normalized = $this->normalizeDesiredState($payload);
        $currentAssignments = $this->loadCurrentAssignments((int) $category->id);
        $methods = $this->loadRelevantMethods($category, $currentAssignments, $normalized);
        $this->assertDesiredActiveMethodsAreGloballyActive($normalized, $methods);
        $effectiveAssignments = $this->buildEffectiveAssignments(
            $category,
            $currentAssignments,
            $normalized,
            $methods,
        );
        $this->assertDefaultValid($normalized['default_calculation_method_id'], $effectiveAssignments, $methods);

        $snapshot = $this->buildSnapshot(
            $normalized['default_calculation_method_id'],
            $effectiveAssignments,
            $methods,
        );
        $mediaImpact = $this->evaluateMediaImpact($category, $snapshot);
        $blocking = $this->bookabilityBlockers($mediaImpact);
        $hasChanges = $this->detectHasChanges($category, $currentAssignments, $normalized, $effectiveAssignments);

        $body = [
            'entity' => 'advertising_category',
            'action' => self::ACTION_CATEGORY_CALCULATION_METHODS_REPLACE,
            'entity_id' => (int) $category->id,
            'lock_version' => (int) $category->lock_version,
            'has_changes' => $hasChanges,
            'can_proceed' => $blocking === [],
            'blocking_reasons' => $blocking,
            'current' => [
                'id' => (int) $category->id,
                'key' => (string) $category->key,
                'is_active' => (bool) $category->is_active,
                'lock_version' => (int) $category->lock_version,
                'default_calculation_method_id' => $category->default_calculation_method_id !== null
                    ? (int) $category->default_calculation_method_id
                    : null,
                'assignments' => $currentAssignments->map(static fn (AdvertisingCategoryCalculationMethod $row): array => [
                    'id' => (int) $row->id,
                    'calculation_method_id' => (int) $row->calculation_method_id,
                    'is_active' => (bool) $row->is_active,
                    'sort' => (int) $row->sort,
                    'engine_profile_key' => $row->engine_profile_key,
                    'lock_version' => (int) $row->lock_version,
                ])->values()->all(),
            ],
            'methods' => $methods->sortBy('id')->values()->map(static fn (CalculationMethod $method): array => [
                'id' => (int) $method->id,
                'key' => (string) $method->key,
                'is_active' => (bool) $method->is_active,
                'lock_version' => (int) $method->lock_version,
            ])->all(),
            'registry_pairs' => $this->registryPairsForAssignments($effectiveAssignments, $methods),
            'desired' => [
                'default_calculation_method_id' => $normalized['default_calculation_method_id'],
                'assignments' => $normalized['assignments'],
            ],
            'effective_assignments' => $effectiveAssignments->map(
                static fn (EffectiveCategoryMethodAssignment $row): array => $row->toPreviewArray(),
            )->values()->all(),
            'protected_inherit_media' => $mediaImpact['protected'],
            'inherit_media' => $mediaImpact['inherit'],
            'override_media_count' => $mediaImpact['override_count'],
            'dependency_note' => 'Bisher buchbare aktive Inherit-Medien müssen nach dem Desired State '
                .'buchbar bleiben. Geplante Methoden dürfen vorbereitet zugeordnet werden. '
                .'Es gibt keine Force-Option.',
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
     * @param  array<string, mixed>  $payload
     * @return array{
     *     default_calculation_method_id: int|null,
     *     assignments: list<array{calculation_method_id: int, is_active: bool, sort: int}>
     * }
     */
    public function normalizeDesiredState(array $payload): array
    {
        $this->assertPayloadClean($payload);

        if (! array_key_exists('assignments', $payload) || ! is_array($payload['assignments'])) {
            throw ValidationException::withMessages([
                'assignments' => 'Die Methodenzuordnungen sind erforderlich.',
            ]);
        }

        $defaultId = $payload['default_calculation_method_id'] ?? null;
        if ($defaultId === '' || $defaultId === false) {
            $defaultId = null;
        }
        if ($defaultId !== null) {
            if (! is_numeric($defaultId) || (int) $defaultId < 1 || (string) (int) $defaultId !== (string) $defaultId) {
                throw ValidationException::withMessages([
                    'default_calculation_method_id' => 'Die Standard-Berechnungsmethode ist ungültig.',
                ]);
            }
            $defaultId = (int) $defaultId;
            if (! CalculationMethod::query()->whereKey($defaultId)->exists()) {
                throw ValidationException::withMessages([
                    'default_calculation_method_id' => 'Die Standard-Berechnungsmethode wurde nicht gefunden.',
                ]);
            }
        }

        $seen = [];
        $assignments = [];
        foreach (array_values($payload['assignments']) as $index => $row) {
            if (! is_array($row)) {
                throw ValidationException::withMessages([
                    "assignments.{$index}" => 'Jede Zuordnung muss ein Objekt sein.',
                ]);
            }

            foreach (array_keys($row) as $key) {
                if (! in_array((string) $key, self::ALLOWED_ASSIGNMENT_KEYS, true)) {
                    throw ValidationException::withMessages([
                        "assignments.{$index}.{$key}" => 'Das Feld „'.$key.'“ darf in Methodenzuordnungen nicht gesetzt werden.',
                    ]);
                }
            }

            if (! array_key_exists('calculation_method_id', $row)
                || ! array_key_exists('is_active', $row)
                || ! array_key_exists('sort', $row)
            ) {
                throw ValidationException::withMessages([
                    "assignments.{$index}" => 'Jede Zuordnung benötigt calculation_method_id, is_active und sort.',
                ]);
            }

            $methodId = $row['calculation_method_id'];
            if (! is_numeric($methodId) || (int) $methodId < 1 || (string) (int) $methodId !== (string) $methodId) {
                throw ValidationException::withMessages([
                    "assignments.{$index}.calculation_method_id" => 'Eine Berechnungsmethode ist erforderlich.',
                ]);
            }
            $methodId = (int) $methodId;

            if (isset($seen[$methodId])) {
                throw ValidationException::withMessages([
                    'assignments' => 'Doppelte Berechnungsmethoden in der Desired-State-Payload sind nicht zulässig.',
                ]);
            }
            $seen[$methodId] = true;

            if (! CalculationMethod::query()->whereKey($methodId)->exists()) {
                throw ValidationException::withMessages([
                    "assignments.{$index}.calculation_method_id" => 'Die Berechnungsmethode wurde nicht gefunden.',
                ]);
            }

            $isActive = $row['is_active'];
            if (! is_bool($isActive)) {
                throw ValidationException::withMessages([
                    "assignments.{$index}.is_active" => 'is_active muss ein Boolean sein.',
                ]);
            }

            $sort = $row['sort'];
            if (! is_numeric($sort) || (int) $sort < 0 || (string) (int) $sort !== (string) $sort) {
                throw ValidationException::withMessages([
                    "assignments.{$index}.sort" => 'Die Sortierung muss eine nicht-negative Ganzzahl sein.',
                ]);
            }

            $assignments[] = [
                'calculation_method_id' => $methodId,
                'is_active' => $isActive,
                'sort' => (int) $sort,
            ];
        }

        usort(
            $assignments,
            static fn (array $a, array $b): int => $a['calculation_method_id'] <=> $b['calculation_method_id'],
        );

        return [
            'default_calculation_method_id' => $defaultId,
            'assignments' => $assignments,
        ];
    }

    /**
     * @param  Collection<int, AdvertisingCategoryCalculationMethod>  $current
     * @param  array{default_calculation_method_id: int|null, assignments: list<array{calculation_method_id: int, is_active: bool, sort: int}>}  $normalized
     * @return Collection<int, CalculationMethod> keyed by id
     */
    public function loadRelevantMethods(
        AdvertisingCategory $category,
        Collection $current,
        array $normalized,
    ): Collection {
        $ids = [];
        foreach ($current as $row) {
            $ids[] = (int) $row->calculation_method_id;
        }
        foreach ($normalized['assignments'] as $row) {
            $ids[] = (int) $row['calculation_method_id'];
        }
        if ($category->default_calculation_method_id !== null) {
            $ids[] = (int) $category->default_calculation_method_id;
        }
        if ($normalized['default_calculation_method_id'] !== null) {
            $ids[] = (int) $normalized['default_calculation_method_id'];
        }

        $ids = array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));
        sort($ids);

        if ($ids === []) {
            return new Collection;
        }

        return CalculationMethod::query()
            ->whereIn('id', $ids)
            ->orderBy('id')
            ->get()
            ->keyBy('id');
    }

    /**
     * @return Collection<int, AdvertisingCategoryCalculationMethod> keyed by calculation_method_id
     */
    public function loadCurrentAssignments(int $categoryId): Collection
    {
        return AdvertisingCategoryCalculationMethod::query()
            ->where('advertising_category_id', $categoryId)
            ->orderBy('id')
            ->get()
            ->keyBy(static fn (AdvertisingCategoryCalculationMethod $row): int => (int) $row->calculation_method_id);
    }

    /**
     * Effektive Desired-Zeilen inkl. Deaktivierung fehlender vorhandener Assignments.
     *
     * @param  Collection<int, AdvertisingCategoryCalculationMethod>  $current
     * @param  array{default_calculation_method_id: int|null, assignments: list<array{calculation_method_id: int, is_active: bool, sort: int}>}  $normalized
     * @param  Collection<int, CalculationMethod>  $methods
     * @return Collection<int, EffectiveCategoryMethodAssignment>
     */
    public function buildEffectiveAssignments(
        AdvertisingCategory $category,
        Collection $current,
        array $normalized,
        Collection $methods,
    ): Collection {
        $desiredByMethod = [];
        foreach ($normalized['assignments'] as $row) {
            $desiredByMethod[(int) $row['calculation_method_id']] = $row;
        }

        $allMethodIds = array_values(array_unique(array_merge(
            $current->keys()->map(static fn ($id): int => (int) $id)->all(),
            array_keys($desiredByMethod),
        )));
        sort($allMethodIds);

        /** @var Collection<int, EffectiveCategoryMethodAssignment> $effective */
        $effective = new Collection;
        foreach ($allMethodIds as $methodId) {
            /** @var AdvertisingCategoryCalculationMethod|null $existing */
            $existing = $current->get($methodId);
            $desired = $desiredByMethod[$methodId] ?? null;

            if ($desired === null) {
                // Fehlende vorhandene Zeile → deaktivieren, nie löschen.
                if ($existing === null) {
                    continue;
                }
                $isActive = false;
                $sort = (int) $existing->sort;
                $willCreate = false;
            } else {
                $isActive = (bool) $desired['is_active'];
                $sort = (int) $desired['sort'];
                $willCreate = $existing === null && $isActive;
                if ($existing === null && ! $isActive) {
                    // Neue inaktive Zeile wird nicht angelegt.
                    continue;
                }
            }

            $engineProfileKey = $existing?->engine_profile_key;
            $willMutate = $willCreate || ($existing !== null && (
                (bool) $existing->is_active !== $isActive
                || (int) $existing->sort !== $sort
            ));

            $effective->push(new EffectiveCategoryMethodAssignment(
                id: $existing !== null ? (int) $existing->id : null,
                calculationMethodId: $methodId,
                isActive: $isActive,
                sort: $sort,
                engineProfileKey: $engineProfileKey,
                lockVersion: $existing !== null ? (int) $existing->lock_version : null,
                willCreate: $willCreate,
                willMutate: $willMutate,
                existing: $existing,
            ));
        }

        return $effective->sortBy(
            static fn (EffectiveCategoryMethodAssignment $row): int => $row->calculationMethodId,
        )->values();
    }

    /**
     * @param  Collection<int, EffectiveCategoryMethodAssignment>  $effective
     * @param  Collection<int, CalculationMethod>  $methods
     */
    public function buildSnapshot(
        ?int $defaultMethodId,
        Collection $effective,
        Collection $methods,
    ): CategoryMethodCatalogSnapshot {
        $assignmentModels = new Collection;
        foreach ($effective as $row) {
            /** @var CalculationMethod|null $method */
            $method = $methods->get($row->calculationMethodId);
            if ($method === null) {
                continue;
            }

            if ($row->existing instanceof AdvertisingCategoryCalculationMethod) {
                $model = $row->existing->replicate();
                $model->id = $row->existing->id;
                $model->exists = true;
            } else {
                $model = new AdvertisingCategoryCalculationMethod;
            }

            $model->calculation_method_id = $row->calculationMethodId;
            $model->is_active = $row->isActive;
            $model->sort = $row->sort;
            $model->setAttribute('engine_profile_key', $row->engineProfileKey);
            $model->setRelation('calculationMethod', $method);
            $assignmentModels->push($model);
        }

        $default = $defaultMethodId !== null ? $methods->get($defaultMethodId) : null;

        return new CategoryMethodCatalogSnapshot(
            $default instanceof CalculationMethod ? $default : null,
            $assignmentModels,
        );
    }

    /**
     * @param  Collection<int, CalculationMethod>  $methods
     * @param  array{assignments: list<array{calculation_method_id: int, is_active: bool}>}  $normalized
     */
    private function assertDesiredActiveMethodsAreGloballyActive(array $normalized, Collection $methods): void
    {
        foreach ($normalized['assignments'] as $row) {
            if (! $row['is_active']) {
                continue;
            }
            /** @var CalculationMethod|null $method */
            $method = $methods->get($row['calculation_method_id']);
            if ($method === null) {
                throw ValidationException::withMessages([
                    'calculation_method_id' => 'Die Berechnungsmethode wurde nicht gefunden.',
                ]);
            }
            if (! $method->is_active) {
                throw ValidationException::withMessages([
                    'calculation_method_id' => 'Einer inaktiven Berechnungsmethode können keine '
                        .'aktiven Zuordnungen zugewiesen werden.',
                ]);
            }
        }
    }

    /**
     * @param  Collection<int, EffectiveCategoryMethodAssignment>  $effective
     * @param  Collection<int, CalculationMethod>  $methods
     */
    public function assertDefaultValid(
        ?int $defaultMethodId,
        Collection $effective,
        Collection $methods,
    ): void {
        if ($defaultMethodId === null) {
            return;
        }

        /** @var CalculationMethod|null $method */
        $method = $methods->get($defaultMethodId);
        if ($method === null) {
            throw ValidationException::withMessages([
                'default_calculation_method_id' => 'Die Standard-Berechnungsmethode wurde nicht gefunden.',
            ]);
        }

        if (! $method->is_active) {
            throw ValidationException::withMessages([
                'default_calculation_method_id' => 'Die Standard-Berechnungsmethode muss global aktiv sein.',
            ]);
        }

        /** @var EffectiveCategoryMethodAssignment|null $assignment */
        $assignment = $effective->first(
            static fn (EffectiveCategoryMethodAssignment $row): bool => $row->calculationMethodId === $defaultMethodId,
        );
        if ($assignment === null || ! $assignment->isActive) {
            throw ValidationException::withMessages([
                'default_calculation_method_id' => 'Die Standard-Berechnungsmethode muss im Desired State aktiv zugeordnet sein.',
            ]);
        }

        $profile = $assignment->engineProfileKey;
        if ($profile === null || trim((string) $profile) === '') {
            throw ValidationException::withMessages([
                'default_calculation_method_id' => 'Die Standard-Berechnungsmethode benötigt ein technisch provisioniertes Profil.',
            ]);
        }

        try {
            EngineProfileRegistry::assertKnownProfile((string) $profile);
            EngineProfileRegistry::assertKnownMethodForProfile((string) $profile, (string) $method->key);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages([
                'default_calculation_method_id' => 'Die Standard-Berechnungsmethode verweist auf ein technisch ungültiges Profil.',
            ]);
        }

        $pairStatus = EngineProfileRegistry::pairStatus((string) $profile, (string) $method->key);
        $version = EngineProfileRegistry::currentReleasedVersion((string) $profile, (string) $method->key);
        if ($pairStatus !== EngineCapabilityStatus::Released || $version === null || trim($version) === '') {
            throw ValidationException::withMessages([
                'default_calculation_method_id' => 'Als Standard ist nur eine freigegebene und ausführbare Berechnungsmethode zulässig.',
            ]);
        }
    }

    /**
     * @return array{
     *     protected: list<array<string, mixed>>,
     *     inherit: list<array<string, mixed>>,
     *     override_count: int
     * }
     */
    public function evaluateMediaImpact(
        AdvertisingCategory $category,
        CategoryMethodCatalogSnapshot $snapshot,
    ): array {
        $media = AdvertisingMedium::query()
            ->where('category_id', $category->id)
            ->orderBy('id')
            ->with([
                'category',
                'defaultCalculationMethod',
                'category.defaultCalculationMethod',
                'calculationMethodAssignments.calculationMethod',
                'category.calculationMethodAssignments.calculationMethod',
            ])
            ->get();

        // Ensure live category relations match DB for "before" evaluation.
        $category->loadMissing([
            'defaultCalculationMethod',
            'calculationMethodAssignments.calculationMethod',
        ]);

        $inherit = [];
        $protected = [];
        $overrideCount = 0;

        foreach ($media as $medium) {
            $medium->setRelation('category', $category);

            if ($medium->calculation_method_mode === CalculationMethodMode::Override) {
                $overrideCount++;

                continue;
            }

            $before = $this->bookability->evaluate($medium);
            $after = $this->bookability->evaluate($medium, null, $snapshot);

            $row = [
                'id' => (int) $medium->id,
                'lock_version' => (int) $medium->lock_version,
                'is_active' => (bool) $medium->is_active,
                'kind' => $medium->getAttributes()['kind'] ?? null,
                'category_id' => (int) $medium->category_id,
                'calculation_method_mode' => $medium->calculation_method_mode->value,
                'default_calculation_method_id' => $medium->default_calculation_method_id !== null
                    ? (int) $medium->default_calculation_method_id
                    : null,
                'code' => (string) $medium->code,
                'name' => (string) $medium->name,
                'bookable_before' => $before->isBookableForNewPositions,
                'bookable_after' => $after->isBookableForNewPositions,
                'unbookable_reason_before' => $before->unbookableReason,
                'unbookable_reason_after' => $after->unbookableReason,
            ];
            $inherit[] = $row;

            if ($medium->is_active && $before->isBookableForNewPositions) {
                $protected[] = $row;
            }
        }

        return [
            'protected' => $protected,
            'inherit' => $inherit,
            'override_count' => $overrideCount,
        ];
    }

    /**
     * @param  array{protected: list<array<string, mixed>>}  $mediaImpact
     * @return list<array{code: string, message: string}>
     */
    private function bookabilityBlockers(array $mediaImpact): array
    {
        $blocking = [];
        foreach ($mediaImpact['protected'] as $row) {
            if ($row['bookable_after'] === true) {
                continue;
            }
            $reason = is_string($row['unbookable_reason_after'] ?? null)
                ? $row['unbookable_reason_after']
                : 'nach der Konfiguration nicht mehr buchbar';
            $blocking[] = [
                'code' => 'inherit_bookability_lost',
                'message' => 'Das bisher buchbare Werbemittel „'.$row['name'].'“ ('
                    .$row['code'].') wäre nach dem Desired State nicht mehr buchbar: '.$reason,
            ];
        }

        return $blocking;
    }

    /**
     * @param  Collection<int, AdvertisingCategoryCalculationMethod>  $current
     * @param  array{default_calculation_method_id: int|null, assignments: list<array{calculation_method_id: int, is_active: bool, sort: int}>}  $normalized
     * @param  Collection<int, EffectiveCategoryMethodAssignment>  $effective
     */
    private function detectHasChanges(
        AdvertisingCategory $category,
        Collection $current,
        array $normalized,
        Collection $effective,
    ): bool {
        $currentDefault = $category->default_calculation_method_id !== null
            ? (int) $category->default_calculation_method_id
            : null;
        if ($currentDefault !== $normalized['default_calculation_method_id']) {
            return true;
        }

        foreach ($effective as $row) {
            if ($row->willCreate || $row->willMutate) {
                return true;
            }
        }

        // Fehlende Desired-Einträge für vorhandene Zeilen sind bereits in effective als mutate abgebildet.
        unset($current);

        return false;
    }

    /**
     * @param  Collection<int, EffectiveCategoryMethodAssignment>  $effective
     * @param  Collection<int, CalculationMethod>  $methods
     * @return list<array{engine_profile_key: string, method_key: string, pair_status: string|null, current_released_version: string|null}>
     */
    private function registryPairsForAssignments(Collection $effective, Collection $methods): array
    {
        $pairs = [];
        foreach ($effective as $row) {
            $profile = $row->engineProfileKey;
            if ($profile === null || trim((string) $profile) === '') {
                continue;
            }
            /** @var CalculationMethod|null $method */
            $method = $methods->get($row->calculationMethodId);
            if ($method === null) {
                continue;
            }
            $methodKey = (string) $method->key;
            $pairStatus = null;
            $version = null;
            try {
                EngineProfileRegistry::assertKnownProfile((string) $profile);
                EngineProfileRegistry::assertKnownMethodForProfile((string) $profile, $methodKey);
                $pairStatus = EngineProfileRegistry::pairStatus((string) $profile, $methodKey)->value;
                $version = EngineProfileRegistry::currentReleasedVersion((string) $profile, $methodKey);
            } catch (InvalidArgumentException) {
                $pairStatus = null;
                $version = null;
            }
            $pairs[] = [
                'engine_profile_key' => (string) $profile,
                'method_key' => $methodKey,
                'pair_status' => $pairStatus,
                'current_released_version' => $version,
            ];
        }

        usort(
            $pairs,
            static function (array $a, array $b): int {
                $byProfile = strcmp($a['engine_profile_key'], $b['engine_profile_key']);
                if ($byProfile !== 0) {
                    return $byProfile;
                }

                return strcmp($a['method_key'], $b['method_key']);
            },
        );

        return $pairs;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function assertPayloadClean(array $payload): void
    {
        foreach (self::PROHIBITED_TOP_LEVEL as $prohibited) {
            if (array_key_exists($prohibited, $payload)) {
                throw ValidationException::withMessages([
                    $prohibited => 'Das Feld „'.$prohibited.'“ darf über die Methodenkonfiguration nicht gesetzt werden.',
                ]);
            }
        }

        if (array_key_exists('assignments', $payload) && is_array($payload['assignments'])) {
            foreach ($payload['assignments'] as $index => $row) {
                if (! is_array($row)) {
                    continue;
                }
                if (array_key_exists('engine_profile_key', $row)) {
                    throw ValidationException::withMessages([
                        "assignments.{$index}.engine_profile_key" => 'Das Feld „engine_profile_key“ darf in Methodenzuordnungen nicht gesetzt werden.',
                    ]);
                }
            }
        }
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
