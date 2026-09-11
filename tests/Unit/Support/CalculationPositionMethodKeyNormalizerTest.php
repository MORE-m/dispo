<?php

namespace Tests\Unit\Support;

use App\Support\Calculation\CalculationPositionMethodKeyNormalizer;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ADV-001c4a: presence-aware Normalisierung calculation_method_key / spot_method.
 */
class CalculationPositionMethodKeyNormalizerTest extends TestCase
{
    private CalculationPositionMethodKeyNormalizer $normalizer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->normalizer = new CalculationPositionMethodKeyNormalizer;
    }

    public function test_absent_fields_mean_not_present(): void
    {
        $this->assertSame(
            ['present' => false, 'key' => null],
            $this->normalizer->normalize([]),
        );
    }

    public function test_only_calculation_method_key(): void
    {
        $this->assertSame(
            ['present' => true, 'key' => 'average'],
            $this->normalizer->normalize(['calculation_method_key' => 'average']),
        );
    }

    public function test_only_spot_method_legacy_alias(): void
    {
        $this->assertSame(
            ['present' => true, 'key' => 'average'],
            $this->normalizer->normalize(['spot_method' => 'average']),
        );
    }

    public function test_identical_key_and_spot_method_accepted(): void
    {
        $this->assertSame(
            ['present' => true, 'key' => 'average'],
            $this->normalizer->normalize([
                'calculation_method_key' => 'average',
                'spot_method' => 'average',
            ]),
        );
    }

    public function test_conflicting_key_and_spot_method_rejected(): void
    {
        try {
            $this->normalizer->normalize([
                'calculation_method_key' => 'average',
                'spot_method' => 'calendar',
            ]);
            $this->fail('Erwartete ValidationException.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                [CalculationPositionMethodKeyNormalizer::CONFLICT_MESSAGE],
                $exception->errors()['positions'] ?? null,
            );
        }
    }

    public function test_explicit_null_or_empty_calculation_method_key_rejected(): void
    {
        foreach ([null, '', '  '] as $value) {
            try {
                $this->normalizer->normalize(['calculation_method_key' => $value]);
                $this->fail('Erwartete ValidationException für leeren Key.');
            } catch (ValidationException $exception) {
                $this->assertSame(
                    [CalculationPositionMethodKeyNormalizer::EMPTY_KEY_MESSAGE],
                    $exception->errors()['positions'] ?? null,
                );
            }
        }
    }

    public function test_explicit_empty_spot_method_without_key_is_absent(): void
    {
        $this->assertSame(
            ['present' => false, 'key' => null],
            $this->normalizer->normalize(['spot_method' => null]),
        );
        $this->assertSame(
            ['present' => false, 'key' => null],
            $this->normalizer->normalize(['spot_method' => '']),
        );
    }
}
