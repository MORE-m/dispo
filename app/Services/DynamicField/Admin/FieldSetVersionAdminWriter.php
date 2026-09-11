<?php

namespace App\Services\DynamicField\Admin;

use App\Enums\FieldAppliesTo;
use App\Enums\FieldScope;
use App\Enums\FieldSetVersionStatus;
use App\Exceptions\FieldSetConflictException;
use App\Models\FieldDefinition;
use App\Models\FieldDefinitionRevision;
use App\Models\FieldRule;
use App\Models\FieldSet;
use App\Models\FieldSetAssignment;
use App\Models\FieldSetVersion;
use App\Models\FieldSetVersionField;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\DynamicField\SnapshotFieldRuleEvaluator;
use App\Support\DynamicField\FieldDefinitionOptionContract;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * DF-3.1 / DF-3.3-fs / DYN-003 / VER-005 / VER-006 / ADM-001:
 * Create freier Feldsets, Draft, Activate, Copy-as-template, Deakt./Reakt.
 * Regeln werden nur kopiert, nie mutiert.
 */
final class FieldSetVersionAdminWriter
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly SnapshotFieldRuleEvaluator $rules,
        private readonly FieldSetKeySlugger $slugger,
    ) {}

    public function assertAdminFieldSet(FieldSet $fieldSet): void
    {
        if (! AdminFieldSetCatalog::isAdministrable($fieldSet)) {
            throw ValidationException::withMessages([
                'field_set' => 'Dieses Feldset ist nicht administrierbar.',
            ]);
        }
    }

    /**
     * @param  array{
     *     name: string,
     *     key?: string|null,
     *     applies_to: FieldAppliesTo|string
     * }  $payload
     */
    public function createFreeFieldSet(array $payload, User $actor): FieldSet
    {
        $name = trim((string) $payload['name']);
        if ($name === '') {
            throw ValidationException::withMessages([
                'name' => 'Der Name ist erforderlich.',
            ]);
        }

        $appliesTo = $this->normalizeAppliesTo($payload['applies_to']);
        $keyCandidate = $payload['key'] ?? null;
        $explicitKey = is_string($keyCandidate) && trim($keyCandidate) !== ''
            ? trim($keyCandidate)
            : null;
        if ($explicitKey !== null) {
            $this->slugger->assertKeyAllowed($explicitKey);
            $key = $explicitKey;
        } else {
            $key = $this->slugger->uniqueSlugFromName($name);
        }

        return DB::transaction(function () use ($name, $key, $appliesTo, $actor): FieldSet {
            $fieldSet = new FieldSet;
            $fieldSet->key = $key;
            $fieldSet->name = $name;
            $fieldSet->is_system = false;
            $fieldSet->applies_to = $appliesTo;
            $fieldSet->is_assignable = false;
            $fieldSet->active_version_id = null;
            $fieldSet->lock_version = 1;
            $fieldSet->save();

            $draft = new FieldSetVersion;
            $draft->field_set_id = $fieldSet->id;
            $draft->version = 1;
            $draft->status = FieldSetVersionStatus::Draft;
            $draft->created_at = now();
            $draft->save();

            $this->audit->record(
                $fieldSet,
                'field_set.created',
                $actor,
                null,
                [
                    'key' => $fieldSet->key,
                    'name' => $fieldSet->name,
                    'applies_to' => $fieldSet->applies_to->value,
                    'is_system' => false,
                    'is_assignable' => false,
                    'lock_version' => $fieldSet->lock_version,
                    'draft_version_id' => $draft->id,
                    'draft_version' => $draft->version,
                ],
            );

            return $fieldSet->fresh(['versions', 'activeVersion']) ?? $fieldSet;
        });
    }

    /**
     * @param  array{
     *     name?: string,
     *     applies_to?: FieldAppliesTo|string,
     *     lock_version: int
     * }  $payload
     */
    public function updateContainerMetadata(FieldSet $fieldSet, array $payload, User $actor): FieldSet
    {
        $this->assertAdminFieldSet($fieldSet);

        if (AdminFieldSetCatalog::isCoreKey($fieldSet->key) || $fieldSet->is_system) {
            throw ValidationException::withMessages([
                'field_set' => 'Kern-Feldsets dürfen nicht über Metadaten geändert werden.',
            ]);
        }

        return DB::transaction(function () use ($fieldSet, $payload, $actor): FieldSet {
            /** @var FieldSet $locked */
            $locked = FieldSet::query()->whereKey($fieldSet->id)->lockForUpdate()->firstOrFail();
            $this->assertLock($locked, (int) $payload['lock_version']);

            $before = [
                'name' => $locked->name,
                'applies_to' => $locked->applies_to->value,
                'is_assignable' => $locked->is_assignable,
                'lock_version' => $locked->lock_version,
            ];

            $nameChanged = false;
            $appliesChanged = false;

            if (array_key_exists('name', $payload)) {
                $name = trim((string) $payload['name']);
                if ($name === '') {
                    throw ValidationException::withMessages([
                        'name' => 'Der Name ist erforderlich.',
                    ]);
                }
                if ($name !== $locked->name) {
                    $locked->name = $name;
                    $nameChanged = true;
                }
            }

            if (array_key_exists('applies_to', $payload)) {
                $newApplies = $this->normalizeAppliesTo($payload['applies_to']);
                if ($newApplies !== $locked->applies_to) {
                    if ($this->hasEverBeenActivated($locked)) {
                        throw ValidationException::withMessages([
                            'applies_to' => 'Die Gültigkeit darf nach der ersten Aktivierung nicht mehr geändert werden.',
                        ]);
                    }

                    $this->assertDraftMembershipsCompatibleWithAppliesTo($locked, $newApplies);
                    $locked->applies_to = $newApplies;
                    $appliesChanged = true;
                }
            }

            if (! $nameChanged && ! $appliesChanged) {
                return $locked;
            }

            $locked->lock_version = $locked->lock_version + 1;
            $locked->save();

            $action = $nameChanged && ! $appliesChanged
                ? 'field_set.renamed'
                : 'field_set.metadata_updated';

            $this->audit->record(
                $locked,
                $action,
                $actor,
                $before,
                [
                    'name' => $locked->name,
                    'applies_to' => $locked->applies_to->value,
                    'is_assignable' => $locked->is_assignable,
                    'lock_version' => $locked->lock_version,
                    'field_set_key' => $locked->key,
                ],
            );

            return $locked;
        });
    }

    public function deactivate(FieldSet $fieldSet, User $actor, int $expectedLockVersion): FieldSet
    {
        $this->assertAdminFieldSet($fieldSet);

        if (AdminFieldSetCatalog::isCoreKey($fieldSet->key) || $fieldSet->is_system) {
            throw ValidationException::withMessages([
                'field_set' => 'Kern-Feldsets können nicht deaktiviert werden.',
            ]);
        }

        return DB::transaction(function () use ($fieldSet, $actor, $expectedLockVersion): FieldSet {
            // Serialisierungsgrenze: nur Feldset-Container. Kein Assignment-FOR-UPDATE
            // (Assignment-Activate sperrt Assignment → Feldset; Gegenrichtung wäre Deadlock-Risiko).
            /** @var FieldSet $locked */
            $locked = FieldSet::query()->whereKey($fieldSet->id)->lockForUpdate()->firstOrFail();
            $this->assertLock($locked, $expectedLockVersion);

            if (! $locked->is_assignable) {
                throw ValidationException::withMessages([
                    'field_set' => 'Das Feldset ist bereits deaktiviert bzw. noch nicht nutzbar.',
                ]);
            }

            // Frischer nicht sperrender Read nach Feldset-Lock (DF-3.3-fs-HF1).
            $activeAssignmentIds = FieldSetAssignment::query()
                ->where('field_set_id', $locked->id)
                ->where('is_active', true)
                ->orderBy('id')
                ->pluck('id')
                ->map(static fn ($id): int => (int) $id)
                ->all();

            if ($activeAssignmentIds !== []) {
                $listed = implode(', ', array_map(
                    static fn (int $id): string => '#'.$id,
                    $activeAssignmentIds,
                ));

                throw ValidationException::withMessages([
                    'field_set' => 'Das Feldset kann nicht deaktiviert werden, solange aktive Assignments darauf verweisen. Deaktiviere zuerst die aufgeführten Assignments ('.$listed.').',
                ]);
            }

            $before = [
                'is_assignable' => true,
                'lock_version' => $locked->lock_version,
                'active_version_id' => $locked->active_version_id,
            ];

            $locked->is_assignable = false;
            $locked->lock_version = $locked->lock_version + 1;
            $locked->save();

            $this->audit->record(
                $locked,
                'field_set.deactivated',
                $actor,
                $before,
                [
                    'is_assignable' => false,
                    'lock_version' => $locked->lock_version,
                    'active_version_id' => $locked->active_version_id,
                    'field_set_key' => $locked->key,
                ],
            );

            return $locked;
        });
    }

    public function reactivate(FieldSet $fieldSet, User $actor, int $expectedLockVersion): FieldSet
    {
        $this->assertAdminFieldSet($fieldSet);

        if (AdminFieldSetCatalog::isCoreKey($fieldSet->key) || $fieldSet->is_system) {
            throw ValidationException::withMessages([
                'field_set' => 'Kern-Feldsets können nicht reaktiviert werden.',
            ]);
        }

        return DB::transaction(function () use ($fieldSet, $actor, $expectedLockVersion): FieldSet {
            /** @var FieldSet $locked */
            $locked = FieldSet::query()->whereKey($fieldSet->id)->lockForUpdate()->firstOrFail();
            $this->assertLock($locked, $expectedLockVersion);

            if ($locked->is_assignable) {
                throw ValidationException::withMessages([
                    'field_set' => 'Das Feldset ist bereits assignierbar.',
                ]);
            }

            if ($locked->active_version_id === null) {
                throw ValidationException::withMessages([
                    'field_set' => 'Reaktivierung erfordert eine gültige aktive Version.',
                ]);
            }

            /** @var FieldSetVersion $active */
            $active = FieldSetVersion::query()->whereKey($locked->active_version_id)->lockForUpdate()->firstOrFail();
            if ($active->status !== FieldSetVersionStatus::Active) {
                throw ValidationException::withMessages([
                    'field_set' => 'Reaktivierung erfordert eine gültige aktive Version.',
                ]);
            }

            $before = [
                'is_assignable' => false,
                'lock_version' => $locked->lock_version,
                'active_version_id' => $locked->active_version_id,
            ];

            $locked->is_assignable = true;
            $locked->lock_version = $locked->lock_version + 1;
            $locked->save();

            $this->audit->record(
                $locked,
                'field_set.reactivated',
                $actor,
                $before,
                [
                    'is_assignable' => true,
                    'lock_version' => $locked->lock_version,
                    'active_version_id' => $locked->active_version_id,
                    'field_set_key' => $locked->key,
                ],
            );

            return $locked;
        });
    }

    public function createDraftFromVersion(FieldSet $fieldSet, FieldSetVersion $source, User $actor, int $expectedLockVersion): FieldSetVersion
    {
        $this->assertAdminFieldSet($fieldSet);

        if ((int) $source->field_set_id !== (int) $fieldSet->id) {
            throw ValidationException::withMessages([
                'source_version' => 'Die Quellversion gehört nicht zu diesem Feldset.',
            ]);
        }

        return DB::transaction(function () use ($fieldSet, $source, $actor, $expectedLockVersion): FieldSetVersion {
            /** @var FieldSet $locked */
            $locked = FieldSet::query()->whereKey($fieldSet->id)->lockForUpdate()->firstOrFail();
            $this->assertLock($locked, $expectedLockVersion);

            $existingDraft = FieldSetVersion::query()
                ->where('field_set_id', $locked->id)
                ->where('status', FieldSetVersionStatus::Draft)
                ->first();

            if ($existingDraft !== null) {
                throw ValidationException::withMessages([
                    'draft' => 'Es existiert bereits ein Entwurf. Bitte diesen bearbeiten oder aktivieren.',
                ]);
            }

            $source->load(['fields', 'rules']);
            $nextVersion = ((int) FieldSetVersion::query()->where('field_set_id', $locked->id)->max('version')) + 1;

            $draft = new FieldSetVersion;
            $draft->field_set_id = $locked->id;
            $draft->version = $nextVersion;
            $draft->status = FieldSetVersionStatus::Draft;
            $draft->created_at = now();
            $draft->save();

            foreach ($source->fields as $membership) {
                $copy = new FieldSetVersionField;
                $copy->field_set_version_id = $draft->id;
                $copy->field_definition_id = $membership->field_definition_id;
                $copy->field_definition_revision_id = $membership->field_definition_revision_id;
                $copy->sort = $membership->sort;
                $copy->required_override = $membership->required_override;
                $copy->visible_override = $membership->visible_override;
                $copy->save();
            }

            foreach ($source->rules as $rule) {
                $ruleCopy = new FieldRule;
                $ruleCopy->field_set_version_id = $draft->id;
                $ruleCopy->sort = $rule->sort;
                $ruleCopy->condition_json = $rule->condition_json;
                $ruleCopy->action_json = $rule->action_json;
                $ruleCopy->save();
            }

            $locked->lock_version = $locked->lock_version + 1;
            $locked->save();

            $this->audit->record(
                $locked,
                'field_set.draft_created',
                $actor,
                [
                    'lock_version' => $expectedLockVersion,
                    'active_version_id' => $locked->active_version_id,
                    'source_version_id' => $source->id,
                    'source_version' => $source->version,
                ],
                [
                    'lock_version' => $locked->lock_version,
                    'draft_version_id' => $draft->id,
                    'draft_version' => $draft->version,
                    'field_set_key' => $locked->key,
                ],
            );

            return $draft->fresh(['fields.revision.definition', 'rules']) ?? $draft;
        });
    }

    /**
     * @param  list<array{
     *     id: int,
     *     field_definition_revision_id: int,
     *     sort: int,
     *     required_override?: bool|null,
     *     visible_override?: bool|null
     * }>  $memberships
     */
    public function updateDraftMemberships(
        FieldSet $fieldSet,
        FieldSetVersion $draft,
        array $memberships,
        User $actor,
        int $expectedLockVersion,
    ): FieldSetVersion {
        $this->assertAdminFieldSet($fieldSet);
        $this->assertDraftOfSet($fieldSet, $draft);

        return DB::transaction(function () use ($fieldSet, $draft, $memberships, $actor, $expectedLockVersion): FieldSetVersion {
            /** @var FieldSet $locked */
            $locked = FieldSet::query()->whereKey($fieldSet->id)->lockForUpdate()->firstOrFail();
            $this->assertLock($locked, $expectedLockVersion);

            /** @var FieldSetVersion $lockedDraft */
            $lockedDraft = FieldSetVersion::query()->whereKey($draft->id)->lockForUpdate()->firstOrFail();
            if ($lockedDraft->status !== FieldSetVersionStatus::Draft) {
                throw ValidationException::withMessages([
                    'version' => 'Nur Entwurfsversionen dürfen bearbeitet werden.',
                ]);
            }

            $lockedDraft->load('fields');
            $existingById = $lockedDraft->fields->keyBy('id');

            if (count($memberships) !== $existingById->count()) {
                throw ValidationException::withMessages([
                    'fields' => 'Memberships bitte über die dedizierten Hinzufügen-/Entfernen-Aktionen ändern.',
                ]);
            }

            $before = $lockedDraft->fields->map(fn (FieldSetVersionField $f): array => [
                'id' => $f->id,
                'field_definition_id' => $f->field_definition_id,
                'field_definition_revision_id' => $f->field_definition_revision_id,
                'sort' => $f->sort,
                'required_override' => $f->required_override,
                'visible_override' => $f->visible_override,
            ])->values()->all();

            $seenIds = [];
            foreach ($memberships as $row) {
                $membershipId = (int) $row['id'];
                if (isset($seenIds[$membershipId])) {
                    throw ValidationException::withMessages([
                        'fields' => 'Doppelte Membership-ID in der Anfrage.',
                    ]);
                }
                $seenIds[$membershipId] = true;

                /** @var FieldSetVersionField|null $membership */
                $membership = $existingById->get($membershipId);
                if ($membership === null) {
                    throw ValidationException::withMessages([
                        'fields' => 'Unbekannte Membership-ID.',
                    ]);
                }

                $revisionId = (int) $row['field_definition_revision_id'];
                /** @var FieldDefinitionRevision $revision */
                $revision = FieldDefinitionRevision::query()->whereKey($revisionId)->firstOrFail();
                if ((int) $revision->field_definition_id !== (int) $membership->field_definition_id) {
                    throw ValidationException::withMessages([
                        "fields.{$membershipId}.field_definition_revision_id" => 'Die Revision gehört nicht zu dieser Felddefinition.',
                    ]);
                }

                $membership->field_definition_revision_id = $revisionId;
                $membership->sort = (int) $row['sort'];
                $membership->required_override = array_key_exists('required_override', $row)
                    ? $row['required_override']
                    : $membership->required_override;
                $membership->visible_override = array_key_exists('visible_override', $row)
                    ? $row['visible_override']
                    : $membership->visible_override;
                $membership->save();
            }

            $lockedDraft->load(['fields.revision.definition', 'rules']);
            $defsByKey = [];
            foreach ($lockedDraft->fields as $membership) {
                $definition = $membership->revision?->definition;
                if ($definition === null) {
                    throw new RuntimeException('Membership ohne gültige Definition.');
                }
                $defsByKey[$definition->key] = $definition;
            }
            $this->rules->assertRulesCompatibleWithDefinitions($defsByKey, $lockedDraft->rules);

            $locked->lock_version = $locked->lock_version + 1;
            $locked->save();

            $after = $lockedDraft->fields->map(fn (FieldSetVersionField $f): array => [
                'id' => $f->id,
                'field_definition_id' => $f->field_definition_id,
                'field_definition_revision_id' => $f->field_definition_revision_id,
                'sort' => $f->sort,
                'required_override' => $f->required_override,
                'visible_override' => $f->visible_override,
            ])->values()->all();

            $this->audit->record(
                $locked,
                'field_set.draft_updated',
                $actor,
                [
                    'lock_version' => $expectedLockVersion,
                    'draft_version_id' => $lockedDraft->id,
                    'fields' => $before,
                ],
                [
                    'lock_version' => $locked->lock_version,
                    'draft_version_id' => $lockedDraft->id,
                    'fields' => $after,
                    'field_set_key' => $locked->key,
                ],
            );

            return $lockedDraft;
        });
    }

    public function activateDraft(FieldSet $fieldSet, FieldSetVersion $draft, User $actor, int $expectedLockVersion): FieldSetVersion
    {
        $this->assertAdminFieldSet($fieldSet);
        $this->assertDraftOfSet($fieldSet, $draft);

        return DB::transaction(function () use ($fieldSet, $draft, $actor, $expectedLockVersion): FieldSetVersion {
            /** @var FieldSet $locked */
            $locked = FieldSet::query()->whereKey($fieldSet->id)->lockForUpdate()->firstOrFail();
            $this->assertLock($locked, $expectedLockVersion);

            /** @var FieldSetVersion $lockedDraft */
            $lockedDraft = FieldSetVersion::query()->whereKey($draft->id)->lockForUpdate()->firstOrFail();
            if ($lockedDraft->status !== FieldSetVersionStatus::Draft) {
                throw ValidationException::withMessages([
                    'version' => 'Nur Entwurfsversionen können aktiviert werden.',
                ]);
            }

            $lockedDraft->load(['fields.revision.options', 'fields.revision.definition', 'rules']);

            if ($lockedDraft->fields->isEmpty()) {
                throw ValidationException::withMessages([
                    'fields' => 'Eine leere Version kann nicht aktiviert werden.',
                ]);
            }

            $wasFirstActivation = ! $this->hasEverBeenActivated($locked);

            $defsByKey = [];
            foreach ($lockedDraft->fields as $membership) {
                $definition = $membership->revision?->definition;
                if ($definition === null) {
                    throw new RuntimeException('Membership ohne gültige Definition.');
                }
                if (isset($defsByKey[$definition->key])) {
                    throw ValidationException::withMessages([
                        'fields' => "Feldschlüssel „{$definition->key}“ ist mehrfach vorhanden.",
                    ]);
                }
                if ($definition->field_type->isChoice()) {
                    $revisionOptions = $membership->revision->options;
                    $canonical = FieldDefinitionOptionContract::fromRevisionOptions($revisionOptions);
                    if ($canonical === [] || ! FieldDefinitionOptionContract::hasActiveOption($canonical)) {
                        throw ValidationException::withMessages([
                            'fields' => "Auswahlfeld „{$definition->key}“ benötigt mindestens eine aktive Option in der gepinnten Revision.",
                        ]);
                    }
                }
                if (! $definition->is_system) {
                    if (! $definition->is_active) {
                        throw ValidationException::withMessages([
                            'fields' => "Definition „{$definition->key}“ ist deaktiviert und kann nicht aktiviert werden.",
                        ]);
                    }
                    $this->assertAppliesToMatchesFieldSet($definition->applies_to, $locked);
                    if (! in_array($definition->scope, [FieldScope::Header, FieldScope::Position], true)) {
                        throw ValidationException::withMessages([
                            'fields' => 'Nur Header- oder Position-Felder dürfen in Feldsets aktiviert werden.',
                        ]);
                    }
                } elseif (! AdminFieldSetCatalog::isCoreKey($locked->key)) {
                    throw ValidationException::withMessages([
                        'fields' => 'Systemdefinitionen dürfen freien Feldsets nicht zugeordnet werden.',
                    ]);
                }
                $defsByKey[$definition->key] = $definition;
            }
            $this->rules->assertRulesCompatibleWithDefinitions($defsByKey, $lockedDraft->rules);

            $previousActiveId = $locked->active_version_id;
            if ($previousActiveId !== null) {
                /** @var FieldSetVersion $previous */
                $previous = FieldSetVersion::query()->whereKey($previousActiveId)->lockForUpdate()->firstOrFail();
                if ($previous->status === FieldSetVersionStatus::Active) {
                    $previous->status = FieldSetVersionStatus::Archived;
                    $previous->save();
                }
            }

            $lockedDraft->status = FieldSetVersionStatus::Active;
            $lockedDraft->save();

            $locked->active_version_id = $lockedDraft->id;

            $assignableBefore = $locked->is_assignable;
            if ($wasFirstActivation && ! $locked->is_system && ! AdminFieldSetCatalog::isCoreKey($locked->key)) {
                $locked->is_assignable = true;
            }

            $locked->lock_version = $locked->lock_version + 1;
            $locked->save();

            $this->audit->record(
                $locked,
                'field_set.version_activated',
                $actor,
                [
                    'lock_version' => $expectedLockVersion,
                    'active_version_id' => $previousActiveId,
                    'is_assignable' => $assignableBefore,
                ],
                [
                    'lock_version' => $locked->lock_version,
                    'active_version_id' => $lockedDraft->id,
                    'active_version' => $lockedDraft->version,
                    'is_assignable' => $locked->is_assignable,
                    'first_activation' => $wasFirstActivation,
                    'field_set_key' => $locked->key,
                ],
            );

            return $lockedDraft;
        });
    }

    public function pinCurrentRevisionsOnDraft(FieldSet $fieldSet, FieldSetVersion $draft, User $actor, int $expectedLockVersion): FieldSetVersion
    {
        $this->assertAdminFieldSet($fieldSet);
        $this->assertDraftOfSet($fieldSet, $draft);

        $draft->load('fields');
        /** @var list<array{id: int, field_definition_revision_id: int, sort: int, required_override: bool|null, visible_override: bool|null}> $memberships */
        $memberships = [];
        foreach ($draft->fields as $field) {
            /** @var FieldDefinition $definition */
            $definition = FieldDefinition::query()->whereKey($field->field_definition_id)->firstOrFail();
            if ($definition->current_revision_id === null) {
                throw ValidationException::withMessages([
                    'fields' => "Definition „{$definition->key}“ hat keine aktuelle Revision.",
                ]);
            }

            $memberships[] = [
                'id' => $field->id,
                'field_definition_revision_id' => (int) $definition->current_revision_id,
                'sort' => $field->sort,
                'required_override' => $field->required_override,
                'visible_override' => $field->visible_override,
            ];
        }

        return $this->updateDraftMemberships($fieldSet, $draft, $memberships, $actor, $expectedLockVersion);
    }

    /**
     * @param  array{
     *     field_definition_id: int,
     *     field_definition_revision_id: int,
     *     sort: int,
     *     required_override?: bool|null,
     *     visible_override?: bool|null,
     *     lock_version: int
     * }  $payload
     */
    public function addCustomMembership(
        FieldSet $fieldSet,
        FieldSetVersion $draft,
        array $payload,
        User $actor,
    ): FieldSetVersionField {
        $this->assertAdminFieldSet($fieldSet);
        $this->assertDraftOfSet($fieldSet, $draft);

        return DB::transaction(function () use ($fieldSet, $draft, $payload, $actor): FieldSetVersionField {
            /** @var FieldSet $locked */
            $locked = FieldSet::query()->whereKey($fieldSet->id)->lockForUpdate()->firstOrFail();
            $this->assertLock($locked, (int) $payload['lock_version']);

            /** @var FieldSetVersion $lockedDraft */
            $lockedDraft = FieldSetVersion::query()->whereKey($draft->id)->lockForUpdate()->firstOrFail();
            if ($lockedDraft->status !== FieldSetVersionStatus::Draft) {
                throw ValidationException::withMessages([
                    'version' => 'Nur Entwurfsversionen dürfen bearbeitet werden.',
                ]);
            }

            /** @var FieldDefinition $definition */
            $definition = FieldDefinition::query()
                ->whereKey((int) $payload['field_definition_id'])
                ->lockForUpdate()
                ->firstOrFail();

            if ($definition->is_system) {
                throw ValidationException::withMessages([
                    'field_definition_id' => 'Systemfelder können nicht nachträglich hinzugefügt werden.',
                ]);
            }

            if (! $definition->is_active) {
                throw ValidationException::withMessages([
                    'field_definition_id' => 'Nur aktive Custom-Definitionen können hinzugefügt werden.',
                ]);
            }

            if (! in_array($definition->scope, [FieldScope::Header, FieldScope::Position], true)) {
                throw ValidationException::withMessages([
                    'field_definition_id' => 'Nur Header- oder Position-Felder sind als Membership zulässig.',
                ]);
            }

            $this->assertAppliesToMatchesFieldSet($definition->applies_to, $locked);

            if (FieldSetVersionField::query()
                ->where('field_set_version_id', $lockedDraft->id)
                ->where('field_definition_id', $definition->id)
                ->exists()) {
                throw ValidationException::withMessages([
                    'field_definition_id' => 'Diese Definition ist bereits Mitglied dieser Version.',
                ]);
            }

            /** @var FieldDefinitionRevision $revision */
            $revision = FieldDefinitionRevision::query()
                ->whereKey((int) $payload['field_definition_revision_id'])
                ->firstOrFail();
            if ((int) $revision->field_definition_id !== (int) $definition->id) {
                throw ValidationException::withMessages([
                    'field_definition_revision_id' => 'Die Revision gehört nicht zu dieser Felddefinition.',
                ]);
            }

            $membership = new FieldSetVersionField;
            $membership->field_set_version_id = $lockedDraft->id;
            $membership->field_definition_id = $definition->id;
            $membership->field_definition_revision_id = $revision->id;
            $membership->sort = (int) $payload['sort'];
            $membership->required_override = array_key_exists('required_override', $payload)
                ? $payload['required_override']
                : null;
            $membership->visible_override = array_key_exists('visible_override', $payload)
                ? $payload['visible_override']
                : null;
            $membership->save();

            $lockedDraft->load(['fields.revision.definition', 'rules']);
            $defsByKey = [];
            foreach ($lockedDraft->fields as $row) {
                $def = $row->revision?->definition;
                if ($def === null) {
                    throw new RuntimeException('Membership ohne gültige Definition.');
                }
                $defsByKey[$def->key] = $def;
            }
            $this->rules->assertRulesCompatibleWithDefinitions($defsByKey, $lockedDraft->rules);

            $locked->lock_version = $locked->lock_version + 1;
            $locked->save();

            $this->audit->record(
                $locked,
                'field_set.membership_added',
                $actor,
                [
                    'lock_version' => (int) $payload['lock_version'],
                    'draft_version_id' => $lockedDraft->id,
                ],
                [
                    'lock_version' => $locked->lock_version,
                    'draft_version_id' => $lockedDraft->id,
                    'membership_id' => $membership->id,
                    'field_definition_id' => $definition->id,
                    'field_definition_key' => $definition->key,
                    'field_definition_revision_id' => $revision->id,
                    'sort' => $membership->sort,
                    'required_override' => $membership->required_override,
                    'visible_override' => $membership->visible_override,
                    'field_set_key' => $locked->key,
                ],
            );

            return $membership;
        });
    }

    public function removeCustomMembership(
        FieldSet $fieldSet,
        FieldSetVersion $draft,
        FieldSetVersionField $membership,
        User $actor,
        int $expectedLockVersion,
    ): void {
        $this->assertAdminFieldSet($fieldSet);
        $this->assertDraftOfSet($fieldSet, $draft);

        if ((int) $membership->field_set_version_id !== (int) $draft->id) {
            throw ValidationException::withMessages([
                'membership' => 'Die Membership gehört nicht zu dieser Version.',
            ]);
        }

        DB::transaction(function () use ($fieldSet, $draft, $membership, $actor, $expectedLockVersion): void {
            /** @var FieldSet $locked */
            $locked = FieldSet::query()->whereKey($fieldSet->id)->lockForUpdate()->firstOrFail();
            $this->assertLock($locked, $expectedLockVersion);

            /** @var FieldSetVersion $lockedDraft */
            $lockedDraft = FieldSetVersion::query()->whereKey($draft->id)->lockForUpdate()->firstOrFail();
            if ($lockedDraft->status !== FieldSetVersionStatus::Draft) {
                throw ValidationException::withMessages([
                    'version' => 'Nur Entwurfsversionen dürfen bearbeitet werden.',
                ]);
            }

            /** @var FieldSetVersionField $lockedMembership */
            $lockedMembership = FieldSetVersionField::query()->whereKey($membership->id)->lockForUpdate()->firstOrFail();
            if ((int) $lockedMembership->field_set_version_id !== (int) $lockedDraft->id) {
                throw ValidationException::withMessages([
                    'membership' => 'Die Membership gehört nicht zu dieser Version.',
                ]);
            }

            /** @var FieldDefinition $definition */
            $definition = FieldDefinition::query()->whereKey($lockedMembership->field_definition_id)->firstOrFail();
            if ($definition->is_system) {
                throw ValidationException::withMessages([
                    'membership' => 'System-Memberships können nicht entfernt werden.',
                ]);
            }

            $before = [
                'membership_id' => $lockedMembership->id,
                'field_definition_id' => $definition->id,
                'field_definition_key' => $definition->key,
                'field_definition_revision_id' => $lockedMembership->field_definition_revision_id,
                'sort' => $lockedMembership->sort,
            ];

            $lockedMembership->delete();

            $lockedDraft->load(['fields.revision.definition', 'rules']);
            $defsByKey = [];
            foreach ($lockedDraft->fields as $row) {
                $def = $row->revision?->definition;
                if ($def === null) {
                    throw new RuntimeException('Membership ohne gültige Definition.');
                }
                $defsByKey[$def->key] = $def;
            }
            $this->rules->assertRulesCompatibleWithDefinitions($defsByKey, $lockedDraft->rules);

            $locked->lock_version = $locked->lock_version + 1;
            $locked->save();

            $this->audit->record(
                $locked,
                'field_set.membership_removed',
                $actor,
                [
                    'lock_version' => $expectedLockVersion,
                    'draft_version_id' => $lockedDraft->id,
                    'membership' => $before,
                ],
                [
                    'lock_version' => $locked->lock_version,
                    'draft_version_id' => $lockedDraft->id,
                    'field_set_key' => $locked->key,
                ],
            );
        });
    }

    /**
     * @return list<string>
     */
    public static function allowedAppliesToValuesForFieldSet(FieldSet $fieldSet): array
    {
        return match ($fieldSet->applies_to) {
            FieldAppliesTo::Calculation => [
                FieldAppliesTo::Calculation->value,
                FieldAppliesTo::Both->value,
            ],
            FieldAppliesTo::DispoOrder => [
                FieldAppliesTo::DispoOrder->value,
                FieldAppliesTo::Both->value,
            ],
            FieldAppliesTo::Both => [
                FieldAppliesTo::Calculation->value,
                FieldAppliesTo::DispoOrder->value,
                FieldAppliesTo::Both->value,
            ],
        };
    }

    private function assertAppliesToMatchesFieldSet(FieldAppliesTo $definitionAppliesTo, FieldSet $fieldSet): void
    {
        $allowed = self::allowedAppliesToValuesForFieldSet($fieldSet);
        if (! in_array($definitionAppliesTo->value, $allowed, true)) {
            throw ValidationException::withMessages([
                'field_definition_id' => match ($definitionAppliesTo) {
                    FieldAppliesTo::Calculation => 'Felder mit applies_to=calculation passen nicht zu diesem Feldset.',
                    FieldAppliesTo::DispoOrder => 'Felder mit applies_to=dispo_order passen nicht zu diesem Feldset.',
                    FieldAppliesTo::Both => 'Dieses Feld kann diesem Feldset nicht zugeordnet werden.',
                },
            ]);
        }
    }

    private function assertDraftMembershipsCompatibleWithAppliesTo(FieldSet $fieldSet, FieldAppliesTo $appliesTo): void
    {
        $draft = FieldSetVersion::query()
            ->where('field_set_id', $fieldSet->id)
            ->where('status', FieldSetVersionStatus::Draft)
            ->with(['fields.revision.definition', 'fields.definition'])
            ->first();

        if ($draft === null) {
            return;
        }

        $allowed = match ($appliesTo) {
            FieldAppliesTo::Calculation => [
                FieldAppliesTo::Calculation->value,
                FieldAppliesTo::Both->value,
            ],
            FieldAppliesTo::DispoOrder => [
                FieldAppliesTo::DispoOrder->value,
                FieldAppliesTo::Both->value,
            ],
            FieldAppliesTo::Both => [
                FieldAppliesTo::Calculation->value,
                FieldAppliesTo::DispoOrder->value,
                FieldAppliesTo::Both->value,
            ],
        };

        foreach ($draft->fields as $membership) {
            $definition = $membership->revision->definition ?? $membership->definition;
            if ($definition === null) {
                throw new RuntimeException('Membership ohne gültige Definition.');
            }
            if (! in_array($definition->applies_to->value, $allowed, true)) {
                throw ValidationException::withMessages([
                    'field_definition_id' => match ($definition->applies_to) {
                        FieldAppliesTo::Calculation => 'Felder mit applies_to=calculation passen nicht zu diesem Feldset.',
                        FieldAppliesTo::DispoOrder => 'Felder mit applies_to=dispo_order passen nicht zu diesem Feldset.',
                        FieldAppliesTo::Both => 'Dieses Feld kann diesem Feldset nicht zugeordnet werden.',
                    },
                ]);
            }
        }
    }

    private function hasEverBeenActivated(FieldSet $fieldSet): bool
    {
        return FieldSetVersion::query()
            ->where('field_set_id', $fieldSet->id)
            ->whereIn('status', [
                FieldSetVersionStatus::Active,
                FieldSetVersionStatus::Archived,
            ])
            ->exists();
    }

    private function normalizeAppliesTo(FieldAppliesTo|string $value): FieldAppliesTo
    {
        if ($value instanceof FieldAppliesTo) {
            return $value;
        }

        $enum = FieldAppliesTo::tryFrom($value);
        if ($enum === null) {
            throw ValidationException::withMessages([
                'applies_to' => 'Ungültige Gültigkeit.',
            ]);
        }

        return $enum;
    }

    private function assertDraftOfSet(FieldSet $fieldSet, FieldSetVersion $version): void
    {
        if ((int) $version->field_set_id !== (int) $fieldSet->id) {
            throw ValidationException::withMessages([
                'version' => 'Die Version gehört nicht zu diesem Feldset.',
            ]);
        }
    }

    private function assertLock(FieldSet $fieldSet, int $expectedLockVersion): void
    {
        if ((int) $fieldSet->lock_version !== $expectedLockVersion) {
            throw new FieldSetConflictException;
        }
    }
}
