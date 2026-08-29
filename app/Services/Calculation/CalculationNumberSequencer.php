<?php

namespace App\Services\Calculation;

use App\Models\Calculation;
use App\Models\CalculationNumberSequence;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final class CalculationNumberSequencer
{
    private const MAX_RETRIES = 5;

    /**
     * @return array{0: int, 1: int, 2: string}
     */
    public function next(): array
    {
        $year = (int) now('Europe/Berlin')->format('Y');

        for ($attempt = 0; $attempt < self::MAX_RETRIES; $attempt++) {
            try {
                return DB::transaction(function () use ($year): array {
                    $sequence = CalculationNumberSequence::query()
                        ->where('year', $year)
                        ->lockForUpdate()
                        ->first();

                    if ($sequence === null) {
                        CalculationNumberSequence::query()->create([
                            'year' => $year,
                            'last_seq' => 0,
                        ]);

                        $sequence = CalculationNumberSequence::query()
                            ->where('year', $year)
                            ->lockForUpdate()
                            ->firstOrFail();
                    }

                    $seq = $sequence->last_seq + 1;
                    $sequence->last_seq = $seq;
                    $sequence->save();

                    $number = sprintf('K-%d-%05d', $year, $seq);

                    return [$year, $seq, $number];
                });
            } catch (QueryException $exception) {
                if ($this->isUniqueViolation($exception) && Calculation::query()->where('number', sprintf('K-%d-%05d', $year, $this->guessSeq($year)))->exists()) {
                    continue;
                }

                if ($this->isUniqueViolation($exception)) {
                    continue;
                }

                throw $exception;
            }
        }

        throw new \RuntimeException('Kalkulationsnummer konnte nicht vergeben werden.');
    }

    private function isUniqueViolation(QueryException $exception): bool
    {
        $code = (string) ($exception->errorInfo[1] ?? $exception->getCode());

        return in_array($code, ['1062', '19', '23000', '23505'], true);
    }

    private function guessSeq(int $year): int
    {
        return (int) CalculationNumberSequence::query()->where('year', $year)->value('last_seq');
    }
}
