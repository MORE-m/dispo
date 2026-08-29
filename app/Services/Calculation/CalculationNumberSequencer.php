<?php

namespace App\Services\Calculation;

use App\Models\CalculationNumberSequence;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final class CalculationNumberSequencer
{
    /**
     * @return array{0: int, 1: int, 2: string}
     */
    public function next(): array
    {
        $year = (int) now('Europe/Berlin')->format('Y');

        if (DB::transactionLevel() === 0) {
            return DB::transaction(fn (): array => $this->allocate($year));
        }

        return $this->allocate($year);
    }

    /**
     * @return array{0: int, 1: int, 2: string}
     */
    private function allocate(int $year): array
    {
        DB::table('calculation_number_sequences')->insertOrIgnore([
            'year' => $year,
            'last_seq' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $sequence = CalculationNumberSequence::query()
            ->where('year', $year)
            ->lockForUpdate()
            ->firstOrFail();

        $seq = $sequence->last_seq + 1;
        $sequence->last_seq = $seq;
        $sequence->save();

        $number = sprintf('K-%d-%05d', $year, $seq);

        return [$year, $seq, $number];
    }

    public function isRetryable(QueryException $exception): bool
    {
        $code = (string) ($exception->errorInfo[1] ?? $exception->getCode());

        return in_array($code, ['1062', '1213', '1205', '19', '23000', '23505'], true);
    }
}
