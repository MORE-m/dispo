<?php

namespace Tests\Unit\DynamicField;

use App\Enums\FieldType;
use App\Support\DynamicField\FieldDefinitionOptionContract;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class FieldDefinitionOptionContractTest extends TestCase
{
    public function test_normalize_desired_payload_rejects_invalid_key(): void
    {
        try {
            FieldDefinitionOptionContract::normalizeDesiredPayload([
                ['key' => 'Bad-Key', 'label' => 'X', 'sort' => 1],
            ]);
            $this->fail('Ungültiger Key muss 422 erzeugen.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('options.0.key', $exception->errors());
        }
    }

    public function test_normalize_desired_payload_rejects_duplicate_keys(): void
    {
        try {
            FieldDefinitionOptionContract::normalizeDesiredPayload([
                ['key' => 'alpha', 'label' => 'A', 'sort' => 1],
                ['key' => 'alpha', 'label' => 'B', 'sort' => 2],
            ]);
            $this->fail('Doppelter Key muss 422 erzeugen.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('options.1.key', $exception->errors());
        }
    }

    public function test_normalize_desired_payload_rejects_too_many_options(): void
    {
        $rows = [];
        for ($i = 0; $i < FieldDefinitionOptionContract::MAX_OPTIONS_PER_DEFINITION + 1; $i++) {
            $rows[] = [
                'key' => 'opt_'.$i,
                'label' => 'L'.$i,
                'sort' => $i,
            ];
        }

        try {
            FieldDefinitionOptionContract::normalizeDesiredPayload($rows);
            $this->fail('Options-Limit muss 422 erzeugen.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('options', $exception->errors());
        }
    }

    public function test_normalize_desired_payload_rejects_long_label(): void
    {
        try {
            FieldDefinitionOptionContract::normalizeDesiredPayload([
                [
                    'key' => 'alpha',
                    'label' => str_repeat('x', FieldDefinitionOptionContract::MAX_LABEL_LENGTH + 1),
                    'sort' => 1,
                ],
            ]);
            $this->fail('Langes Label muss 422 erzeugen.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('options.0.label', $exception->errors());
        }
    }

    public function test_normalize_desired_payload_accepts_strict_integer_sort_bounds(): void
    {
        foreach ([0, 1, FieldDefinitionOptionContract::MAX_SORT] as $sort) {
            $normalized = FieldDefinitionOptionContract::normalizeDesiredPayload([
                ['key' => 'alpha', 'label' => 'A', 'sort' => $sort],
            ]);
            $this->assertSame($sort, $normalized[0]['sort']);
            $this->assertTrue($normalized[0]['is_active']);
        }
    }

    public function test_normalize_desired_payload_rejects_non_strict_integer_sort(): void
    {
        $invalidSorts = [
            -1,
            1.5,
            '1',
            '1.5',
            '1e3',
            null,
            true,
            FieldDefinitionOptionContract::MAX_SORT + 1,
        ];

        foreach ($invalidSorts as $sort) {
            try {
                FieldDefinitionOptionContract::normalizeDesiredPayload([
                    ['key' => 'alpha', 'label' => 'A', 'sort' => $sort],
                ]);
                $this->fail('Ungültiges sort muss 422 erzeugen: '.var_export($sort, true));
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('options.0.sort', $exception->errors());
                $message = $exception->errors()['options.0.sort'][0];
                $this->assertIsString($message);
                $this->assertNotSame('', $message);
            }
        }
    }

    public function test_normalize_desired_payload_accepts_explicit_boolean_is_active(): void
    {
        foreach ([true, false] as $isActive) {
            $normalized = FieldDefinitionOptionContract::normalizeDesiredPayload([
                ['key' => 'alpha', 'label' => 'A', 'sort' => 1, 'is_active' => $isActive],
            ]);
            $this->assertSame($isActive, $normalized[0]['is_active']);
        }
    }

    public function test_normalize_desired_payload_defaults_missing_is_active_to_true(): void
    {
        $normalized = FieldDefinitionOptionContract::normalizeDesiredPayload([
            ['key' => 'alpha', 'label' => 'A', 'sort' => 1],
        ]);

        $this->assertTrue($normalized[0]['is_active']);
    }

    public function test_normalize_desired_payload_rejects_non_strict_boolean_is_active(): void
    {
        $invalid = [1, 0, 'true', 'false', 'yes', 'no', null, []];

        foreach ($invalid as $isActive) {
            try {
                FieldDefinitionOptionContract::normalizeDesiredPayload([
                    ['key' => 'alpha', 'label' => 'A', 'sort' => 1, 'is_active' => $isActive],
                ]);
                $this->fail('Ungültiges is_active muss 422 erzeugen: '.var_export($isActive, true));
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('options.0.is_active', $exception->errors());
                $this->assertSame(
                    'is_active muss true oder false sein.',
                    $exception->errors()['options.0.is_active'][0],
                );
            }
        }
    }

    public function test_merge_deactivates_missing_previous_options(): void
    {
        $merged = FieldDefinitionOptionContract::mergeWithPrevious(
            [
                ['key' => 'keep', 'label' => 'Keep', 'sort' => 1, 'is_active' => true],
            ],
            [
                ['key' => 'keep', 'label' => 'Old', 'sort' => 0, 'is_active' => true],
                ['key' => 'drop', 'label' => 'Drop', 'sort' => 2, 'is_active' => true],
            ],
        );

        $this->assertSame([
            ['key' => 'keep', 'label' => 'Keep', 'sort' => 1, 'is_active' => true],
            ['key' => 'drop', 'label' => 'Drop', 'sort' => 2, 'is_active' => false],
        ], $merged);
    }

    public function test_equals_canonical_is_sort_stable(): void
    {
        $left = [
            ['key' => 'b', 'label' => 'B', 'sort' => 2, 'is_active' => true],
            ['key' => 'a', 'label' => 'A', 'sort' => 1, 'is_active' => true],
        ];
        $right = [
            ['key' => 'a', 'label' => 'A', 'sort' => 1, 'is_active' => true],
            ['key' => 'b', 'label' => 'B', 'sort' => 2, 'is_active' => true],
        ];

        $this->assertTrue(FieldDefinitionOptionContract::equalsCanonical($left, $right));
    }

    public function test_assert_frozen_options_fail_closed_for_select_without_options(): void
    {
        $this->expectException(\RuntimeException::class);
        FieldDefinitionOptionContract::assertFrozenOptions(null, FieldType::Select, 'choice');
    }

    public function test_assert_frozen_options_allows_null_for_non_choice(): void
    {
        $this->assertNull(
            FieldDefinitionOptionContract::assertFrozenOptions(null, FieldType::ShortText, 'note'),
        );
    }
}
