<?php

namespace App\Services\Calculation;

use App\Enums\ComponentCalculationStrategy;
use App\Enums\SpotCalculationMethod;
use App\Enums\SpotComponentRole;
use Illuminate\Validation\ValidationException;

/**
 * BL-P4-02c / AT-04: serverseitige Komponentenvalidierung (fail-closed).
 */
final class ComponentValidator
{
    /**
     * @param  array<string, mixed>  $position
     * @return list<array{role: string, label: string, length_seconds: int, sort: int}>
     */
    public function validateAndNormalize(
        array $position,
        int $index,
        SpotCalculationMethod $method,
    ): array {
        $raw = $position['components'] ?? null;

        if ($raw === null || $raw === []) {
            if (array_key_exists('component_calculation_strategy', $position)
                && $position['component_calculation_strategy'] !== null
                && $position['component_calculation_strategy'] !== ''
            ) {
                throw ValidationException::withMessages([
                    "positions.{$index}.component_calculation_strategy" => 'Strategie ohne Komponenten ist unzulässig.',
                ]);
            }

            return [];
        }

        if (! is_array($raw)) {
            throw ValidationException::withMessages([
                "positions.{$index}.components" => 'Komponenten müssen als Liste übergeben werden.',
            ]);
        }

        if (! in_array($method, [SpotCalculationMethod::Average, SpotCalculationMethod::Calendar], true)) {
            throw ValidationException::withMessages([
                "positions.{$index}.components" => 'Spot-Komponenten sind für diese Berechnungsmethode nicht unterstützt.',
            ]);
        }

        $normalized = [];
        $mainCount = 0;
        $allongeCount = 0;

        foreach (array_values($raw) as $componentIndex => $component) {
            if (! is_array($component)) {
                throw ValidationException::withMessages([
                    "positions.{$index}.components.{$componentIndex}" => 'Ungültige Komponente.',
                ]);
            }

            $roleRaw = (string) ($component['role'] ?? '');
            $role = SpotComponentRole::tryFrom($roleRaw);
            if ($role === null || ! in_array($role, SpotComponentRole::allowedInBlP402c(), true)) {
                throw ValidationException::withMessages([
                    "positions.{$index}.components.{$componentIndex}.role" => 'Unbekannte oder nicht unterstützte Komponentenrolle.',
                ]);
            }

            if ($role === SpotComponentRole::MainSpot) {
                $mainCount++;
            }
            if ($role === SpotComponentRole::Allonge) {
                $allongeCount++;
            }

            $lengthSeconds = $this->positiveIntegerSeconds(
                $component['length_seconds'] ?? null,
                "positions.{$index}.components.{$componentIndex}.length_seconds",
            );

            $label = trim((string) ($component['label'] ?? $role->label()));
            if ($label === '') {
                $label = $role->label();
            }

            $sort = array_key_exists('sort', $component) ? (int) $component['sort'] : $componentIndex;

            $normalized[] = [
                'role' => $role->value,
                'label' => $label,
                'length_seconds' => $lengthSeconds,
                'sort' => $sort,
            ];
        }

        if ($mainCount < 1) {
            throw ValidationException::withMessages([
                "positions.{$index}.components" => 'Bei aktivierten Komponenten ist genau ein Hauptspot erforderlich.',
            ]);
        }

        if ($mainCount > 1) {
            throw ValidationException::withMessages([
                "positions.{$index}.components" => 'Es darf nur ein Hauptspot vorhanden sein.',
            ]);
        }

        if ($allongeCount > 1) {
            throw ValidationException::withMessages([
                "positions.{$index}.components" => 'In diesem Teilblock ist maximal eine Allonge erlaubt.',
            ]);
        }

        usort(
            $normalized,
            function (array $a, array $b): int {
                $roleOrder = [
                    SpotComponentRole::MainSpot->value => 0,
                    SpotComponentRole::Allonge->value => 1,
                ];
                $roleCmp = $roleOrder[$a['role']] <=> $roleOrder[$b['role']];
                if ($roleCmp !== 0) {
                    return $roleCmp;
                }

                return $a['sort'] <=> $b['sort'];
            },
        );

        foreach ($normalized as $i => &$row) {
            $row['sort'] = $i;
        }
        unset($row);

        $totalLength = array_sum(array_column($normalized, 'length_seconds'));
        if (array_key_exists('length_seconds', $position) && $position['length_seconds'] !== null && $position['length_seconds'] !== '') {
            $clientLength = (int) $position['length_seconds'];
            if ($clientLength !== $totalLength) {
                throw ValidationException::withMessages([
                    "positions.{$index}.length_seconds" => 'Bei Komponenten muss die Positionslänge der Summe der Komponentenlängen entsprechen.',
                ]);
            }
        }

        return $normalized;
    }

    private function positiveIntegerSeconds(mixed $length, string $errorKey): int
    {
        if (is_int($length)) {
            if ($length < 1) {
                throw ValidationException::withMessages([
                    $errorKey => 'Komponentenlänge muss größer als 0 sein.',
                ]);
            }

            return $length;
        }

        if (is_string($length) && preg_match('/^\d+$/', $length) === 1) {
            $parsed = (int) $length;
            if ($parsed < 1) {
                throw ValidationException::withMessages([
                    $errorKey => 'Komponentenlänge muss größer als 0 sein.',
                ]);
            }

            return $parsed;
        }

        if (is_float($length) || (is_string($length) && str_contains($length, '.'))) {
            throw ValidationException::withMessages([
                $errorKey => 'Komponentenlänge muss ganzzahlig sein.',
            ]);
        }

        throw ValidationException::withMessages([
            $errorKey => 'Komponentenlänge muss eine positive ganze Zahl sein.',
        ]);
    }

    public function parseStrategy(mixed $value, int $index): ComponentCalculationStrategy
    {
        if (! is_string($value) || $value === '') {
            throw ValidationException::withMessages([
                "positions.{$index}.component_calculation_strategy" => 'Ungültige Komponenten-Berechnungsstrategie.',
            ]);
        }

        $strategy = ComponentCalculationStrategy::tryFrom($value);
        if ($strategy === null) {
            throw ValidationException::withMessages([
                "positions.{$index}.component_calculation_strategy" => 'Ungültige Komponenten-Berechnungsstrategie.',
            ]);
        }

        return $strategy;
    }
}
