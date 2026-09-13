<?php

namespace App\Services\Inventory\Admin;

use App\Enums\InventoryType;
use App\Exceptions\CatalogAdminConflictException;
use App\Models\Inventory;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\Inventory\InventoryCodeValidator;
use App\Support\Organization\SingletonOrganizationResolver;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * BL-P2-01a: Admin-Lifecycle für Inventare. Kein Hard Delete, keine Memberships.
 */
final class InventoryAdminWriter
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly InventoryImpactPreviewService $impact,
        private readonly SingletonOrganizationResolver $organizations,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function create(array $payload, User $actor): Inventory
    {
        return DB::transaction(function () use ($payload, $actor): Inventory {
            $code = InventoryCodeValidator::assertValid((string) $payload['code']);
            $name = $this->assertName((string) ($payload['name'] ?? ''));
            $type = $this->assertType($payload['type'] ?? null);
            $sort = $this->normalizeSort($payload['sort'] ?? 0);
            $logoPath = $this->normalizeLogoPath($payload['logo_path'] ?? null);
            $isActive = array_key_exists('is_active', $payload) ? (bool) $payload['is_active'] : true;
            $organization = $this->organizations->resolve();

            try {
                $inventory = new Inventory;
                $inventory->organization_id = $organization->id;
                $inventory->name = $name;
                $inventory->code = $code;
                $inventory->type = $type;
                $inventory->is_active = $isActive;
                $inventory->sort = $sort;
                $inventory->logo_path = $logoPath;
                $inventory->lock_version = 1;
                $inventory->save();
            } catch (UniqueConstraintViolationException) {
                throw $this->duplicateCode();
            } catch (QueryException $exception) {
                if ($this->isUniqueViolation($exception)) {
                    throw $this->duplicateCode();
                }
                throw $exception;
            }

            $this->audit->record(
                $inventory,
                'inventory.created',
                $actor,
                null,
                $this->auditPayload($inventory),
            );

            return $inventory->fresh() ?? $inventory;
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function update(Inventory $inventory, array $payload, User $actor): Inventory
    {
        return DB::transaction(function () use ($inventory, $payload, $actor): Inventory {
            /** @var Inventory $locked */
            $locked = Inventory::query()->whereKey($inventory->id)->lockForUpdate()->firstOrFail();
            $this->assertLock($locked, (int) $payload['lock_version']);

            if (array_key_exists('code', $payload) && (string) $payload['code'] !== $locked->code) {
                throw ValidationException::withMessages([
                    'code' => 'Der Kurzcode ist nach dem Anlegen unveränderlich.',
                ]);
            }

            if (array_key_exists('type', $payload)
                && (string) $payload['type'] !== $locked->type->value
            ) {
                throw ValidationException::withMessages([
                    'type' => 'Der Inventartyp ist nach dem Anlegen unveränderlich.',
                ]);
            }

            if (array_key_exists('is_active', $payload)
                && (bool) $payload['is_active'] !== (bool) $locked->is_active
            ) {
                throw ValidationException::withMessages([
                    'is_active' => 'Der Aktivstatus wird nur über Aktivieren oder Deaktivieren geändert.',
                ]);
            }

            $name = $this->assertName((string) ($payload['name'] ?? ''));
            $sort = $this->normalizeSort($payload['sort'] ?? $locked->sort);
            $logoPath = array_key_exists('logo_path', $payload)
                ? $this->normalizeLogoPath($payload['logo_path'])
                : $locked->logo_path;

            if ($name === $locked->name
                && $sort === (int) $locked->sort
                && $logoPath === $locked->logo_path
            ) {
                return $locked;
            }

            $before = $this->auditPayload($locked);
            $locked->name = $name;
            $locked->sort = $sort;
            $locked->logo_path = $logoPath;
            $locked->lock_version = $locked->lock_version + 1;
            $locked->save();

            $this->audit->record(
                $locked,
                'inventory.updated',
                $actor,
                $before,
                $this->auditPayload($locked),
            );

            return $locked->fresh() ?? $locked;
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function deactivate(Inventory $inventory, array $payload, User $actor): Inventory
    {
        return DB::transaction(function () use ($inventory, $payload, $actor): Inventory {
            /** @var Inventory $locked */
            $locked = Inventory::query()->whereKey($inventory->id)->lockForUpdate()->firstOrFail();
            $this->assertLock($locked, (int) $payload['lock_version']);

            $preview = $this->impact->previewDeactivate($locked);
            $this->impact->assertFingerprint($preview, (string) $payload['fingerprint']);

            if (! $preview['can_proceed']) {
                /** @var list<array{code?: string, message?: string}> $reasons */
                $reasons = is_array($preview['blocking_reasons'] ?? null)
                    ? $preview['blocking_reasons']
                    : [];
                $message = $reasons[0]['message'] ?? 'Die Deaktivierung ist blockiert.';
                throw ValidationException::withMessages([
                    'inventory' => $message,
                ]);
            }

            $before = $this->auditPayload($locked);
            $locked->is_active = false;
            $locked->lock_version = $locked->lock_version + 1;
            $locked->save();

            $this->audit->record(
                $locked,
                'inventory.deactivated',
                $actor,
                $before,
                array_merge($this->auditPayload($locked), [
                    'impact_summary' => $this->impactSummary($preview),
                    'fingerprint' => $preview['fingerprint'],
                ]),
            );

            return $locked->fresh() ?? $locked;
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function reactivate(Inventory $inventory, array $payload, User $actor): Inventory
    {
        return DB::transaction(function () use ($inventory, $payload, $actor): Inventory {
            /** @var Inventory $locked */
            $locked = Inventory::query()->whereKey($inventory->id)->lockForUpdate()->firstOrFail();
            $this->assertLock($locked, (int) $payload['lock_version']);

            if ($locked->is_active) {
                throw ValidationException::withMessages([
                    'inventory' => 'Das Inventar ist bereits aktiv.',
                ]);
            }

            $before = $this->auditPayload($locked);
            $locked->is_active = true;
            $locked->lock_version = $locked->lock_version + 1;
            $locked->save();

            $this->audit->record(
                $locked,
                'inventory.reactivated',
                $actor,
                $before,
                $this->auditPayload($locked),
            );

            return $locked->fresh() ?? $locked;
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function auditPayload(Inventory $inventory): array
    {
        return [
            'id' => $inventory->id,
            'organization_id' => $inventory->organization_id,
            'name' => $inventory->name,
            'code' => $inventory->code,
            'type' => $inventory->type->value,
            'sort' => $inventory->sort,
            'is_active' => $inventory->is_active,
            'logo_path' => $inventory->logo_path,
            'lock_version' => $inventory->lock_version,
        ];
    }

    /**
     * @param  array<string, mixed>  $preview
     * @return array<string, mixed>
     */
    private function impactSummary(array $preview): array
    {
        return [
            'calculation_positions_count' => $preview['calculation_positions_count'] ?? 0,
            'dispo_order_positions_count' => $preview['dispo_order_positions_count'] ?? 0,
            'price_lists_count' => $preview['price_lists_count'] ?? 0,
            'inventory_medium_rules_count' => $preview['inventory_medium_rules_count'] ?? 0,
        ];
    }

    private function assertName(string $name): string
    {
        $trimmed = trim($name);
        if ($trimmed === '') {
            throw ValidationException::withMessages([
                'name' => 'Der Name ist erforderlich.',
            ]);
        }

        if (mb_strlen($trimmed) > 255) {
            throw ValidationException::withMessages([
                'name' => 'Der Name darf maximal 255 Zeichen lang sein.',
            ]);
        }

        return $trimmed;
    }

    private function assertType(mixed $value): InventoryType
    {
        $raw = is_string($value) ? trim($value) : '';
        $type = InventoryType::tryFrom($raw);
        if ($type === null) {
            throw ValidationException::withMessages([
                'type' => 'Der Typ muss Sender oder Kombi sein.',
            ]);
        }

        return $type;
    }

    private function normalizeSort(mixed $value): int
    {
        if ($value === null || $value === '') {
            return 0;
        }

        if (! is_numeric($value) || (int) $value < 0) {
            throw ValidationException::withMessages([
                'sort' => 'Die Sortierung muss eine nicht-negative Ganzzahl sein.',
            ]);
        }

        return (int) $value;
    }

    private function normalizeLogoPath(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);
        if ($trimmed === '') {
            return null;
        }

        if (mb_strlen($trimmed) > 255) {
            throw ValidationException::withMessages([
                'logo_path' => 'Der Logo-Pfad darf maximal 255 Zeichen lang sein.',
            ]);
        }

        if (! str_starts_with($trimmed, '/')
            || str_contains($trimmed, '..')
            || str_contains($trimmed, ':')
            || ! preg_match('#^/[A-Za-z0-9._/-]+$#', $trimmed)
        ) {
            throw ValidationException::withMessages([
                'logo_path' => 'Der Logo-Pfad muss ein relativer App-Pfad sein (beginnend mit /).',
            ]);
        }

        return $trimmed;
    }

    private function assertLock(Inventory $inventory, int $expected): void
    {
        if ((int) $inventory->lock_version !== $expected) {
            throw new CatalogAdminConflictException(
                'Das Inventar wurde parallel geändert. Bitte neu laden und erneut prüfen.',
            );
        }
    }

    private function duplicateCode(): ValidationException
    {
        return ValidationException::withMessages([
            'code' => 'Dieser Kurzcode ist in der Organisation bereits vergeben.',
        ]);
    }

    private function isUniqueViolation(QueryException $exception): bool
    {
        $code = (string) $exception->getCode();
        $message = $exception->getMessage();

        return in_array($code, ['23000', '19', '1062'], true)
            || str_contains($message, 'inventories_organization_id_code_unique')
            || str_contains($message, 'UNIQUE constraint failed');
    }
}
