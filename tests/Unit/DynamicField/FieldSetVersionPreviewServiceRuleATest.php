<?php

namespace Tests\Unit\DynamicField;

use App\Enums\FieldScope;
use App\Enums\FieldSetVersionStatus;
use App\Enums\FieldType;
use App\Models\FieldDefinition;
use App\Models\FieldDefinitionRevision;
use App\Models\FieldRule;
use App\Models\FieldSetVersion;
use App\Models\FieldSetVersionField;
use App\Services\DynamicField\Admin\FieldSetVersionPreviewService;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class FieldSetVersionPreviewServiceRuleATest extends TestCase
{
    public function test_preview_rejects_invalid_rules_fail_closed(): void
    {
        $version = $this->makeVersionWithRule(
            condition: [
                'op' => 'all',
                'conditions' => [
                    ['op' => 'field_equals', 'field_key' => 'flag', 'value' => true],
                    [
                        'op' => 'any',
                        'conditions' => [
                            ['op' => 'field_empty', 'field_key' => 'notes'],
                            ['op' => 'field_not_empty', 'field_key' => 'notes'],
                        ],
                    ],
                ],
            ],
            action: ['op' => 'set_visible', 'field_key' => 'notes', 'value' => false],
        );

        try {
            app(FieldSetVersionPreviewService::class)->preview($version);
            $this->fail('Preview muss verschachtelte Gruppen ablehnen.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('rules', $exception->errors());
            $this->assertStringContainsString(
                'Verschachtelte',
                $exception->errors()['rules'][0],
            );
        }
    }

    public function test_preview_accepts_valid_set_visible_and_require(): void
    {
        $version = $this->makeVersionWithRule(
            condition: ['op' => 'field_equals', 'field_key' => 'flag', 'value' => true],
            action: ['op' => 'set_visible', 'field_key' => 'notes', 'value' => false],
        );

        $payload = app(FieldSetVersionPreviewService::class)->preview($version);

        $this->assertSame(94001, $payload['version_id']);
        $this->assertNotEmpty($payload['rules']);
        $notes = collect($payload['fields'])->firstWhere('key', 'notes');
        $this->assertIsArray($notes);
    }

    /**
     * @param  array<string, mixed>  $condition
     * @param  array<string, mixed>  $action
     */
    private function makeVersionWithRule(array $condition, array $action): FieldSetVersion
    {
        $version = new FieldSetVersion;
        $version->id = 94001;
        $version->version = 1;
        $version->status = FieldSetVersionStatus::Draft;
        $version->exists = true;

        $fields = collect();
        foreach (
            [
                ['key' => 'flag', 'type' => FieldType::Boolean, 'scope' => FieldScope::Header],
                ['key' => 'notes', 'type' => FieldType::ShortText, 'scope' => FieldScope::Position],
                ['key' => 'period_open', 'type' => FieldType::Boolean, 'scope' => FieldScope::Position],
            ] as $index => $spec
        ) {
            $definition = new FieldDefinition;
            $definition->id = 95000 + $index;
            $definition->key = $spec['key'];
            $definition->field_type = $spec['type'];
            $definition->scope = $spec['scope'];
            $definition->is_system = false;
            $definition->exists = true;

            $revision = new FieldDefinitionRevision;
            $revision->id = 96000 + $index;
            $revision->label = $spec['key'];
            $revision->help_text = null;
            $revision->group_key = null;
            $revision->revision = 1;
            $revision->exists = true;
            $revision->setRelation('definition', $definition);
            $revision->setRelation('options', collect());

            $membership = new FieldSetVersionField;
            $membership->id = 97000 + $index;
            $membership->sort = $index;
            $membership->required_override = false;
            $membership->visible_override = true;
            $membership->exists = true;
            $membership->setRelation('revision', $revision);

            $fields->push($membership);
        }

        $rule = new FieldRule;
        $rule->id = 98001;
        $rule->condition_json = $condition;
        $rule->action_json = $action;
        $rule->sort = 0;
        $rule->exists = true;

        $version->setRelation('fields', $fields);
        $version->setRelation('rules', collect([$rule]));

        return $version;
    }
}
