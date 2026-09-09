<?php

namespace App\Services\Advertising\Admin;

use App\Models\AdvertisingCategory;
use App\Models\AdvertisingMedium;
use App\Services\DynamicField\Assignment\AssignmentConfigurationLockCoordinator;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * ADV-001b: schützt „aktives Werbemittel nie unter inaktiver Oberkategorie“.
 *
 * Protokolle:
 * - Bestehendes Medium + Kategorien: Medium zuerst, danach Kategorien nach id ASC
 *   (wie {@see AssignmentConfigurationLockCoordinator::lockTargetRows}).
 * - Create ohne Mediumzeile: nur Zielkategorie sperren, prüfen, dann anlegen.
 * - Kategorie-Deaktivierung: ausschließlich die Kategoriezeile als
 *   Lifecycle-Serialisierungsgrenze (erster DB-Read der Transaktion). Danach
 *   frischer Medien-Read ohne FOR UPDATE. Niemals Kategorie halten und auf
 *   Medienzeilen warten – das würde mit Assignment/Freeze (Media→Kategorie)
 *   deadlocken.
 *
 * Warum der Medien-Read nach Kategorie-Lock ohne FOR UPDATE sicher ist:
 * Create/Reactivate/Change sperren die Zielkategorie vor der Mutation. Ein
 * konkurrierender Writer hat damit nur: bereits vor unserem Kategorie-Lock
 * committed (sichtbar im frischen Read) oder wartet auf die Kategorie und
 * wird nach Deaktivierung fachlich abgelehnt. Der Kategorie-Lock muss der
 * erste Read der Deactivate-Transaktion sein, damit kein früherer Consistent
 * Read unter MySQL REPEATABLE READ einen veralteten Snapshot eröffnet.
 */
final class CatalogLifecycleLockCoordinator
{
    /**
     * Nur Kategorie sperren (Create-Pfad ohne bestehende Mediumzeile).
     */
    public function lockCategory(int $categoryId): AdvertisingCategory
    {
        if ($categoryId < 1) {
            throw ValidationException::withMessages([
                'category_id' => 'Eine Oberkategorie ist erforderlich.',
            ]);
        }

        $category = AdvertisingCategory::query()
            ->whereKey($categoryId)
            ->lockForUpdate()
            ->first();

        if ($category === null) {
            throw ValidationException::withMessages([
                'category_id' => 'Die Oberkategorie wurde nicht gefunden.',
            ]);
        }

        $this->testGateAfterCategoryLock();

        return $category;
    }

    /**
     * Kategorie-Deaktivierung: Kategorie zuerst und ausschließlich sperren,
     * danach Medienrelation frisch ohne Zeilensperre laden.
     *
     * Aufrufer: dieser Aufruf muss der erste DB-Read innerhalb der Transaktion sein.
     */
    public function lockCategoryForDeactivate(int $categoryId): AdvertisingCategory
    {
        $category = $this->lockCategory($categoryId);

        $media = AdvertisingMedium::query()
            ->where('category_id', $categoryId)
            ->orderBy('id')
            ->get();
        $category->setRelation('advertisingMedia', $media);

        return $category;
    }

    /**
     * Medium-Lifecycle mit Kategoriebezug: Medium zuerst, danach Kategorien nach id ASC.
     *
     * @param  list<int>  $extraCategoryIds  z. B. Zielkategorie beim Wechsel
     * @return array{medium: AdvertisingMedium, categories: Collection<int, AdvertisingCategory>}
     */
    public function lockMediumAndCategories(int $mediumId, array $extraCategoryIds = []): array
    {
        /** @var AdvertisingMedium $medium */
        $medium = AdvertisingMedium::query()
            ->whereKey($mediumId)
            ->lockForUpdate()
            ->firstOrFail();

        $this->testGateAfterMediumLock();

        $categoryIds = array_merge([(int) $medium->category_id], $extraCategoryIds);
        $categories = $this->lockCategoriesByIds($categoryIds);

        return [
            'medium' => $medium,
            'categories' => $categories,
        ];
    }

    /**
     * @param  array<int, int>  $mediumIds
     * @return Collection<int, AdvertisingMedium>
     */
    public function lockMediaByIds(array $mediumIds): Collection
    {
        /** @var list<int> $ids */
        $ids = array_values(array_unique(array_filter(
            array_map(static fn ($id): int => (int) $id, $mediumIds),
            static fn (int $id): bool => $id > 0,
        )));
        sort($ids);

        if ($ids === []) {
            /** @var Collection<int, AdvertisingMedium> $empty */
            $empty = new Collection;

            return $empty;
        }

        /** @var Collection<int, AdvertisingMedium> $locked */
        $locked = AdvertisingMedium::query()
            ->whereIn('id', $ids)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        return $locked;
    }

    /**
     * @param  array<int, int>  $categoryIds
     * @return Collection<int, AdvertisingCategory>
     */
    public function lockCategoriesByIds(array $categoryIds): Collection
    {
        /** @var list<int> $ids */
        $ids = array_values(array_unique(array_filter(
            array_map(static fn ($id): int => (int) $id, $categoryIds),
            static fn (int $id): bool => $id > 0,
        )));
        sort($ids);

        if ($ids === []) {
            /** @var Collection<int, AdvertisingCategory> $empty */
            $empty = new Collection;

            return $empty;
        }

        /** @var Collection<int, AdvertisingCategory> $locked */
        $locked = AdvertisingCategory::query()
            ->whereIn('id', $ids)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $this->testGateAfterCategoryLock();

        return $locked;
    }

    /**
     * Test-only: deterministische Überlappung an Lock-Grenzen (MySQL-Paralleltests).
     * Produktion: Env unset → no-op.
     */
    private function testGateAfterCategoryLock(): void
    {
        $this->runTestGate('CATALOG_LOCK_GATE_AFTER_CATEGORY');
    }

    private function testGateAfterMediumLock(): void
    {
        $this->runTestGate('CATALOG_LOCK_GATE_AFTER_MEDIUM');
    }

    private function runTestGate(string $envKey): void
    {
        $dir = getenv('CATALOG_LOCK_TEST_GATE_DIR');
        if (! is_string($dir) || $dir === '') {
            return;
        }

        $spec = getenv($envKey);
        if (! is_string($spec) || $spec === '') {
            return;
        }

        // Format: signalFile|waitForFile|waitForFile2...
        $parts = array_values(array_filter(explode('|', $spec), static fn (string $p): bool => $p !== ''));
        if ($parts === []) {
            return;
        }

        $signal = array_shift($parts);
        file_put_contents($dir.'/'.$signal, '1');

        $deadline = microtime(true) + 45.0;
        foreach ($parts as $waitFile) {
            while (! is_file($dir.'/'.$waitFile)) {
                if (microtime(true) > $deadline) {
                    throw new \RuntimeException("Catalog lock test gate timeout waiting for {$waitFile}");
                }
                usleep(5_000);
            }
        }
    }
}
