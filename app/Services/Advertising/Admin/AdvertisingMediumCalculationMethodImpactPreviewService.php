<?php

namespace App\Services\Advertising\Admin;

use App\Enums\CalculationMethodMode;
use App\Enums\EngineCapabilityStatus;
use App\Exceptions\CatalogAdminConflictException;
use App\Models\AdvertisingCategory;
use App\Models\AdvertisingCategoryCalculationMethod;
use App\Models\AdvertisingMedium;
use App\Models\AdvertisingMediumCalculationMethod;
use App\Models\CalculationMethod;
use App\Support\Advertising\AdvertisingMediumLiveBookability;
use App\Support\Advertising\MediumMethodCatalogSnapshot;
use App\Support\Calculation\EngineProfileRegistry;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * ADV-001c3c: gemeinsame Normalisierung, Preview und Fingerprint für
 * Medium-Methoden-Desired-State inkl. Mode (Preview und Apply identisch).
 */
final class AdvertisingMediumCalculationMethodImpactPreviewService
{
    public const ACTION_MEDIUM_CALCULATION_METHODS_REPLACE = 'medium_calculation_methods_replace';

    /** @var list<string> */
    public const PROHIBITED_TOP_LEVEL = [
        'key',
        'engine_profile_key',
        'algorithm_version',
        'registry_status',
        'pair_status',
        'current_released_version',
        'is_active',
        'category_id',
        'code',
        'kind',
        'name',
        'handler',
        'service',
        'php_class',
        'class',
        'category_assignments',
        'category_default_calculation_method_id',
        'default_calculation_method_key',
        'medium_assignments',
        'medium_defaults',
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
    public function preview(AdvertisingMedium $medium, array $payload): array
    {
        $medium->loadMissing([
            'category.defaultCalculationMethod',
            'category.calculationMethodAssignments.calculationMethod',
            'defaultCalculationMethod',
            'calculationMethodAssignments.calculationMethod',
        ]);

        $normalized = $this->normalizeDesiredState($payload);
        $currentAssignments = $this->loadCurrentAssignments((int) $medium->id);
        $category = $medium->category;
        if ($category === null) {
            throw ValidationException::withMessages([
                'medium' => 'Die Oberkategorie des Werbemittels wurde nicht gefunden.',
            ]);
        }

        $methods = $this->loadRelevantMethods($medium, $category, $currentAssignments, $normalized);
        $this->assertDesiredActiveMethodsAreGloballyActive($normalized, $methods);
        $effectiveAssignments = $this->buildEffectiveAssignments(
            $currentAssignments,
            $normalized,
            $methods,
        );
        $this->assertStoredDefaultMembership(
            $normalized['default_calculation_method_id'],
            $effectiveAssignments,
            $methods,
        );
        if ($normalized['calculation_method_mode'] === CalculationMethodMode::Override) {
            $this->assertOverrideDefaultValid(
                $normalized['default_calculation_method_id'],
                $effectiveAssignments,
                $methods,
            );
        }

        $evaluation = $this->evaluateBookability($medium, $category, $normalized, $effectiveAssignments, $methods);
        $blocking = $this->bookabilityBlockers($medium, $evaluation);
        $hasChanges = $this->detectHasChanges($medium, $currentAssignments, $normalized, $effectiveAssignments);

        $categoryAssignments = $this->serializeCategoryAssignments($category);
        $storedBefore = $this->storedState($medium, $currentAssignments);
        $storedAfter = [
            'calculation_method_mode' => $normalized['calculation_method_mode']->value,
            'default_calculation_method_id' => $normalized['default_calculation_method_id'],
            'assignments' => $effectiveAssignments->map(
                static fn (EffectiveMediumMethodAssignment $row): array => $row->toPreviewArray(),
            )->values()->all(),
            'is_operative' => $normalized['calculation_method_mode'] === CalculationMethodMode::Override,
        ];

        $body = [
            'entity' => 'advertising_medium',
            'action' => self::ACTION_MEDIUM_CALCULATION_METHODS_REPLACE,
            'entity_id' => (int) $medium->id,
            'lock_version' => (int) $medium->lock_version,
            'has_changes' => $hasChanges,
            'can_proceed' => $blocking === [],
            'blocking_reasons' => $blocking,
            'current' => [
                'id' => (int) $medium->id,
                'code' => (string) $medium->code,
                'is_active' => (bool) $medium->is_active,
                'kind' => $medium->getAttributes()['kind'] ?? null,
                'category_id' => (int) $medium->category_id,
                'calculation_method_mode' => $medium->calculation_method_mode->value,
                'default_calculation_method_id' => $medium->default_calculation_method_id !== null
                    ? (int) $medium->default_calculation_method_id
                    : null,
                'lock_version' => (int) $medium->lock_version,
                'assignments' => $currentAssignments->map(static fn (AdvertisingMediumCalculationMethod $row): array => [
                    'id' => (int) $row->id,
                    'calculation_method_id' => (int) $row->calculation_method_id,
                    'is_active' => (bool) $row->is_active,
                    'sort' => (int) $row->sort,
                    'engine_profile_key' => $row->engine_profile_key,
                    'lock_version' => (int) $row->lock_version,
                ])->values()->all(),
            ],
            'category' => [
                'id' => (int) $category->id,
                'key' => (string) $category->key,
                'is_active' => (bool) $category->is_active,
                'default_calculation_method_id' => $category->default_calculation_method_id !== null
                    ? (int) $category->default_calculation_method_id
                    : null,
                'lock_version' => (int) $category->lock_version,
                'assignments' => $categoryAssignments,
            ],
            'methods' => $methods->sortBy('id')->values()->map(static fn (CalculationMethod $method): array => [
                'id' => (int) $method->id,
                'key' => (string) $method->key,
                'is_active' => (bool) $method->is_active,
                'lock_version' => (int) $method->lock_version,
            ])->all(),
            'registry_pairs' => $this->registryPairsForAssignments($effectiveAssignments, $methods),
            'desired' => [
                'calculation_method_mode' => $normalized['calculation_method_mode']->value,
                'default_calculation_method_id' => $normalized['default_calculation_method_id'],
                'assignments' => $normalized['assignments'],
            ],
            'stored_before' => $storedBefore,
            'stored_after' => $storedAfter,
            'effective_before' => $evaluation['effective_before'],
            'effective_after' => $evaluation['effective_after'],
            'evaluation' => [
                'source_before' => $evaluation['source_before'],
                'source_after' => $evaluation['source_after'],
                'bookable_before' => $evaluation['bookable_before'],
                'bookable_after' => $evaluation['bookable_after'],
                'reason_before' => $evaluation['reason_before'],
                'reason_after' => $evaluation['reason_after'],
            ],
            'effective_assignments' => $effectiveAssignments->map(
                static fn (EffectiveMediumMethodAssignment $row): array => $row->toPreviewArray(),
            )->values()->all(),
            'dependency_note' => 'Bisher buchbare aktive Werbemittel müssen nach dem Desired State '
                .'buchbar bleiben. Gespeicherte Overrides bei Vererbung sind unwirksam, bleiben aber erhalten. '
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
     *     calculation_method_mode: CalculationMethodMode,
     *     default_calculation_method_id: int|null,
     *     assignments: list<array{calculation_method_id: int, is_active: bool, sort: int}>
     * }
     */
    public function normalizeDesiredState(array $payload): array
    {
        $this->assertPayloadClean($payload);

        if (! array_key_exists('calculation_method_mode', $payload)
            || ! is_string($payload['calculation_method_mode'])
        ) {
            throw ValidationException::withMessages([
                'calculation_method_mode' => 'Der Vererbungsmodus ist erforderlich.',
            ]);
        }

        $mode = CalculationMethodMode::tryFrom($payload['calculation_method_mode']);
        if ($mode === null) {
            throw ValidationException::withMessages([
                'calculation_method_mode' => 'Der Vererbungsmodus muss inherit oder override sein.',
            ]);
        }

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
            'calculation_method_mode' => $mode,
            'default_calculation_method_id' => $defaultId,
            'assignments' => $assignments,
        ];
    }

    /**
     * @param  Collection<int, AdvertisingMediumCalculationMethod>  $current
     * @param  array{
     *     calculation_method_mode: CalculationMethodMode,
     *     default_calculation_method_id: int|null,
     *     assignments: list<array{calculation_method_id: int, is_active: bool, sort: int}>
     * }  $normalized
     * @return Collection<int, CalculationMethod> keyed by id
     */
    public function loadRelevantMethods(
        AdvertisingMedium $medium,
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
        if ($medium->default_calculation_method_id !== null) {
            $ids[] = (int) $medium->default_calculation_method_id;
        }
        if ($normalized['default_calculation_method_id'] !== null) {
            $ids[] = (int) $normalized['default_calculation_method_id'];
        }
        if ($category->default_calculation_method_id !== null) {
            $ids[] = (int) $category->default_calculation_method_id;
        }
        foreach ($category->calculationMethodAssignments as $row) {
            $ids[] = (int) $row->calculation_method_id;
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
     * @return Collection<int, AdvertisingMediumCalculationMethod> keyed by calculation_method_id
     */
    public function loadCurrentAssignments(int $mediumId): Collection
    {
        return AdvertisingMediumCalculationMethod::query()
            ->where('advertising_medium_id', $mediumId)
            ->orderBy('id')
            ->get()
            ->keyBy(static fn (AdvertisingMediumCalculationMethod $row): int => (int) $row->calculation_method_id);
    }

    /**
     * @param  Collection<int, AdvertisingMediumCalculationMethod>  $current
     * @param  array{
     *     default_calculation_method_id: int|null,
     *     assignments: list<array{calculation_method_id: int, is_active: bool, sort: int}>
     * }  $normalized
     * @param  Collection<int, CalculationMethod>  $methods
     * @return Collection<int, EffectiveMediumMethodAssignment>
     */
    public function buildEffectiveAssignments(
        Collection $current,
        array $normalized,
        Collection $methods,
    ): Collection {
        unset($methods);

        $desiredByMethod = [];
        foreach ($normalized['assignments'] as $row) {
            $desiredByMethod[(int) $row['calculation_method_id']] = $row;
        }

        $allMethodIds = array_values(array_unique(array_merge(
            $current->keys()->map(static fn ($id): int => (int) $id)->all(),
            array_keys($desiredByMethod),
        )));
        sort($allMethodIds);

        /** @var Collection<int, EffectiveMediumMethodAssignment> $effective */
        $effective = new Collection;
        foreach ($allMethodIds as $methodId) {
            /** @var AdvertisingMediumCalculationMethod|null $existing */
            $existing = $current->get($methodId);
            $desired = $desiredByMethod[$methodId] ?? null;

            if ($desired === null) {
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
                    continue;
                }
            }

            $engineProfileKey = $existing?->engine_profile_key;
            $willMutate = $willCreate || ($existing !== null && (
                (bool) $existing->is_active !== $isActive
                || (int) $existing->sort !== $sort
            ));

            $effective->push(new EffectiveMediumMethodAssignment(
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
            static fn (EffectiveMediumMethodAssignment $row): int => $row->calculationMethodId,
        )->values();
    }

    /**
     * @param  Collection<int, EffectiveMediumMethodAssignment>  $effective
     * @param  Collection<int, CalculationMethod>  $methods
     */
    public function buildOverrideSnapshot(
        ?int $defaultMethodId,
        Collection $effective,
        Collection $methods,
    ): MediumMethodCatalogSnapshot {
        $assignmentModels = new Collection;
        foreach ($effective as $row) {
            /** @var CalculationMethod|null $method */
            $method = $methods->get($row->calculationMethodId);
            if ($method === null) {
                continue;
            }

            if ($row->existing instanceof AdvertisingMediumCalculationMethod) {
                $model = $row->existing->replicate();
                $model->id = $row->existing->id;
                $model->exists = true;
            } else {
                $model = new AdvertisingMediumCalculationMethod;
            }

            $model->calculation_method_id = $row->calculationMethodId;
            $model->is_active = $row->isActive;
            $model->sort = $row->sort;
            $model->setAttribute('engine_profile_key', $row->engineProfileKey);
            $model->setRelation('calculationMethod', $method);
            $assignmentModels->push($model);
        }

        $default = $defaultMethodId !== null ? $methods->get($defaultMethodId) : null;

        return new MediumMethodCatalogSnapshot(
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
     * Gespeicherter Default muss Mitglied einer gespeicherten aktiven Medium-Zuordnung sein.
     *
     * @param  Collection<int, EffectiveMediumMethodAssignment>  $effective
     * @param  Collection<int, CalculationMethod>  $methods
     */
    public function assertStoredDefaultMembership(
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

        /** @var EffectiveMediumMethodAssignment|null $assignment */
        $assignment = $effective->first(
            static fn (EffectiveMediumMethodAssignment $row): bool => $row->calculationMethodId === $defaultMethodId,
        );
        if ($assignment === null || ! $assignment->isActive) {
            throw ValidationException::withMessages([
                'default_calculation_method_id' => 'Die gespeicherte Standard-Berechnungsmethode muss einer aktiven Medium-Zuordnung angehören.',
            ]);
        }
    }

    /**
     * @param  Collection<int, EffectiveMediumMethodAssignment>  $effective
     * @param  Collection<int, CalculationMethod>  $methods
     */
    public function assertOverrideDefaultValid(
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

        /** @var EffectiveMediumMethodAssignment|null $assignment */
        $assignment = $effective->first(
            static fn (EffectiveMediumMethodAssignment $row): bool => $row->calculationMethodId === $defaultMethodId,
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
     * @param  array{
     *     calculation_method_mode: CalculationMethodMode,
     *     default_calculation_method_id: int|null
     * }  $normalized
     * @param  Collection<int, EffectiveMediumMethodAssignment>  $effective
     * @param  Collection<int, CalculationMethod>  $methods
     * @return array{
     *     bookable_before: bool,
     *     bookable_after: bool,
     *     reason_before: string|null,
     *     reason_after: string|null,
     *     source_before: string,
     *     source_after: string,
     *     effective_before: array<string, mixed>,
     *     effective_after: array<string, mixed>
     * }
     */
    public function evaluateBookability(
        AdvertisingMedium $medium,
        AdvertisingCategory $category,
        array $normalized,
        Collection $effective,
        Collection $methods,
    ): array {
        $before = $this->bookability->evaluate($medium);
        $sourceBefore = $this->bookability->configurationSource($medium);

        $simulated = $this->simulationMedium($medium, $category, $normalized['calculation_method_mode']);

        if ($normalized['calculation_method_mode'] === CalculationMethodMode::Override) {
            $snapshot = $this->buildOverrideSnapshot(
                $normalized['default_calculation_method_id'],
                $effective,
                $methods,
            );
            $after = $this->bookability->evaluate($simulated, null, null, $snapshot);
            $sourceAfter = AdvertisingMediumLiveBookability::SOURCE_MEDIUM_OVERRIDE;
            $effectiveAfter = [
                'source' => $sourceAfter,
                'calculation_method_mode' => CalculationMethodMode::Override->value,
                'default_calculation_method_id' => $normalized['default_calculation_method_id'],
                'assignments' => $effective->filter(
                    static fn (EffectiveMediumMethodAssignment $row): bool => $row->isActive,
                )->map(static fn (EffectiveMediumMethodAssignment $row): array => $row->toPreviewArray())->values()->all(),
            ];
        } else {
            $after = $this->bookability->evaluate($simulated);
            $sourceAfter = AdvertisingMediumLiveBookability::SOURCE_CATEGORY;
            $effectiveAfter = [
                'source' => $sourceAfter,
                'calculation_method_mode' => CalculationMethodMode::Inherit->value,
                'default_calculation_method_id' => $category->default_calculation_method_id !== null
                    ? (int) $category->default_calculation_method_id
                    : null,
                'assignments' => $this->serializeCategoryAssignments($category),
            ];
        }

        $effectiveBefore = $medium->calculation_method_mode === CalculationMethodMode::Override
            ? [
                'source' => $sourceBefore,
                'calculation_method_mode' => CalculationMethodMode::Override->value,
                'default_calculation_method_id' => $medium->default_calculation_method_id !== null
                    ? (int) $medium->default_calculation_method_id
                    : null,
                'assignments' => $medium->calculationMethodAssignments
                    ->sortBy([
                        ['sort', 'asc'],
                        ['calculation_method_id', 'asc'],
                    ])
                    ->values()
                    ->map(static fn (AdvertisingMediumCalculationMethod $row): array => [
                        'id' => (int) $row->id,
                        'calculation_method_id' => (int) $row->calculation_method_id,
                        'is_active' => (bool) $row->is_active,
                        'sort' => (int) $row->sort,
                        'engine_profile_key' => $row->engine_profile_key,
                    ])->all(),
            ]
            : [
                'source' => $sourceBefore,
                'calculation_method_mode' => CalculationMethodMode::Inherit->value,
                'default_calculation_method_id' => $category->default_calculation_method_id !== null
                    ? (int) $category->default_calculation_method_id
                    : null,
                'assignments' => $this->serializeCategoryAssignments($category),
            ];

        return [
            'bookable_before' => $before->isBookableForNewPositions,
            'bookable_after' => $after->isBookableForNewPositions,
            'reason_before' => $before->unbookableReason,
            'reason_after' => $after->unbookableReason,
            'source_before' => $sourceBefore,
            'source_after' => $sourceAfter,
            'effective_before' => $effectiveBefore,
            'effective_after' => $effectiveAfter,
        ];
    }

    /**
     * @param  array{
     *     bookable_before: bool,
     *     bookable_after: bool,
     *     reason_after: string|null
     * }  $evaluation
     * @return list<array{code: string, message: string}>
     */
    private function bookabilityBlockers(AdvertisingMedium $medium, array $evaluation): array
    {
        if (! $medium->is_active || $evaluation['bookable_before'] !== true) {
            return [];
        }

        if ($evaluation['bookable_after'] === true) {
            return [];
        }

        $reason = is_string($evaluation['reason_after'] ?? null)
            ? $evaluation['reason_after']
            : 'nach der Konfiguration nicht mehr buchbar';

        return [[
            'code' => 'medium_bookability_lost',
            'message' => 'Das bisher buchbare Werbemittel wäre nach dem Desired State nicht mehr buchbar: '.$reason,
        ]];
    }

    /**
     * @param  Collection<int, AdvertisingMediumCalculationMethod>  $current
     * @param  array{
     *     calculation_method_mode: CalculationMethodMode,
     *     default_calculation_method_id: int|null,
     *     assignments: list<array{calculation_method_id: int, is_active: bool, sort: int}>
     * }  $normalized
     * @param  Collection<int, EffectiveMediumMethodAssignment>  $effective
     */
    private function detectHasChanges(
        AdvertisingMedium $medium,
        Collection $current,
        array $normalized,
        Collection $effective,
    ): bool {
        unset($current);

        if ($medium->calculation_method_mode !== $normalized['calculation_method_mode']) {
            return true;
        }

        $currentDefault = $medium->default_calculation_method_id !== null
            ? (int) $medium->default_calculation_method_id
            : null;
        if ($currentDefault !== $normalized['default_calculation_method_id']) {
            return true;
        }

        foreach ($effective as $row) {
            if ($row->willCreate || $row->willMutate) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  Collection<int, AdvertisingMediumCalculationMethod>  $current
     * @return array<string, mixed>
     */
    private function storedState(AdvertisingMedium $medium, Collection $current): array
    {
        return [
            'calculation_method_mode' => $medium->calculation_method_mode->value,
            'default_calculation_method_id' => $medium->default_calculation_method_id !== null
                ? (int) $medium->default_calculation_method_id
                : null,
            'assignments' => $current->sortBy('id')->values()->map(
                static fn (AdvertisingMediumCalculationMethod $row): array => [
                    'id' => (int) $row->id,
                    'calculation_method_id' => (int) $row->calculation_method_id,
                    'is_active' => (bool) $row->is_active,
                    'sort' => (int) $row->sort,
                    'engine_profile_key' => $row->engine_profile_key,
                    'lock_version' => (int) $row->lock_version,
                ],
            )->all(),
            'is_operative' => $medium->calculation_method_mode === CalculationMethodMode::Override,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function serializeCategoryAssignments(AdvertisingCategory $category): array
    {
        $category->loadMissing(['calculationMethodAssignments']);

        $rows = $category->calculationMethodAssignments
            ->sortBy([
                ['sort', 'asc'],
                ['calculation_method_id', 'asc'],
            ])
            ->values()
            ->map(static fn (AdvertisingCategoryCalculationMethod $row): array => [
                'id' => (int) $row->id,
                'calculation_method_id' => (int) $row->calculation_method_id,
                'is_active' => (bool) $row->is_active,
                'sort' => (int) $row->sort,
                'engine_profile_key' => $row->engine_profile_key,
                'lock_version' => (int) $row->lock_version,
            ])
            ->all();

        return array_values($rows);
    }

    private function simulationMedium(
        AdvertisingMedium $medium,
        AdvertisingCategory $category,
        CalculationMethodMode $mode,
    ): AdvertisingMedium {
        $simulated = $medium->newInstance([], true);
        $simulated->id = $medium->id;
        $simulated->exists = true;
        $simulated->setRawAttributes($medium->getAttributes(), true);
        $simulated->calculation_method_mode = $mode;
        $simulated->setRelation('category', $category);
        $simulated->setRelation('defaultCalculationMethod', $medium->defaultCalculationMethod);
        $simulated->setRelation('calculationMethodAssignments', $medium->calculationMethodAssignments);

        return $simulated;
    }

    /**
     * @param  Collection<int, EffectiveMediumMethodAssignment>  $effective
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
