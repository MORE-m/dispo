<?php

namespace App\Support\Advertising;

use App\Enums\CalculationKind;
use Illuminate\Validation\ValidationException;

/**
 * ADV-001b / PO-ADV001b-8: fachlich-technische Kompatibilität kind ↔ Oberkategorie.
 *
 * Aktuell existiert nur CalculationKind::SpotClassic. Spot Classic ist laut
 * CatalogResolver und ADV-001a-Bestandsmap an die Kategorie „spots“ gebunden.
 * Weitere Kinds sind eigene spätere Fachslices.
 */
final class AdvertisingKindCategoryCompatibility
{
    /**
     * @return list<string>
     */
    public static function allowedCategoryKeysFor(CalculationKind $kind): array
    {
        return match ($kind) {
            CalculationKind::SpotClassic => [CanonicalAdvertisingCategories::SPOTS],
        };
    }

    public static function assertCompatible(CalculationKind $kind, string $categoryKey): void
    {
        $allowed = self::allowedCategoryKeysFor($kind);
        if (in_array($categoryKey, $allowed, true)) {
            return;
        }

        $allowedList = implode(', ', $allowed);
        throw ValidationException::withMessages([
            'category_id' => "Die Berechnungsart „{$kind->value}“ ist nur mit Oberkategorie-Key(s) {$allowedList} kompatibel (PO-ADV001b-8).",
            'kind' => "Die Berechnungsart „{$kind->value}“ ist nur mit Oberkategorie-Key(s) {$allowedList} kompatibel (PO-ADV001b-8).",
        ]);
    }

    public static function isCompatible(CalculationKind $kind, string $categoryKey): bool
    {
        return in_array($categoryKey, self::allowedCategoryKeysFor($kind), true);
    }
}
