<?php

namespace Tests\Unit\DynamicField;

use App\Enums\FieldAppliesTo;
use App\Enums\FieldScope;
use App\Services\DynamicField\Assignment\FieldSetAssignmentMergeResolver;
use PHPUnit\Framework\TestCase;

/**
 * DF-3.3a1 / DYN-002 – deterministischer Merge ohne DB.
 */
class FieldSetAssignmentMergeResolverTest extends TestCase
{
    private FieldSetAssignmentMergeResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new FieldSetAssignmentMergeResolver;
    }

    public function test_source_order_core_global_category_medium_with_sort_and_id_tiebreaker(): void
    {
        $result = $this->resolver->resolve([
            'process' => FieldAppliesTo::Calculation,
            'scope' => FieldScope::Position,
            'sources' => [
                $this->source('advertising_medium', 20, 2, 200, [
                    $this->membership(10, 100, 'm_field', FieldScope::Position, sort: 1),
                ]),
                $this->source('global', 5, 9, 30, [
                    $this->membership(11, 101, 'g_late', FieldScope::Position, sort: 1),
                ]),
                $this->source('primary_core', null, null, 1, [
                    $this->membership(1, 1, 'core_a', FieldScope::Position, sort: 1),
                ], isCore: true),
                $this->source('global', 1, 2, 20, [
                    $this->membership(12, 102, 'g_early', FieldScope::Position, sort: 1),
                ]),
                $this->source('advertising_category', 3, 7, 100, [
                    $this->membership(13, 103, 'c_field', FieldScope::Position, sort: 1),
                ]),
            ],
        ]);

        $this->assertSame(
            ['primary_core', 'global', 'global', 'advertising_category', 'advertising_medium'],
            array_column($result['sources'], 'layer'),
        );
        $this->assertSame([1, 2, 3, 4, 5], array_column($result['sources'], 'merge_order'));
        $this->assertSame([1, 5], [
            $result['sources'][1]['assignment_id'],
            $result['sources'][2]['assignment_id'],
        ]);
        $this->assertFalse($result['has_blocking_conflicts']);
    }

    public function test_header_scope_ignores_position_memberships_and_blocks_header_on_category(): void
    {
        $ok = $this->resolver->resolve([
            'process' => FieldAppliesTo::Calculation,
            'scope' => FieldScope::Header,
            'sources' => [
                $this->source('primary_core', null, null, 1, [
                    $this->membership(1, 1, 'campaign_period', FieldScope::Header, sort: 1),
                    $this->membership(2, 2, 'position_flight_period', FieldScope::Position, sort: 2),
                ], isCore: true),
                $this->source('global', 1, 1, 2, [
                    $this->membership(3, 3, 'extra_header', FieldScope::Header, sort: 5),
                ]),
            ],
        ]);

        $keys = array_column($ok['fields'], 'field_key');
        $this->assertSame(['campaign_period', 'extra_header'], $keys);
        $this->assertFalse($ok['has_blocking_conflicts']);

        $bad = $this->resolver->resolve([
            'process' => FieldAppliesTo::Calculation,
            'scope' => FieldScope::Position,
            'sources' => [
                $this->source('primary_core', null, null, 1, [
                    $this->membership(1, 1, 'pos', FieldScope::Position, sort: 1),
                ], isCore: true),
                $this->source('advertising_category', 1, 1, 2, [
                    $this->membership(9, 9, 'hdr', FieldScope::Header, sort: 1),
                ]),
            ],
        ]);
        $this->assertTrue($bad['has_blocking_conflicts']);
        $this->assertContains('header_field_on_contextual_assignment', array_column($bad['conflicts'], 'code'));
    }

    public function test_three_state_overrides_and_same_layer_conflict(): void
    {
        $merged = $this->resolver->resolve([
            'process' => FieldAppliesTo::Calculation,
            'scope' => FieldScope::Position,
            'sources' => [
                $this->source('primary_core', null, null, 1, [
                    $this->membership(1, 1, 'f', FieldScope::Position, sort: 1, required: null, visible: null),
                ], isCore: true),
                $this->source('global', 1, 1, 2, [
                    $this->membership(1, 1, 'f', FieldScope::Position, sort: 2, required: true, visible: null),
                ]),
                $this->source('advertising_category', 2, 1, 3, [
                    $this->membership(1, 1, 'f', FieldScope::Position, sort: 3, required: null, visible: false),
                ]),
            ],
        ]);

        $this->assertFalse($merged['has_blocking_conflicts']);
        $field = $merged['fields'][0];
        $this->assertTrue($field['effective_required']);
        $this->assertFalse($field['effective_visible']);
        $this->assertSame(3, $field['sort']);
        $this->assertSame('advertising_category', $field['winning_layer']);

        $conflict = $this->resolver->resolve([
            'process' => FieldAppliesTo::Calculation,
            'scope' => FieldScope::Position,
            'sources' => [
                $this->source('primary_core', null, null, 1, [
                    $this->membership(1, 1, 'f', FieldScope::Position, sort: 1),
                ], isCore: true),
                $this->source('global', 1, 1, 2, [
                    $this->membership(1, 1, 'f', FieldScope::Position, sort: 1, required: true),
                ]),
                $this->source('global', 2, 2, 3, [
                    $this->membership(1, 1, 'f', FieldScope::Position, sort: 2, required: false),
                ]),
            ],
        ]);
        $this->assertTrue($conflict['has_blocking_conflicts']);
        $this->assertContains('same_layer_required_conflict', array_column($conflict['conflicts'], 'code'));
    }

    public function test_same_definition_same_revision_merges_different_revision_conflicts(): void
    {
        $ok = $this->resolver->resolve([
            'process' => FieldAppliesTo::Calculation,
            'scope' => FieldScope::Position,
            'sources' => [
                $this->source('primary_core', null, null, 1, [
                    $this->membership(1, 10, 'f', FieldScope::Position, sort: 1),
                ], isCore: true),
                $this->source('global', 1, 1, 2, [
                    $this->membership(1, 10, 'f', FieldScope::Position, sort: 5, required: true),
                ]),
            ],
        ]);
        $this->assertFalse($ok['has_blocking_conflicts']);
        $this->assertCount(1, $ok['fields']);

        $bad = $this->resolver->resolve([
            'process' => FieldAppliesTo::Calculation,
            'scope' => FieldScope::Position,
            'sources' => [
                $this->source('primary_core', null, null, 1, [
                    $this->membership(1, 10, 'f', FieldScope::Position, sort: 1),
                ], isCore: true),
                $this->source('global', 1, 1, 2, [
                    $this->membership(1, 11, 'f', FieldScope::Position, sort: 5),
                ]),
            ],
        ]);
        $this->assertTrue($bad['has_blocking_conflicts']);
        $this->assertContains('revision_mismatch', array_column($bad['conflicts'], 'code'));
    }

    public function test_same_key_different_definitions_conflicts(): void
    {
        $result = $this->resolver->resolve([
            'process' => FieldAppliesTo::Calculation,
            'scope' => FieldScope::Position,
            'sources' => [
                $this->source('primary_core', null, null, 1, [
                    $this->membership(1, 1, 'shared', FieldScope::Position, sort: 1),
                ], isCore: true),
                $this->source('global', 1, 1, 2, [
                    $this->membership(2, 2, 'shared', FieldScope::Position, sort: 1),
                ]),
            ],
        ]);
        $this->assertTrue($result['has_blocking_conflicts']);
        $this->assertContains('key_definition_mismatch', array_column($result['conflicts'], 'code'));
    }

    public function test_inactive_definition_and_process_filter(): void
    {
        $inactive = $this->resolver->resolve([
            'process' => FieldAppliesTo::Calculation,
            'scope' => FieldScope::Position,
            'sources' => [
                $this->source('primary_core', null, null, 1, [
                    $this->membership(1, 1, 'core', FieldScope::Position, sort: 1),
                ], isCore: true),
                $this->source('global', 1, 1, 2, [
                    $this->membership(2, 2, 'x', FieldScope::Position, sort: 1, active: false),
                ]),
            ],
        ]);
        $this->assertTrue($inactive['has_blocking_conflicts']);
        $this->assertContains('inactive_definition', array_column($inactive['conflicts'], 'code'));

        $process = $this->resolver->resolve([
            'process' => FieldAppliesTo::Calculation,
            'scope' => FieldScope::Position,
            'sources' => [
                $this->source('primary_core', null, null, 1, [
                    $this->membership(1, 1, 'core', FieldScope::Position, sort: 1),
                ], isCore: true),
                $this->source('global', 1, 1, 2, [
                    $this->membership(2, 2, 'dispo_only', FieldScope::Position, sort: 1, appliesTo: FieldAppliesTo::DispoOrder),
                ]),
            ],
        ]);
        $this->assertTrue($process['has_blocking_conflicts']);
        $this->assertContains('process_incompatible_definition', array_column($process['conflicts'], 'code'));
    }

    public function test_rule_merge_dedup_and_conflicting_actions(): void
    {
        $ruleA = [
            'field_rule_id' => 1,
            'sort' => 1,
            'condition_json' => ['op' => 'field_equals', 'field_key' => 'a', 'value' => false],
            'action_json' => ['op' => 'require_field', 'field_key' => 'b'],
        ];
        $ruleB = $ruleA;
        $ruleB['field_rule_id'] = 2;

        $dedup = $this->resolver->resolve([
            'process' => FieldAppliesTo::Calculation,
            'scope' => FieldScope::Position,
            'sources' => [
                $this->source('primary_core', null, null, 1, [
                    $this->membership(1, 1, 'a', FieldScope::Position, sort: 1),
                    $this->membership(2, 2, 'b', FieldScope::Position, sort: 2),
                ], isCore: true, rules: [$ruleA]),
                $this->source('global', 1, 1, 2, [
                    $this->membership(2, 2, 'b', FieldScope::Position, sort: 2),
                ], rules: [$ruleB]),
            ],
        ]);
        $this->assertCount(1, $dedup['rules']);
        $this->assertNotEmpty($dedup['warnings']);

        $conflictRule = [
            'field_rule_id' => 3,
            'sort' => 1,
            'condition_json' => ['op' => 'field_equals', 'field_key' => 'a', 'value' => true],
            'action_json' => ['op' => 'set_visible', 'field_key' => 'b', 'value' => false],
        ];
        $visibleRule = [
            'field_rule_id' => 4,
            'sort' => 2,
            'condition_json' => ['op' => 'field_equals', 'field_key' => 'a', 'value' => false],
            'action_json' => ['op' => 'set_visible', 'field_key' => 'b', 'value' => true],
        ];
        $conflict = $this->resolver->resolve([
            'process' => FieldAppliesTo::Calculation,
            'scope' => FieldScope::Position,
            'sources' => [
                $this->source('primary_core', null, null, 1, [
                    $this->membership(1, 1, 'a', FieldScope::Position, sort: 1),
                    $this->membership(2, 2, 'b', FieldScope::Position, sort: 2),
                ], isCore: true, rules: [$visibleRule]),
                $this->source('global', 1, 1, 2, [], rules: [$conflictRule]),
            ],
        ]);
        $this->assertTrue($conflict['has_blocking_conflicts']);
        $this->assertContains('rule_conflicting_set_visible', array_column($conflict['conflicts'], 'code'));
    }

    public function test_field_sort_tuple_is_stable(): void
    {
        $result = $this->resolver->resolve([
            'process' => FieldAppliesTo::Calculation,
            'scope' => FieldScope::Position,
            'sources' => [
                $this->source('primary_core', null, null, 1, [
                    $this->membership(30, 30, 'c', FieldScope::Position, sort: 10),
                    $this->membership(10, 10, 'a', FieldScope::Position, sort: 10),
                    $this->membership(20, 20, 'b', FieldScope::Position, sort: 5),
                ], isCore: true),
            ],
        ]);

        $this->assertSame(['b', 'a', 'c'], array_column($result['fields'], 'field_key'));
    }

    /**
     * @param  list<array<string, mixed>>  $memberships
     * @param  list<array<string, mixed>>  $rules
     * @return array<string, mixed>
     */
    private function source(
        string $layer,
        ?int $assignmentId,
        ?int $assignmentSort,
        int $fieldSetId,
        array $memberships,
        bool $isCore = false,
        array $rules = [],
    ): array {
        return [
            'layer' => $layer,
            'assignment_id' => $assignmentId,
            'assignment_sort' => $assignmentSort,
            'field_set_id' => $fieldSetId,
            'field_set_key' => 'set_'.$fieldSetId,
            'field_set_version_id' => $fieldSetId * 10,
            'field_set_version_number' => 1,
            'is_system_core' => $isCore,
            'memberships' => $memberships,
            'rules' => $rules,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function membership(
        int $definitionId,
        int $revisionId,
        string $key,
        FieldScope $scope,
        int $sort = 0,
        ?bool $required = null,
        ?bool $visible = null,
        bool $active = true,
        FieldAppliesTo $appliesTo = FieldAppliesTo::Both,
    ): array {
        return [
            'field_definition_id' => $definitionId,
            'field_definition_revision_id' => $revisionId,
            'field_key' => $key,
            'field_scope' => $scope->value,
            'field_applies_to' => $appliesTo->value,
            'definition_is_active' => $active,
            'definition_is_system' => false,
            'group_key' => 'grp',
            'sort' => $sort,
            'required_override' => $required,
            'visible_override' => $visible,
            'label' => $key,
            'field_type' => 'short_text',
        ];
    }
}
