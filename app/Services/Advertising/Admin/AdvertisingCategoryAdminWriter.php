<?php

namespace App\Services\Advertising\Admin;

use App\Exceptions\CatalogAdminConflictException;
use App\Models\AdvertisingCategory;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\Advertising\AdvertisingCatalogKeyValidator;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ADV-001b / ADV-001: Admin-Lifecycle für Oberkategorien.
 */
final class AdvertisingCategoryAdminWriter
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly CatalogImpactPreviewService $impact,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function create(array $payload, User $actor): AdvertisingCategory
    {
        return DB::transaction(function () use ($payload, $actor): AdvertisingCategory {
            $key = trim((string) $payload['key']);
            $name = trim((string) $payload['name']);
            AdvertisingCatalogKeyValidator::assertValid($key, 'key');
            $this->assertName($name);
            $sort = $this->normalizeSort($payload['sort'] ?? 0);
            $isActive = array_key_exists('is_active', $payload) ? (bool) $payload['is_active'] : true;

            try {
                $category = new AdvertisingCategory;
                $category->key = $key;
                $category->name = $name;
                $category->sort = $sort;
                $category->is_active = $isActive;
                $category->lock_version = 1;
                $category->save();
            } catch (UniqueConstraintViolationException) {
                throw ValidationException::withMessages([
                    'key' => 'Dieser technische Key ist bereits vergeben.',
                ]);
            } catch (QueryException $exception) {
                if ($this->isUniqueViolation($exception)) {
                    throw ValidationException::withMessages([
                        'key' => 'Dieser technische Key ist bereits vergeben.',
                    ]);
                }
                throw $exception;
            }

            $this->audit->record(
                $category,
                'advertising_category.created',
                $actor,
                null,
                $this->auditPayload($category),
            );

            return $category->fresh() ?? $category;
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function update(AdvertisingCategory $category, array $payload, User $actor): AdvertisingCategory
    {
        return DB::transaction(function () use ($category, $payload, $actor): AdvertisingCategory {
            /** @var AdvertisingCategory $locked */
            $locked = AdvertisingCategory::query()->whereKey($category->id)->lockForUpdate()->firstOrFail();
            $this->assertLock($locked, (int) $payload['lock_version']);

            if (array_key_exists('key', $payload) && (string) $payload['key'] !== $locked->key) {
                throw ValidationException::withMessages([
                    'key' => 'Der technische Key ist nach dem Anlegen unveränderlich (PO-ADV001b-2).',
                ]);
            }

            $name = trim((string) $payload['name']);
            $this->assertName($name);
            $sort = $this->normalizeSort($payload['sort'] ?? $locked->sort);

            $before = $this->auditPayload($locked);
            $locked->name = $name;
            $locked->sort = $sort;
            $locked->lock_version = $locked->lock_version + 1;
            $locked->save();

            $this->audit->record(
                $locked,
                'advertising_category.updated',
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
    public function deactivate(AdvertisingCategory $category, array $payload, User $actor): AdvertisingCategory
    {
        return DB::transaction(function () use ($category, $payload, $actor): AdvertisingCategory {
            /** @var AdvertisingCategory $locked */
            $locked = AdvertisingCategory::query()->whereKey($category->id)->lockForUpdate()->firstOrFail();
            $this->assertLock($locked, (int) $payload['lock_version']);

            if (! $locked->is_active) {
                throw ValidationException::withMessages([
                    'category' => 'Die Oberkategorie ist bereits deaktiviert.',
                ]);
            }

            $preview = $this->impact->previewCategoryDeactivate($locked);
            $this->impact->assertFingerprint($preview, (string) $payload['fingerprint']);

            if (! $preview['can_proceed']) {
                /** @var list<array{code?: string, message?: string}> $reasons */
                $reasons = is_array($preview['blocking_reasons'] ?? null)
                    ? $preview['blocking_reasons']
                    : [];
                $message = $reasons[0]['message'] ?? 'Die Deaktivierung ist blockiert.';
                throw ValidationException::withMessages([
                    'category' => $message,
                ]);
            }

            $before = $this->auditPayload($locked);
            $locked->is_active = false;
            $locked->lock_version = $locked->lock_version + 1;
            $locked->save();

            $this->audit->record(
                $locked,
                'advertising_category.deactivated',
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
    public function reactivate(AdvertisingCategory $category, array $payload, User $actor): AdvertisingCategory
    {
        return DB::transaction(function () use ($category, $payload, $actor): AdvertisingCategory {
            /** @var AdvertisingCategory $locked */
            $locked = AdvertisingCategory::query()->whereKey($category->id)->lockForUpdate()->firstOrFail();
            $this->assertLock($locked, (int) $payload['lock_version']);

            if ($locked->is_active) {
                throw ValidationException::withMessages([
                    'category' => 'Die Oberkategorie ist bereits aktiv.',
                ]);
            }

            $before = $this->auditPayload($locked);
            $locked->is_active = true;
            $locked->lock_version = $locked->lock_version + 1;
            $locked->save();

            $this->audit->record(
                $locked,
                'advertising_category.reactivated',
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
    private function auditPayload(AdvertisingCategory $category): array
    {
        return [
            'id' => $category->id,
            'key' => $category->key,
            'name' => $category->name,
            'sort' => $category->sort,
            'is_active' => $category->is_active,
            'lock_version' => $category->lock_version,
        ];
    }

    /**
     * @param  array<string, mixed>  $preview
     * @return array<string, mixed>
     */
    private function impactSummary(array $preview): array
    {
        return [
            'active_media_count' => $preview['active_media_count'] ?? 0,
            'inactive_media_count' => $preview['inactive_media_count'] ?? 0,
            'assignments' => $preview['assignments'] ?? [],
            'calculation_positions_count' => $preview['calculation_positions_count'] ?? 0,
            'dispo_order_positions_count' => $preview['dispo_order_positions_count'] ?? 0,
            'reactivation_assignment_warning' => $preview['reactivation_assignment_warning'] ?? null,
        ];
    }

    private function assertName(string $name): void
    {
        if ($name === '') {
            throw ValidationException::withMessages([
                'name' => 'Der Name ist erforderlich.',
            ]);
        }

        if (mb_strlen($name) > 255) {
            throw ValidationException::withMessages([
                'name' => 'Der Name darf maximal 255 Zeichen lang sein.',
            ]);
        }
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

    private function assertLock(AdvertisingCategory $category, int $expected): void
    {
        if ((int) $category->lock_version !== $expected) {
            throw new CatalogAdminConflictException(
                'Die Oberkategorie wurde parallel geändert. Bitte neu laden und erneut prüfen.',
            );
        }
    }

    private function isUniqueViolation(QueryException $exception): bool
    {
        $code = (string) $exception->getCode();
        $message = $exception->getMessage();

        return in_array($code, ['23000', '19', '1062'], true)
            || str_contains($message, 'advertising_categories_key_unique')
            || str_contains($message, 'UNIQUE constraint failed');
    }
}
