<?php

namespace App\Services\DispoOrder\SpotDistributionExport;

use App\Enums\SpotComponentProfile;
use App\Enums\SpotComponentRole;
use App\Models\DispoOrderPosition;
use App\Support\Advertising\SpotComponentProfileContract;
use Illuminate\Validation\ValidationException;

/**
 * Gemeinsame Helfer für Calendar- und Average-Exportzeilen (SPT-008).
 */
final class SpotPlanningExportSupport
{
    public function positionLabel(DispoOrderPosition $position): string
    {
        return 'Position '.((int) $position->sort + 1);
    }

    public function quantityUnit(?SpotComponentProfile $profile): string
    {
        if ($profile === null) {
            return 'Spots';
        }

        return $profile->unitLabel();
    }

    public function componentAirings(?SpotComponentProfile $profile, int $unitCount): int
    {
        if ($profile === null) {
            return $unitCount;
        }

        return SpotComponentProfileContract::derivedAirings($profile, $unitCount);
    }

    public function resolveTotalLengthSeconds(DispoOrderPosition $position): int
    {
        $components = $position->components_snapshot ?? [];
        if ($components !== []) {
            $sum = 0;
            foreach ($components as $component) {
                if (! isset($component['length_seconds'])) {
                    throw ValidationException::withMessages([
                        'export' => 'Komponenten-Snapshot enthält ungültige Längenwerte.',
                    ]);
                }
                $length = (int) $component['length_seconds'];
                if ($length < 1) {
                    throw ValidationException::withMessages([
                        'export' => 'Komponenten-Snapshot enthält ungültige Längenwerte.',
                    ]);
                }
                $sum += $length;
            }

            return $sum;
        }

        $length = (int) $position->length_seconds;
        if ($length < 1) {
            throw ValidationException::withMessages([
                'export' => 'Die Spotlänge der Position ist ungültig.',
            ]);
        }

        return $length;
    }

    public function formatComponents(DispoOrderPosition $position, ?SpotComponentProfile $profile): string
    {
        $components = $position->components_snapshot ?? [];

        if ($components === []) {
            $length = (int) $position->length_seconds;
            if ($length < 1) {
                throw ValidationException::withMessages([
                    'export' => 'Legacy-Position ohne Komponenten und ohne gültige Spotlänge.',
                ]);
            }

            return 'Spot '.$length.' s';
        }

        $normalized = [];
        foreach ($components as $index => $component) {
            $roleValue = $component['role'] ?? null;
            if (! is_string($roleValue) || $roleValue === '') {
                throw ValidationException::withMessages([
                    'export' => 'Komponenten-Snapshot ohne Rolle.',
                ]);
            }

            $role = SpotComponentRole::tryFrom($roleValue);
            if ($role === null) {
                throw ValidationException::withMessages([
                    'export' => "Unbekannte Komponentenrolle „{$roleValue}“.",
                ]);
            }

            $sort = isset($component['sort']) ? (int) $component['sort'] : $index + 1;
            $length = isset($component['length_seconds']) ? (int) $component['length_seconds'] : 0;
            if ($length < 1) {
                throw ValidationException::withMessages([
                    'export' => 'Komponenten-Snapshot enthält ungültige Längenwerte.',
                ]);
            }

            $label = $this->componentDisplayLabel($profile, $role, $sort, $component);

            $normalized[] = [
                'sort' => $sort,
                'label' => $label,
                'length_seconds' => $length,
            ];
        }

        usort($normalized, static fn (array $a, array $b): int => $a['sort'] <=> $b['sort']);

        if ($profile !== null) {
            $expected = SpotComponentProfileContract::slots($profile);
            if (count($normalized) !== count($expected)) {
                throw ValidationException::withMessages([
                    'export' => 'Komponenten-Snapshot passt nicht zum gespeicherten Profil.',
                ]);
            }
        }

        return implode(' | ', array_map(
            static fn (array $part): string => $part['label'].' '.$part['length_seconds'].' s',
            $normalized,
        ));
    }

    /**
     * @param  array<string, mixed>  $component
     */
    private function componentDisplayLabel(
        ?SpotComponentProfile $profile,
        SpotComponentRole $role,
        int $sort,
        array $component,
    ): string {
        if ($profile !== null) {
            return SpotComponentProfileContract::displayLabel($profile, $role, $sort);
        }

        $label = $component['label'] ?? null;
        if (is_string($label) && trim($label) !== '') {
            return trim($label);
        }

        return $role->label();
    }

    /**
     * Gespeicherter Flugzeitraum der Position, ohne Erfindung.
     *
     * @return array{0: string, 1: string}|null [von, bis] im Format d.m.Y
     */
    public function flightPeriodDisplay(DispoOrderPosition $position): ?array
    {
        $position->loadMissing(['fieldValues.snapshotFieldDefinition']);

        $periodOpen = null;
        $start = null;
        $end = null;

        foreach ($position->fieldValues as $value) {
            $key = $value->snapshotFieldDefinition?->key;
            if ($key === 'period_open') {
                $periodOpen = $value->value_boolean;
            }
            if ($key === 'position_flight_period') {
                $start = $value->value_period_start;
                $end = $value->value_period_end;
            }
        }

        if ($periodOpen === true) {
            return null;
        }

        if ($start === null || $end === null) {
            return null;
        }

        $startIso = $start instanceof \DateTimeInterface
            ? $start->format('Y-m-d')
            : (string) $start;
        $endIso = $end instanceof \DateTimeInterface
            ? $end->format('Y-m-d')
            : (string) $end;

        if ($startIso === '' || $endIso === '') {
            return null;
        }

        if ($endIso < $startIso) {
            throw ValidationException::withMessages([
                'export' => 'Der gespeicherte Flugzeitraum der Position ist ungültig (Ende vor Start).',
            ]);
        }

        return [
            (new \DateTimeImmutable($startIso))->format('d.m.Y'),
            (new \DateTimeImmutable($endIso))->format('d.m.Y'),
        ];
    }
}
