<?php

namespace App\Services\Advertising\Admin;

use App\Exceptions\CatalogAdminConflictException;
use App\Models\AdvertisingCategoryCalculationMethod;
use App\Models\AdvertisingMediumCalculationMethod;
use App\Models\CalculationMethod;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ADV-001c3b1: Metadaten- und Lifecycle-Writer für systemdefinierte Berechnungsmethoden.
 *
 * Kein Create/Delete technischer Keys. is_active nur über deactivate/reactivate.
 * Künftige Assignment-Aktivierungen müssen die Methode zuerst sperren und
 * is_active prüfen (siehe CalculationMethodAssignmentActivationGuard).
 */
final class CalculationMethodAdminWriter
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly CalculationMethodImpactPreviewService $impact,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function update(CalculationMethod $method, array $payload, User $actor): CalculationMethod
    {
        return DB::transaction(function () use ($method, $payload, $actor): CalculationMethod {
            $this->assertMetadataPayloadClean($payload);

            /** @var CalculationMethod $locked */
            $locked = CalculationMethod::query()->whereKey($method->id)->lockForUpdate()->firstOrFail();
            $this->assertLock($locked, (int) $payload['lock_version']);

            if (array_key_exists('key', $payload) && (string) $payload['key'] !== $locked->key) {
                throw ValidationException::withMessages([
                    'key' => 'Der technische Key ist systemseitig definiert und unveränderlich.',
                ]);
            }

            $name = trim((string) $payload['name']);
            $this->assertName($name);
            $helpText = array_key_exists('help_text', $payload)
                ? $this->normalizeHelpText($payload['help_text'])
                : $locked->help_text;
            $sort = $this->normalizeSort($payload['sort'] ?? $locked->sort);

            $before = $this->auditPayload($locked);
            $locked->name = $name;
            $locked->help_text = $helpText;
            $locked->sort = $sort;
            $locked->lock_version = $locked->lock_version + 1;
            $locked->save();

            $this->audit->record(
                $locked,
                'calculation_method.updated',
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
    public function deactivate(CalculationMethod $method, array $payload, User $actor): CalculationMethod
    {
        return DB::transaction(function () use ($method, $payload, $actor): CalculationMethod {
            /** @var CalculationMethod $locked */
            $locked = CalculationMethod::query()->whereKey($method->id)->lockForUpdate()->firstOrFail();
            $this->assertLock($locked, (int) $payload['lock_version']);

            if (! $locked->is_active) {
                throw ValidationException::withMessages([
                    'method' => 'Die Berechnungsmethode ist bereits deaktiviert.',
                ]);
            }

            // Lock-Reihenfolge c3b1: Method → Cat-Assignments ASC → Med-Assignments ASC.
            // Keine Parent-Category/Media-FOR-UPDATE (Media→Category-Regel).
            $categoryRows = AdvertisingCategoryCalculationMethod::query()
                ->where('calculation_method_id', $locked->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $mediumRows = AdvertisingMediumCalculationMethod::query()
                ->where('calculation_method_id', $locked->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $preview = $this->impact->previewDeactivate($locked);
            $this->impact->assertFingerprint($preview, (string) $payload['fingerprint']);

            if (! $preview['can_proceed']) {
                $this->throwActiveAssignmentBlocker($preview);
            }

            // Membership unter Locks erneut absichern (Fingerprint deckt HTTP-Drift ab).
            $activeCategory = $categoryRows->where('is_active', true)->values();
            $activeMedium = $mediumRows->where('is_active', true)->values();
            if ($activeCategory->isNotEmpty() || $activeMedium->isNotEmpty()) {
                $this->throwActiveAssignmentBlocker($this->impact->previewDeactivate($locked));
            }

            $before = $this->auditPayload($locked);
            $locked->is_active = false;
            $locked->lock_version = $locked->lock_version + 1;
            $locked->save();

            $this->audit->record(
                $locked,
                'calculation_method.deactivated',
                $actor,
                $before,
                array_merge($this->auditPayload($locked), [
                    'impact_summary' => [
                        'active_category_assignments' => $preview['active_category_assignments'] ?? [],
                        'active_medium_assignments' => $preview['active_medium_assignments'] ?? [],
                    ],
                    'fingerprint' => $preview['fingerprint'],
                ]),
            );

            return $locked->fresh() ?? $locked;
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function reactivate(CalculationMethod $method, array $payload, User $actor): CalculationMethod
    {
        return DB::transaction(function () use ($method, $payload, $actor): CalculationMethod {
            /** @var CalculationMethod $locked */
            $locked = CalculationMethod::query()->whereKey($method->id)->lockForUpdate()->firstOrFail();
            $this->assertLock($locked, (int) $payload['lock_version']);

            if ($locked->is_active) {
                throw ValidationException::withMessages([
                    'method' => 'Die Berechnungsmethode ist bereits aktiv.',
                ]);
            }

            $before = $this->auditPayload($locked);
            $locked->is_active = true;
            $locked->lock_version = $locked->lock_version + 1;
            $locked->save();

            $this->audit->record(
                $locked,
                'calculation_method.reactivated',
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
    private function assertMetadataPayloadClean(array $payload): void
    {
        foreach ([
            'is_active',
            'engine_profile_key',
            'algorithm_version',
            'registry_status',
            'current_released_version',
            'assignments',
            'default_calculation_method_id',
        ] as $prohibited) {
            if (array_key_exists($prohibited, $payload)) {
                throw ValidationException::withMessages([
                    $prohibited => 'Das Feld „'.$prohibited.'“ darf über die Metadatenpflege nicht gesetzt werden.',
                ]);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $preview
     */
    private function throwActiveAssignmentBlocker(array $preview): never
    {
        $parts = [];
        /** @var list<array{category_key?: string, category_name?: string}> $cats */
        $cats = is_array($preview['active_category_assignments'] ?? null)
            ? $preview['active_category_assignments']
            : [];
        foreach ($cats as $row) {
            $parts[] = 'Kategorie „'.($row['category_name'] ?? '').'“ ('.($row['category_key'] ?? '').')';
        }
        /** @var list<array{medium_name?: string, medium_code?: string}> $media */
        $media = is_array($preview['active_medium_assignments'] ?? null)
            ? $preview['active_medium_assignments']
            : [];
        foreach ($media as $row) {
            $parts[] = 'Werbemittel „'.($row['medium_name'] ?? '').'“ ('.($row['medium_code'] ?? '').')';
        }

        $detail = $parts === []
            ? 'Es bestehen noch aktive Zuordnungen.'
            : 'Betroffen: '.implode('; ', $parts).'.';

        throw ValidationException::withMessages([
            'method' => 'Die Berechnungsmethode kann nicht deaktiviert werden, solange aktive '
                .'Kategorie- oder Mediumzuordnungen bestehen. '.$detail
                .' Bitte zuerst die Zuordnungen deaktivieren. Es gibt keine Force-Option.',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function auditPayload(CalculationMethod $method): array
    {
        return [
            'id' => $method->id,
            'key' => $method->key,
            'name' => $method->name,
            'help_text' => $method->help_text,
            'sort' => $method->sort,
            'is_active' => $method->is_active,
            'lock_version' => $method->lock_version,
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

    private function normalizeHelpText(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }

        if (mb_strlen($text) > 2000) {
            throw ValidationException::withMessages([
                'help_text' => 'Der Hilfetext darf maximal 2000 Zeichen lang sein.',
            ]);
        }

        return $text;
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

    private function assertLock(CalculationMethod $method, int $expected): void
    {
        if ((int) $method->lock_version !== $expected) {
            throw new CatalogAdminConflictException(
                'Die Berechnungsmethode wurde parallel geändert. Bitte neu laden und erneut prüfen.',
            );
        }
    }
}
