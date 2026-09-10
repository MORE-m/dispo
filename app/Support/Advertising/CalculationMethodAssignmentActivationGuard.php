<?php

namespace App\Support\Advertising;

use App\Models\CalculationMethod;
use Illuminate\Validation\ValidationException;

/**
 * ADV-001c3b1: verbindlicher Vertrag für künftige Assignment-Aktivierungen (c3b2/c3c).
 *
 * Vor dem Anlegen, Reaktivieren oder Aktivieren einer Kategorie- oder
 * Mediumzuordnung muss die calculation_method unter FOR UPDATE gesperrt und
 * auf Existenz + is_active geprüft werden. Ein roher DB-Insert ohne diesen
 * Guard gilt nicht als Anwendungssicherheit.
 */
final class CalculationMethodAssignmentActivationGuard
{
    /**
     * Sperrt die Methode und stellt sicher, dass sie aktiv ist.
     */
    public function lockActiveMethod(int $calculationMethodId): CalculationMethod
    {
        if ($calculationMethodId < 1) {
            throw ValidationException::withMessages([
                'calculation_method_id' => 'Eine Berechnungsmethode ist erforderlich.',
            ]);
        }

        /** @var CalculationMethod|null $method */
        $method = CalculationMethod::query()
            ->whereKey($calculationMethodId)
            ->lockForUpdate()
            ->first();

        if ($method === null) {
            throw ValidationException::withMessages([
                'calculation_method_id' => 'Die Berechnungsmethode wurde nicht gefunden.',
            ]);
        }

        if (! $method->is_active) {
            throw ValidationException::withMessages([
                'calculation_method_id' => 'Einer inaktiven Berechnungsmethode können keine '
                    .'aktiven Zuordnungen zugewiesen werden.',
            ]);
        }

        return $method;
    }
}
