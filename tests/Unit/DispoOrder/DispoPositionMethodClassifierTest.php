<?php

namespace Tests\Unit\DispoOrder;

use App\Support\DispoOrder\DispoPositionMethodClassifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * PO-CALC-TKP-1D3d-1: Method × Engine Klassifikation.
 */
class DispoPositionMethodClassifierTest extends TestCase
{
    #[Test]
    #[DataProvider('validOaPairs')]
    public function classifies_valid_oa_tkp(?string $profile, ?string $method): void
    {
        $this->assertSame(
            DispoPositionMethodClassifier::KIND_OA_TKP,
            DispoPositionMethodClassifier::classify($profile, $method),
        );
        $this->assertTrue(DispoPositionMethodClassifier::isOaTkp($profile, $method));
    }

    /**
     * @return list<array{0: string|null, 1: string|null}>
     */
    public static function validOaPairs(): array
    {
        return [
            ['oa_tkp', 'tkp'],
        ];
    }

    #[Test]
    #[DataProvider('validSpotFamilyPairs')]
    public function classifies_valid_spot_family(?string $profile, ?string $method): void
    {
        $this->assertSame(
            DispoPositionMethodClassifier::KIND_SPOT_FAMILY,
            DispoPositionMethodClassifier::classify($profile, $method),
        );
        $this->assertTrue(DispoPositionMethodClassifier::isSpotFamily($profile, $method));
    }

    /**
     * @return list<array{0: string|null, 1: string|null}>
     */
    public static function validSpotFamilyPairs(): array
    {
        return [
            [null, null],
            ['spot_classic', 'average'],
            ['spot_classic', 'calendar'],
            ['spot_classic', 'fixed_price'],
            ['swf_trailer', 'average'],
            ['swf_trailer', 'calendar'],
            ['swf_allonge', 'average'],
            ['swf_promo', 'average'],
            ['swf_contest', 'calendar'],
        ];
    }

    #[Test]
    #[DataProvider('inconsistentPairs')]
    public function classifies_inconsistent_pairs(?string $profile, ?string $method): void
    {
        $this->assertSame(
            DispoPositionMethodClassifier::KIND_INCONSISTENT,
            DispoPositionMethodClassifier::classify($profile, $method),
        );
        $this->assertTrue(DispoPositionMethodClassifier::isInconsistent($profile, $method));
        $this->assertFalse(DispoPositionMethodClassifier::isOaTkp($profile, $method));
        $this->assertFalse(DispoPositionMethodClassifier::isSpotFamily($profile, $method));
    }

    /**
     * @return list<array{0: string|null, 1: string|null}>
     */
    public static function inconsistentPairs(): array
    {
        return [
            ['tkp', 'spot_classic'], // vertauscht / falsch
            ['spot_classic', 'tkp'],
            ['oa_tkp', 'average'],
            ['oa_tkp', 'calendar'],
            ['oa_tkp', null],
            [null, 'tkp'],
            ['oa_tkp', ''],
            ['', 'tkp'],
            ['unknown_profile', 'average'],
            ['spot_classic', 'unknown_method'],
        ];
    }
}
