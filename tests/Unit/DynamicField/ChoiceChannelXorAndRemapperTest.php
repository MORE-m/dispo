<?php

namespace Tests\Unit\DynamicField;

use App\Enums\FieldType;
use App\Models\CalculationFieldValue;
use App\Models\CalculationPositionFieldValue;
use App\Models\DispoOrderFieldValue;
use App\Models\DispoOrderPositionFieldValue;
use App\Models\SnapshotFieldDefinition;
use App\Services\DynamicField\PositionEffectiveValueRemapper;
use App\Support\DynamicField\ChoiceFieldValueContract;
use Illuminate\Validation\ValidationException;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

/**
 * DF-3-REST-C1: XOR über alle vier Wertemodelle + Remapper Choice fail-closed.
 */
class ChoiceChannelXorAndRemapperTest extends TestCase
{
    public function test_assert_choice_channel_exclusive_for_all_four_value_models(): void
    {
        $choice = new SnapshotFieldDefinition;
        $choice->key = 'choice';
        $choice->label = 'Choice';
        $choice->field_type = FieldType::Select;

        $text = clone $choice;
        $text->field_type = FieldType::ShortText;

        $rows = [
            new CalculationFieldValue,
            new CalculationPositionFieldValue,
            new DispoOrderFieldValue,
            new DispoOrderPositionFieldValue,
        ];

        foreach ($rows as $row) {
            $row->setRawAttributes([
                'value_string' => 'leak',
                'value_json' => '"opt_a"',
            ], true);

            try {
                ChoiceFieldValueContract::assertChoiceChannelExclusive($choice, $row);
                $this->fail('Choice+Scalar muss fail-closed sein ('.$row::class.').');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('skalare', $exception->getMessage());
            }

            $row->setRawAttributes([
                'value_string' => null,
                'value_json' => '"opt_a"',
            ], true);
            try {
                ChoiceFieldValueContract::assertChoiceChannelExclusive($text, $row);
                $this->fail('Text+value_json muss fail-closed sein ('.$row::class.').');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('value_json', $exception->getMessage());
            }
        }
    }

    public function test_clear_scalar_channels_skips_boolean_on_dispo_header(): void
    {
        $header = new DispoOrderFieldValue;
        $header->value_string = 'x';
        $header->value_text = 'y';
        $header->value_period_start = '2026-01-01';
        $header->value_period_end = '2026-01-02';
        ChoiceFieldValueContract::clearScalarChannels($header);
        $this->assertNull($header->value_string);
        $this->assertNull($header->value_text);
        $this->assertNull($header->value_period_start);
        $this->assertNull($header->value_period_end);

        $position = new DispoOrderPositionFieldValue;
        $position->value_boolean = true;
        ChoiceFieldValueContract::clearScalarChannels($position);
        $this->assertNull($position->value_boolean);
    }

    public function test_remapper_rejects_select_key_missing_in_target_options(): void
    {
        $previous = new SnapshotFieldDefinition;
        $previous->key = 'choice';
        $previous->label = 'Choice';
        $previous->field_type = FieldType::Select;
        $previous->field_definition_id = 10;
        $previous->options_json = [
            ['key' => 'opt_a', 'label' => 'A', 'sort' => 1, 'is_active' => true],
        ];

        $next = clone $previous;
        $next->options_json = [
            ['key' => 'opt_b', 'label' => 'B', 'sort' => 1, 'is_active' => true],
        ];

        $row = new CalculationPositionFieldValue;
        $row->value_json = 'opt_a';

        $method = new ReflectionMethod(PositionEffectiveValueRemapper::class, 'choiceRemapViolation');
        $violation = $method->invoke(new PositionEffectiveValueRemapper, $previous, $next, $row);
        $this->assertNotNull($violation);
        $this->assertStringContainsString('nicht übernommen', (string) $violation);
    }

    public function test_remapper_keeps_compatible_select_and_multi(): void
    {
        $previous = new SnapshotFieldDefinition;
        $previous->key = 'choice';
        $previous->label = 'Choice';
        $previous->field_type = FieldType::Select;
        $previous->options_json = [
            ['key' => 'opt_a', 'label' => 'A', 'sort' => 1, 'is_active' => false],
        ];

        $next = clone $previous;
        $next->options_json = [
            ['key' => 'opt_a', 'label' => 'A hist', 'sort' => 1, 'is_active' => false],
            ['key' => 'opt_b', 'label' => 'B', 'sort' => 2, 'is_active' => true],
        ];

        $row = new CalculationPositionFieldValue;
        $row->value_json = 'opt_a';

        $method = new ReflectionMethod(PositionEffectiveValueRemapper::class, 'choiceRemapViolation');
        $this->assertNull($method->invoke(new PositionEffectiveValueRemapper, $previous, $next, $row));

        $previous->field_type = FieldType::MultiSelect;
        $next->field_type = FieldType::MultiSelect;
        $row->value_json = ['opt_a'];
        $this->assertNull($method->invoke(new PositionEffectiveValueRemapper, $previous, $next, $row));
    }

    public function test_batch_normalize_does_not_relegitimise_removed_inactive_via_second_call(): void
    {
        $options = [
            ['key' => 'old', 'label' => 'Old', 'sort' => 1, 'is_active' => false],
            ['key' => 'new', 'label' => 'New', 'sort' => 2, 'is_active' => true],
        ];

        $afterRemove = ChoiceFieldValueContract::normalizeIncoming(
            FieldType::MultiSelect,
            $options,
            ['new'],
            ['old', 'new'],
            'f',
            'Feld',
        );
        $this->assertSame(['new'], $afterRemove);

        try {
            ChoiceFieldValueContract::normalizeIncoming(
                FieldType::MultiSelect,
                $options,
                ['old', 'new'],
                $afterRemove,
                'f',
                'Feld',
            );
            $this->fail('Entfernter inaktiver Key darf nicht erneut legitimiert werden.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('f', $exception->errors());
        }
    }
}
