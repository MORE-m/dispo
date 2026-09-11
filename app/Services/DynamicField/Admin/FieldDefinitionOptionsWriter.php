<?php

namespace App\Services\DynamicField\Admin;

use App\Exceptions\FieldDefinitionConflictException;
use App\Models\FieldDefinition;
use App\Models\FieldDefinitionRevision;
use App\Models\FieldDefinitionRevisionOption;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\DynamicField\FieldDefinitionOptionContract;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * DF-3-REST-A: atomarer Desired-State für Auswahloptionen einer Felddefinition.
 *
 * Erzeugt bei Änderung eine neue FieldDefinitionRevision. Aktive/historische
 * Revisionen werden nie in-place mutiert. Feldset-Pins bleiben unverändert.
 *
 * Lock-Reihenfolge: field_definitions → field_definition_revisions (aktuell)
 * → field_definition_revision_options der aktuellen Revision (id ASC).
 */
final class FieldDefinitionOptionsWriter
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{
     *     options: list<array<string, mixed>>,
     *     lock_version: int,
     *     fingerprint?: string
     * }  $payload
     * @return array{
     *     definition: FieldDefinition,
     *     revision: FieldDefinitionRevision|null,
     *     has_changes: bool,
     *     options: list<array{key: string, label: string, sort: int, is_active: bool}>,
     *     fingerprint: string
     * }
     */
    public function replace(FieldDefinition $definition, array $payload, User $actor): array
    {
        return DB::transaction(function () use ($definition, $payload, $actor): array {
            /** @var FieldDefinition $locked */
            $locked = FieldDefinition::query()
                ->whereKey($definition->id)
                ->lockForUpdate()
                ->firstOrFail();

            $expectedLock = (int) $payload['lock_version'];
            if ((int) $locked->lock_version !== $expectedLock) {
                throw new FieldDefinitionConflictException;
            }

            if (! $locked->field_type->isChoice()) {
                throw ValidationException::withMessages([
                    'definition' => 'Optionen können nur für select- und multi_select-Felder gepflegt werden.',
                ]);
            }

            if ($locked->current_revision_id === null) {
                throw ValidationException::withMessages([
                    'definition' => 'Die Felddefinition besitzt keine aktuelle Revision.',
                ]);
            }

            /** @var FieldDefinitionRevision $previous */
            $previous = FieldDefinitionRevision::query()
                ->whereKey($locked->current_revision_id)
                ->lockForUpdate()
                ->firstOrFail();

            FieldDefinitionRevisionOption::query()
                ->where('field_definition_revision_id', $previous->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id']);

            $previous->load('options');
            $previousOptions = FieldDefinitionOptionContract::fromRevisionOptions($previous->options);
            $desired = FieldDefinitionOptionContract::normalizeDesiredPayload($payload['options']);
            $nextOptions = FieldDefinitionOptionContract::mergeWithPrevious($desired, $previousOptions);
            $fingerprint = FieldDefinitionOptionContract::fingerprint($nextOptions);

            if (array_key_exists('fingerprint', $payload)) {
                $expectedFingerprint = (string) $payload['fingerprint'];
                if (! hash_equals($fingerprint, $expectedFingerprint)) {
                    throw new FieldDefinitionConflictException(
                        'Die Optionsvorschau ist veraltet. Bitte die Seite neu laden.',
                    );
                }
            }

            if (FieldDefinitionOptionContract::equalsCanonical($previousOptions, $nextOptions)) {
                return [
                    'definition' => $locked,
                    'revision' => null,
                    'has_changes' => false,
                    'options' => $previousOptions,
                    'fingerprint' => $fingerprint,
                ];
            }

            $revision = new FieldDefinitionRevision;
            $revision->field_definition_id = $locked->id;
            $revision->revision = ((int) $previous->revision) + 1;
            $revision->label = $previous->label;
            $revision->help_text = $previous->help_text;
            $revision->validation_json = $previous->validation_json;
            $revision->group_key = $previous->group_key;
            $revision->sort_default = $previous->sort_default;
            $revision->reportable = $previous->reportable;
            $revision->created_at = now();
            $revision->save();

            foreach ($nextOptions as $row) {
                $option = new FieldDefinitionRevisionOption;
                $option->field_definition_revision_id = $revision->id;
                $option->key = $row['key'];
                $option->label = $row['label'];
                $option->sort = $row['sort'];
                $option->is_active = $row['is_active'];
                $option->save();
            }

            $locked->current_revision_id = $revision->id;
            $locked->lock_version = $locked->lock_version + 1;
            $locked->save();

            $this->audit->record(
                $locked,
                'field_definition.options_replaced',
                $actor,
                [
                    'revision_id' => $previous->id,
                    'revision' => $previous->revision,
                    'options' => $previousOptions,
                    'lock_version' => $expectedLock,
                ],
                [
                    'revision_id' => $revision->id,
                    'revision' => $revision->revision,
                    'options' => $nextOptions,
                    'key' => $locked->key,
                    'lock_version' => $locked->lock_version,
                    'fingerprint' => $fingerprint,
                ],
            );

            return [
                'definition' => $locked->fresh() ?? $locked,
                'revision' => $revision->fresh(['options']) ?? $revision,
                'has_changes' => true,
                'options' => $nextOptions,
                'fingerprint' => $fingerprint,
            ];
        });
    }

    /**
     * @param  list<array<string, mixed>>  $options
     */
    public function previewFingerprint(FieldDefinition $definition, array $options): string
    {
        $definition->loadMissing('currentRevision.options');
        $previous = $definition->currentRevision !== null
            ? FieldDefinitionOptionContract::fromRevisionOptions($definition->currentRevision->options)
            : [];
        $desired = FieldDefinitionOptionContract::normalizeDesiredPayload($options);
        $merged = FieldDefinitionOptionContract::mergeWithPrevious($desired, $previous);

        return FieldDefinitionOptionContract::fingerprint($merged);
    }
}
