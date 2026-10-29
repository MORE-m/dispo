<?php

namespace App\Services\OnlineAudio\Admin;

use App\Enums\OaTargetingCategory;
use App\Models\OaTargetingDefinition;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\OnlineAudio\Import\OaTargetingDefinitionImporter;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * PO-CALC-TKP-1D4c-4c: Admin-Verwaltung OA-Targetings über den Importvertrag.
 *
 * Identity: (code, revision_number). Preisänderungen an bestehenden Revisionen
 * gehen über denselben Importer wie CLI (kein Raw-CRUD). Historische Kalkulationen
 * bleiben über Selection-Snapshots + Definition-ID-Pin geschützt.
 *
 * Empfehlung für Preiserhöhungen: neue revision_number anlegen und alte ggf.
 * is_active=false setzen – nicht stillschweigend Live-Lookup-Werte umbiegen.
 */
final class OaTargetingAdminWriter
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly OaTargetingDefinitionImporter $importer,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function upsert(array $payload, User $actor): OaTargetingDefinition
    {
        $row = $this->normalizeRow($payload);
        $before = OaTargetingDefinition::query()
            ->where('code', $row['code'])
            ->where('revision_number', $row['revision_number'])
            ->first();
        $beforePayload = $before !== null ? $this->auditPayload($before) : null;

        $this->importer->import([$row], false);

        $definition = OaTargetingDefinition::query()
            ->where('code', $row['code'])
            ->where('revision_number', $row['revision_number'])
            ->firstOrFail();

        $this->audit->record(
            $definition,
            $beforePayload === null ? 'oa_targeting_definition.created' : 'oa_targeting_definition.updated',
            $actor,
            $beforePayload,
            $this->auditPayload($definition),
        );

        return $definition;
    }

    /**
     * Neue Revision für bestehenden Code (revisionssichere Preisänderung).
     *
     * @param  array<string, mixed>  $payload
     */
    public function createNewRevision(string $code, array $payload, User $actor): OaTargetingDefinition
    {
        $code = trim($code);
        if ($code === '') {
            throw ValidationException::withMessages(['code' => 'code ist erforderlich.']);
        }

        $latest = OaTargetingDefinition::query()
            ->where('code', $code)
            ->orderByDesc('revision_number')
            ->first();

        if ($latest === null) {
            throw ValidationException::withMessages([
                'code' => "Targeting „{$code}“ existiert nicht; bitte upsert mit revision_number nutzen.",
            ]);
        }

        $nextRevision = ((int) $latest->revision_number) + 1;
        $merged = [
            'code' => $code,
            'name' => $payload['name'] ?? $latest->name,
            'category' => $payload['category'] ?? $latest->category->value,
            'tkp_surcharge' => $payload['tkp_surcharge'] ?? $latest->tkp_surcharge,
            'minimum_tkp_surcharge' => $payload['minimum_tkp_surcharge'] ?? $latest->minimum_tkp_surcharge,
            'revision_number' => $nextRevision,
            'is_active' => array_key_exists('is_active', $payload) ? (bool) $payload['is_active'] : true,
            'valid_from' => $payload['valid_from'] ?? null,
            'valid_to' => $payload['valid_to'] ?? null,
        ];

        return DB::transaction(function () use ($merged, $latest, $actor, $payload): OaTargetingDefinition {
            if (($payload['deactivate_previous'] ?? false) === true) {
                $beforePrev = $this->auditPayload($latest);
                $latest->is_active = false;
                $latest->save();
                $this->audit->record(
                    $latest->fresh() ?? $latest,
                    'oa_targeting_definition.updated',
                    $actor,
                    $beforePrev,
                    $this->auditPayload($latest->fresh() ?? $latest),
                );
            }

            return $this->upsert($merged, $actor);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function auditPayload(OaTargetingDefinition $definition): array
    {
        return [
            'id' => (int) $definition->id,
            'code' => (string) $definition->code,
            'name' => (string) $definition->name,
            'category' => $definition->category instanceof OaTargetingCategory
                ? $definition->category->value
                : (string) $definition->category,
            'tkp_surcharge' => (string) $definition->tkp_surcharge,
            'minimum_tkp_surcharge' => (string) $definition->minimum_tkp_surcharge,
            'revision_number' => (int) $definition->revision_number,
            'is_active' => (bool) $definition->is_active,
            'valid_from' => $definition->valid_from?->toDateString(),
            'valid_to' => $definition->valid_to?->toDateString(),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function normalizeRow(array $payload): array
    {
        $code = trim((string) ($payload['code'] ?? ''));
        $name = trim((string) ($payload['name'] ?? ''));
        $category = trim((string) ($payload['category'] ?? ''));
        $revision = (int) ($payload['revision_number'] ?? 0);

        if ($code === '') {
            throw ValidationException::withMessages(['code' => 'code ist erforderlich.']);
        }
        if ($name === '') {
            throw ValidationException::withMessages(['name' => 'name ist erforderlich.']);
        }
        if (OaTargetingCategory::tryFrom($category) === null) {
            throw ValidationException::withMessages([
                'category' => 'category ungültig (erwartet technisch|dmp). Geo wird unter „technisch“ geführt.',
            ]);
        }
        if ($revision < 1) {
            throw ValidationException::withMessages(['revision_number' => 'revision_number muss ≥ 1 sein.']);
        }
        if (! array_key_exists('tkp_surcharge', $payload)) {
            throw ValidationException::withMessages(['tkp_surcharge' => 'tkp_surcharge (Listen-Aufschlag) ist erforderlich.']);
        }

        return [
            'code' => $code,
            'name' => $name,
            'category' => $category,
            'tkp_surcharge' => $payload['tkp_surcharge'],
            'minimum_tkp_surcharge' => $payload['minimum_tkp_surcharge'] ?? '0.0000',
            'revision_number' => $revision,
            'is_active' => array_key_exists('is_active', $payload) ? (bool) $payload['is_active'] : true,
            'valid_from' => $payload['valid_from'] ?? null,
            'valid_to' => $payload['valid_to'] ?? null,
        ];
    }
}
