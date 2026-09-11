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
