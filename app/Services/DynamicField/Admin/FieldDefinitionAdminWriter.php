<?php

namespace App\Services\DynamicField\Admin;

use App\Exceptions\FieldDefinitionConflictException;
use App\Models\FieldDefinition;
use App\Models\FieldDefinitionRevision;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * DF-3.1 / DF-3.2a / DYN-001 / ADM-001: neue Revision für System- und Custom-Definitionen.
 */
final class FieldDefinitionAdminWriter
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{
     *     label: string,
     *     help_text?: string|null,
     *     group_key?: string|null,
     *     sort_default: int,
     *     reportable: bool,
     *     validation_json?: array<string, mixed>|null,
     *     max_length?: int|null
     * }  $payload
     */
    public function createRevision(
        FieldDefinition $definition,
        array $payload,
        User $actor,
        int $expectedLockVersion,
    ): FieldDefinitionRevision {
        $isSystemProtected = $definition->is_system && $definition->is_key_protected;
        $isCustom = ! $definition->is_system;

        if (! $isSystemProtected && ! $isCustom) {
            throw ValidationException::withMessages([
                'definition' => 'Nur geschützte Systemfelder oder Custom-Felder dürfen revisioniert werden.',
            ]);
        }

        return DB::transaction(function () use ($definition, $payload, $actor, $expectedLockVersion): FieldDefinitionRevision {
            /** @var FieldDefinition $locked */
            $locked = FieldDefinition::query()->whereKey($definition->id)->lockForUpdate()->firstOrFail();

            if ((int) $locked->lock_version !== $expectedLockVersion) {
                throw new FieldDefinitionConflictException;
            }

            $locked->load('currentRevision');

            $previous = $locked->currentRevision;
            $nextRevisionNumber = $previous === null
                ? 1
                : ((int) $previous->revision) + 1;

            $validationJson = $payload['validation_json'] ?? $previous?->validation_json;
            if (array_key_exists('max_length', $payload) && $payload['max_length'] !== null) {
                $validationJson = is_array($validationJson) ? $validationJson : [];
                $validationJson['max_length'] = (int) $payload['max_length'];
            }

            $revision = new FieldDefinitionRevision;
            $revision->field_definition_id = $locked->id;
            $revision->revision = $nextRevisionNumber;
            $revision->label = $payload['label'];
            $revision->help_text = $payload['help_text'] ?? null;
            $revision->group_key = $payload['group_key'] ?? null;
            $revision->sort_default = $payload['sort_default'];
            $revision->reportable = $payload['reportable'];
            $revision->validation_json = $validationJson;
            $revision->created_at = now();
            $revision->save();

            $locked->current_revision_id = $revision->id;
            $locked->lock_version = $locked->lock_version + 1;
            $locked->save();

            $this->audit->record(
                $locked,
                'field_definition.revision_created',
                $actor,
                $previous === null ? null : [
                    'revision_id' => $previous->id,
                    'revision' => $previous->revision,
                    'label' => $previous->label,
                    'help_text' => $previous->help_text,
                    'group_key' => $previous->group_key,
                    'sort_default' => $previous->sort_default,
                    'reportable' => $previous->reportable,
                    'validation_json' => $previous->validation_json,
                    'lock_version' => $expectedLockVersion,
                ],
                [
                    'revision_id' => $revision->id,
                    'revision' => $revision->revision,
                    'label' => $revision->label,
                    'help_text' => $revision->help_text,
                    'group_key' => $revision->group_key,
                    'sort_default' => $revision->sort_default,
                    'reportable' => $revision->reportable,
                    'validation_json' => $revision->validation_json,
                    'key' => $locked->key,
                    'lock_version' => $locked->lock_version,
                ],
            );

            return $revision;
        });
    }
}
