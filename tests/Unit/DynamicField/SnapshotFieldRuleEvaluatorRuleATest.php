<?php

namespace Tests\Unit\DynamicField;

use App\Enums\FieldScope;
use App\Enums\FieldType;
use App\Models\ConfigurationSnapshot;
use App\Models\ConfigurationSnapshotSource;
use App\Models\SnapshotFieldDefinition;
use App\Models\SnapshotFieldRule;
use App\Services\DynamicField\SnapshotFieldRuleEvaluator;
use App\Support\DynamicField\FieldRuleContract;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

class SnapshotFieldRuleEvaluatorRuleATest extends TestCase
{
    public function test_header_require_runs_once_without_and_with_positions(): void
    {
        $snapshot = $this->makeSnapshot([
            ['key' => 'flag', 'type' => FieldType::Boolean, 'scope' => FieldScope::Header, 'required' => false, 'visible' => true],
            ['key' => 'title', 'type' => FieldType::ShortText, 'scope' => FieldScope::Header, 'required' => false, 'visible' => true],
        ], [
            [
                'condition_json' => ['op' => 'field_equals', 'field_key' => 'flag', 'value' => true],
                'action_json' => ['op' => 'require_field', 'field_key' => 'title'],
            ],
        ]);

        $evaluator = app(SnapshotFieldRuleEvaluator::class);

        try {
            $evaluator->validate($snapshot, ['flag' => true, 'title' => null], []);
            $this->fail('Header-Require ohne Positionen muss greifen.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('dynamic_field_values.title', $exception->errors());
            $this->assertCount(1, $exception->errors());
        }

        try {
            $evaluator->validate($snapshot, ['flag' => true, 'title' => null], [
                ['index' => 0, 'values' => []],
                ['index' => 1, 'values' => []],
            ]);
            $this->fail('Header-Require mit Positionen muss genau einmal greifen.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                ['dynamic_field_values.title'],
                array_keys($exception->errors()),
            );
        }

        $evaluator->validate($snapshot, ['flag' => true, 'title' => 'ok'], [
            ['index' => 0, 'values' => []],
            ['index' => 1, 'values' => []],
        ]);
        $this->assertTrue(true);
    }

    public function test_position_rules_do_not_leak_across_positions(): void
    {
        $snapshot = $this->makeSnapshot([
            ['key' => 'period_open', 'type' => FieldType::Boolean, 'scope' => FieldScope::Position, 'required' => false, 'visible' => true],
            ['key' => 'position_flight_period', 'type' => FieldType::Period, 'scope' => FieldScope::Position, 'required' => false, 'visible' => true],
        ], [
            [
                'condition_json' => ['op' => 'field_equals', 'field_key' => 'period_open', 'value' => false],
                'action_json' => ['op' => 'require_field', 'field_key' => 'position_flight_period'],
            ],
        ]);

        try {
            app(SnapshotFieldRuleEvaluator::class)->validate($snapshot, [], [
                [
                    'index' => 0,
                    'values' => [
                        'period_open' => false,
                        'position_flight_period' => null,
                    ],
                ],
                [
                    'index' => 1,
                    'values' => [
                        'period_open' => true,
                        'position_flight_period' => null,
                    ],
                ],
            ]);
            $this->fail('Nur Position 0 muss Pflichtfehler erzeugen.');
        } catch (ValidationException $exception) {
            $errors = $exception->errors();
            $this->assertArrayHasKey('positions.0.dynamic_field_values.position_flight_period', $errors);
            $this->assertArrayNotHasKey('positions.1.dynamic_field_values.position_flight_period', $errors);
        }
    }

    public function test_header_to_position_require(): void
    {
        $snapshot = $this->makeSnapshot([
            ['key' => 'flag', 'type' => FieldType::Boolean, 'scope' => FieldScope::Header, 'required' => false, 'visible' => true],
            ['key' => 'notes', 'type' => FieldType::ShortText, 'scope' => FieldScope::Position, 'required' => false, 'visible' => true],
        ], [
            [
                'condition_json' => ['op' => 'field_equals', 'field_key' => 'flag', 'value' => true],
                'action_json' => ['op' => 'require_field', 'field_key' => 'notes'],
            ],
        ]);

        try {
            app(SnapshotFieldRuleEvaluator::class)->validate($snapshot, ['flag' => true], [
                ['index' => 0, 'values' => ['notes' => null]],
                ['index' => 1, 'values' => ['notes' => null]],
            ]);
            $this->fail('Header→Position muss je Position greifen.');
        } catch (ValidationException $exception) {
            $errors = $exception->errors();
            $this->assertArrayHasKey('positions.0.dynamic_field_values.notes', $errors);
            $this->assertArrayHasKey('positions.1.dynamic_field_values.notes', $errors);
        }
    }

    public function test_mixed_all_and_any_header_and_position_atoms_for_position_action(): void
    {
        $snapshot = $this->makeSnapshot([
            ['key' => 'flag', 'type' => FieldType::Boolean, 'scope' => FieldScope::Header, 'required' => false, 'visible' => true],
            ['key' => 'period_open', 'type' => FieldType::Boolean, 'scope' => FieldScope::Position, 'required' => false, 'visible' => true],
            ['key' => 'notes', 'type' => FieldType::ShortText, 'scope' => FieldScope::Position, 'required' => false, 'visible' => true],
        ], [
            [
                'condition_json' => [
                    'op' => 'all',
                    'conditions' => [
                        ['op' => 'field_equals', 'field_key' => 'flag', 'value' => true],
                        ['op' => 'field_equals', 'field_key' => 'period_open', 'value' => false],
                    ],
                ],
                'action_json' => ['op' => 'require_field', 'field_key' => 'notes'],
            ],
        ]);

        $evaluator = app(SnapshotFieldRuleEvaluator::class);

        try {
            $evaluator->validate($snapshot, ['flag' => true], [
                ['index' => 0, 'values' => ['period_open' => false, 'notes' => null]],
            ]);
            $this->fail('Gemischtes all muss require auslösen.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('positions.0.dynamic_field_values.notes', $exception->errors());
        }

        $evaluator->validate($snapshot, ['flag' => true], [
            ['index' => 0, 'values' => ['period_open' => true, 'notes' => null]],
        ]);

        $anySnapshot = $this->makeSnapshot([
            ['key' => 'flag', 'type' => FieldType::Boolean, 'scope' => FieldScope::Header, 'required' => false, 'visible' => true],
            ['key' => 'period_open', 'type' => FieldType::Boolean, 'scope' => FieldScope::Position, 'required' => false, 'visible' => true],
            ['key' => 'notes', 'type' => FieldType::ShortText, 'scope' => FieldScope::Position, 'required' => false, 'visible' => true],
        ], [
            [
                'condition_json' => [
                    'op' => 'any',
                    'conditions' => [
                        ['op' => 'field_equals', 'field_key' => 'flag', 'value' => true],
                        ['op' => 'field_equals', 'field_key' => 'period_open', 'value' => false],
                    ],
                ],
                'action_json' => ['op' => 'require_field', 'field_key' => 'notes'],
            ],
        ]);

        try {
            $evaluator->validate($anySnapshot, ['flag' => false], [
                ['index' => 0, 'values' => ['period_open' => false, 'notes' => null]],
            ]);
            $this->fail('Gemischtes any muss require auslösen.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('positions.0.dynamic_field_values.notes', $exception->errors());
        }

        $evaluator->validate($anySnapshot, ['flag' => false], [
            ['index' => 0, 'values' => ['period_open' => true, 'notes' => 'ok']],
        ]);
        $this->assertTrue(true);
    }

    public function test_hidden_by_rule_skips_require_dyn005(): void
    {
        $snapshot = $this->makeSnapshot([
            ['key' => 'flag', 'type' => FieldType::Boolean, 'scope' => FieldScope::Position, 'required' => false, 'visible' => true],
            ['key' => 'notes', 'type' => FieldType::ShortText, 'scope' => FieldScope::Position, 'required' => false, 'visible' => true],
        ], [
            [
                'condition_json' => ['op' => 'field_equals', 'field_key' => 'flag', 'value' => true],
                'action_json' => ['op' => 'set_visible', 'field_key' => 'notes', 'value' => false],
            ],
            [
                'condition_json' => ['op' => 'field_equals', 'field_key' => 'flag', 'value' => true],
                'action_json' => ['op' => 'require_field', 'field_key' => 'notes'],
            ],
        ]);

        app(SnapshotFieldRuleEvaluator::class)->validate(
            $snapshot,
            [],
            [['index' => 0, 'values' => ['flag' => true, 'notes' => null]]],
        );

        $this->assertTrue(true);
    }

    public function test_historical_set_visible_on_calc_origin_fails_closed(): void
    {
        $snapshot = $this->makeSnapshot([
            ['key' => 'period_open', 'type' => FieldType::Boolean, 'scope' => FieldScope::Position, 'required' => false, 'visible' => true],
            ['key' => 'position_flight_period', 'type' => FieldType::Period, 'scope' => FieldScope::Position, 'required' => false, 'visible' => true],
        ], [
            [
                'condition_json' => ['op' => 'field_equals', 'field_key' => 'period_open', 'value' => false],
                'action_json' => ['op' => 'set_visible', 'field_key' => 'position_flight_period', 'value' => false],
            ],
        ], calcOriginKeys: ['period_open', 'position_flight_period']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Calc-Origin/');
        app(SnapshotFieldRuleEvaluator::class)->validate(
            $snapshot,
            [],
            [['index' => 0, 'values' => ['period_open' => false]]],
        );
    }

    public function test_historical_require_field_on_non_seed_calc_origin_fails_closed(): void
    {
        $snapshot = $this->makeSnapshot([
            ['key' => 'flag', 'type' => FieldType::Boolean, 'scope' => FieldScope::Header, 'required' => false, 'visible' => true],
            ['key' => 'campaign_period', 'type' => FieldType::Period, 'scope' => FieldScope::Header, 'required' => false, 'visible' => true],
        ], [
            [
                'condition_json' => ['op' => 'field_equals', 'field_key' => 'flag', 'value' => true],
                'action_json' => ['op' => 'require_field', 'field_key' => 'campaign_period'],
            ],
        ], calcOriginKeys: ['campaign_period']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Calc-Origin/');
        app(SnapshotFieldRuleEvaluator::class)->validate(
            $snapshot,
            ['flag' => true, 'campaign_period' => null],
            [],
        );
    }

    public function test_exact_seed_on_calc_origin_remains_valid(): void
    {
        $snapshot = $this->makeSnapshot([
            ['key' => 'period_open', 'type' => FieldType::Boolean, 'scope' => FieldScope::Position, 'required' => false, 'visible' => true],
            ['key' => 'position_flight_period', 'type' => FieldType::Period, 'scope' => FieldScope::Position, 'required' => false, 'visible' => true],
        ], [
            [
                'condition_json' => ['op' => 'field_equals', 'field_key' => 'period_open', 'value' => false],
                'action_json' => ['op' => 'require_field', 'field_key' => 'position_flight_period'],
            ],
        ], calcOriginKeys: ['period_open', 'position_flight_period']);

        try {
            app(SnapshotFieldRuleEvaluator::class)->validate($snapshot, [], [
                ['index' => 0, 'values' => ['period_open' => false, 'position_flight_period' => null]],
            ]);
            $this->fail('Seed-Regel muss Pflichtfehler erzeugen.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey(
                'positions.0.dynamic_field_values.position_flight_period',
                $exception->errors(),
            );
        }
    }

    public function test_effective_apis_reject_malformed_rules(): void
    {
        $defs = FieldRuleContract::normalizeDefinitions([
            'flag' => (object) [
                'key' => 'flag',
                'field_type' => FieldType::Boolean,
                'scope' => FieldScope::Position,
                'options_json' => null,
            ],
            'notes' => (object) [
                'key' => 'notes',
                'field_type' => FieldType::ShortText,
                'scope' => FieldScope::Position,
                'options_json' => null,
            ],
        ]);
        $rules = [(object) [
            'condition_json' => ['op' => 'all', 'conditions' => []],
            'action_json' => ['op' => 'set_visible', 'field_key' => 'notes', 'value' => false],
        ]];

        $this->expectException(RuntimeException::class);
        app(SnapshotFieldRuleEvaluator::class)->effectiveVisibilityForScope(
            $rules,
            $defs,
            [],
            ['flag' => true],
            FieldScope::Position,
            ['notes' => true],
        );
    }

    public function test_seed_dedupe_constant_matches_payload(): void
    {
        $condition = ['op' => 'field_equals', 'field_key' => 'period_open', 'value' => false];
        $action = ['op' => 'require_field', 'field_key' => 'position_flight_period'];

        $this->assertSame(
            FieldRuleContract::SEED_RULE_DEDUPE_SHA256,
            FieldRuleContract::dedupePayload($condition, $action),
        );
    }

    /**
     * @param  list<array{key: string, type: FieldType, scope: FieldScope, required: bool, visible: bool}>  $fields
     * @param  list<array{condition_json: array<string, mixed>, action_json: array<string, mixed>}>  $rules
     * @param  list<string>  $calcOriginKeys
     */
    private function makeSnapshot(array $fields, array $rules, array $calcOriginKeys = []): ConfigurationSnapshot
    {
        $snapshot = new ConfigurationSnapshot;
        $snapshot->id = 91001;
        $snapshot->exists = true;

        $calcOriginSourceId = 94001;
        $source = null;
        if ($calcOriginKeys !== []) {
            $source = new ConfigurationSnapshotSource;
            $source->id = $calcOriginSourceId;
            $source->configuration_snapshot_id = $snapshot->id;
            $source->target_identity = ConfigurationSnapshotSource::TARGET_IDENTITY_CALC_ORIGIN;
            $source->role = ConfigurationSnapshotSource::ROLE_ADDITIONAL;
            $source->exists = true;
            $source->setRelation('rules', collect());
        }

        $defs = collect();
        foreach ($fields as $index => $field) {
            $def = new SnapshotFieldDefinition;
            $def->id = 92000 + $index;
            $def->configuration_snapshot_id = $snapshot->id;
            $def->key = $field['key'];
            $def->field_type = $field['type'];
            $def->scope = $field['scope'];
            $def->label = $field['key'];
            $def->required = $field['required'];
            $def->visible = $field['visible'];
            $def->options_json = null;
            if (in_array($field['key'], $calcOriginKeys, true)) {
                $def->provenance_definition_source_id = $calcOriginSourceId;
            }
            $defs->push($def);
        }

        $ruleModels = collect();
        foreach ($rules as $index => $rule) {
            $model = new SnapshotFieldRule;
            $model->id = 93000 + $index;
            $model->configuration_snapshot_id = $snapshot->id;
            $model->condition_json = $rule['condition_json'];
            $model->action_json = $rule['action_json'];
            $model->sort = $index;
            $ruleModels->push($model);
        }

        $snapshot->setRelation('fieldDefinitions', $defs);
        $snapshot->setRelation('rules', $ruleModels);
        $snapshot->setRelation('sources', $source !== null ? collect([$source]) : collect());

        return $snapshot;
    }
}
