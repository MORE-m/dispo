<?php

namespace Tests\Unit\Calculation;

use App\Enums\DayGroup;
use App\Services\Calculation\DayGroupPrice;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DayGroupPriceTest extends TestCase
{
    public function test_base_groups_require_only_own_price(): void
    {
        $this->assertSame(
            '1.2500',
            DayGroupPrice::fromBaseMap([DayGroup::MoFr->value => '1.2500'], DayGroup::MoFr),
        );
        $this->assertSame(
            '2.0000',
            DayGroupPrice::fromBaseMap([DayGroup::Sa->value => '2.0000'], DayGroup::Sa),
        );
        $this->assertSame(
            '0.8000',
            DayGroupPrice::fromBaseMap([DayGroup::So->value => '0.8000'], DayGroup::So),
        );
    }

    public function test_mo_sa_without_sunday_is_available(): void
    {
        $this->assertSame(
            '1.1667',
            DayGroupPrice::fromBaseMap([
                DayGroup::MoFr->value => '1.0000',
                DayGroup::Sa->value => '2.0000',
            ], DayGroup::MoSa),
        );
    }

    public function test_mo_so_requires_all_three_bases(): void
    {
        $this->expectException(InvalidArgumentException::class);
        DayGroupPrice::fromBaseMap([
            DayGroup::MoFr->value => '1.0000',
            DayGroup::Sa->value => '2.0000',
        ], DayGroup::MoSo);
    }

    #[DataProvider('missingRequirementProvider')]
    public function test_missing_required_base_fails_closed(DayGroup $target, array $base): void
    {
        $this->expectException(InvalidArgumentException::class);
        DayGroupPrice::fromBaseMap($base, $target);
    }

    /**
     * @return array<string, array{0: DayGroup, 1: array<string, string>}>
     */
    public static function missingRequirementProvider(): array
    {
        return [
            'mo_fr ohne mo_fr' => [DayGroup::MoFr, [DayGroup::Sa->value => '1']],
            'sa ohne sa' => [DayGroup::Sa, [DayGroup::MoFr->value => '1']],
            'mo_sa ohne sa' => [DayGroup::MoSa, [DayGroup::MoFr->value => '1']],
            'mo_so ohne so' => [DayGroup::MoSo, [
                DayGroup::MoFr->value => '1',
                DayGroup::Sa->value => '1',
            ]],
        ];
    }

    public function test_second_price_formulas_unchanged(): void
    {
        $this->assertSame(
            '1.1667',
            DayGroupPrice::secondPrice('1.0000', '2.0000', '9.0000', DayGroup::MoSa),
        );
        $this->assertSame(
            '1.4286',
            DayGroupPrice::secondPrice('1.0000', '2.0000', '3.0000', DayGroup::MoSo),
        );
    }
}
