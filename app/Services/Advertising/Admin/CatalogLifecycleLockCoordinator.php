<?php

namespace App\Services\Advertising\Admin;

use App\Models\AdvertisingCategory;
use App\Models\AdvertisingMedium;
use App\Services\DynamicField\Assignment\AssignmentConfigurationLockCoordinator;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * ADV-001b Locking-Härtung: schützt die Invariante
 * „Ein aktives Werbemittel darf niemals einer inaktiven Oberkategorie zugeordnet sein.“
 *
 * Lock-Reihenfolge (kompatibel zu
 * {@see AssignmentConfigurationLockCoordinator::lockTargetRows}):
 * 1. `advertising_media` nach `id` ASC (falls beteiligt)
 * 2. `advertising_categories` nach `id` ASC
 *
 * Ein normales SELECT reicht nicht: Prüfung und Mutation müssen unter derselben
 * Zeilensperre liegen, sonst kann eine parallele Kategorie-Deaktivierung die
 * Invariante zwischen Check und Write verletzen. Keine abweichende Reihenfolge
 * gegenüber dem Assignment-/Freeze-Coordinator, um Deadlocks zu vermeiden.
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

        return $category;
    }

    /**
     * Kategorie-Deaktivierung: zuerst Mediumzeilen (id ASC), dann die Kategorie.
     *
     * Danach erneutes `lockForUpdate` auf alle Medien der Kategorie: unter MySQL
     * REPEATABLE READ reicht ein frühes non-locking SELECT nicht – parallele
     * Inserts/Moves (Phantome) und Zustandsänderungen wären sonst unsichtbar.
     * Die Relation `advertisingMedia` wird mit dem Locking-Ergebnis gesetzt.
     */
    public function lockCategoryWithOwnedMedia(int $categoryId): AdvertisingCategory
    {
        $discoveredIds = array_values(AdvertisingMedium::query()
            ->where('category_id', $categoryId)
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all());

        $this->lockMediaByIds($discoveredIds);
        $category = $this->lockCategory($categoryId);

        $lockedMediaIds = $discoveredIds;
        sort($lockedMediaIds);

        for ($attempt = 0; $attempt < 8; $attempt++) {
            /** @var Collection<int, AdvertisingMedium> $media */
            $media = AdvertisingMedium::query()
                ->where('category_id', $categoryId)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $currentIds = array_values($media
                ->map(static fn (AdvertisingMedium $medium): int => (int) $medium->id)
                ->all());
            sort($currentIds);

            $missing = array_values(array_diff($currentIds, $lockedMediaIds));
            if ($missing === []) {
                $category->setRelation('advertisingMedia', $media);

                return $category;
            }

            // Neu erschienene Zeilen nachziehen (Reihenfolge bleibt media → bereits gehaltene Kategorie).
            $this->lockMediaByIds($missing);
            $lockedMediaIds = array_values(array_unique(array_merge($lockedMediaIds, $currentIds)));
            sort($lockedMediaIds);
        }

        throw ValidationException::withMessages([
            'category' => 'Die Oberkategorie konnte wegen paralleler Medienänderungen nicht sicher gesperrt werden. Bitte erneut versuchen.',
        ]);
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

        return $locked;
    }
}
