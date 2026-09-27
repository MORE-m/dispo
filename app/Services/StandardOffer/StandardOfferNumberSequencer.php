<?php

namespace App\Services\StandardOffer;

use App\Models\StandardOfferNumberSequence;
use App\Support\DocumentNumber;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final class StandardOfferNumberSequencer
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
        DB::table('standard_offer_number_sequences')->insertOrIgnore([
            'year' => $year,
            'last_seq' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $sequence = StandardOfferNumberSequence::query()
            ->where('year', $year)
            ->lockForUpdate()
            ->firstOrFail();

        $seq = $sequence->last_seq + 1;
        $sequence->last_seq = $seq;
        $sequence->save();

        return [$year, $seq, DocumentNumber::standardOffer($year, $seq)];
    }

    public function isRetryable(QueryException $exception): bool
    {
        $code = (string) ($exception->errorInfo[1] ?? $exception->getCode());

        return in_array($code, ['1062', '1213', '1205', '19', '23000', '23505'], true);
    }
}
