<?php

namespace Tests\Unit\Support;

use App\Support\DocumentNumber;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DocumentNumberTest extends TestCase
{
    public function test_calculation_uses_five_digit_padding(): void
    {
        $this->assertSame('K-2026-00005', DocumentNumber::calculation(2026, 5));
        $this->assertSame('K-2026-00001', DocumentNumber::calculation(2026, 1));
    }

    public function test_dispo_order_matches_calculation_padding_by_default(): void
    {
        $this->assertSame('DA-2026-00005-01', DocumentNumber::dispoOrder(2026, 5, 1));
        $this->assertSame('DA-2026-00005-02', DocumentNumber::dispoOrder(2026, 5, 2));
    }

    public function test_dispo_order_supports_legacy_six_digit_padding(): void
    {
        $this->assertSame('DA-2026-000008-03', DocumentNumber::dispoOrder(2026, 8, 3, 6));
    }

    #[DataProvider('legacyPadProvider')]
    public function test_sequence_pad_from_dispo_number(string $number, int $expectedPad): void
    {
        $this->assertSame($expectedPad, DocumentNumber::sequencePadFromDispoNumber($number));
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function legacyPadProvider(): array
    {
        return [
            'new five digit' => ['DA-2026-00005-01', 5],
            'legacy six digit' => ['DA-2026-000008-02', 6],
            'invalid falls back' => ['broken', DocumentNumber::SEQUENCE_PAD],
        ];
    }
}
