<?php

namespace App\Services\DynamicField\Admin;

use App\Models\FieldDefinition;
use App\Models\FieldDefinitionRevision;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * DF-3.1 / DYN-001 / ADM-001: neue Revision für geschützte Systemdefinitionen.
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
     *     validation_json?: array<string, mixed>|null
     * }  $payload
     */
    public function createRevision(FieldDefinition $definition, array $payload, User $actor): FieldDefinitionRevision
    {
        if (! $definition->is_system || ! $definition->is_key_protected) {
            throw ValidationException::withMessages([
                'definition' => 'In DF-3.1 dürfen nur geschützte Systemfelder revisioniert werden.',
            ]);
        }

        return DB::transaction(function () use ($definition, $payload, $actor): FieldDefinitionRevision {
            /** @var FieldDefinition $locked */
            $locked = FieldDefinition::query()->whereKey($definition->id)->lockForUpdate()->firstOrFail();
            $locked->load('currentRevision');

            $previous = $locked->currentRevision;
            $nextRevisionNumber = $previous === null
                ? 1
                : ((int) $previous->revision) + 1;

            $revision = new FieldDefinitionRevision;
            $revision->field_definition_id = $locked->id;
            $revision->revision = $nextRevisionNumber;
            $revision->label = $payload['label'];
            $revision->help_text = $payload['help_text'] ?? null;
            $revision->group_key = $payload['group_key'] ?? null;
            $revision->sort_default = $payload['sort_default'];
            $revision->reportable = $payload['reportable'];
            $revision->validation_json = $payload['validation_json'] ?? $previous?->validation_json;
            $revision->created_at = now();
            $revision->save();

            $locked->current_revision_id = $revision->id;
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
                ],
                [
                    'revision_id' => $revision->id,
                    'revision' => $revision->revision,
                    'label' => $revision->label,
                    'help_text' => $revision->help_text,
                    'group_key' => $revision->group_key,
                    'sort_default' => $revision->sort_default,
                    'reportable' => $revision->reportable,
                    'key' => $locked->key,
                ],
            );

            return $revision;
        });
    }
}
