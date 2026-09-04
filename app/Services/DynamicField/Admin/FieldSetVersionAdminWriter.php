<?php

namespace App\Services\DynamicField\Admin;

use App\Enums\FieldSetVersionStatus;
use App\Exceptions\FieldSetConflictException;
use App\Models\FieldDefinition;
use App\Models\FieldDefinitionRevision;
use App\Models\FieldRule;
use App\Models\FieldSet;
use App\Models\FieldSetVersion;
use App\Models\FieldSetVersionField;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\DynamicField\SnapshotFieldRuleEvaluator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * DF-3.1 / DYN-003 / VER-005 / VER-006 / ADM-001: Draft, Activate, Copy-as-template.
 * Regeln werden nur kopiert, nie mutiert.
 */
final class FieldSetVersionAdminWriter
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly SnapshotFieldRuleEvaluator $rules,
    ) {}

    public function assertAdminFieldSet(FieldSet $fieldSet): void
    {
        if (! AdminFieldSetCatalog::isAllowed($fieldSet->key)) {
            throw ValidationException::withMessages([
                'field_set' => 'Dieses Feldset ist in DF-3.1 nicht administrierbar.',
            ]);
        }
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
                    'fields' => 'In DF-3.1 dürfen Memberships nicht hinzugefügt oder entfernt werden.',
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

            $lockedDraft->load(['fields.revision.definition', 'rules']);
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
            $locked->lock_version = $locked->lock_version + 1;
            $locked->save();

            $this->audit->record(
                $locked,
                'field_set.version_activated',
                $actor,
                [
                    'lock_version' => $expectedLockVersion,
                    'active_version_id' => $previousActiveId,
                ],
                [
                    'lock_version' => $locked->lock_version,
                    'active_version_id' => $lockedDraft->id,
                    'active_version' => $lockedDraft->version,
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
