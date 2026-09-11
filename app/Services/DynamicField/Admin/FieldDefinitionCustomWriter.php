<?php

namespace App\Services\DynamicField\Admin;

use App\Enums\FieldAppliesTo;
use App\Enums\FieldScope;
use App\Enums\FieldSetVersionStatus;
use App\Enums\FieldType;
use App\Exceptions\FieldDefinitionConflictException;
use App\Models\CalculationFieldValue;
use App\Models\CalculationPositionFieldValue;
use App\Models\DispoOrderFieldValue;
use App\Models\DispoOrderPositionFieldValue;
use App\Models\FieldDefinition;
use App\Models\FieldDefinitionRevision;
use App\Models\FieldSetVersionField;
use App\Models\SnapshotFieldDefinition;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * DF-3.2b / DF-3-REST-B: Custom Header-/Position-Felder (Text + Auswahl) anlegen,
 * ändern, deaktivieren, löschen.
 */
final class FieldDefinitionCustomWriter
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly FieldKeySlugger $slugger,
        private readonly FieldDefinitionAdminWriter $revisions,
    ) {}

    /**
     * @param  array{
     *     label: string,
     *     key?: string|null,
     *     field_type: FieldType|string,
     *     scope: FieldScope|string,
     *     applies_to: FieldAppliesTo|string,
     *     help_text?: string|null,
     *     group_key?: string|null,
     *     sort_default?: int,
     *     reportable?: bool,
     *     max_length?: int|null
     * }  $payload
     */
    public function create(array $payload, User $actor): FieldDefinition
    {
        $fieldType = $this->normalizeFieldType($payload['field_type']);
        $scope = $this->normalizeScope($payload['scope']);
        $appliesTo = $this->normalizeAppliesTo($payload['applies_to']);
        $this->assertAllowedCustomType($fieldType);
        $validationJson = $this->resolveValidationJson(
            $fieldType,
            array_key_exists('max_length', $payload),
            $payload['max_length'] ?? null,
        );
        $keyCandidate = $payload['key'] ?? null;
        $explicitKey = is_string($keyCandidate) && trim($keyCandidate) !== ''
            ? trim($keyCandidate)
            : null;
        if ($explicitKey !== null) {
            $this->slugger->assertKeyAllowed($explicitKey);
            $key = $explicitKey;
        } else {
            $key = $this->slugger->uniqueSlugFromLabel($payload['label']);
        }

        return DB::transaction(function () use ($payload, $actor, $fieldType, $scope, $appliesTo, $validationJson, $key): FieldDefinition {
            $definition = new FieldDefinition;
            $definition->key = $key;
            $definition->field_type = $fieldType;
            $definition->is_system = false;
            $definition->is_key_protected = false;
            $definition->scope = $scope;
            $definition->applies_to = $appliesTo;
            $definition->is_active = true;
            $definition->lock_version = 1;
            $definition->save();

            $revision = new FieldDefinitionRevision;
            $revision->field_definition_id = $definition->id;
            $revision->revision = 1;
            $revision->label = $payload['label'];
            $revision->help_text = $payload['help_text'] ?? null;
            $revision->group_key = $payload['group_key'] ?? null;
            $revision->sort_default = (int) ($payload['sort_default'] ?? 100);
            $revision->reportable = (bool) ($payload['reportable'] ?? false);
            $revision->validation_json = $validationJson;
            $revision->created_at = now();
            $revision->save();

            $definition->current_revision_id = $revision->id;
            $definition->save();

            $this->audit->record(
                $definition,
                'field_definition.created',
                $actor,
                null,
                [
                    'key' => $definition->key,
                    'field_type' => $definition->field_type->value,
                    'applies_to' => $definition->applies_to->value,
                    'scope' => $definition->scope->value,
                    'lock_version' => $definition->lock_version,
                    'revision_id' => $revision->id,
                    'label' => $revision->label,
                    'max_length' => is_array($validationJson) ? $validationJson['max_length'] : null,
                ],
            );

            return $definition->fresh(['currentRevision']) ?? $definition;
        });
    }

    /**
     * Strukturelle Änderung nur solange die Definition unbenutzt ist.
     *
     * @param  array{
     *     label?: string,
     *     field_type?: FieldType|string,
     *     scope?: FieldScope|string,
     *     applies_to?: FieldAppliesTo|string,
     *     help_text?: string|null,
     *     group_key?: string|null,
     *     sort_default?: int,
     *     reportable?: bool,
     *     max_length?: int|null,
     *     lock_version: int
     * }  $payload
     */
    public function updateBeforeUsed(FieldDefinition $definition, array $payload, User $actor): FieldDefinition
    {
        $this->assertCustom($definition);

        return DB::transaction(function () use ($definition, $payload, $actor): FieldDefinition {
            /** @var FieldDefinition $locked */
            $locked = FieldDefinition::query()->whereKey($definition->id)->lockForUpdate()->firstOrFail();
            $this->assertCustom($locked);
            $this->assertLock($locked, (int) $payload['lock_version']);

            if ($this->isUsed($locked)) {
                throw ValidationException::withMessages([
                    'definition' => 'Diese Definition wird bereits verwendet. Bitte eine neue Revision anlegen.',
                ]);
            }

            $locked->load('currentRevision');
            $previous = $locked->currentRevision;
            if ($previous === null) {
                throw ValidationException::withMessages([
                    'definition' => 'Die Definition besitzt keine aktuelle Revision.',
                ]);
            }

            $before = [
                'key' => $locked->key,
                'field_type' => $locked->field_type->value,
                'scope' => $locked->scope->value,
                'applies_to' => $locked->applies_to->value,
                'lock_version' => $locked->lock_version,
                'label' => $previous->label,
                'help_text' => $previous->help_text,
                'group_key' => $previous->group_key,
                'sort_default' => $previous->sort_default,
                'reportable' => $previous->reportable,
                'validation_json' => $previous->validation_json,
            ];

            if (isset($payload['field_type'])) {
                $fieldType = $this->normalizeFieldType($payload['field_type']);
                $this->assertAllowedCustomType($fieldType);
                $locked->field_type = $fieldType;
            }
            if (isset($payload['scope'])) {
                $locked->scope = $this->normalizeScope($payload['scope']);
            }
            if (isset($payload['applies_to'])) {
                $locked->applies_to = $this->normalizeAppliesTo($payload['applies_to']);
            }

            $fieldType = $locked->field_type;
            $previousMaxLength = is_array($previous->validation_json)
                ? ($previous->validation_json['max_length'] ?? null)
                : null;
            $maxLengthPresent = array_key_exists('max_length', $payload);
            $validationJson = $this->resolveValidationJson(
                $fieldType,
                $maxLengthPresent,
                $maxLengthPresent ? ($payload['max_length'] ?? null) : $previousMaxLength,
            );

            if (isset($payload['label'])) {
                $previous->label = $payload['label'];
            }
            if (array_key_exists('help_text', $payload)) {
                $previous->help_text = $payload['help_text'];
            }
            if (array_key_exists('group_key', $payload)) {
                $previous->group_key = $payload['group_key'];
            }
            if (isset($payload['sort_default'])) {
                $previous->sort_default = (int) $payload['sort_default'];
            }
            if (isset($payload['reportable'])) {
                $previous->reportable = (bool) $payload['reportable'];
            }
            $previous->validation_json = $validationJson;
            $previous->save();

            $locked->lock_version = $locked->lock_version + 1;
            $locked->save();

            $this->audit->record(
                $locked,
                'field_definition.updated',
                $actor,
                $before,
                [
                    'key' => $locked->key,
                    'field_type' => $locked->field_type->value,
                    'scope' => $locked->scope->value,
                    'applies_to' => $locked->applies_to->value,
                    'lock_version' => $locked->lock_version,
                    'label' => $previous->label,
                    'help_text' => $previous->help_text,
                    'group_key' => $previous->group_key,
                    'sort_default' => $previous->sort_default,
                    'reportable' => $previous->reportable,
                    'validation_json' => $previous->validation_json,
                ],
            );

            return $locked->fresh(['currentRevision']) ?? $locked;
        });
    }

    /**
     * @param  array{
     *     label: string,
     *     help_text?: string|null,
     *     group_key?: string|null,
     *     sort_default: int,
     *     reportable: bool,
     *     max_length?: int|null,
     *     lock_version: int
     * }  $payload
     */
    public function createRevision(FieldDefinition $definition, array $payload, User $actor): FieldDefinitionRevision
    {
        $this->assertCustom($definition);

        $fieldType = $definition->field_type;
        if ($fieldType->isChoice()) {
            if (array_key_exists('max_length', $payload) && $payload['max_length'] !== null) {
                throw ValidationException::withMessages([
                    'max_length' => 'max_length ist für select- und multi_select-Felder nicht zulässig.',
                ]);
            }

            return $this->revisions->createRevision(
                $definition,
                [
                    'label' => $payload['label'],
                    'help_text' => $payload['help_text'] ?? null,
                    'group_key' => $payload['group_key'] ?? null,
                    'sort_default' => (int) $payload['sort_default'],
                    'reportable' => (bool) $payload['reportable'],
                ],
                $actor,
                (int) $payload['lock_version'],
            );
        }

        $maxLength = null;
        if (array_key_exists('max_length', $payload)) {
            $maxLength = $this->resolveMaxLength($fieldType, $payload['max_length']);
        }

        return $this->revisions->createRevision(
            $definition,
            [
                'label' => $payload['label'],
                'help_text' => $payload['help_text'] ?? null,
                'group_key' => $payload['group_key'] ?? null,
                'sort_default' => (int) $payload['sort_default'],
                'reportable' => (bool) $payload['reportable'],
                'max_length' => $maxLength,
                'validation_json' => $maxLength === null ? null : ['max_length' => $maxLength],
            ],
            $actor,
            (int) $payload['lock_version'],
        );
    }

    public function deactivate(FieldDefinition $definition, User $actor, int $expectedLockVersion): FieldDefinition
    {
        $this->assertCustom($definition);

        return DB::transaction(function () use ($definition, $actor, $expectedLockVersion): FieldDefinition {
            /** @var FieldDefinition $locked */
            $locked = FieldDefinition::query()->whereKey($definition->id)->lockForUpdate()->firstOrFail();
            $this->assertCustom($locked);
            $this->assertLock($locked, $expectedLockVersion);

            if (! $locked->is_active) {
                throw ValidationException::withMessages([
                    'definition' => 'Die Definition ist bereits deaktiviert.',
                ]);
            }

            if ($this->hasActiveOrDraftMembership($locked)) {
                throw ValidationException::withMessages([
                    'definition' => 'Die Definition ist noch Mitglied einer aktiven oder Entwurfs-Feldsetversion. Bitte zuerst entfernen und die Version aktivieren.',
                ]);
            }

            $before = ['is_active' => true, 'lock_version' => $locked->lock_version];
            $locked->is_active = false;
            $locked->lock_version = $locked->lock_version + 1;
            $locked->save();

            $this->audit->record(
                $locked,
                'field_definition.deactivated',
                $actor,
                $before,
                ['is_active' => false, 'lock_version' => $locked->lock_version, 'key' => $locked->key],
            );

            return $locked;
        });
    }

    public function reactivate(FieldDefinition $definition, User $actor, int $expectedLockVersion): FieldDefinition
    {
        $this->assertCustom($definition);

        return DB::transaction(function () use ($definition, $actor, $expectedLockVersion): FieldDefinition {
            /** @var FieldDefinition $locked */
            $locked = FieldDefinition::query()->whereKey($definition->id)->lockForUpdate()->firstOrFail();
            $this->assertCustom($locked);
            $this->assertLock($locked, $expectedLockVersion);

            if ($locked->is_active) {
                throw ValidationException::withMessages([
                    'definition' => 'Die Definition ist bereits aktiv.',
                ]);
            }

            $before = ['is_active' => false, 'lock_version' => $locked->lock_version];
            $locked->is_active = true;
            $locked->lock_version = $locked->lock_version + 1;
            $locked->save();

            $this->audit->record(
                $locked,
                'field_definition.reactivated',
                $actor,
                $before,
                ['is_active' => true, 'lock_version' => $locked->lock_version, 'key' => $locked->key],
            );

            return $locked;
        });
    }

    public function destroy(FieldDefinition $definition, User $actor, int $expectedLockVersion): void
    {
        $this->assertCustom($definition);

        DB::transaction(function () use ($definition, $actor, $expectedLockVersion): void {
            /** @var FieldDefinition $locked */
            $locked = FieldDefinition::query()->whereKey($definition->id)->lockForUpdate()->firstOrFail();
            $this->assertCustom($locked);
            $this->assertLock($locked, $expectedLockVersion);

            if ($this->hasAnyMembership($locked)
                || $this->hasSnapshotReference($locked)
                || $this->hasAnyValue($locked)) {
                throw ValidationException::withMessages([
                    'definition' => 'Die Definition kann nicht gelöscht werden, solange Memberships, Snapshots oder Werte existieren.',
                ]);
            }

            $snapshot = [
                'id' => $locked->id,
                'key' => $locked->key,
                'lock_version' => $locked->lock_version,
            ];

            FieldDefinitionRevision::query()
                ->where('field_definition_id', $locked->id)
                ->delete();

            $locked->current_revision_id = null;
            $locked->save();
            $locked->delete();

            $this->audit->record(
                $definition,
                'field_definition.deleted',
                $actor,
                $snapshot,
                null,
            );
        });
    }

    public function isUsed(FieldDefinition $definition): bool
    {
        return $this->hasActiveOrDraftMembership($definition)
            || $this->hasActiveOrArchivedMembership($definition)
            || $this->hasSnapshotReference($definition)
            || $this->hasAnyValue($definition);
    }

    private function assertCustom(FieldDefinition $definition): void
    {
        if ($definition->is_system) {
            throw ValidationException::withMessages([
                'definition' => 'Systemfelder können nicht über die Custom-Feld-API geändert werden.',
            ]);
        }
    }

    private function assertLock(FieldDefinition $definition, int $expectedLockVersion): void
    {
        if ((int) $definition->lock_version !== $expectedLockVersion) {
            throw new FieldDefinitionConflictException;
        }
    }

    private function assertAllowedCustomType(FieldType $fieldType): void
    {
        if (! in_array($fieldType, [
            FieldType::ShortText,
            FieldType::LongText,
            FieldType::Select,
            FieldType::MultiSelect,
        ], true)) {
            throw ValidationException::withMessages([
                'field_type' => 'Zulässig sind short_text, long_text, select und multi_select.',
            ]);
        }
    }

    private function normalizeFieldType(FieldType|string $value): FieldType
    {
        return $value instanceof FieldType ? $value : FieldType::from((string) $value);
    }

    private function normalizeScope(FieldScope|string $value): FieldScope
    {
        $scope = $value instanceof FieldScope ? $value : FieldScope::from((string) $value);
        if (! in_array($scope, [FieldScope::Header, FieldScope::Position], true)) {
            throw ValidationException::withMessages([
                'scope' => 'Scope muss header oder position sein.',
            ]);
        }

        return $scope;
    }

    private function normalizeAppliesTo(FieldAppliesTo|string $value): FieldAppliesTo
    {
        return $value instanceof FieldAppliesTo ? $value : FieldAppliesTo::from((string) $value);
    }

    /**
     * @return array{max_length: int}|null
     */
    private function resolveValidationJson(FieldType $fieldType, bool $maxLengthPresent, mixed $raw): ?array
    {
        if ($fieldType->isChoice()) {
            if ($maxLengthPresent && $raw !== null && $raw !== '') {
                throw ValidationException::withMessages([
                    'max_length' => 'max_length ist für select- und multi_select-Felder nicht zulässig.',
                ]);
            }

            return null;
        }

        return ['max_length' => $this->resolveMaxLength($fieldType, $raw)];
    }

    private function resolveMaxLength(FieldType $fieldType, mixed $raw): int
    {
        if ($fieldType->isChoice()) {
            throw ValidationException::withMessages([
                'max_length' => 'max_length ist für select- und multi_select-Felder nicht zulässig.',
            ]);
        }

        $default = $fieldType === FieldType::ShortText ? 255 : 20000;
        $maxAllowed = $default;
        $value = $raw === null || $raw === '' ? $default : (int) $raw;

        if ($value < 1 || $value > $maxAllowed) {
            throw ValidationException::withMessages([
                'max_length' => $fieldType === FieldType::ShortText
                    ? 'max_length muss zwischen 1 und 255 liegen.'
                    : 'max_length muss zwischen 1 und 20000 liegen.',
            ]);
        }

        return $value;
    }

    private function hasActiveOrDraftMembership(FieldDefinition $definition): bool
    {
        return FieldSetVersionField::query()
            ->where('field_definition_id', $definition->id)
            ->whereHas('version', function ($query): void {
                $query->whereIn('status', [
                    FieldSetVersionStatus::Active,
                    FieldSetVersionStatus::Draft,
                ]);
            })
            ->exists();
    }

    private function hasActiveOrArchivedMembership(FieldDefinition $definition): bool
    {
        return FieldSetVersionField::query()
            ->where('field_definition_id', $definition->id)
            ->whereHas('version', function ($query): void {
                $query->whereIn('status', [
                    FieldSetVersionStatus::Active,
                    FieldSetVersionStatus::Archived,
                ]);
            })
            ->exists();
    }

    private function hasAnyMembership(FieldDefinition $definition): bool
    {
        return FieldSetVersionField::query()
            ->where('field_definition_id', $definition->id)
            ->exists();
    }

    private function hasSnapshotReference(FieldDefinition $definition): bool
    {
        return SnapshotFieldDefinition::query()
            ->where('field_definition_id', $definition->id)
            ->exists();
    }

    private function hasAnyValue(FieldDefinition $definition): bool
    {
        $snapshotIds = SnapshotFieldDefinition::query()
            ->where('field_definition_id', $definition->id)
            ->pluck('id');

        if ($snapshotIds->isEmpty()) {
            return false;
        }

        return CalculationFieldValue::query()->whereIn('snapshot_field_definition_id', $snapshotIds)->exists()
            || CalculationPositionFieldValue::query()->whereIn('snapshot_field_definition_id', $snapshotIds)->exists()
            || DispoOrderFieldValue::query()->whereIn('snapshot_field_definition_id', $snapshotIds)->exists()
            || DispoOrderPositionFieldValue::query()->whereIn('snapshot_field_definition_id', $snapshotIds)->exists();
    }
}
