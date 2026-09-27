<?php

namespace App\Support;

/**
 * Zentrale Formatierung lesbarer Dokumentnummern.
 *
 * Kalkulation: K-JJJJ-NNNNN (SEQUENCE_PAD Stellen)
 * Standardangebot: SA-JJJJ-NNNNN (TEC-001)
 * Dispoauftrag: DA-JJJJ-NNNNN-SS (neue Familien) bzw. Legacy-Padding aus Bestandsnummer
 */
final class DocumentNumber
{
    public const SEQUENCE_PAD = 5;

    public static function calculation(int $year, int $seq): string
    {
        return sprintf('K-%d-%0'.self::SEQUENCE_PAD.'d', $year, $seq);
    }

    public static function standardOffer(int $year, int $seq): string
    {
        return sprintf('SA-%d-%0'.self::SEQUENCE_PAD.'d', $year, $seq);
    }

    public static function dispoOrder(
        int $year,
        int $orgSeq,
        int $calcSeq,
        int $sequencePad = self::SEQUENCE_PAD,
    ): string {
        return sprintf(
            'DA-%d-%0'.$sequencePad.'d-%02d',
            $year,
            $orgSeq,
            $calcSeq,
        );
    }

    /**
     * Padding der Stammsequenz aus einer bestehenden Dispoauftragsnummer.
     * Legacy-Familien nutzen typischerweise 6 Stellen; neue Familien 5.
     */
    public static function sequencePadFromDispoNumber(string $number): int
    {
        if (preg_match('/^DA-\d+-(\d+)-\d{2}$/', $number, $matches) !== 1) {
            return self::SEQUENCE_PAD;
        }

        return strlen($matches[1]);
    }
}
