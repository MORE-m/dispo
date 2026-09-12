<?php

namespace Tests\Unit\DynamicField;

use App\Enums\FieldType;
use App\Models\CalculationFieldValue;
use App\Models\SnapshotFieldDefinition;
use App\Support\DynamicField\ChoiceFieldValueContract;
use App\Support\DynamicField\FieldDefinitionOptionContract;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ChoiceFieldValueContractTest extends TestCase
{
    /**
     * @return list<array{key: string, label: string, sort: int, is_active: bool}>
     */
    private function freezeOptions(): array
    {
        return FieldDefinitionOptionContract::canonicalize([
            ['key' => 'alpha', 'label' => 'Alpha', 'sort' => 10, 'is_active' => true],
            ['key' => 'beta', 'label' => 'Beta', 'sort' => 20, 'is_active' => true],
            ['key' => 'gone', 'label' => 'Gone', 'sort' => 30, 'is_active' => false],
        ]);
    }

    public function test_select_valid_and_empty(): void
    {
        $this->assertSame(
            'alpha',
            ChoiceFieldValueContract::normalizeIncoming(
                FieldType::Select,
                $this->freezeOptions(),
                'alpha',
                null,
                'f',
                'Feld',
            ),
        );
        $this->assertNull(
            ChoiceFieldValueContract::normalizeIncoming(
                FieldType::Select,
                $this->freezeOptions(),
                null,
                null,
                'f',
                'Feld',
            ),
        );
        $this->assertNull(
            ChoiceFieldValueContract::normalizeIncoming(
                FieldType::Select,
                $this->freezeOptions(),
                '',
                null,
                'f',
                'Feld',
            ),
        );
    }

    public function test_select_rejects_invalid_types(): void
    {
        foreach ([1, true, ['alpha'], ['key' => 'alpha'], 1.5] as $raw) {
            try {
                ChoiceFieldValueContract::normalizeIncoming(
                    FieldType::Select,
                    $this->freezeOptions(),
                    $raw,
                    null,
                    'f',
                    'Feld',
                );
                $this->fail('Ungültiger Select-Typ muss 422 erzeugen: '.var_export($raw, true));
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('f', $exception->errors());
            }
        }
    }

    public function test_multi_valid_empty_canonicalize_and_duplicates(): void
    {
        $this->assertSame(
            ['alpha', 'beta'],
            ChoiceFieldValueContract::normalizeIncoming(
                FieldType::MultiSelect,
                $this->freezeOptions(),
                ['beta', 'alpha'],
                [],
                'f',
                'Feld',
            ),
        );
        $this->assertSame(
            [],
            ChoiceFieldValueContract::normalizeIncoming(
                FieldType::MultiSelect,
                $this->freezeOptions(),
                null,
                [],
                'f',
                'Feld',
            ),
        );
        $this->assertSame(
            [],
            ChoiceFieldValueContract::normalizeIncoming(
                FieldType::MultiSelect,
                $this->freezeOptions(),
                [],
                [],
                'f',
                'Feld',
            ),
        );

        try {
            ChoiceFieldValueContract::normalizeIncoming(
                FieldType::MultiSelect,
                $this->freezeOptions(),
                ['alpha', 'alpha'],
                [],
                'f',
                'Feld',
            );
            $this->fail('Duplikate müssen 422 erzeugen.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('f', $exception->errors());
        }
    }

    public function test_multi_rejects_over_limit_and_invalid_shapes(): void
    {
        $keys = [];
        for ($i = 0; $i < FieldDefinitionOptionContract::MAX_MULTI_SELECT_SELECTED + 1; $i++) {
            $keys[] = 'k'.$i;
        }
        $options = [];
        foreach ($keys as $i => $key) {
            $options[] = ['key' => $key, 'label' => $key, 'sort' => $i, 'is_active' => true];
        }

        try {
            ChoiceFieldValueContract::normalizeIncoming(
                FieldType::MultiSelect,
                FieldDefinitionOptionContract::canonicalize($options),
                $keys,
                [],
                'f',
                'Feld',
            );
            $this->fail('Limit 50 muss 422 erzeugen.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('f', $exception->errors());
        }

        $invalidShapes = [[1], [true], [['a']], ['a' => 'b'], [null]];
        foreach ($invalidShapes as $raw) {
            try {
                ChoiceFieldValueContract::normalizeIncoming(
                    FieldType::MultiSelect,
                    $this->freezeOptions(),
                    $raw,
                    [],
                    'f',
                    'Feld',
                );
                $this->fail('Ungültige Multi-Form muss 422 erzeugen.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('f', $exception->errors());
            }
        }
    }

    public function test_unknown_and_new_inactive_keys_rejected(): void
    {
        try {
            ChoiceFieldValueContract::normalizeIncoming(
                FieldType::Select,
                $this->freezeOptions(),
                'missing',
                null,
                'f',
                'Feld',
            );
            $this->fail('Unbekannter Key muss 422 erzeugen.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('f', $exception->errors());
        }

        try {
            ChoiceFieldValueContract::normalizeIncoming(
                FieldType::Select,
                $this->freezeOptions(),
                'gone',
                null,
                'f',
                'Feld',
            );
            $this->fail('Neuer inaktiver Key muss 422 erzeugen.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('nicht mehr auswählbar', $exception->errors()['f'][0]);
        }
    }

    public function test_historical_inactive_keep_remove_and_readd(): void
    {
        $this->assertSame(
            'gone',
            ChoiceFieldValueContract::normalizeIncoming(
                FieldType::Select,
                $this->freezeOptions(),
                'gone',
                'gone',
                'f',
                'Feld',
            ),
        );

        $kept = ChoiceFieldValueContract::normalizeIncoming(
            FieldType::MultiSelect,
            $this->freezeOptions(),
            ['gone', 'alpha'],
            ['gone'],
            'f',
            'Feld',
        );
        $this->assertSame(['alpha', 'gone'], $kept);

        $removed = ChoiceFieldValueContract::normalizeIncoming(
            FieldType::MultiSelect,
            $this->freezeOptions(),
            ['alpha'],
            ['gone', 'alpha'],
            'f',
            'Feld',
        );
        $this->assertSame(['alpha'], $removed);

        try {
            ChoiceFieldValueContract::normalizeIncoming(
                FieldType::MultiSelect,
                $this->freezeOptions(),
                ['alpha', 'gone'],
                ['alpha'],
                'f',
                'Feld',
            );
            $this->fail('Erneut hinzugefügter inaktiver Key muss 422 erzeugen.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('nicht mehr auswählbar', $exception->errors()['f'][0]);
        }
    }

    public function test_missing_freeze_options_fail_closed(): void
    {
        try {
            ChoiceFieldValueContract::normalizeIncoming(
                FieldType::Select,
                null,
                'alpha',
                null,
                'f',
                'Feld',
            );
            $this->fail('Fehlende Options müssen 422 erzeugen.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('f', $exception->errors());
        }
    }

    public function test_required_visible_empty_helpers(): void
    {
        $this->assertTrue(ChoiceFieldValueContract::isEmpty(FieldType::Select, null));
        $this->assertFalse(ChoiceFieldValueContract::isEmpty(FieldType::Select, 'alpha'));
        $this->assertTrue(ChoiceFieldValueContract::isEmpty(FieldType::MultiSelect, []));
        $this->assertFalse(ChoiceFieldValueContract::isEmpty(FieldType::MultiSelect, ['alpha']));
        $this->assertNull(ChoiceFieldValueContract::emptyValue(FieldType::Select));
        $this->assertSame([], ChoiceFieldValueContract::emptyValue(FieldType::MultiSelect));
    }

    public function test_channel_xor_write_and_read(): void
    {
        $def = new SnapshotFieldDefinition;
        $def->key = 'choice';
        $def->field_type = FieldType::Select;
        $def->label = 'Choice';
        $def->options_json = $this->freezeOptions();

        $row = new CalculationFieldValue;
        $row->value_string = 'leak';
        ChoiceFieldValueContract::writeStored($def, $row, 'alpha');
        $this->assertNull($row->value_string);
        $this->assertSame('alpha', $row->value_json);
        $this->assertSame('alpha', ChoiceFieldValueContract::readStored($def, $row));

        $multiDef = clone $def;
        $multiDef->field_type = FieldType::MultiSelect;
        ChoiceFieldValueContract::writeStored($multiDef, $row, ['beta', 'alpha']);
        $this->assertSame(['alpha', 'beta'], $row->value_json);

        $textDef = clone $def;
        $textDef->field_type = FieldType::ShortText;
        $row->value_json = 'alpha';
        $this->expectException(\RuntimeException::class);
        ChoiceFieldValueContract::assertChoiceChannelExclusive($textDef, $row);
    }

    public function test_canonicalize_keys_deterministic(): void
    {
        $this->assertSame(
            ['a', 'b', 'c'],
            ChoiceFieldValueContract::canonicalizeKeys(['c', 'a', 'b', 'a']),
        );
    }
}
