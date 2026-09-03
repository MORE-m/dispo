<?php

namespace App\Services\DispoOrder;

use App\Models\Calculation;
use App\Models\DispoOrder;
use App\Support\DocumentNumber;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final class DispoOrderNumberSequencer
{
    /**
     * @return array{0: int, 1: int, 2: int, 3: string}
     */
    public function next(Calculation $calculation): array
    {
        if (DB::transactionLevel() === 0) {
            return DB::transaction(fn (): array => $this->allocate($calculation));
        }

        return $this->allocate($calculation);
    }

    /**
     * @return array{0: int, 1: int, 2: int, 3: string}
     */
    private function allocate(Calculation $calculation): array
    {
        /** @var Calculation $locked */
        $locked = Calculation::query()->whereKey($calculation->id)->lockForUpdate()->firstOrFail();

        $family = DispoOrder::query()
            ->where('calculation_id', $locked->id)
            ->orderBy('id')
            ->lockForUpdate()
            ->first();

        if ($family !== null) {
            $year = (int) $family->number_year;
            $orgSeq = (int) $family->number_org_seq;
            $calcSeq = (int) (DispoOrder::query()
                ->where('calculation_id', $locked->id)
                ->max('number_calc_seq') ?? 0) + 1;
            $pad = DocumentNumber::sequencePadFromDispoNumber($family->number);

            return [$year, $orgSeq, $calcSeq, DocumentNumber::dispoOrder($year, $orgSeq, $calcSeq, $pad)];
        }

        // Neue Familie: Stamm direkt aus der Kalkulationsnummer (kein Verbrauch der Legacy-Jahressequenz).
        $year = (int) $locked->number_year;
        $orgSeq = (int) $locked->number_seq;
        $calcSeq = 1;

        return [$year, $orgSeq, $calcSeq, DocumentNumber::dispoOrder($year, $orgSeq, $calcSeq)];
    }

    public function isRetryable(QueryException $exception): bool
    {
        $code = (string) ($exception->errorInfo[1] ?? $exception->getCode());

        return in_array($code, ['1062', '1213', '1205', '19', '23000', '23505'], true);
    }
}
