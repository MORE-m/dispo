<?php

namespace App\Support\Advertising;

use App\Enums\ComponentCalculationStrategy;
use App\Enums\SpotComponentProfile;
use App\Enums\SpotComponentRole;

/**
 * BL-P4-02e: kanonische Komponentenprofile (Rollen, Anzahl, Sort, Strategie).
 */
final class SpotComponentProfileContract
{
    /**
     * @return list<array{role: SpotComponentRole, label: string, sort: int}>
     */
    public static function slots(SpotComponentProfile $profile): array
    {
        return match ($profile) {
            SpotComponentProfile::Tandem => [
                [
                    'role' => SpotComponentRole::MainSpot,
                    'label' => SpotComponentRole::MainSpot->label(),
                    'sort' => 1,
                ],
                [
                    'role' => SpotComponentRole::Reminder,
                    'label' => SpotComponentRole::Reminder->label(),
                    'sort' => 2,
                ],
            ],
            SpotComponentProfile::Tridem => [
                [
                    'role' => SpotComponentRole::MainSpot,
                    'label' => SpotComponentRole::MainSpot->label(),
                    'sort' => 1,
                ],
                [
                    'role' => SpotComponentRole::Reminder,
                    'label' => SpotComponentRole::Reminder->label(),
                    'sort' => 2,
                ],
                [
                    'role' => SpotComponentRole::Reminder,
                    'label' => SpotComponentRole::Reminder->label(),
                    'sort' => 3,
                ],
            ],
        };
    }

    /**
     * @return list<SpotComponentRole>
     */
    public static function allowedRoles(SpotComponentProfile $profile): array
    {
        return match ($profile) {
            SpotComponentProfile::Tandem, SpotComponentProfile::Tridem => [
                SpotComponentRole::MainSpot,
                SpotComponentRole::Reminder,
            ],
        };
    }

    public static function requiredStrategy(SpotComponentProfile $profile): ComponentCalculationStrategy
    {
        return $profile->requiredStrategy();
    }

    /**
     * UI-Bezeichnung für Reminder-Slots (fachlich weiterhin role=reminder).
     */
    public static function displayLabel(SpotComponentProfile $profile, SpotComponentRole $role, int $sort): string
    {
        if ($role === SpotComponentRole::MainSpot) {
            return $role->label();
        }

        if ($role === SpotComponentRole::Reminder) {
            return match ($profile) {
                SpotComponentProfile::Tandem => 'Reminder',
                SpotComponentProfile::Tridem => $sort === 2 ? 'Reminder 1' : 'Reminder 2',
            };
        }

        return $role->label();
    }

    public static function derivedAirings(SpotComponentProfile $profile, int $unitCount): int
    {
        return max(0, $unitCount) * $profile->unitCount();
    }

    public static function tryFrom(?string $value): ?SpotComponentProfile
    {
        if ($value === null || $value === '') {
            return null;
        }

        return SpotComponentProfile::tryFrom($value);
    }
}
