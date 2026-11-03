<?php

namespace App\Services\OnlineAudio\Admin;

use App\Exceptions\OaTkpAdminConflictException;
use App\Models\AdvertisingMedium;
use App\Models\OaPlatform;
use App\Models\OaPlatformMediumRule;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\OnlineAudio\OaPlatformMediumRules;
use App\Support\OnlineAudio\OaTkpCoreMedia;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * PO-CALC-TKP-1D4c-4j-2: Admin-Matrix Plattform × OA-Kernmedium.
 *
 * Voller Desired-State in einer Transaktion. Kein Hard-Delete, kein Enforcement.
 */
final class OaPlatformMediumRuleAdminWriter
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @return array{
     *     revision_token: string,
     *     media: list<array{id: int, code: string, name: string}>,
     *     platforms: list<array<string, mixed>>,
     *     notes: array<string, string>
     * }
     */
    public function snapshot(): array
    {
        return DB::transaction(function (): array {
            return $this->present($this->loadLockedState());
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{
     *     message: string,
     *     revision_token: string,
     *     media: list<array{id: int, code: string, name: string}>,
     *     platforms: list<array<string, mixed>>,
     *     notes: array<string, string>
     * }
     */
    public function replace(array $payload, User $actor): array
    {
        return DB::transaction(function () use ($payload, $actor): array {
            $state = $this->loadLockedState();
            $this->assertToken($state, $payload);
            $desired = $this->desiredCells($state, $payload);
            $changed = $this->apply($state, $desired, $actor);
            $fresh = $this->present($this->loadLockedState());

            return [
                'message' => $changed ? 'Matrix gespeichert.' : 'Keine Änderungen.',
                ...$fresh,
            ];
        });
    }

    /**
     * @param  array{
     *     platforms: Collection<int, OaPlatform>,
     *     media: Collection<int, AdvertisingMedium>,
     *     rules: Collection<int, OaPlatformMediumRule>
     * }  $state
     * @return array{
     *     revision_token: string,
     *     media: list<array{id: int, code: string, name: string}>,
     *     platforms: list<array<string, mixed>>,
     *     notes: array<string, string>
     * }
     */
    private function present(array $state): array
    {
        /** @var array<string, OaPlatformMediumRule> $rulesByPair */
        $rulesByPair = [];
        foreach ($state['rules'] as $rule) {
            $rulesByPair[(int) $rule->oa_platform_id.'|'.(int) $rule->advertising_medium_id] = $rule;
        }

        $media = [];
        $platforms = [];
        foreach ($state['media'] as $medium) {
            $media[] = [
                'id' => (int) $medium->id,
                'code' => (string) $medium->code,
                'name' => (string) $medium->name,
            ];
        }

        foreach ($state['platforms'] as $platform) {
            $cells = [];
            foreach ($state['media'] as $medium) {
                $rule = $rulesByPair[(int) $platform->id.'|'.(int) $medium->id] ?? null;
                $cells[] = [
                    'medium_code' => (string) $medium->code,
                    'advertising_medium_id' => (int) $medium->id,
                    'is_active' => $rule !== null && (bool) $rule->is_active,
                    'rule_id' => $rule !== null ? (int) $rule->id : null,
                ];
            }

            $platforms[] = [
                'id' => (int) $platform->id,
                'code' => (string) $platform->code,
                'name' => (string) $platform->name,
                'is_active' => (bool) $platform->is_active,
                'sort' => (int) $platform->sort,
                'cells' => $cells,
            ];
        }

        return [
            'revision_token' => $this->revisionToken($state),
            'media' => $media,
            'platforms' => $platforms,
            'notes' => $this->notes(),
        ];
    }

    /**
     * Lock-Reihenfolge: Kernmedien nach ID, Plattformen nach ID, Rules nach Paar.
     *
     * @return array{
     *     platforms: Collection<int, OaPlatform>,
     *     media: Collection<int, AdvertisingMedium>,
     *     rules: Collection<int, OaPlatformMediumRule>
     * }
     */
    private function loadLockedState(): array
    {
        /** @var Collection<int, AdvertisingMedium> $lockedMedia */
        $lockedMedia = AdvertisingMedium::query()
            ->whereIn('code', OaTkpCoreMedia::codes())
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        /** @var array<string, AdvertisingMedium> $mediaByCode */
        $mediaByCode = [];
        foreach ($lockedMedia as $medium) {
            $mediaByCode[(string) $medium->code] = $medium;
        }

        /** @var Collection<int, AdvertisingMedium> $media */
        $media = new Collection;
        foreach (OaTkpCoreMedia::codes() as $code) {
            $medium = $mediaByCode[$code] ?? null;
            if (! $medium instanceof AdvertisingMedium) {
                throw ValidationException::withMessages([
                    'media' => "OA-Kernmedium „{$code}“ fehlt im Katalog.",
                ]);
            }
            $media->push($medium);
        }

        /** @var Collection<int, OaPlatform> $lockedPlatforms */
        $lockedPlatforms = OaPlatform::query()
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->sort(function (OaPlatform $left, OaPlatform $right): int {
                $bySort = ((int) $left->sort) <=> ((int) $right->sort);

                return $bySort !== 0 ? $bySort : strcmp((string) $left->code, (string) $right->code);
            })
            ->values();

        /** @var Collection<int, OaPlatformMediumRule> $rules */
        $rules = OaPlatformMediumRule::query()
            ->orderBy('oa_platform_id')
            ->orderBy('advertising_medium_id')
            ->lockForUpdate()
            ->get();

        return [
            'platforms' => $lockedPlatforms,
            'media' => $media,
            'rules' => $rules,
        ];
    }

    /**
     * @param  array{
     *     platforms: Collection<int, OaPlatform>,
     *     media: Collection<int, AdvertisingMedium>,
     *     rules: Collection<int, OaPlatformMediumRule>
     * }  $state
     */
    private function revisionToken(array $state): string
    {
        $platformIds = [];
        foreach ($state['platforms'] as $platform) {
            $platformIds[] = (int) $platform->id;
        }
        sort($platformIds);

        $mediaRows = [];
        foreach ($state['media'] as $medium) {
            $mediaRows[] = [
                'advertising_medium_id' => (int) $medium->id,
                'code' => (string) $medium->code,
            ];
        }

        $ruleRows = [];
        foreach ($state['rules'] as $rule) {
            $ruleRows[] = [
                'oa_platform_id' => (int) $rule->oa_platform_id,
                'advertising_medium_id' => (int) $rule->advertising_medium_id,
                'is_active' => (bool) $rule->is_active,
            ];
        }
        usort($ruleRows, function (array $left, array $right): int {
            $byPlatform = $left['oa_platform_id'] <=> $right['oa_platform_id'];

            return $byPlatform !== 0
                ? $byPlatform
                : $left['advertising_medium_id'] <=> $right['advertising_medium_id'];
        });

        $canonical = [
            'platforms' => array_map(
                static fn (int $id): array => ['oa_platform_id' => $id],
                $platformIds,
            ),
            'media' => $mediaRows,
            'rules' => $ruleRows,
        ];

        $encoded = json_encode(
            $canonical,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );

        return hash('sha256', $encoded);
    }

    /**
     * @param  array{
     *     platforms: Collection<int, OaPlatform>,
     *     media: Collection<int, AdvertisingMedium>,
     *     rules: Collection<int, OaPlatformMediumRule>
     * }  $state
     * @param  array<string, mixed>  $payload
     */
    private function assertToken(array $state, array $payload): void
    {
        $token = $payload['revision_token'] ?? null;
        if (! is_string($token) || $token === '') {
            throw ValidationException::withMessages([
                'revision_token' => 'revision_token ist erforderlich.',
            ]);
        }

        $current = $this->revisionToken($state);
        if (strlen($token) !== strlen($current) || ! hash_equals($current, $token)) {
            throw new OaTkpAdminConflictException(
                'Die Zuordnungsmatrix wurde parallel geändert. Bitte neu laden.',
            );
        }
    }

    /**
     * @param  array{
     *     platforms: Collection<int, OaPlatform>,
     *     media: Collection<int, AdvertisingMedium>,
     *     rules: Collection<int, OaPlatformMediumRule>
     * }  $state
     * @param  array<string, mixed>  $payload
     * @return list<array{oa_platform_id: int, advertising_medium_id: int, medium_code: string, is_active: bool}>
     */
    private function desiredCells(array $state, array $payload): array
    {
        $cells = $payload['cells'] ?? null;
        if (! is_array($cells)) {
            throw ValidationException::withMessages([
                'cells' => 'cells ist erforderlich.',
            ]);
        }

        $platformIds = [];
        foreach ($state['platforms'] as $platform) {
            $platformIds[(int) $platform->id] = true;
        }

        $expected = [];
        foreach ($state['platforms'] as $platform) {
            foreach ($state['media'] as $medium) {
                $expected[(int) $platform->id.'|'.(int) $medium->id] = true;
            }
        }

        /** @var array<string, true> $seen */
        $seen = [];
        /** @var list<array{oa_platform_id: int, advertising_medium_id: int, medium_code: string, is_active: bool}> $desired */
        $desired = [];

        foreach (array_values($cells) as $index => $cell) {
            if (! is_array($cell)) {
                throw ValidationException::withMessages([
                    "cells.{$index}" => 'Ungültige Zelle.',
                ]);
            }

            $platformId = $cell['oa_platform_id'] ?? null;
            if (! is_int($platformId)) {
                throw ValidationException::withMessages([
                    "cells.{$index}.oa_platform_id" => 'oa_platform_id ist erforderlich.',
                ]);
            }
            if (! isset($platformIds[$platformId])) {
                throw ValidationException::withMessages([
                    "cells.{$index}.oa_platform_id" => 'Unbekannte Plattform.',
                ]);
            }

            $mediumCode = $cell['medium_code'] ?? null;
            if (! is_string($mediumCode) || trim($mediumCode) === '') {
                throw ValidationException::withMessages([
                    "cells.{$index}.medium_code" => 'medium_code ist erforderlich.',
                ]);
            }

            if (! array_key_exists('is_active', $cell) || ! is_bool($cell['is_active'])) {
                throw ValidationException::withMessages([
                    "cells.{$index}.is_active" => 'is_active muss wahr oder falsch sein.',
                ]);
            }

            $medium = OaPlatformMediumRules::assertAdvertisingMediumAllowed($mediumCode);
            $pair = $platformId.'|'.(int) $medium->id;
            if (isset($seen[$pair])) {
                throw ValidationException::withMessages([
                    "cells.{$index}" => 'Doppelte Zelle für Plattform und Medium.',
                ]);
            }
            $seen[$pair] = true;
            $desired[] = [
                'oa_platform_id' => $platformId,
                'advertising_medium_id' => (int) $medium->id,
                'medium_code' => (string) $medium->code,
                'is_active' => $cell['is_active'],
            ];
        }

        foreach (array_keys($expected) as $pair) {
            if (! isset($seen[$pair])) {
                throw ValidationException::withMessages([
                    'cells' => 'Die Matrix muss jede Plattform mit allen fünf OA-Kernmedien enthalten.',
                ]);
            }
        }

        if (count($seen) !== count($expected)) {
            throw ValidationException::withMessages([
                'cells' => 'Die Matrix enthält unerwartete Zellen.',
            ]);
        }

        return $desired;
    }

    /**
     * @param  array{
     *     platforms: Collection<int, OaPlatform>,
     *     media: Collection<int, AdvertisingMedium>,
     *     rules: Collection<int, OaPlatformMediumRule>
     * }  $state
     * @param  list<array{oa_platform_id: int, advertising_medium_id: int, medium_code: string, is_active: bool}>  $desired
     */
    private function apply(array $state, array $desired, User $actor): bool
    {
        /** @var array<string, OaPlatformMediumRule> $rulesByPair */
        $rulesByPair = [];
        foreach ($state['rules'] as $rule) {
            $rulesByPair[(int) $rule->oa_platform_id.'|'.(int) $rule->advertising_medium_id] = $rule;
        }

        $changed = false;
        foreach ($desired as $cell) {
            $pair = $cell['oa_platform_id'].'|'.$cell['advertising_medium_id'];
            $existing = $rulesByPair[$pair] ?? null;

            if ($existing === null) {
                if ($cell['is_active'] === false) {
                    continue;
                }

                $created = OaPlatformMediumRule::query()->create([
                    'oa_platform_id' => $cell['oa_platform_id'],
                    'advertising_medium_id' => $cell['advertising_medium_id'],
                    'is_active' => true,
                ]);
                $this->audit->record(
                    $created,
                    'oa_platform_medium_rule.created',
                    $actor,
                    null,
                    $this->auditPayload($created, $cell['medium_code']),
                );
                $changed = true;

                continue;
            }

            if ((bool) $existing->is_active === $cell['is_active']) {
                continue;
            }

            $before = $this->auditPayload($existing, $cell['medium_code']);
            $existing->is_active = $cell['is_active'];
            $existing->save();
            $this->audit->record(
                $existing,
                'oa_platform_medium_rule.updated',
                $actor,
                $before,
                $this->auditPayload($existing, $cell['medium_code']),
            );
            $changed = true;
        }

        return $changed;
    }

    /**
     * @return array{
     *     id: int,
     *     oa_platform_id: int,
     *     advertising_medium_id: int,
     *     medium_code: string,
     *     is_active: bool
     * }
     */
    private function auditPayload(OaPlatformMediumRule $rule, string $mediumCode): array
    {
        return [
            'id' => (int) $rule->id,
            'oa_platform_id' => (int) $rule->oa_platform_id,
            'advertising_medium_id' => (int) $rule->advertising_medium_id,
            'medium_code' => $mediumCode,
            'is_active' => (bool) $rule->is_active,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function notes(): array
    {
        return [
            'effect' => 'Zuordnungen werden nur als Stammdaten gespeichert. Bestehende Kalkulationen und Preise werden dadurch nicht verändert.',
            'inactive_platform' => 'Inaktive Plattformen bleiben pflegbar. Operativ zählen sie erst, wenn die Plattform aktiv ist.',
            'no_hard_delete' => 'Eine abgewählte Zuordnung wird inaktiv gesetzt und bleibt historisch erhalten.',
            'empty_platforms' => 'Noch keine Plattformen angelegt.',
        ];
    }
}
