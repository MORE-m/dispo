<?php

namespace Tests\Feature\DynamicField;

use App\Enums\FieldAppliesTo;
use App\Enums\FieldScope;
use App\Enums\FieldSetVersionStatus;
use App\Enums\FieldType;
use App\Enums\Role;
use App\Models\FieldDefinition;
use App\Models\FieldDefinitionRevision;
use App\Models\FieldDefinitionRevisionOption;
use App\Models\FieldRule;
use App\Models\FieldSet;
use App\Models\FieldSetVersion;
use App\Models\FieldSetVersionField;
use App\Models\User;
use App\Services\DynamicField\Admin\AdminFieldSetCatalog;
use App\Services\DynamicField\Admin\FieldSetVersionAdminWriter;
use App\Services\DynamicField\ConfigurationSnapshotMaterializer;
use App\Services\DynamicField\DispoConfigurationSnapshotComposer;
use App\Support\DynamicField\FieldRuleContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * DF-3-RULE-C: Desired-State Regel-Editor.
 */
class FieldSetVersionRulesAdminRuleCTest extends TestCase
{
    use RefreshDatabase;

    public function test_non_admin_roles_cannot_preview_or_replace_rules(): void
    {
        $fieldSet = FieldSet::query()->where('key', AdminFieldSetCatalog::CALCULATION_CORE)->firstOrFail();
        $version = FieldSetVersion::query()->whereKey($fieldSet->active_version_id)->firstOrFail();
        $seed = $this->seedRulePayload();

        foreach ([Role::Sales, Role::Disposition, Role::ProductManagement] as $role) {
            $user = User::factory()->role($role)->create();

            $this->actingAs($user)
                ->postJson(route('administration.dynamic-fields.field-sets.versions.rules.preview', [$fieldSet, $version]), [
                    'lock_version' => $fieldSet->lock_version,
                    'rules' => [$seed],
                ])
                ->assertForbidden();

            $this->actingAs($user)
                ->putJson(route('administration.dynamic-fields.field-sets.versions.rules.replace', [$fieldSet, $version]), [
                    'lock_version' => $fieldSet->lock_version,
                    'fingerprint' => str_repeat('a', 64),
                    'rules' => [$seed],
                ])
                ->assertForbidden();
        }
    }

    public function test_active_version_is_read_only_for_rules_replace(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $fieldSet = FieldSet::query()->where('key', AdminFieldSetCatalog::CALCULATION_CORE)->firstOrFail();
        $version = FieldSetVersion::query()->whereKey($fieldSet->active_version_id)->firstOrFail();
        $this->assertSame(FieldSetVersionStatus::Active, $version->status);

        $preview = $this->actingAs($admin)
            ->postJson(route('administration.dynamic-fields.field-sets.versions.rules.preview', [$fieldSet, $version]), [
                'lock_version' => $fieldSet->lock_version,
                'rules' => [$this->seedRulePayload()],
            ])
            ->assertOk()
            ->json();

        $this->assertFalse($preview['writable']);

        $this->actingAs($admin)
            ->putJson(route('administration.dynamic-fields.field-sets.versions.rules.replace', [$fieldSet, $version]), [
                'lock_version' => $fieldSet->lock_version,
                'fingerprint' => $preview['fingerprint'],
                'rules' => [$this->seedRulePayload()],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['version']);
    }

    public function test_free_fieldset_allows_empty_rules_and_audits_apply(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $writer = app(FieldSetVersionAdminWriter::class);
        $fieldSet = $writer->createFreeFieldSet([
            'name' => 'Regelset frei',
            'key' => 'rule_c_free',
            'applies_to' => FieldAppliesTo::Calculation,
        ], $admin);
        $draft = FieldSetVersion::query()->where('field_set_id', $fieldSet->id)->where('status', FieldSetVersionStatus::Draft)->firstOrFail();
        $this->addBooleanMembership($draft, 'header_flag', FieldScope::Header);
        $this->addBooleanMembership($draft, 'other_flag', FieldScope::Header);

        $preview = $this->actingAs($admin)
            ->postJson(route('administration.dynamic-fields.field-sets.versions.rules.preview', [$fieldSet, $draft]), [
                'lock_version' => $fieldSet->fresh()->lock_version,
                'rules' => [],
            ])
            ->assertOk()
            ->json();

        $this->assertTrue($preview['writable']);
        $this->assertSame([], $preview['rules']);

        $lockBefore = (int) $fieldSet->fresh()->lock_version;
        $this->actingAs($admin)
            ->putJson(route('administration.dynamic-fields.field-sets.versions.rules.replace', [$fieldSet, $draft]), [
                'lock_version' => $lockBefore,
                'fingerprint' => $preview['fingerprint'],
                'rules' => [],
            ])
            ->assertOk()
            ->assertJsonPath('has_changes', false);

        $this->assertSame($lockBefore, (int) $fieldSet->fresh()->lock_version);

        $rule = [
            'condition' => [
                'op' => 'field_equals',
                'field_key' => 'header_flag',
                'value' => true,
            ],
            'action' => [
                'op' => 'set_visible',
                'field_key' => 'other_flag',
                'value' => false,
            ],
        ];

        $preview2 = $this->actingAs($admin)
            ->postJson(route('administration.dynamic-fields.field-sets.versions.rules.preview', [$fieldSet, $draft]), [
                'lock_version' => $lockBefore,
                'rules' => [$rule],
            ])
            ->assertOk()
            ->json();

        $this->actingAs($admin)
            ->putJson(route('administration.dynamic-fields.field-sets.versions.rules.replace', [$fieldSet, $draft]), [
                'lock_version' => $lockBefore,
                'fingerprint' => $preview2['fingerprint'],
                'rules' => [$rule],
            ])
            ->assertOk()
            ->assertJsonPath('has_changes', true)
            ->assertJsonPath('lock_version', $lockBefore + 1);

        $this->assertDatabaseHas('audit_events', [
            'action' => 'field_set.rules_replaced',
            'auditable_type' => FieldSet::class,
            'auditable_id' => $fieldSet->id,
        ]);
        $this->assertSame(1, FieldRule::query()->where('field_set_version_id', $draft->id)->count());
        $this->assertSame(0, FieldRule::query()->where('field_set_version_id', $draft->id)->value('sort'));
    }

    public function test_system_core_seed_must_remain_first_and_exact(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        [$fieldSet, $draft] = $this->createCalculationCoreDraft($admin);
        $seed = $this->seedRulePayload();

        $this->actingAs($admin)
            ->postJson(route('administration.dynamic-fields.field-sets.versions.rules.preview', [$fieldSet, $draft]), [
                'lock_version' => $fieldSet->fresh()->lock_version,
                'rules' => [],
            ])
            ->assertStatus(422);

        $altered = $seed;
        $altered['action']['field_key'] = 'campaign_period';
        $this->actingAs($admin)
            ->postJson(route('administration.dynamic-fields.field-sets.versions.rules.preview', [$fieldSet, $draft]), [
                'lock_version' => $fieldSet->fresh()->lock_version,
                'rules' => [$altered],
            ])
            ->assertStatus(422);

        $extra = [
            'condition' => [
                'op' => 'field_equals',
                'field_key' => 'period_open',
                'value' => true,
            ],
            'action' => [
                'op' => 'set_visible',
                'field_key' => 'position_flight_period',
                'value' => true,
            ],
        ];

        $this->actingAs($admin)
            ->postJson(route('administration.dynamic-fields.field-sets.versions.rules.preview', [$fieldSet, $draft]), [
                'lock_version' => $fieldSet->fresh()->lock_version,
                'rules' => [$extra, $seed],
            ])
            ->assertStatus(422);

        $this->actingAs($admin)
            ->postJson(route('administration.dynamic-fields.field-sets.versions.rules.preview', [$fieldSet, $draft]), [
                'lock_version' => $fieldSet->fresh()->lock_version,
                'rules' => [$seed, $seed],
            ])
            ->assertStatus(422);

        $this->actingAs($admin)
            ->postJson(route('administration.dynamic-fields.field-sets.versions.rules.preview', [$fieldSet, $draft]), [
                'lock_version' => $fieldSet->fresh()->lock_version,
                'rules' => [$seed, $extra],
            ])
            ->assertStatus(422);

        $preview = $this->actingAs($admin)
            ->postJson(route('administration.dynamic-fields.field-sets.versions.rules.preview', [$fieldSet, $draft]), [
                'lock_version' => $fieldSet->fresh()->lock_version,
                'rules' => [$seed],
            ])
            ->assertOk()
            ->json();

        $this->assertTrue($preview['rules'][0]['is_system_seed']);
        $this->assertTrue($preview['matched'][0]);
    }

    public function test_calc_origin_actions_are_rejected_except_exact_seed(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        [$fieldSet, $draft] = $this->createCalculationCoreDraft($admin);
        $lock = (int) $fieldSet->fresh()->lock_version;
        $seed = $this->seedRulePayload();

        foreach ([
            [
                'condition' => ['op' => 'field_equals', 'field_key' => 'period_open', 'value' => true],
                'action' => ['op' => 'require_field', 'field_key' => 'campaign_period'],
            ],
            [
                'condition' => ['op' => 'field_equals', 'field_key' => 'period_open', 'value' => true],
                'action' => ['op' => 'set_visible', 'field_key' => 'campaign_period', 'value' => false],
            ],
            [
                'condition' => ['op' => 'field_equals', 'field_key' => 'period_open', 'value' => true],
                'action' => ['op' => 'require_field', 'field_key' => 'period_open'],
            ],
            [
                'condition' => ['op' => 'field_equals', 'field_key' => 'period_open', 'value' => true],
                'action' => ['op' => 'set_visible', 'field_key' => 'position_flight_period', 'value' => true],
            ],
        ] as $invalid) {
            $this->actingAs($admin)
                ->postJson(route('administration.dynamic-fields.field-sets.versions.rules.preview', [$fieldSet, $draft]), [
                    'lock_version' => $lock,
                    'rules' => [$seed, $invalid],
                ])
                ->assertStatus(422);
        }

        $alteredSeed = $seed;
        $alteredSeed['condition']['value'] = true;
        $this->actingAs($admin)
            ->postJson(route('administration.dynamic-fields.field-sets.versions.rules.preview', [$fieldSet, $draft]), [
                'lock_version' => $lock,
                'rules' => [$alteredSeed],
            ])
            ->assertStatus(422);

        $this->actingAs($admin)
            ->postJson(route('administration.dynamic-fields.field-sets.versions.rules.preview', [$fieldSet, $draft]), [
                'lock_version' => $lock,
                'rules' => [$seed],
            ])
            ->assertOk();
    }

    public function test_free_fieldset_does_not_treat_same_keys_as_calc_origin(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $writer = app(FieldSetVersionAdminWriter::class);
        $fieldSet = $writer->createFreeFieldSet([
            'name' => 'Kein Calc-Origin',
            'key' => 'rule_c_no_calc_origin',
            'applies_to' => FieldAppliesTo::Calculation,
        ], $admin);
        $draft = FieldSetVersion::query()->where('field_set_id', $fieldSet->id)->firstOrFail();
        foreach (['period_open', 'position_flight_period', 'campaign_period'] as $index => $key) {
            $definition = FieldDefinition::query()->where('key', $key)->firstOrFail();
            FieldSetVersionField::query()->create([
                'field_set_version_id' => $draft->id,
                'field_definition_id' => $definition->id,
                'field_definition_revision_id' => $definition->current_revision_id,
                'sort' => ($index + 1) * 10,
                'required_override' => null,
                'visible_override' => null,
            ]);
        }

        $rule = [
            'condition' => ['op' => 'field_equals', 'field_key' => 'period_open', 'value' => true],
            'action' => ['op' => 'set_visible', 'field_key' => 'position_flight_period', 'value' => false],
        ];

        $this->actingAs($admin)
            ->postJson(route('administration.dynamic-fields.field-sets.versions.rules.preview', [$fieldSet, $draft]), [
                'lock_version' => $fieldSet->fresh()->lock_version,
                'rules' => [$rule],
            ])
            ->assertOk();

        $headerAction = [
            'condition' => ['op' => 'field_not_empty', 'field_key' => 'campaign_period'],
            'action' => ['op' => 'require_field', 'field_key' => 'campaign_period'],
        ];
        $this->actingAs($admin)
            ->postJson(route('administration.dynamic-fields.field-sets.versions.rules.preview', [$fieldSet, $draft]), [
                'lock_version' => $fieldSet->fresh()->lock_version,
                'rules' => [$headerAction],
            ])
            ->assertOk();
    }

    public function test_preview_uses_membership_basis_visible_and_required(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $writer = app(FieldSetVersionAdminWriter::class);
        $fieldSet = $writer->createFreeFieldSet([
            'name' => 'Basis Overrides',
            'key' => 'rule_c_basis',
            'applies_to' => FieldAppliesTo::Calculation,
        ], $admin);
        $draft = FieldSetVersion::query()->where('field_set_id', $fieldSet->id)->firstOrFail();
        $hidden = $this->addBooleanMembership($draft, 'basis_hidden', FieldScope::Header, null, false);
        $required = $this->addBooleanMembership($draft, 'basis_required', FieldScope::Header, true, null);
        $both = $this->addBooleanMembership($draft, 'basis_required_hidden', FieldScope::Header, true, false);
        $extra = $this->addBooleanMembership($draft, 'basis_extra', FieldScope::Header);

        $lock = (int) $fieldSet->fresh()->lock_version;
        $emptyPreview = $this->actingAs($admin)
            ->postJson(route('administration.dynamic-fields.field-sets.versions.rules.preview', [$fieldSet, $draft]), [
                'lock_version' => $lock,
                'rules' => [],
            ])
            ->assertOk()
            ->json();

        $this->assertFalse($emptyPreview['effective_visible']['header']['basis_hidden']);
        $this->assertTrue($emptyPreview['effective_required']['header']['basis_required']);
        $this->assertFalse($emptyPreview['effective_visible']['header']['basis_required_hidden']);
        $this->assertFalse($emptyPreview['effective_required']['header']['basis_required_hidden']);

        $rule = [
            'condition' => ['op' => 'field_equals', 'field_key' => 'basis_extra', 'value' => true],
            'action' => ['op' => 'require_field', 'field_key' => 'basis_required'],
        ];
        $withRule = $this->actingAs($admin)
            ->postJson(route('administration.dynamic-fields.field-sets.versions.rules.preview', [$fieldSet, $draft]), [
                'lock_version' => $lock,
                'rules' => [$rule],
                'example_values' => [
                    'header' => ['basis_extra' => true],
                ],
            ])
            ->assertOk()
            ->json();

        $this->assertTrue($withRule['matched'][0]);
        $this->assertTrue($withRule['effective_required']['header']['basis_required']);

        $this->actingAs($admin)
            ->putJson(route('administration.dynamic-fields.field-sets.versions.rules.replace', [$fieldSet, $draft]), [
                'lock_version' => $lock,
                'fingerprint' => $withRule['fingerprint'],
                'rules' => [$rule],
            ])
            ->assertOk();

        $this->actingAs($admin)
            ->post(route('administration.dynamic-fields.field-sets.versions.activate', [$fieldSet, $draft]), [
                'lock_version' => $fieldSet->fresh()->lock_version,
            ])
            ->assertRedirect();

        $snapshot = app(ConfigurationSnapshotMaterializer::class)
            ->materializeFromActiveSet($fieldSet->key);
        $defs = $snapshot->fieldDefinitions->keyBy('key');

        $this->assertFalse((bool) $defs['basis_hidden']->visible);
        $this->assertTrue((bool) $defs['basis_required']->required);
        $this->assertFalse((bool) $defs['basis_required_hidden']->visible);
        $this->assertTrue((bool) $defs['basis_required_hidden']->required);

        $this->assertSame(
            (bool) $emptyPreview['effective_visible']['header']['basis_hidden'],
            (bool) $defs['basis_hidden']->visible,
        );
        $this->assertSame(
            (bool) $emptyPreview['effective_required']['header']['basis_required'],
            (bool) $defs['basis_required']->required,
        );

        unset($hidden, $required, $both, $extra);
    }

    public function test_valid_system_core_rules_can_materialize_after_editor_apply(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        [$fieldSet, $draft] = $this->createCalculationCoreDraft($admin);
        $seed = $this->seedRulePayload();

        $preview = $this->actingAs($admin)
            ->postJson(route('administration.dynamic-fields.field-sets.versions.rules.preview', [$fieldSet, $draft]), [
                'lock_version' => $fieldSet->fresh()->lock_version,
                'rules' => [$seed],
            ])
            ->assertOk()
            ->json();

        $this->actingAs($admin)
            ->putJson(route('administration.dynamic-fields.field-sets.versions.rules.replace', [$fieldSet, $draft]), [
                'lock_version' => $fieldSet->fresh()->lock_version,
                'fingerprint' => $preview['fingerprint'],
                'rules' => [$seed],
            ])
            ->assertOk();

        $this->actingAs($admin)
            ->post(route('administration.dynamic-fields.field-sets.versions.activate', [$fieldSet, $draft]), [
                'lock_version' => $fieldSet->fresh()->lock_version,
            ])
            ->assertRedirect();

        $calcSnapshot = app(ConfigurationSnapshotMaterializer::class)
            ->materializeFromActiveSet(AdminFieldSetCatalog::CALCULATION_CORE);
        $this->assertGreaterThan(0, $calcSnapshot->rules()->count());

        $dispoSnapshot = app(DispoConfigurationSnapshotComposer::class)
            ->composeFromCalculationSnapshot($calcSnapshot);
        $this->assertNotNull($dispoSnapshot->id);
    }

    public function test_activation_rejects_calc_origin_action_inserted_bypassing_editor(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        [$fieldSet, $draft] = $this->createCalculationCoreDraft($admin);
        $seed = $this->seedRulePayload();

        FieldRule::query()->where('field_set_version_id', $draft->id)->delete();
        FieldRule::query()->create([
            'field_set_version_id' => $draft->id,
            'sort' => 0,
            'condition_json' => $seed['condition'],
            'action_json' => $seed['action'],
        ]);
        FieldRule::query()->create([
            'field_set_version_id' => $draft->id,
            'sort' => 10,
            'condition_json' => [
                'op' => 'field_equals',
                'field_key' => 'period_open',
                'value' => true,
            ],
            'action_json' => [
                'op' => 'require_field',
                'field_key' => 'campaign_period',
            ],
        ]);

        $this->actingAs($admin)
            ->post(route('administration.dynamic-fields.field-sets.versions.activate', [$fieldSet, $draft]), [
                'lock_version' => $fieldSet->fresh()->lock_version,
            ])
            ->assertSessionHasErrors();
    }

    public function test_identical_seed_content_in_free_fieldset_is_editable(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $writer = app(FieldSetVersionAdminWriter::class);
        $fieldSet = $writer->createFreeFieldSet([
            'name' => 'Freier Seed-Clone',
            'key' => 'rule_c_seed_clone',
            'applies_to' => FieldAppliesTo::Calculation,
        ], $admin);
        $draft = FieldSetVersion::query()->where('field_set_id', $fieldSet->id)->firstOrFail();
        $periodOpen = FieldDefinition::query()->where('key', 'period_open')->firstOrFail();
        $flight = FieldDefinition::query()->where('key', 'position_flight_period')->firstOrFail();
        FieldSetVersionField::query()->create([
            'field_set_version_id' => $draft->id,
            'field_definition_id' => $periodOpen->id,
            'field_definition_revision_id' => $periodOpen->current_revision_id,
            'sort' => 10,
            'required_override' => null,
            'visible_override' => null,
        ]);
        FieldSetVersionField::query()->create([
            'field_set_version_id' => $draft->id,
            'field_definition_id' => $flight->id,
            'field_definition_revision_id' => $flight->current_revision_id,
            'sort' => 20,
            'required_override' => null,
            'visible_override' => null,
        ]);

        $seed = $this->seedRulePayload();
        $preview = $this->actingAs($admin)
            ->postJson(route('administration.dynamic-fields.field-sets.versions.rules.preview', [$fieldSet, $draft]), [
                'lock_version' => $fieldSet->fresh()->lock_version,
                'rules' => [$seed],
            ])
            ->assertOk()
            ->json();

        $this->assertFalse($preview['rules'][0]['is_system_seed']);

        $this->actingAs($admin)
            ->putJson(route('administration.dynamic-fields.field-sets.versions.rules.replace', [$fieldSet, $draft]), [
                'lock_version' => $fieldSet->fresh()->lock_version,
                'fingerprint' => $preview['fingerprint'],
                'rules' => [$seed],
            ])
            ->assertOk();

        $emptyPreview = $this->actingAs($admin)
            ->postJson(route('administration.dynamic-fields.field-sets.versions.rules.preview', [$fieldSet, $draft->fresh()]), [
                'lock_version' => $fieldSet->fresh()->lock_version,
                'rules' => [],
            ])
            ->assertOk()
            ->json();

        $this->actingAs($admin)
            ->putJson(route('administration.dynamic-fields.field-sets.versions.rules.replace', [$fieldSet, $draft]), [
                'lock_version' => $fieldSet->fresh()->lock_version,
                'fingerprint' => $emptyPreview['fingerprint'],
                'rules' => [],
            ])
            ->assertOk()
            ->assertJsonPath('has_changes', true);
    }

    public function test_lock_conflict_and_preview_does_not_persist(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $writer = app(FieldSetVersionAdminWriter::class);
        $fieldSet = $writer->createFreeFieldSet([
            'name' => 'Lock Regelset',
            'key' => 'rule_c_lock',
            'applies_to' => FieldAppliesTo::Calculation,
        ], $admin);
        $draft = FieldSetVersion::query()->where('field_set_id', $fieldSet->id)->firstOrFail();
        $this->addBooleanMembership($draft, 'lock_flag', FieldScope::Header);

        $rule = [
            'condition' => [
                'op' => 'field_empty',
                'field_key' => 'lock_flag',
            ],
            'action' => [
                'op' => 'require_field',
                'field_key' => 'lock_flag',
            ],
        ];

        $countBefore = FieldRule::query()->where('field_set_version_id', $draft->id)->count();
        $this->actingAs($admin)
            ->postJson(route('administration.dynamic-fields.field-sets.versions.rules.preview', [$fieldSet, $draft]), [
                'lock_version' => $fieldSet->fresh()->lock_version,
                'rules' => [$rule],
            ])
            ->assertOk();
        $this->assertSame($countBefore, FieldRule::query()->where('field_set_version_id', $draft->id)->count());

        $this->actingAs($admin)
            ->postJson(route('administration.dynamic-fields.field-sets.versions.rules.preview', [$fieldSet, $draft]), [
                'lock_version' => $fieldSet->fresh()->lock_version + 99,
                'rules' => [$rule],
            ])
            ->assertStatus(409);
    }

    public function test_rejects_unknown_ops_nested_groups_duplicate_and_client_sort(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $writer = app(FieldSetVersionAdminWriter::class);
        $fieldSet = $writer->createFreeFieldSet([
            'name' => 'Validierung',
            'key' => 'rule_c_validation',
            'applies_to' => FieldAppliesTo::Calculation,
        ], $admin);
        $draft = FieldSetVersion::query()->where('field_set_id', $fieldSet->id)->firstOrFail();
        $this->addBooleanMembership($draft, 'flag_a', FieldScope::Header);
        $this->addBooleanMembership($draft, 'flag_b', FieldScope::Header);

        $lock = $fieldSet->fresh()->lock_version;

        $this->actingAs($admin)
            ->postJson(route('administration.dynamic-fields.field-sets.versions.rules.preview', [$fieldSet, $draft]), [
                'lock_version' => $lock,
                'rules' => [[
                    'condition' => ['op' => 'field_gt', 'field_key' => 'flag_a', 'value' => 1],
                    'action' => ['op' => 'require_field', 'field_key' => 'flag_b'],
                ]],
            ])
            ->assertStatus(422);

        $this->actingAs($admin)
            ->postJson(route('administration.dynamic-fields.field-sets.versions.rules.preview', [$fieldSet, $draft]), [
                'lock_version' => $lock,
                'rules' => [[
                    'sort' => 5,
                    'condition' => ['op' => 'field_equals', 'field_key' => 'flag_a', 'value' => true],
                    'action' => ['op' => 'require_field', 'field_key' => 'flag_b'],
                ]],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['rules.0.sort']);

        $this->actingAs($admin)
            ->postJson(route('administration.dynamic-fields.field-sets.versions.rules.preview', [$fieldSet, $draft]), [
                'lock_version' => $lock,
                'rules' => [[
                    'condition' => [
                        'op' => 'all',
                        'conditions' => [
                            ['op' => 'all', 'conditions' => [
                                ['op' => 'field_equals', 'field_key' => 'flag_a', 'value' => true],
                                ['op' => 'field_equals', 'field_key' => 'flag_b', 'value' => false],
                            ]],
                            ['op' => 'field_equals', 'field_key' => 'flag_b', 'value' => true],
                        ],
                    ],
                    'action' => ['op' => 'require_field', 'field_key' => 'flag_b'],
                ]],
            ])
            ->assertStatus(422);

        $dup = [
            'condition' => ['op' => 'field_equals', 'field_key' => 'flag_a', 'value' => true],
            'action' => ['op' => 'require_field', 'field_key' => 'flag_b'],
        ];
        $this->actingAs($admin)
            ->postJson(route('administration.dynamic-fields.field-sets.versions.rules.preview', [$fieldSet, $draft]), [
                'lock_version' => $lock,
                'rules' => [$dup, $dup],
            ])
            ->assertStatus(422);
    }

    public function test_choice_options_require_active_keys_and_select_multi_ops(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $writer = app(FieldSetVersionAdminWriter::class);
        $fieldSet = $writer->createFreeFieldSet([
            'name' => 'Choice Regeln',
            'key' => 'rule_c_choice',
            'applies_to' => FieldAppliesTo::Calculation,
        ], $admin);
        $draft = FieldSetVersion::query()->where('field_set_id', $fieldSet->id)->firstOrFail();
        $this->addSelectMembership($draft, 'color', FieldScope::Header, [
            ['key' => 'red', 'label' => 'Rot', 'sort' => 0, 'is_active' => true],
            ['key' => 'blue', 'label' => 'Blau', 'sort' => 1, 'is_active' => false],
        ]);
        $this->addMultiSelectMembership($draft, 'tags', FieldScope::Header, [
            ['key' => 'a', 'label' => 'A', 'sort' => 0, 'is_active' => true],
        ]);

        $lock = $fieldSet->fresh()->lock_version;

        $this->actingAs($admin)
            ->postJson(route('administration.dynamic-fields.field-sets.versions.rules.preview', [$fieldSet, $draft]), [
                'lock_version' => $lock,
                'rules' => [[
                    'condition' => ['op' => 'field_equals', 'field_key' => 'color', 'value' => 'blue'],
                    'action' => ['op' => 'require_field', 'field_key' => 'tags'],
                ]],
            ])
            ->assertStatus(422);

        $this->actingAs($admin)
            ->postJson(route('administration.dynamic-fields.field-sets.versions.rules.preview', [$fieldSet, $draft]), [
                'lock_version' => $lock,
                'rules' => [[
                    'condition' => ['op' => 'field_equals', 'field_key' => 'tags', 'value' => 'a'],
                    'action' => ['op' => 'require_field', 'field_key' => 'color'],
                ]],
            ])
            ->assertStatus(422);

        $this->actingAs($admin)
            ->postJson(route('administration.dynamic-fields.field-sets.versions.rules.preview', [$fieldSet, $draft]), [
                'lock_version' => $lock,
                'rules' => [[
                    'condition' => ['op' => 'field_contains', 'field_key' => 'tags', 'value' => 'a'],
                    'action' => ['op' => 'set_visible', 'field_key' => 'color', 'value' => false],
                ]],
            ])
            ->assertOk();
    }

    public function test_draft_copy_keeps_rules_and_activation_uses_them(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $writer = app(FieldSetVersionAdminWriter::class);
        $fieldSet = $writer->createFreeFieldSet([
            'name' => 'Copy Regeln',
            'key' => 'rule_c_copy',
            'applies_to' => FieldAppliesTo::Calculation,
        ], $admin);
        $draft = FieldSetVersion::query()->where('field_set_id', $fieldSet->id)->firstOrFail();
        $this->addBooleanMembership($draft, 'copy_flag', FieldScope::Header);

        $rule = [
            'condition' => ['op' => 'field_equals', 'field_key' => 'copy_flag', 'value' => false],
            'action' => ['op' => 'require_field', 'field_key' => 'copy_flag'],
        ];
        $preview = $this->actingAs($admin)
            ->postJson(route('administration.dynamic-fields.field-sets.versions.rules.preview', [$fieldSet, $draft]), [
                'lock_version' => $fieldSet->fresh()->lock_version,
                'rules' => [$rule],
            ])
            ->assertOk()
            ->json();

        $this->actingAs($admin)
            ->putJson(route('administration.dynamic-fields.field-sets.versions.rules.replace', [$fieldSet, $draft]), [
                'lock_version' => $fieldSet->fresh()->lock_version,
                'fingerprint' => $preview['fingerprint'],
                'rules' => [$rule],
            ])
            ->assertOk();

        $this->actingAs($admin)
            ->post(route('administration.dynamic-fields.field-sets.versions.activate', [$fieldSet, $draft]), [
                'lock_version' => $fieldSet->fresh()->lock_version,
            ])
            ->assertRedirect();

        $fieldSet->refresh();
        $active = FieldSetVersion::query()->whereKey($fieldSet->active_version_id)->firstOrFail();
        $this->assertSame(1, $active->rules()->count());

        $newDraft = $writer->createDraftFromVersion($fieldSet, $active, $admin, (int) $fieldSet->fresh()->lock_version);
        $this->assertSame(1, $newDraft->rules()->count());
        $this->assertSame(
            FieldRuleContract::dedupePayload($rule['condition'], $rule['action']),
            FieldRuleContract::dedupePayload(
                $newDraft->rules->first()->condition_json,
                $newDraft->rules->first()->action_json,
            ),
        );
    }

    /**
     * @return array{condition: array<string, mixed>, action: array<string, mixed>}
     */
    private function seedRulePayload(): array
    {
        return [
            'condition' => [
                'op' => 'field_equals',
                'field_key' => 'period_open',
                'value' => false,
            ],
            'action' => [
                'op' => 'require_field',
                'field_key' => 'position_flight_period',
            ],
        ];
    }

    /**
     * @return array{0: FieldSet, 1: FieldSetVersion}
     */
    private function createCalculationCoreDraft(User $admin): array
    {
        $fieldSet = FieldSet::query()->where('key', AdminFieldSetCatalog::CALCULATION_CORE)->firstOrFail();
        $active = FieldSetVersion::query()->whereKey($fieldSet->active_version_id)->firstOrFail();
        $draft = app(FieldSetVersionAdminWriter::class)
            ->createDraftFromVersion($fieldSet, $active, $admin, (int) $fieldSet->lock_version);

        return [$fieldSet->fresh(), $draft];
    }

    private function addBooleanMembership(
        FieldSetVersion $draft,
        string $key,
        FieldScope $scope,
        ?bool $requiredOverride = null,
        ?bool $visibleOverride = null,
    ): FieldSetVersionField {
        $definition = FieldDefinition::query()->create([
            'key' => $key,
            'field_type' => FieldType::Boolean,
            'scope' => $scope,
            'applies_to' => FieldAppliesTo::Calculation,
            'is_system' => false,
            'is_active' => true,
            'lock_version' => 1,
        ]);
        $revision = FieldDefinitionRevision::query()->create([
            'field_definition_id' => $definition->id,
            'revision' => 1,
            'label' => $key,
            'help_text' => null,
            'validation_json' => null,
            'group_key' => 'test',
            'sort_default' => 10,
            'reportable' => false,
            'created_at' => now(),
        ]);
        $definition->current_revision_id = $revision->id;
        $definition->save();

        return FieldSetVersionField::query()->create([
            'field_set_version_id' => $draft->id,
            'field_definition_id' => $definition->id,
            'field_definition_revision_id' => $revision->id,
            'sort' => 10,
            'required_override' => $requiredOverride,
            'visible_override' => $visibleOverride,
        ]);
    }

    private function addPeriodMembership(FieldSetVersion $draft, string $key, FieldScope $scope): void
    {
        $definition = FieldDefinition::query()->create([
            'key' => $key,
            'field_type' => FieldType::Period,
            'scope' => $scope,
            'applies_to' => FieldAppliesTo::Calculation,
            'is_system' => false,
            'is_active' => true,
            'lock_version' => 1,
        ]);
        $revision = FieldDefinitionRevision::query()->create([
            'field_definition_id' => $definition->id,
            'revision' => 1,
            'label' => $key,
            'help_text' => null,
            'validation_json' => null,
            'group_key' => 'test',
            'sort_default' => 20,
            'reportable' => false,
            'created_at' => now(),
        ]);
        $definition->current_revision_id = $revision->id;
        $definition->save();

        FieldSetVersionField::query()->create([
            'field_set_version_id' => $draft->id,
            'field_definition_id' => $definition->id,
            'field_definition_revision_id' => $revision->id,
            'sort' => 20,
            'required_override' => null,
            'visible_override' => null,
        ]);
    }

    /**
     * @param  list<array{key: string, label: string, sort: int, is_active: bool}>  $options
     */
    private function addSelectMembership(FieldSetVersion $draft, string $key, FieldScope $scope, array $options): void
    {
        $this->addChoiceMembership($draft, $key, $scope, FieldType::Select, $options);
    }

    /**
     * @param  list<array{key: string, label: string, sort: int, is_active: bool}>  $options
     */
    private function addMultiSelectMembership(FieldSetVersion $draft, string $key, FieldScope $scope, array $options): void
    {
        $this->addChoiceMembership($draft, $key, $scope, FieldType::MultiSelect, $options);
    }

    /**
     * @param  list<array{key: string, label: string, sort: int, is_active: bool}>  $options
     */
    private function addChoiceMembership(
        FieldSetVersion $draft,
        string $key,
        FieldScope $scope,
        FieldType $type,
        array $options,
    ): void {
        $definition = FieldDefinition::query()->create([
            'key' => $key,
            'field_type' => $type,
            'scope' => $scope,
            'applies_to' => FieldAppliesTo::Calculation,
            'is_system' => false,
            'is_active' => true,
            'lock_version' => 1,
        ]);
        $revision = FieldDefinitionRevision::query()->create([
            'field_definition_id' => $definition->id,
            'revision' => 1,
            'label' => $key,
            'help_text' => null,
            'validation_json' => null,
            'group_key' => 'test',
            'sort_default' => 30,
            'reportable' => false,
            'created_at' => now(),
        ]);
        foreach ($options as $option) {
            FieldDefinitionRevisionOption::query()->create([
                'field_definition_revision_id' => $revision->id,
                'key' => $option['key'],
                'label' => $option['label'],
                'sort' => $option['sort'],
                'is_active' => $option['is_active'],
            ]);
        }
        $definition->current_revision_id = $revision->id;
        $definition->save();

        FieldSetVersionField::query()->create([
            'field_set_version_id' => $draft->id,
            'field_definition_id' => $definition->id,
            'field_definition_revision_id' => $revision->id,
            'sort' => 30,
            'required_override' => null,
            'visible_override' => null,
        ]);
    }
}
