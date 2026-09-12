<?php

namespace Tests\Unit\DynamicField;

use App\Enums\FieldScope;
use App\Enums\FieldType;
use App\Support\DynamicField\FieldRuleContract;
use RuntimeException;
use Tests\TestCase;

class FieldRuleContractTest extends TestCase
{
    public function test_seed_equals_require_still_valid_and_legacy_hash_stable(): void
    {
        $defs = $this->defs([
            'period_open' => [FieldType::Boolean, FieldScope::Position],
            'position_flight_period' => [FieldType::Period, FieldScope::Position],
        ]);

        $condition = ['op' => 'field_equals', 'field_key' => 'period_open', 'value' => false];
        $action = ['op' => 'require_field', 'field_key' => 'position_flight_period'];

        FieldRuleContract::assertRuleStructure($defs, $condition, $action);

        $this->assertSame(
            FieldRuleContract::SEED_RULE_DEDUPE_SHA256,
            FieldRuleContract::dedupePayload($condition, $action),
        );
        $this->assertSame(
            'e306809641bbbd5158bdae6f89787d2edbf93de9b484b91269ee6f9ec200b9e6',
            FieldRuleContract::SEED_RULE_DEDUPE_SHA256,
        );

        $reorderedCondition = ['value' => false, 'field_key' => 'period_open', 'op' => 'field_equals'];
        $reorderedAction = ['field_key' => 'position_flight_period', 'op' => 'require_field'];
        $this->assertSame(
            FieldRuleContract::SEED_RULE_DEDUPE_SHA256,
            FieldRuleContract::dedupePayload($reorderedCondition, $reorderedAction),
        );
    }

    public function test_native_calculation_fields_may_be_action_targets(): void
    {
        $defs = $this->defs([
            'period_open' => [FieldType::Boolean, FieldScope::Position],
            'position_flight_period' => [FieldType::Period, FieldScope::Position],
            'campaign_period' => [FieldType::Period, FieldScope::Header],
            'custom_calc' => [FieldType::ShortText, FieldScope::Position],
        ]);

        FieldRuleContract::assertRuleStructure(
            $defs,
            ['op' => 'field_equals', 'field_key' => 'period_open', 'value' => false],
            ['op' => 'require_field', 'field_key' => 'position_flight_period'],
        );
        FieldRuleContract::assertRuleStructure(
            $defs,
            ['op' => 'field_empty', 'field_key' => 'period_open'],
            ['op' => 'require_field', 'field_key' => 'position_flight_period'],
        );
        FieldRuleContract::assertRuleStructure(
            $defs,
            ['op' => 'field_equals', 'field_key' => 'period_open', 'value' => true],
            ['op' => 'set_visible', 'field_key' => 'custom_calc', 'value' => false],
        );
    }

    public function test_calc_origin_readonly_blocks_actions_except_exact_seed(): void
    {
        $defs = $this->defs([
            'period_open' => [FieldType::Boolean, FieldScope::Position, null, true],
            'position_flight_period' => [FieldType::Period, FieldScope::Position, null, true],
            'campaign_period' => [FieldType::Period, FieldScope::Header, null, true],
            'custom_from_calc' => [FieldType::ShortText, FieldScope::Position, null, true],
            'native_dispo' => [FieldType::ShortText, FieldScope::Position, null, false],
            'flag' => [FieldType::Boolean, FieldScope::Header],
        ]);

        FieldRuleContract::assertRuleStructure(
            $defs,
            ['op' => 'field_equals', 'field_key' => 'period_open', 'value' => false],
            ['op' => 'require_field', 'field_key' => 'position_flight_period'],
        );
        $this->assertTrue(FieldRuleContract::isExactDf1SeedRule(
            ['op' => 'field_equals', 'field_key' => 'period_open', 'value' => false],
            ['op' => 'require_field', 'field_key' => 'position_flight_period'],
        ));

        try {
            FieldRuleContract::assertRuleStructure(
                $defs,
                ['op' => 'field_empty', 'field_key' => 'period_open'],
                ['op' => 'require_field', 'field_key' => 'position_flight_period'],
            );
            $this->fail('Nicht-Seed-Condition auf Calc-Origin-Ziel muss scheitern.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Calc-Origin', $exception->getMessage());
        }

        foreach (['campaign_period', 'period_open', 'position_flight_period', 'custom_from_calc'] as $target) {
            $condition = $target === 'campaign_period'
                ? ['op' => 'field_equals', 'field_key' => 'flag', 'value' => true]
                : ['op' => 'field_equals', 'field_key' => 'period_open', 'value' => false];
            try {
                FieldRuleContract::assertRuleStructure(
                    $defs,
                    $condition,
                    ['op' => 'set_visible', 'field_key' => $target, 'value' => false],
                );
                $this->fail("set_visible auf {$target} muss scheitern.");
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('Calc-Origin', $exception->getMessage());
            }
        }

        try {
            FieldRuleContract::assertRuleStructure(
                $defs,
                ['op' => 'field_equals', 'field_key' => 'period_open', 'value' => false],
                ['op' => 'require_field', 'field_key' => 'custom_from_calc'],
            );
            $this->fail('require_field auf Custom-Calc-Origin muss scheitern.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Calc-Origin', $exception->getMessage());
        }

        FieldRuleContract::assertRuleStructure(
            $defs,
            ['op' => 'field_equals', 'field_key' => 'period_open', 'value' => false],
            ['op' => 'require_field', 'field_key' => 'native_dispo'],
        );
        FieldRuleContract::assertRuleStructure(
            $defs,
            ['op' => 'field_equals', 'field_key' => 'period_open', 'value' => false],
            ['op' => 'set_visible', 'field_key' => 'native_dispo', 'value' => true],
        );
    }

    public function test_set_visible_self_reference_rejected_require_self_allowed(): void
    {
        $defs = $this->defs([
            'flag' => [FieldType::Boolean, FieldScope::Position],
            'notes' => [FieldType::ShortText, FieldScope::Position],
        ]);

        try {
            FieldRuleContract::assertRuleStructure(
                $defs,
                ['op' => 'field_equals', 'field_key' => 'notes', 'value' => 'x'],
                ['op' => 'set_visible', 'field_key' => 'notes', 'value' => false],
            );
            $this->fail('Selbstreferenz set_visible atomar muss scheitern.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('nicht in der eigenen Bedingung', $exception->getMessage());
        }

        try {
            FieldRuleContract::assertRuleStructure(
                $defs,
                [
                    'op' => 'all',
                    'conditions' => [
                        ['op' => 'field_equals', 'field_key' => 'flag', 'value' => true],
                        ['op' => 'field_empty', 'field_key' => 'notes'],
                    ],
                ],
                ['op' => 'set_visible', 'field_key' => 'notes', 'value' => false],
            );
            $this->fail('Selbstreferenz set_visible in all muss scheitern.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('nicht in der eigenen Bedingung', $exception->getMessage());
        }

        try {
            FieldRuleContract::assertRuleStructure(
                $defs,
                [
                    'op' => 'any',
                    'conditions' => [
                        ['op' => 'field_equals', 'field_key' => 'flag', 'value' => false],
                        ['op' => 'field_not_empty', 'field_key' => 'notes'],
                    ],
                ],
                ['op' => 'set_visible', 'field_key' => 'notes', 'value' => true],
            );
            $this->fail('Selbstreferenz set_visible in any muss scheitern.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('nicht in der eigenen Bedingung', $exception->getMessage());
        }

        FieldRuleContract::assertRuleStructure(
            $defs,
            ['op' => 'field_empty', 'field_key' => 'notes'],
            ['op' => 'require_field', 'field_key' => 'notes'],
        );
    }

    public function test_cross_scope_header_action_rejects_position_atoms(): void
    {
        $defs = $this->defs([
            'period_open' => [FieldType::Boolean, FieldScope::Position],
            'title' => [FieldType::ShortText, FieldScope::Header],
            'flag' => [FieldType::Boolean, FieldScope::Header],
        ]);

        try {
            FieldRuleContract::assertRuleStructure(
                $defs,
                ['op' => 'field_equals', 'field_key' => 'period_open', 'value' => false],
                ['op' => 'require_field', 'field_key' => 'title'],
            );
            $this->fail('Position→Header muss scheitern.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Position→Header', $exception->getMessage());
        }

        FieldRuleContract::assertRuleStructure(
            $defs,
            ['op' => 'field_equals', 'field_key' => 'flag', 'value' => true],
            ['op' => 'require_field', 'field_key' => 'title'],
        );
    }

    public function test_all_and_any_flat_groups_and_dedupe_commutes(): void
    {
        $defs = $this->defs([
            'period_open' => [FieldType::Boolean, FieldScope::Position],
            'notes' => [FieldType::ShortText, FieldScope::Position],
            'tags' => [FieldType::MultiSelect, FieldScope::Position, [
                ['key' => 'opt_a', 'label' => 'A', 'sort' => 1, 'is_active' => true],
            ]],
        ]);

        $allA = [
            'op' => 'all',
            'conditions' => [
                ['op' => 'field_empty', 'field_key' => 'notes'],
                ['op' => 'field_equals', 'field_key' => 'period_open', 'value' => false],
            ],
        ];
        $allB = [
            'op' => 'all',
            'conditions' => [
                ['op' => 'field_equals', 'field_key' => 'period_open', 'value' => false],
                ['op' => 'field_empty', 'field_key' => 'notes'],
            ],
        ];
        $action = ['op' => 'require_field', 'field_key' => 'notes'];
        $this->assertSame(
            FieldRuleContract::dedupePayload($allA, $action),
            FieldRuleContract::dedupePayload($allB, $action),
        );

        $anyA = [
            'op' => 'any',
            'conditions' => [
                ['op' => 'field_contains', 'field_key' => 'tags', 'value' => 'opt_a'],
                ['op' => 'field_equals', 'field_key' => 'period_open', 'value' => true],
            ],
        ];
        $anyB = [
            'op' => 'any',
            'conditions' => [
                ['op' => 'field_equals', 'field_key' => 'period_open', 'value' => true],
                ['op' => 'field_contains', 'field_key' => 'tags', 'value' => 'opt_a'],
            ],
        ];
        $this->assertSame(
            FieldRuleContract::dedupePayload($anyA, $action),
            FieldRuleContract::dedupePayload($anyB, $action),
        );

        $this->assertNotSame(
            FieldRuleContract::dedupePayload($allA, $action),
            FieldRuleContract::dedupePayload($anyA, $action),
        );
    }

    public function test_rejects_nested_groups_multi_equals_and_inactive_options(): void
    {
        $defs = $this->defs([
            'period_open' => [FieldType::Boolean, FieldScope::Position],
            'notes' => [FieldType::ShortText, FieldScope::Position],
            'tags' => [FieldType::MultiSelect, FieldScope::Position, [
                ['key' => 'opt_a', 'label' => 'A', 'sort' => 1, 'is_active' => true],
            ]],
            'channel' => [FieldType::Select, FieldScope::Header, [
                ['key' => 'live', 'label' => 'Live', 'sort' => 1, 'is_active' => true],
                ['key' => 'old', 'label' => 'Alt', 'sort' => 2, 'is_active' => false],
            ]],
        ]);

        try {
            FieldRuleContract::assertRuleStructure(
                $defs,
                [
                    'op' => 'all',
                    'conditions' => [
                        ['op' => 'field_equals', 'field_key' => 'period_open', 'value' => false],
                        [
                            'op' => 'any',
                            'conditions' => [
                                ['op' => 'field_empty', 'field_key' => 'notes'],
                                ['op' => 'field_not_empty', 'field_key' => 'notes'],
                            ],
                        ],
                    ],
                ],
                ['op' => 'require_field', 'field_key' => 'notes'],
            );
            $this->fail('Verschachtelung muss scheitern.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Verschachtelte', $exception->getMessage());
        }

        try {
            FieldRuleContract::assertRuleStructure(
                $defs,
                ['op' => 'field_equals', 'field_key' => 'tags', 'value' => 'opt_a'],
                ['op' => 'require_field', 'field_key' => 'notes'],
            );
            $this->fail('Multi-equals muss scheitern.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('field_contains', $exception->getMessage());
        }

        try {
            FieldRuleContract::assertRuleStructure(
                $defs,
                ['op' => 'field_equals', 'field_key' => 'channel', 'value' => 'old'],
                ['op' => 'require_field', 'field_key' => 'notes'],
                requireActiveOptionKeys: true,
            );
            $this->fail('Inactive Key muss für neue Regeln scheitern.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('inaktiv', $exception->getMessage());
        }

        FieldRuleContract::assertRuleStructure(
            $defs,
            ['op' => 'field_equals', 'field_key' => 'channel', 'value' => 'old'],
            ['op' => 'require_field', 'field_key' => 'notes'],
            requireActiveOptionKeys: false,
        );
    }

    /**
     * @param  array<string, array{0: FieldType, 1: FieldScope, 2?: list<array<string, mixed>>|null, 3?: bool}>  $map
     * @return array<string, object>
     */
    private function defs(array $map): array
    {
        $out = [];
        foreach ($map as $key => $spec) {
            $out[$key] = (object) [
                'key' => $key,
                'field_type' => $spec[0],
                'scope' => $spec[1],
                'options_json' => $spec[2] ?? null,
                'action_target_readonly' => $spec[3] ?? false,
            ];
        }

        return FieldRuleContract::normalizeDefinitions($out);
    }
}
