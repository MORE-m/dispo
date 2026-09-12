<?php

namespace Tests\Unit\DynamicField;

use App\Casts\ChoiceValueJsonCast;
use App\Models\CalculationFieldValue;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * DF-3-REST-C1: ChoiceValueJsonCast fail-closed ohne FieldType-Raten.
 */
class ChoiceValueJsonCastTest extends TestCase
{
    private ChoiceValueJsonCast $cast;

    private CalculationFieldValue $model;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cast = new ChoiceValueJsonCast;
        $this->model = new CalculationFieldValue;
    }

    public function test_null_roundtrip(): void
    {
        $this->assertNull($this->cast->get($this->model, 'value_json', null, []));
        $this->assertNull($this->cast->set($this->model, 'value_json', null, []));
    }

    public function test_select_string_encode_decode_without_extra_quotes(): void
    {
        $encoded = $this->cast->set($this->model, 'value_json', 'opt_a', []);
        $this->assertSame('"opt_a"', $encoded);
        $decoded = $this->cast->get($this->model, 'value_json', $encoded, []);
        $this->assertSame('opt_a', $decoded);
    }

    public function test_multi_array_and_empty_list(): void
    {
        $encoded = $this->cast->set($this->model, 'value_json', ['b', 'a'], []);
        $this->assertSame('["b","a"]', $encoded);
        $this->assertSame(['b', 'a'], $this->cast->get($this->model, 'value_json', $encoded, []));

        $empty = $this->cast->set($this->model, 'value_json', [], []);
        $this->assertSame('[]', $empty);
        $this->assertSame([], $this->cast->get($this->model, 'value_json', $empty, []));
    }

    public function test_driver_already_decoded_list_passes_validation(): void
    {
        $this->assertSame(['opt_a'], $this->cast->get($this->model, 'value_json', ['opt_a'], []));
    }

    public function test_bare_non_json_string_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->cast->get($this->model, 'value_json', 'opt_a', []);
    }

    public function test_invalid_json_and_wrong_shapes_fail_closed(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->cast->get($this->model, 'value_json', '{broken', []);
    }

    public function test_json_number_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->cast->get($this->model, 'value_json', '1', []);
    }

    public function test_json_boolean_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->cast->get($this->model, 'value_json', 'true', []);
    }

    public function test_json_object_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->cast->get($this->model, 'value_json', '{"a":1}', []);
    }

    public function test_nested_array_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->cast->get($this->model, 'value_json', '[["a"]]', []);
    }

    public function test_array_with_non_string_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->cast->get($this->model, 'value_json', [1, 'a'], []);
    }

    public function test_set_rejects_number_and_object(): void
    {
        try {
            $this->cast->set($this->model, 'value_json', 1, []);
            $this->fail('Zahl muss abgelehnt werden.');
        } catch (InvalidArgumentException) {
            $this->assertTrue(true);
        }

        $this->expectException(InvalidArgumentException::class);
        $this->cast->set($this->model, 'value_json', ['k' => 'v'], []);
    }
}
