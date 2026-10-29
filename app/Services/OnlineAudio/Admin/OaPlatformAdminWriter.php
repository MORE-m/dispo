<?php

namespace App\Services\OnlineAudio\Admin;

use App\Models\OaPlatform;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\OnlineAudio\Import\OaPlatformImporter;
use Illuminate\Validation\ValidationException;

/**
 * PO-CALC-TKP-1D4c-4c: Admin-Verwaltung OA-Plattformen über den Importvertrag.
 *
 * Keine Plattformpreise. Kein Hard-Delete; Deaktivierung über is_active=false.
 * Keine automatische Anlage von Spotify/YouTube/Deezer durch diesen Slice.
 */
final class OaPlatformAdminWriter
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly OaPlatformImporter $importer,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function upsert(array $payload, User $actor): OaPlatform
    {
        $row = $this->normalizeRow($payload);
        $before = OaPlatform::query()->where('code', $row['code'])->first();
        $beforePayload = $before !== null ? $this->auditPayload($before) : null;

        $this->importer->import([$row], false);

        $platform = OaPlatform::query()->where('code', $row['code'])->firstOrFail();

        $this->audit->record(
            $platform,
            $beforePayload === null ? 'oa_platform.created' : 'oa_platform.updated',
            $actor,
            $beforePayload,
            $this->auditPayload($platform),
        );

        return $platform;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function setActive(OaPlatform $platform, bool $active, array $payload, User $actor): OaPlatform
    {
        if (($payload['code'] ?? null) !== null && (string) $payload['code'] !== (string) $platform->code) {
            throw ValidationException::withMessages(['code' => 'code ist unveränderlich.']);
        }

        return $this->upsert([
            'code' => (string) $platform->code,
            'name' => (string) ($payload['name'] ?? $platform->name),
            'is_active' => $active,
            'sort' => (int) ($payload['sort'] ?? $platform->sort),
            'is_adserver_relevant' => array_key_exists('is_adserver_relevant', $payload)
                ? (bool) $payload['is_adserver_relevant']
                : (bool) $platform->is_adserver_relevant,
        ], $actor);
    }

    /**
     * @return array<string, mixed>
     */
    public function auditPayload(OaPlatform $platform): array
    {
        return [
            'id' => (int) $platform->id,
            'code' => (string) $platform->code,
            'name' => (string) $platform->name,
            'is_active' => (bool) $platform->is_active,
            'sort' => (int) $platform->sort,
            'is_adserver_relevant' => (bool) $platform->is_adserver_relevant,
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
        if ($code === '') {
            throw ValidationException::withMessages(['code' => 'code ist erforderlich.']);
        }
        if ($name === '') {
            throw ValidationException::withMessages(['name' => 'name ist erforderlich.']);
        }

        return [
            'code' => $code,
            'name' => $name,
            'is_active' => array_key_exists('is_active', $payload) ? (bool) $payload['is_active'] : true,
            'sort' => (int) ($payload['sort'] ?? 0),
            'is_adserver_relevant' => (bool) ($payload['is_adserver_relevant'] ?? false),
        ];
    }
}
