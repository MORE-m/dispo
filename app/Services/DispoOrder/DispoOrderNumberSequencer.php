<?php

namespace App\Services\DispoOrder;

use App\Models\Calculation;
use App\Models\DispoOrder;
use App\Models\DispoOrderNumberSequence;
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
        Calculation::query()->whereKey($calculation->id)->lockForUpdate()->firstOrFail();

        $family = DispoOrder::query()
            ->where('calculation_id', $calculation->id)
            ->orderBy('id')
            ->lockForUpdate()
            ->first();

        if ($family !== null) {
            $year = (int) $family->number_year;
            $orgSeq = (int) $family->number_org_seq;
            $calcSeq = (int) (DispoOrder::query()
                ->where('calculation_id', $calculation->id)
                ->max('number_calc_seq') ?? 0) + 1;

            return [$year, $orgSeq, $calcSeq, $this->format($year, $orgSeq, $calcSeq)];
        }

        $year = (int) now('Europe/Berlin')->format('Y');

        DB::table('dispo_order_number_sequences')->insertOrIgnore([
            'year' => $year,
            'last_seq' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        /** @var DispoOrderNumberSequence|null $sequence */
        $sequence = DispoOrderNumberSequence::query()
            ->where('year', $year)
            ->lockForUpdate()
            ->first();

        if ($sequence === null) {
            throw new \RuntimeException("Dispo-Jahressequenz {$year} konnte nicht gesperrt werden.");
        }

        $orgSeq = $sequence->last_seq + 1;
        $sequence->last_seq = $orgSeq;
        $sequence->save();

        $calcSeq = 1;

        return [$year, $orgSeq, $calcSeq, $this->format($year, $orgSeq, $calcSeq)];
    }

    private function format(int $year, int $orgSeq, int $calcSeq): string
    {
        return sprintf('DA-%d-%06d-%02d', $year, $orgSeq, $calcSeq);
    }

    public function isRetryable(QueryException $exception): bool
    {
        $code = (string) ($exception->errorInfo[1] ?? $exception->getCode());

        return in_array($code, ['1062', '1213', '1205', '19', '23000', '23505'], true);
    }
}
