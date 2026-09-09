<?php

namespace App\Services\Advertising\Admin;

use App\Enums\CalculationKind;
use App\Exceptions\CatalogAdminConflictException;
use App\Models\AdvertisingCategory;
use App\Models\AdvertisingMedium;
use App\Models\CalculationPosition;
use App\Models\DispoOrderPosition;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\Advertising\AdvertisingCatalogKeyValidator;
use App\Support\Advertising\AdvertisingKindCategoryCompatibility;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use ValueError;

/**
 * ADV-001b / ADV-001: Admin-Lifecycle für Werbemittel.
 */
final class AdvertisingMediumAdminWriter
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly CatalogImpactPreviewService $impact,
        private readonly CatalogLifecycleLockCoordinator $locks,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function create(array $payload, User $actor): AdvertisingMedium
    {
        return DB::transaction(function () use ($payload, $actor): AdvertisingMedium {
            $code = trim((string) $payload['code']);
            $name = trim((string) $payload['name']);
            AdvertisingCatalogKeyValidator::assertValid($code, 'code');
            $this->assertName($name);

            $kind = $this->parseKind($payload['kind'] ?? null);
            // Kategorie unter lockForUpdate prüfen – verhindert Race mit Deaktivierung.
            $category = $this->locks->lockCategory((int) ($payload['category_id'] ?? 0));
            if (! $category->is_active) {
                throw ValidationException::withMessages([
                    'category_id' => 'Neue Werbemittel können nur aktiven Oberkategorien zugeordnet werden.',
                ]);
            }
            AdvertisingKindCategoryCompatibility::assertCompatible($kind, $category->key);

            $length = $this->normalizeLength($payload['default_length_seconds'] ?? 30);
            $sort = $this->normalizeSort($payload['sort'] ?? 0);
            $isDiscountable = array_key_exists('is_discountable', $payload) ? (bool) $payload['is_discountable'] : true;
            $isAeEligible = array_key_exists('is_ae_eligible', $payload) ? (bool) $payload['is_ae_eligible'] : true;
            $isActive = array_key_exists('is_active', $payload) ? (bool) $payload['is_active'] : true;

            try {
                $medium = new AdvertisingMedium;
                $medium->category_id = $category->id;
                $medium->code = $code;
                $medium->name = $name;
                $medium->kind = $kind;
                $medium->default_length_seconds = $length;
                $medium->is_discountable = $isDiscountable;
                $medium->is_ae_eligible = $isAeEligible;
                $medium->sort = $sort;
                $medium->is_active = $isActive;
                $medium->lock_version = 1;
                $medium->save();
            } catch (UniqueConstraintViolationException) {
                throw ValidationException::withMessages([
                    'code' => 'Dieser technische Code ist bereits vergeben.',
                ]);
            } catch (QueryException $exception) {
                if ($this->isUniqueViolation($exception)) {
                    throw ValidationException::withMessages([
                        'code' => 'Dieser technische Code ist bereits vergeben.',
                    ]);
                }
                throw $exception;
            }

            $this->audit->record(
                $medium,
                'advertising_medium.created',
                $actor,
                null,
                $this->auditPayload($medium),
            );

            return $medium->fresh(['category']) ?? $medium;
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function update(AdvertisingMedium $medium, array $payload, User $actor): AdvertisingMedium
    {
        return DB::transaction(function () use ($medium, $payload, $actor): AdvertisingMedium {
            /** @var AdvertisingMedium $locked */
            $locked = AdvertisingMedium::query()->whereKey($medium->id)->lockForUpdate()->firstOrFail();
            $this->assertLock($locked, (int) $payload['lock_version']);

            if (array_key_exists('code', $payload) && (string) $payload['code'] !== $locked->code) {
                throw ValidationException::withMessages([
                    'code' => 'Der technische Code ist nach dem Anlegen unveränderlich (PO-ADV001b-2).',
                ]);
            }

            if (array_key_exists('kind', $payload) && (string) $payload['kind'] !== $locked->kind->value) {
                throw ValidationException::withMessages([
                    'kind' => 'Die Berechnungsart kann über diese Aktion nicht geändert werden.',
                ]);
            }

            if (array_key_exists('category_id', $payload)
                && (int) $payload['category_id'] !== (int) $locked->category_id) {
                throw ValidationException::withMessages([
                    'category_id' => 'Der Kategoriewechsel ist nur über die dedizierte Vorschau-Aktion erlaubt.',
                ]);
            }

            $name = trim((string) $payload['name']);
            $this->assertName($name);

            $before = $this->auditPayload($locked);
            $locked->name = $name;
            $locked->default_length_seconds = $this->normalizeLength(
                $payload['default_length_seconds'] ?? $locked->default_length_seconds,
            );
            $locked->is_discountable = array_key_exists('is_discountable', $payload)
                ? (bool) $payload['is_discountable']
                : $locked->is_discountable;
            $locked->is_ae_eligible = array_key_exists('is_ae_eligible', $payload)
                ? (bool) $payload['is_ae_eligible']
                : $locked->is_ae_eligible;
            $locked->sort = $this->normalizeSort($payload['sort'] ?? $locked->sort);
            $locked->lock_version = $locked->lock_version + 1;
            $locked->save();

            $this->audit->record(
                $locked,
                'advertising_medium.updated',
                $actor,
                $before,
                $this->auditPayload($locked),
            );

            return $locked->fresh(['category']) ?? $locked;
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function changeCategory(AdvertisingMedium $medium, array $payload, User $actor): AdvertisingMedium
    {
        return DB::transaction(function () use ($medium, $payload, $actor): AdvertisingMedium {
            $targetCategoryId = (int) ($payload['category_id'] ?? 0);

            // Rows sperren → Zustand neu lesen → Preview neu → Fingerprint → mutieren
            $lockedBundle = $this->locks->lockMediumAndCategories(
                (int) $medium->id,
                [$targetCategoryId],
            );
            $locked = $lockedBundle['medium'];
            $this->assertLock($locked, (int) $payload['lock_version']);

            /** @var AdvertisingCategory|null $lockedTarget */
            $lockedTarget = $lockedBundle['categories']->firstWhere('id', $targetCategoryId);
            $preview = $this->impact->previewMediumCategoryChange($locked, [
                'target_category_id' => $targetCategoryId,
                'locked_target_category' => $lockedTarget,
            ]);
            $this->impact->assertFingerprint($preview, (string) $payload['fingerprint']);

            if (! $preview['can_proceed']) {
                /** @var list<array{code?: string, message?: string}> $reasons */
                $reasons = is_array($preview['blocking_reasons'] ?? null)
                    ? $preview['blocking_reasons']
                    : [];
                $message = $reasons[0]['message'] ?? 'Der Kategoriewechsel ist blockiert.';
                throw ValidationException::withMessages([
                    'category_id' => $message,
                ]);
            }

            $targetId = (int) $preview['intended_change']['category_id'];
            $before = $this->auditPayload($locked);
            $locked->category_id = $targetId;
            $locked->lock_version = $locked->lock_version + 1;
            $locked->save();

            $after = $this->auditPayload($locked);
            $this->audit->record(
                $locked,
                'advertising_medium.category_changed',
                $actor,
                $before,
                array_merge($after, [
                    'impact_summary' => [
                        'assignments' => $preview['assignments'] ?? [],
                        'calculation_positions_count' => $preview['calculation_positions_count'] ?? 0,
                        'dispo_order_positions_count' => $preview['dispo_order_positions_count'] ?? 0,
                        'schema_change_warning' => $preview['schema_change_warning'] ?? null,
                    ],
                    'fingerprint' => $preview['fingerprint'],
                ]),
            );

            return $locked->fresh(['category']) ?? $locked;
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function deactivate(AdvertisingMedium $medium, array $payload, User $actor): AdvertisingMedium
    {
        return DB::transaction(function () use ($medium, $payload, $actor): AdvertisingMedium {
            /** @var AdvertisingMedium $locked */
            $locked = AdvertisingMedium::query()->whereKey($medium->id)->lockForUpdate()->firstOrFail();
            $this->assertLock($locked, (int) $payload['lock_version']);

            if (! $locked->is_active) {
                throw ValidationException::withMessages([
                    'medium' => 'Das Werbemittel ist bereits deaktiviert.',
                ]);
            }

            // Preview erst nach Zeilensperre neu berechnen (Fingerprint-Sicherheit).
            $preview = $this->impact->previewMediumDeactivate($locked);
            $this->impact->assertFingerprint($preview, (string) $payload['fingerprint']);

            $before = $this->auditPayload($locked);
            $locked->is_active = false;
            $locked->lock_version = $locked->lock_version + 1;
            $locked->save();

            $this->audit->record(
                $locked,
                'advertising_medium.deactivated',
                $actor,
                $before,
                array_merge($this->auditPayload($locked), [
                    'impact_summary' => [
                        'assignments' => $preview['assignments'] ?? [],
                        'calculation_positions_count' => $preview['calculation_positions_count'] ?? 0,
                        'dispo_order_positions_count' => $preview['dispo_order_positions_count'] ?? 0,
                        'inventory_medium_rules_count' => $preview['inventory_medium_rules_count'] ?? 0,
                        'reactivation_assignment_warning' => $preview['reactivation_assignment_warning'] ?? null,
                    ],
                    'fingerprint' => $preview['fingerprint'],
                ]),
            );

            return $locked->fresh(['category']) ?? $locked;
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function reactivate(AdvertisingMedium $medium, array $payload, User $actor): AdvertisingMedium
    {
        return DB::transaction(function () use ($medium, $payload, $actor): AdvertisingMedium {
            $lockedBundle = $this->locks->lockMediumAndCategories((int) $medium->id);
            $locked = $lockedBundle['medium'];
            $this->assertLock($locked, (int) $payload['lock_version']);

            if ($locked->is_active) {
                throw ValidationException::withMessages([
                    'medium' => 'Das Werbemittel ist bereits aktiv.',
                ]);
            }

            /** @var AdvertisingCategory|null $category */
            $category = $lockedBundle['categories']->firstWhere('id', (int) $locked->category_id);
            if ($category === null) {
                $category = $this->locks->lockCategory((int) $locked->category_id);
            }

            if (! $category->is_active) {
                throw ValidationException::withMessages([
                    'medium' => 'Reaktivierung nur möglich, wenn die zugeordnete Oberkategorie aktiv ist.',
                ]);
            }

            AdvertisingKindCategoryCompatibility::assertCompatible($locked->kind, $category->key);

            $before = $this->auditPayload($locked);
            $locked->is_active = true;
            $locked->lock_version = $locked->lock_version + 1;
            $locked->save();

            $this->audit->record(
                $locked,
                'advertising_medium.reactivated',
                $actor,
                $before,
                $this->auditPayload($locked),
            );

            return $locked->fresh(['category']) ?? $locked;
        });
    }

    public function hasPositionReference(AdvertisingMedium $medium): bool
    {
        return CalculationPosition::query()->where('advertising_medium_id', $medium->id)->exists()
            || DispoOrderPosition::query()->where('advertising_medium_id', $medium->id)->exists();
    }

    /**
     * @return array<string, mixed>
     */
    private function auditPayload(AdvertisingMedium $medium): array
    {
        $medium->loadMissing('category');

        return [
            'id' => $medium->id,
            'code' => $medium->code,
            'name' => $medium->name,
            'kind' => $medium->kind->value,
            'category_id' => $medium->category_id,
            'category_key' => $medium->category?->key,
            'default_length_seconds' => $medium->default_length_seconds,
            'is_discountable' => $medium->is_discountable,
            'is_ae_eligible' => $medium->is_ae_eligible,
            'sort' => $medium->sort,
            'is_active' => $medium->is_active,
            'lock_version' => $medium->lock_version,
        ];
    }

    private function parseKind(mixed $value): CalculationKind
    {
        try {
            return CalculationKind::from((string) $value);
        } catch (ValueError) {
            throw ValidationException::withMessages([
                'kind' => 'Unbekannte oder nicht unterstützte Berechnungsart.',
            ]);
        }
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

    private function normalizeLength(mixed $value): int
    {
        if (! is_numeric($value) || (int) $value < 1 || (int) $value > 3600) {
            throw ValidationException::withMessages([
                'default_length_seconds' => 'Die Standardlänge muss zwischen 1 und 3600 Sekunden liegen.',
            ]);
        }

        return (int) $value;
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

    private function assertLock(AdvertisingMedium $medium, int $expected): void
    {
        if ((int) $medium->lock_version !== $expected) {
            throw new CatalogAdminConflictException(
                'Das Werbemittel wurde parallel geändert. Bitte neu laden und erneut prüfen.',
            );
        }
    }

    private function isUniqueViolation(QueryException $exception): bool
    {
        $code = (string) $exception->getCode();
        $message = $exception->getMessage();

        return in_array($code, ['23000', '19', '1062'], true)
            || str_contains($message, 'advertising_media_code_unique')
            || str_contains($message, 'UNIQUE constraint failed');
    }
}
