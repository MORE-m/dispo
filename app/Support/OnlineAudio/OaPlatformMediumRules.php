<?php

namespace App\Support\OnlineAudio;

use App\Models\AdvertisingMedium;
use App\Models\OaPlatform;
use App\Models\OaPlatformMediumRule;
use App\Services\Calculation\OaTkp\OaTkpWriterPositionResolver;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * PO-CALC-TKP-1D4c-4j-1: Lese-/Prüf-API für Platform × OA-Kernmedium-Zuordnungen.
 *
 * Noch nicht in {@see OaTkpWriterPositionResolver}
 * eingebunden (Enforcement = D4c-4j-5). Leerer Regelbestand ⇒ keine aktiven Zuordnungen
 * (keine implizite Freigabe).
 */
final class OaPlatformMediumRules
{
    public static function isAdvertisingMediumCodeAllowed(string $code): bool
    {
        $code = trim($code);

        return $code !== ''
            && $code !== 'native_ad'
            && OaTkpCoreMedia::contains($code);
    }

    /**
     * Fachliche Zulässigkeit eines Mediums für Platform-Rules (serverseitig).
     *
     * @throws ValidationException
     */
    public static function assertAdvertisingMediumAllowed(AdvertisingMedium|int|string $medium): AdvertisingMedium
    {
        if ($medium instanceof AdvertisingMedium) {
            $model = $medium;
        } elseif (is_int($medium)) {
            $model = AdvertisingMedium::query()->find($medium);
        } elseif (ctype_digit($medium)) {
            $model = AdvertisingMedium::query()->find((int) $medium);
        } else {
            $model = AdvertisingMedium::query()->where('code', trim($medium))->first();
        }

        if ($model === null) {
            throw ValidationException::withMessages([
                'advertising_medium_id' => 'Unbekanntes Werbemittel.',
            ]);
        }

        $code = (string) $model->code;
        if ($code === 'native_ad' || ! OaTkpCoreMedia::contains($code)) {
            throw ValidationException::withMessages([
                'advertising_medium_id' => 'Plattform-Zuordnungen sind nur für OA-TKP-Kernmedien zulässig (nicht native_ad / andere Werbeformen).',
            ]);
        }

        return $model;
    }

    /**
     * Aktive Plattform-IDs für ein Medium (Rule aktiv + Plattform aktiv + Kernmedium).
     *
     * @return list<int>
     */
    public static function activePlatformIdsForMedium(int $advertisingMediumId): array
    {
        if (! self::mediumIdIsCore($advertisingMediumId)) {
            return [];
        }

        $ids = [];
        foreach (
            OaPlatformMediumRule::query()
                ->active()
                ->where('advertising_medium_id', $advertisingMediumId)
                ->whereHas('oaPlatform', static fn ($q) => $q->where('is_active', true))
                ->orderBy('oa_platform_id')
                ->pluck('oa_platform_id') as $id
        ) {
            $ids[] = (int) $id;
        }

        return $ids;
    }

    /**
     * Aktive Medium-IDs für eine Plattform (Rule aktiv + Plattform aktiv + Kernmedium).
     *
     * @return list<int>
     */
    public static function activeMediumIdsForPlatform(int $oaPlatformId): array
    {
        $platform = OaPlatform::query()->find($oaPlatformId);
        if ($platform === null || ! $platform->is_active) {
            return [];
        }

        $ids = [];
        foreach (
            OaPlatformMediumRule::query()
                ->active()
                ->where('oa_platform_id', $oaPlatformId)
                ->whereHas('advertisingMedium', static function ($q): void {
                    $q->whereIn('code', OaTkpCoreMedia::codes());
                })
                ->orderBy('advertising_medium_id')
                ->pluck('advertising_medium_id') as $id
        ) {
            $ids[] = (int) $id;
        }

        return $ids;
    }

    /**
     * Operativ auswählbar: aktive Rule, aktive Plattform, Kernmedium.
     */
    public static function isActivelyAssigned(int $oaPlatformId, int $advertisingMediumId): bool
    {
        if (! self::mediumIdIsCore($advertisingMediumId)) {
            return false;
        }

        return OaPlatformMediumRule::query()
            ->active()
            ->where('oa_platform_id', $oaPlatformId)
            ->where('advertising_medium_id', $advertisingMediumId)
            ->whereHas('oaPlatform', static fn ($q) => $q->where('is_active', true))
            ->exists();
    }

    /**
     * Alle Rules einer Plattform (inkl. inaktiv) – für spätere Admin-Matrix.
     *
     * @return Collection<int, OaPlatformMediumRule>
     */
    public static function rulesForPlatform(int $oaPlatformId, bool $onlyActive = false): Collection
    {
        $query = OaPlatformMediumRule::query()
            ->where('oa_platform_id', $oaPlatformId)
            ->with(['advertisingMedium:id,code,name', 'oaPlatform:id,code,name,is_active'])
            ->orderBy('advertising_medium_id');

        if ($onlyActive) {
            $query->active();
        }

        return $query->get();
    }

    /**
     * Alle Rules eines Mediums (inkl. inaktiv) – für spätere Adminansichten.
     *
     * @return Collection<int, OaPlatformMediumRule>
     */
    public static function rulesForMedium(int $advertisingMediumId, bool $onlyActive = false): Collection
    {
        $query = OaPlatformMediumRule::query()
            ->where('advertising_medium_id', $advertisingMediumId)
            ->with(['oaPlatform:id,code,name,is_active', 'advertisingMedium:id,code,name'])
            ->orderBy('oa_platform_id');

        if ($onlyActive) {
            $query->active();
        }

        return $query->get();
    }

    private static function mediumIdIsCore(int $advertisingMediumId): bool
    {
        $code = AdvertisingMedium::query()->whereKey($advertisingMediumId)->value('code');

        return is_string($code) && self::isAdvertisingMediumCodeAllowed($code);
    }
}
