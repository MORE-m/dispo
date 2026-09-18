<?php

namespace App\Services\Calculation;

use App\Enums\ComponentCalculationStrategy;
use App\Enums\SpotCalculationMethod;
use App\Enums\SpotComponentProfile;
use App\Enums\SpotComponentRole;
use App\Models\CalculationPosition;
use App\Support\Advertising\SpotComponentProfileContract;
use Illuminate\Validation\ValidationException;

/**
 * BL-P4-02c / AT-04 / BL-P4-02e / SPT-012: serverseitige Komponentenvalidierung (fail-closed).
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
        ?CalculationPosition $existing = null,
        ?SpotComponentProfile $profile = null,
    ): array {
        $keyPresent = array_key_exists('components', $position);
        $raw = $keyPresent ? $position['components'] : null;

        if ($profile !== null) {
            return $this->validateForcedProfile(
                $position,
                $index,
                $method,
                $existing,
                $profile,
                $keyPresent,
                $raw,
            );
        }

        return $this->validateOptionalAllonge(
            $position,
            $index,
            $method,
            $existing,
            $keyPresent,
            $raw,
        );
    }

    /**
     * @param  array<string, mixed>  $position
     * @return list<array{role: string, label: string, length_seconds: int, sort: int}>
     */
    private function validateForcedProfile(
        array $position,
        int $index,
        SpotCalculationMethod $method,
        ?CalculationPosition $existing,
        SpotComponentProfile $profile,
        bool $keyPresent,
        mixed $raw,
    ): array {
        if (! $keyPresent) {
            if ($this->existingHasComponents($existing)) {
                $fromExisting = $this->fromExisting($existing);

                return $this->assertMatchesProfile($fromExisting, $profile, $index);
            }

            throw ValidationException::withMessages([
                "positions.{$index}.components" => $this->profileMissingMessage($profile),
            ]);
        }

        if ($raw === null) {
            throw ValidationException::withMessages([
                "positions.{$index}.components" => 'Komponenten dürfen nicht null sein. Fehlendes Feld behält bestehende Komponenten; [] deaktiviert sie explizit.',
            ]);
        }

        if ($raw === []) {
            throw ValidationException::withMessages([
                "positions.{$index}.components" => $this->profileMissingMessage($profile),
            ]);
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

        $slots = SpotComponentProfileContract::slots($profile);
        $allowedRoles = SpotComponentProfileContract::allowedRoles($profile);
        $normalized = [];

        foreach (array_values($raw) as $componentIndex => $component) {
            if (! is_array($component)) {
                throw ValidationException::withMessages([
                    "positions.{$index}.components.{$componentIndex}" => 'Ungültige Komponente.',
                ]);
            }

            $roleRaw = (string) ($component['role'] ?? '');
            $role = SpotComponentRole::tryFrom($roleRaw);
            if ($role === null || ! in_array($role, $allowedRoles, true)) {
                throw ValidationException::withMessages([
                    "positions.{$index}.components.{$componentIndex}.role" => 'Unbekannte oder für dieses Komponentenprofil nicht zulässige Rolle.',
                ]);
            }

            if ($role === SpotComponentRole::Allonge) {
                throw ValidationException::withMessages([
                    "positions.{$index}.components.{$componentIndex}.role" => 'Allonge ist bei diesem Komponentenprofil nicht zulässig.',
                ]);
            }

            $lengthSeconds = $this->positiveIntegerSeconds(
                $component['length_seconds'] ?? null,
                "positions.{$index}.components.{$componentIndex}.length_seconds",
            );

            $canonicalLabel = $role->label();
            if (array_key_exists('label', $component) && $component['label'] !== null && $component['label'] !== '') {
                $clientLabel = trim((string) $component['label']);
                if ($clientLabel !== $canonicalLabel) {
                    throw ValidationException::withMessages([
                        "positions.{$index}.components.{$componentIndex}.label" => "Die Bezeichnung für {$role->value} muss „{$canonicalLabel}“ sein.",
                    ]);
                }
            }

            $sort = array_key_exists('sort', $component) ? (int) $component['sort'] : ($componentIndex + 1);

            $normalized[] = [
                'role' => $role->value,
                'label' => $canonicalLabel,
                'length_seconds' => $lengthSeconds,
                'sort' => $sort,
            ];
        }

        $normalized = $this->assertMatchesProfile($normalized, $profile, $index);
        $this->assertClientLengthMatchesTotal($position, $index, $normalized);

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $position
     * @return list<array{role: string, label: string, length_seconds: int, sort: int}>
     */
    private function validateOptionalAllonge(
        array $position,
        int $index,
        SpotCalculationMethod $method,
        ?CalculationPosition $existing,
        bool $keyPresent,
        mixed $raw,
    ): array {
        if (! $keyPresent) {
            if ($this->existingHasComponents($existing)) {
                return $this->fromExisting($existing);
            }

            $this->assertNoOrphanStrategy($position, $index);

            return [];
        }

        if ($raw === null) {
            throw ValidationException::withMessages([
                "positions.{$index}.components" => 'Komponenten dürfen nicht null sein. Fehlendes Feld behält bestehende Komponenten; [] deaktiviert sie explizit.',
            ]);
        }

        if ($raw === []) {
            $this->assertNoOrphanStrategy($position, $index);

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
            if ($role === null || ! in_array($role, SpotComponentRole::allowedForOptionalAllonge(), true)) {
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

            $canonicalLabel = $role->label();
            if (array_key_exists('label', $component) && $component['label'] !== null && $component['label'] !== '') {
                $clientLabel = trim((string) $component['label']);
                if ($clientLabel !== $canonicalLabel) {
                    throw ValidationException::withMessages([
                        "positions.{$index}.components.{$componentIndex}.label" => "Die Bezeichnung für {$role->value} muss „{$canonicalLabel}“ sein.",
                    ]);
                }
            }

            $sort = array_key_exists('sort', $component) ? (int) $component['sort'] : $componentIndex;

            $normalized[] = [
                'role' => $role->value,
                'label' => $canonicalLabel,
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
                $roleCmp = ($roleOrder[$a['role']] ?? 99) <=> ($roleOrder[$b['role']] ?? 99);
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

        $this->assertClientLengthMatchesTotal($position, $index, $normalized);

        return $normalized;
    }

    /**
     * @param  list<array{role: string, label: string, length_seconds: int, sort: int}>  $normalized
     * @return list<array{role: string, label: string, length_seconds: int, sort: int}>
     */
    private function assertMatchesProfile(array $normalized, SpotComponentProfile $profile, int $index): array
    {
        $slots = SpotComponentProfileContract::slots($profile);

        if (count($normalized) !== count($slots)) {
            throw ValidationException::withMessages([
                "positions.{$index}.components" => match ($profile) {
                    SpotComponentProfile::Tandem => 'Tandem erfordert exakt einen Hauptspot und einen Reminder.',
                    SpotComponentProfile::Tridem => 'Tridem erfordert exakt einen Hauptspot und zwei Reminder.',
                },
            ]);
        }

        usort($normalized, fn (array $a, array $b): int => $a['sort'] <=> $b['sort']);

        $mainCount = count(array_filter($normalized, fn (array $row): bool => $row['role'] === SpotComponentRole::MainSpot->value));
        $reminderCount = count(array_filter($normalized, fn (array $row): bool => $row['role'] === SpotComponentRole::Reminder->value));
        $allongeCount = count(array_filter($normalized, fn (array $row): bool => $row['role'] === SpotComponentRole::Allonge->value));

        if ($mainCount !== 1 || $reminderCount !== $profile->reminderCount() || $allongeCount !== 0) {
            throw ValidationException::withMessages([
                "positions.{$index}.components" => match ($profile) {
                    SpotComponentProfile::Tandem => 'Tandem erfordert exakt einen Hauptspot und einen Reminder.',
                    SpotComponentProfile::Tridem => 'Tridem erfordert exakt einen Hauptspot und zwei Reminder.',
                },
            ]);
        }

        foreach ($slots as $offset => $slot) {
            $row = $normalized[$offset];
            if ($row['role'] !== $slot['role']->value) {
                throw ValidationException::withMessages([
                    "positions.{$index}.components" => 'Die Komponentenrollen entsprechen nicht dem Komponentenprofil (Reihenfolge: Hauptspot, dann Reminder).',
                ]);
            }
        }

        $canonical = [];
        foreach ($slots as $offset => $slot) {
            $canonical[] = [
                'role' => $slot['role']->value,
                'label' => $slot['label'],
                'length_seconds' => (int) $normalized[$offset]['length_seconds'],
                'sort' => (int) $slot['sort'],
            ];
        }

        return $canonical;
    }

    /**
     * @param  array<string, mixed>  $position
     * @param  list<array{role: string, label: string, length_seconds: int, sort: int}>  $normalized
     */
    private function assertClientLengthMatchesTotal(array $position, int $index, array $normalized): void
    {
        $totalLength = array_sum(array_column($normalized, 'length_seconds'));
        if (array_key_exists('length_seconds', $position) && $position['length_seconds'] !== null && $position['length_seconds'] !== '') {
            $clientLength = (int) $position['length_seconds'];
            if ($clientLength !== $totalLength) {
                throw ValidationException::withMessages([
                    "positions.{$index}.length_seconds" => 'Bei Komponenten muss die Positionslänge der Summe der Komponentenlängen entsprechen.',
                ]);
            }
        }
    }

    private function profileMissingMessage(SpotComponentProfile $profile): string
    {
        return match ($profile) {
            SpotComponentProfile::Tandem => 'Tandem erfordert exakt einen Hauptspot und einen Reminder.',
            SpotComponentProfile::Tridem => 'Tridem erfordert exakt einen Hauptspot und zwei Reminder.',
        };
    }

    /**
     * @param  array<string, mixed>  $position
     */
    private function assertNoOrphanStrategy(array $position, int $index): void
    {
        if (array_key_exists('component_calculation_strategy', $position)
            && $position['component_calculation_strategy'] !== null
            && $position['component_calculation_strategy'] !== ''
        ) {
            throw ValidationException::withMessages([
                "positions.{$index}.component_calculation_strategy" => 'Strategie ohne Komponenten ist unzulässig.',
            ]);
        }
    }

    private function existingHasComponents(?CalculationPosition $existing): bool
    {
        if ($existing === null) {
            return false;
        }

        $existing->loadMissing('components');

        return $existing->components->isNotEmpty();
    }

    /**
     * @return list<array{role: string, label: string, length_seconds: int, sort: int}>
     */
    private function fromExisting(CalculationPosition $existing): array
    {
        $existing->loadMissing('components');

        $rows = [];
        foreach ($existing->components->sortBy('sort')->values() as $component) {
            $role = $component->role;
            $rows[] = [
                'role' => $role->value,
                'label' => $role->label(),
                'length_seconds' => (int) $component->length_seconds,
                'sort' => (int) $component->sort,
            ];
        }

        return $rows;
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
