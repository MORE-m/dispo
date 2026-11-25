<?php

namespace App\Services\InventoryMediumRule\Catalog;

use App\Enums\CalculationKind;
use App\Enums\CalculationMethodMode;
use App\Models\AdvertisingMedium;
use App\Models\AdvertisingMediumCalculationMethod;
use App\Models\CalculationMethod;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * PO-REFOCUS-6 / PO-SWF-CALENDAR-1: Average+Calendar-Override an einem SWF-Längenmedium.
 * Idempotent. Bekannte Vorzustände: frisch (inherit/leer) oder nur Average; sonst fail-closed.
 */
final class SwfLengthMediumOverrideEnsurer
{
    public const OUTCOME_MISSING = 'missing';

    public const OUTCOME_UNCHANGED = 'unchanged';

    public const OUTCOME_INSTALLED_FRESH = 'installed_fresh';

    public const OUTCOME_UPGRADED_CALENDAR = 'upgraded_calendar';

    /**
     * @return array{
     *     outcome: string,
     *     medium_id: int|null,
     *     before: array<string, mixed>|null,
     *     after: array<string, mixed>|null,
     *     changes: list<string>
     * }
     */
    public function ensure(
        string $mediumCode,
        CalculationKind $expectedKind,
        string $engineProfile,
        string $label,
        bool $dryRun = false,
        bool $requireMedium = false,
    ): array {
        $medium = AdvertisingMedium::query()->where('code', $mediumCode)->lockForUpdate()->first();
        if ($medium === null) {
            if ($requireMedium) {
                throw new RuntimeException("{$label}-Medium „{$mediumCode}“ fehlt. Reparatur abgebrochen.");
            }

            return [
                'outcome' => self::OUTCOME_MISSING,
                'medium_id' => null,
                'before' => null,
                'after' => null,
                'changes' => [],
            ];
        }

        if ($medium->kind !== $expectedKind) {
            throw new RuntimeException(
                "{$label}-Medium hat nicht die Berechnungsart {$expectedKind->value}. Bootstrap überschreibt das nicht.",
            );
        }

        $average = CalculationMethod::query()->where('key', 'average')->first();
        $calendar = CalculationMethod::query()->where('key', 'calendar')->first();
        if ($average === null || $calendar === null) {
            throw new RuntimeException("Berechnungsmethoden „average“/„calendar“ fehlen für den {$label}-Override.");
        }

        /** @var Collection<int, AdvertisingMediumCalculationMethod> $assignments */
        $assignments = AdvertisingMediumCalculationMethod::query()
            ->where('advertising_medium_id', $medium->id)
            ->get();

        $before = $this->snapshot($medium, $assignments);

        $hasAverageExact = $assignments->contains(
            fn (AdvertisingMediumCalculationMethod $row): bool => (int) $row->calculation_method_id === (int) $average->id
                && $row->engine_profile_key === $engineProfile
                && (bool) $row->is_active
                && (int) $row->sort === 10,
        );
        $hasCalendarExact = $assignments->contains(
            fn (AdvertisingMediumCalculationMethod $row): bool => (int) $row->calculation_method_id === (int) $calendar->id
                && $row->engine_profile_key === $engineProfile
                && (bool) $row->is_active
                && (int) $row->sort === 20,
        );

        // Strikter Sollzustand: genau Average@10 + Calendar@20, Default average, Override, keine Extra-Zeilen.
        $matches = $medium->calculation_method_mode === CalculationMethodMode::Override
            && (int) $medium->default_calculation_method_id === (int) $average->id
            && $hasAverageExact
            && $hasCalendarExact
            && $assignments->count() === 2;

        if ($matches) {
            return [
                'outcome' => self::OUTCOME_UNCHANGED,
                'medium_id' => (int) $medium->id,
                'before' => $before,
                'after' => $before,
                'changes' => [],
            ];
        }

        $canInstallFresh = $medium->calculation_method_mode === CalculationMethodMode::Inherit
            && $medium->default_calculation_method_id === null
            && $assignments->isEmpty();

        // Bekannter Migrations-Vorzustand: nur Average mit sort=10 am Soll-Profil.
        $canUpgradeAverageOnly = $medium->calculation_method_mode === CalculationMethodMode::Override
            && (int) $medium->default_calculation_method_id === (int) $average->id
            && $hasAverageExact
            && ! $hasCalendarExact
            && $assignments->count() === 1;

        if (! $canInstallFresh && ! $canUpgradeAverageOnly) {
            throw new RuntimeException(
                "{$label}-Methodenvertrag weicht ab und wird nicht überschrieben.",
            );
        }

        $outcome = $canInstallFresh ? self::OUTCOME_INSTALLED_FRESH : self::OUTCOME_UPGRADED_CALENDAR;
        $changes = [];

        if ($canInstallFresh) {
            $changes[] = 'calculation_method_mode: inherit → override';
            $changes[] = 'default_calculation_method_id: null → average';
            $changes[] = "medium_method: average @ {$engineProfile} (sort 10)";
        }
        $changes[] = "medium_method: calendar @ {$engineProfile} (sort 20)";

        if ($dryRun) {
            return [
                'outcome' => $outcome,
                'medium_id' => (int) $medium->id,
                'before' => $before,
                'after' => $this->plannedSnapshot((int) $average->id, (int) $calendar->id, $engineProfile, $before, $canInstallFresh),
                'changes' => $changes,
            ];
        }

        if ($canInstallFresh) {
            $medium->calculation_method_mode = CalculationMethodMode::Override;
            $medium->default_calculation_method_id = $average->id;
            $medium->save();

            $averageAssignment = new AdvertisingMediumCalculationMethod;
            $averageAssignment->advertising_medium_id = $medium->id;
            $averageAssignment->calculation_method_id = $average->id;
            $averageAssignment->engine_profile_key = $engineProfile;
            $averageAssignment->is_active = true;
            $averageAssignment->sort = 10;
            $averageAssignment->lock_version = 1;
            $averageAssignment->save();
        }

        $calendarAssignment = new AdvertisingMediumCalculationMethod;
        $calendarAssignment->advertising_medium_id = $medium->id;
        $calendarAssignment->calculation_method_id = $calendar->id;
        $calendarAssignment->engine_profile_key = $engineProfile;
        $calendarAssignment->is_active = true;
        $calendarAssignment->sort = 20;
        $calendarAssignment->lock_version = 1;
        $calendarAssignment->save();

        $fresh = $medium->fresh() ?? $medium;
        /** @var Collection<int, AdvertisingMediumCalculationMethod> $afterAssignments */
        $afterAssignments = AdvertisingMediumCalculationMethod::query()
            ->where('advertising_medium_id', $medium->id)
            ->get();

        return [
            'outcome' => $outcome,
            'medium_id' => (int) $medium->id,
            'before' => $before,
            'after' => $this->snapshot($fresh, $afterAssignments),
            'changes' => $changes,
        ];
    }

    /**
     * @param  Collection<int, AdvertisingMediumCalculationMethod>  $assignments
     * @return array{
     *     calculation_method_mode: string,
     *     default_calculation_method_id: int|null,
     *     methods: list<array{method_id: int, engine_profile_key: string, is_active: bool, sort: int}>
     * }
     */
    private function snapshot(AdvertisingMedium $medium, Collection $assignments): array
    {
        return [
            'calculation_method_mode' => $medium->calculation_method_mode->value,
            'default_calculation_method_id' => $medium->default_calculation_method_id === null
                ? null
                : (int) $medium->default_calculation_method_id,
            'methods' => array_values($assignments
                ->sortBy('sort')
                ->values()
                ->map(fn (AdvertisingMediumCalculationMethod $row): array => [
                    'method_id' => (int) $row->calculation_method_id,
                    'engine_profile_key' => (string) $row->engine_profile_key,
                    'is_active' => (bool) $row->is_active,
                    'sort' => (int) $row->sort,
                ])
                ->all()),
        ];
    }

    /**
     * @param  array{
     *     calculation_method_mode: string,
     *     default_calculation_method_id: int|null,
     *     methods: list<array{method_id: int, engine_profile_key: string, is_active: bool, sort: int}>
     * }  $before
     * @return array{
     *     calculation_method_mode: string,
     *     default_calculation_method_id: int|null,
     *     methods: list<array{method_id: int, engine_profile_key: string, is_active: bool, sort: int}>
     * }
     */
    private function plannedSnapshot(
        int $averageId,
        int $calendarId,
        string $engineProfile,
        array $before,
        bool $installFresh,
    ): array {
        $methods = $installFresh ? [] : $before['methods'];
        if ($installFresh) {
            $methods[] = [
                'method_id' => $averageId,
                'engine_profile_key' => $engineProfile,
                'is_active' => true,
                'sort' => 10,
            ];
        }
        $methods[] = [
            'method_id' => $calendarId,
            'engine_profile_key' => $engineProfile,
            'is_active' => true,
            'sort' => 20,
        ];

        return [
            'calculation_method_mode' => CalculationMethodMode::Override->value,
            'default_calculation_method_id' => $averageId,
            'methods' => $methods,
        ];
    }
}
