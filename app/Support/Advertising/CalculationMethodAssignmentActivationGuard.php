<?php

namespace App\Support\Advertising;

use App\Models\CalculationMethod;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * ADV-001c3b1/c3b2: verbindlicher Vertrag für Assignment-Aktivierungen.
 *
 * Vor dem Anlegen, Reaktivieren oder Aktivieren einer Kategorie- oder
 * Mediumzuordnung müssen die calculation_methods unter FOR UPDATE gesperrt und
 * auf Existenz + is_active geprüft werden. Ein roher DB-Insert ohne diesen
 * Guard gilt nicht als Anwendungssicherheit.
 *
 * c3b2 sperrt alle relevanten Methoden gemeinsam nach ID ASC, bevor
 * Assignment-Zeilen gesperrt werden. Die Aktivitätsprüfung erfolgt danach
 * gezielt gegen die bereits gesperrten Methodenzeilen.
 */
final class CalculationMethodAssignmentActivationGuard
{
    /**
     * Sperrt eine Methode und stellt sicher, dass sie aktiv ist
     * (Einzellock-Pfad, z. B. Concurrency-Worker / c3c).
     */
    public function lockActiveMethod(int $calculationMethodId): CalculationMethod
    {
        $locked = $this->lockMethodsByIdsAsc([$calculationMethodId]);
        /** @var CalculationMethod|null $method */
        $method = $locked->get($calculationMethodId);
        if ($method === null) {
            throw ValidationException::withMessages([
                'calculation_method_id' => 'Die Berechnungsmethode wurde nicht gefunden.',
            ]);
        }

        $this->assertAllowsActiveAssignment($method);

        return $method;
    }

    /**
     * Sperrt alle Methodenzeilen gemeinsam nach ID ASC.
     * Keine Aktivitätsprüfung – siehe {@see assertAllowsActiveAssignment()}.
     *
     * @param  list<int>|array<int, int>  $calculationMethodIds
     * @return Collection<int, CalculationMethod> keyed by id
     */
    public function lockMethodsByIdsAsc(array $calculationMethodIds): Collection
    {
        /** @var list<int> $ids */
        $ids = array_values(array_unique(array_filter(
            array_map(static fn ($id): int => (int) $id, $calculationMethodIds),
            static fn (int $id): bool => $id > 0,
        )));
        sort($ids);

        if ($ids === []) {
            /** @var Collection<int, CalculationMethod> $empty */
            $empty = new Collection;

            return $empty;
        }

        /** @var Collection<int, CalculationMethod> $locked */
        $locked = CalculationMethod::query()
            ->whereIn('id', $ids)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        foreach ($ids as $id) {
            if (! $locked->has($id)) {
                throw ValidationException::withMessages([
                    'calculation_method_id' => 'Die Berechnungsmethode wurde nicht gefunden.',
                ]);
            }
        }

        return $locked;
    }

    /**
     * Prüft eine bereits gesperrte Methode für aktive Zuordnung.
     */
    public function assertAllowsActiveAssignment(CalculationMethod $method): void
    {
        if ((int) $method->id < 1) {
            throw ValidationException::withMessages([
                'calculation_method_id' => 'Eine Berechnungsmethode ist erforderlich.',
            ]);
        }

        if (! $method->is_active) {
            throw ValidationException::withMessages([
                'calculation_method_id' => 'Einer inaktiven Berechnungsmethode können keine '
                    .'aktiven Zuordnungen zugewiesen werden.',
            ]);
        }
    }
}
