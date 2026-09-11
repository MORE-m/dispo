<?php

namespace Tests\Feature\DynamicField;

use App\Enums\FieldAppliesTo;
use App\Enums\FieldScope;
use App\Enums\FieldType;
use App\Enums\Role;
use App\Models\AuditEvent;
use App\Models\FieldDefinition;
use App\Models\FieldDefinitionRevision;
use App\Models\FieldDefinitionRevisionOption;
use App\Models\FieldSetVersionField;
use App\Models\User;
use App\Services\DynamicField\Admin\FieldDefinitionCustomWriter;
use App\Services\DynamicField\Admin\FieldDefinitionOptionsWriter;
use App\Services\DynamicField\Admin\FieldSetVersionAdminWriter;
use App\Support\DynamicField\FieldDefinitionOptionContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * DF-3-REST-B: Options-Admin-UI HTTP + Choice-Type-Freigabe.
 */
class FieldDefinitionOptionsAdminRestBTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_from_options_endpoints(): void
    {
        $definition = $this->createSelectDefinition('guest_choice');

        $this->postJson(route('administration.dynamic-fields.definitions.options.preview', $definition), [
            'lock_version' => 1,
            'options' => [],
        ])->assertUnauthorized();

        $this->putJson(route('administration.dynamic-fields.definitions.options.replace', $definition), [
            'lock_version' => 1,
            'fingerprint' => str_repeat('a', 64),
            'options' => [],
        ])->assertUnauthorized();
    }

    public function test_non_admin_receives_403(): void
    {
        $sales = User::factory()->role(Role::Sales)->create();
        $definition = $this->createSelectDefinition('sales_choice');

        $this->actingAs($sales)
            ->postJson(route('administration.dynamic-fields.definitions.options.preview', $definition), [
                'lock_version' => $definition->lock_version,
                'options' => [],
            ])
            ->assertForbidden();
    }

    public function test_custom_choice_show_includes_options_props(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $definition = $this->createSelectDefinition('show_choice');
        app(FieldDefinitionOptionsWriter::class)->replace($definition, [
            'lock_version' => $definition->lock_version,
            'options' => [
                ['key' => 'alpha', 'label' => 'Alpha', 'sort' => 1, 'is_active' => true],
            ],
        ], $admin);
        $definition->refresh();

        $this->actingAs($admin)
            ->get(route('administration.dynamic-fields.definitions.show', $definition))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('administration/dynamic-fields/definitions/show')
                ->where('definition.can_manage_options', true)
                ->where('definition.options.0.key', 'alpha')
                ->has('definition.options_fingerprint')
                ->has('routes.optionsPreview')
                ->has('routes.optionsReplace')
                ->where('optionsBoundaryNote', fn (string $note): bool => str_contains($note, 'gepinnten')));
    }

    public function test_system_definition_options_endpoints_return_404(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $system = FieldDefinition::query()
            ->where('is_system', true)
            ->where('is_key_protected', true)
            ->firstOrFail();

        $this->actingAs($admin)
            ->postJson(route('administration.dynamic-fields.definitions.options.preview', $system), [
                'lock_version' => $system->lock_version,
                'options' => [
                    ['key' => 'x', 'label' => 'X', 'sort' => 1, 'is_active' => true],
                ],
            ])
            ->assertNotFound();
    }

    public function test_non_choice_cannot_manage_options(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $definition = app(FieldDefinitionCustomWriter::class)->create([
            'label' => 'Nur Text',
            'key' => 'nur_text_restb',
            'field_type' => FieldType::ShortText,
            'scope' => FieldScope::Header,
            'applies_to' => FieldAppliesTo::Both,
            'max_length' => 80,
        ], $admin);

        $this->actingAs($admin)
            ->get(route('administration.dynamic-fields.definitions.show', $definition))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('definition.can_manage_options', false)
                ->where('definition.options', []));

        $this->actingAs($admin)
            ->postJson(route('administration.dynamic-fields.definitions.options.preview', $definition), [
                'lock_version' => $definition->lock_version,
                'options' => [
                    ['key' => 'x', 'label' => 'X', 'sort' => 1, 'is_active' => true],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['definition']);
    }

    public function test_preview_and_apply_happy_path_and_noop(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $definition = $this->createSelectDefinition('preview_apply');

        $preview = $this->actingAs($admin)
            ->postJson(route('administration.dynamic-fields.definitions.options.preview', $definition), [
                'lock_version' => $definition->lock_version,
                'options' => [
                    ['key' => 'one', 'label' => 'Eins', 'sort' => 10, 'is_active' => true],
                    ['key' => 'two', 'label' => 'Zwei', 'sort' => 20, 'is_active' => true],
                ],
            ])
            ->assertOk()
            ->json();

        $this->assertTrue($preview['has_changes']);
        $this->assertSame(['one', 'two'], $preview['summary']['added']);
        $this->assertNotEmpty($preview['fingerprint']);

        $apply = $this->actingAs($admin)
            ->putJson(route('administration.dynamic-fields.definitions.options.replace', $definition), [
                'lock_version' => $definition->lock_version,
                'fingerprint' => $preview['fingerprint'],
                'options' => [
                    ['key' => 'one', 'label' => 'Eins', 'sort' => 10, 'is_active' => true],
                    ['key' => 'two', 'label' => 'Zwei', 'sort' => 20, 'is_active' => true],
                ],
            ])
            ->assertOk()
            ->json();

        $this->assertTrue($apply['has_changes']);
        $this->assertSame('Auswahloptionen übernommen.', $apply['message']);
        $definition->refresh();
        $this->assertSame(2, (int) $definition->lock_version);
        $this->assertSame(2, (int) $definition->currentRevision?->revision);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'field_definition.options_replaced',
        ]);

        $auditsBefore = AuditEvent::query()->where('action', 'field_definition.options_replaced')->count();
        $noopPreview = $this->actingAs($admin)
            ->postJson(route('administration.dynamic-fields.definitions.options.preview', $definition), [
                'lock_version' => $definition->lock_version,
                'options' => [
                    ['key' => 'one', 'label' => 'Eins', 'sort' => 10, 'is_active' => true],
                    ['key' => 'two', 'label' => 'Zwei', 'sort' => 20, 'is_active' => true],
                ],
            ])
            ->assertOk()
            ->json();
        $this->assertFalse($noopPreview['has_changes']);
        $this->assertTrue($noopPreview['summary']['unchanged']);

        $noop = $this->actingAs($admin)
            ->putJson(route('administration.dynamic-fields.definitions.options.replace', $definition), [
                'lock_version' => $definition->lock_version,
                'fingerprint' => $noopPreview['fingerprint'],
                'options' => [
                    ['key' => 'one', 'label' => 'Eins', 'sort' => 10, 'is_active' => true],
                    ['key' => 'two', 'label' => 'Zwei', 'sort' => 20, 'is_active' => true],
                ],
            ])
            ->assertOk()
            ->json();

        $definition->refresh();
        $this->assertFalse($noop['has_changes']);
        $this->assertSame('Keine Änderungen', $noop['message']);
        $this->assertSame(2, (int) $definition->lock_version);
        $this->assertSame(2, (int) $definition->currentRevision?->revision);
        $this->assertSame(
            $auditsBefore,
            AuditEvent::query()->where('action', 'field_definition.options_replaced')->count(),
        );
    }

    public function test_stale_lock_and_fingerprint_return_409(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $definition = $this->createSelectDefinition('conflict_choice');
        $options = [
            ['key' => 'only', 'label' => 'Only', 'sort' => 1, 'is_active' => true],
        ];
        $preview = app(FieldDefinitionOptionsWriter::class)->preview($definition, $options);

        $this->actingAs($admin)
            ->putJson(route('administration.dynamic-fields.definitions.options.replace', $definition), [
                'lock_version' => ((int) $definition->lock_version) + 1,
                'fingerprint' => $preview['fingerprint'],
                'options' => $options,
            ])
            ->assertStatus(409)
            ->assertJsonFragment(['message' => 'Die Felddefinition wurde parallel geändert. Bitte die Seite neu laden.']);

        $this->actingAs($admin)
            ->putJson(route('administration.dynamic-fields.definitions.options.replace', $definition), [
                'lock_version' => $definition->lock_version,
                'fingerprint' => str_repeat('b', 64),
                'options' => $options,
            ])
            ->assertStatus(409)
            ->assertJsonFragment(['message' => 'Die Optionsvorschau ist veraltet. Bitte die Seite neu laden.']);
    }

    public function test_validation_errors_for_key_limits_sort_and_is_active(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $definition = $this->createSelectDefinition('validation_choice');

        $this->actingAs($admin)
            ->postJson(route('administration.dynamic-fields.definitions.options.preview', $definition), [
                'lock_version' => $definition->lock_version,
                'options' => [
                    ['key' => 'Bad-Key', 'label' => 'X', 'sort' => 1, 'is_active' => true],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['options.0.key']);

        $this->actingAs($admin)
            ->postJson(route('administration.dynamic-fields.definitions.options.preview', $definition), [
                'lock_version' => $definition->lock_version,
                'options' => [
                    ['key' => 'dup', 'label' => 'A', 'sort' => 1, 'is_active' => true],
                    ['key' => 'dup', 'label' => 'B', 'sort' => 2, 'is_active' => true],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['options.1.key']);

        $tooMany = [];
        for ($i = 0; $i < 101; $i++) {
            $tooMany[] = [
                'key' => 'o'.$i,
                'label' => 'L'.$i,
                'sort' => $i,
                'is_active' => true,
            ];
        }
        $this->actingAs($admin)
            ->postJson(route('administration.dynamic-fields.definitions.options.preview', $definition), [
                'lock_version' => $definition->lock_version,
                'options' => $tooMany,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['options']);

        $this->actingAs($admin)
            ->postJson(route('administration.dynamic-fields.definitions.options.preview', $definition), [
                'lock_version' => $definition->lock_version,
                'options' => [
                    ['key' => 'long', 'label' => str_repeat('x', 256), 'sort' => 1, 'is_active' => true],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['options.0.label']);

        $this->actingAs($admin)
            ->postJson(route('administration.dynamic-fields.definitions.options.preview', $definition), [
                'lock_version' => $definition->lock_version,
                'options' => [
                    ['key' => 'sorty', 'label' => 'S', 'sort' => '1', 'is_active' => true],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['options.0.sort']);

        $this->actingAs($admin)
            ->postJson(route('administration.dynamic-fields.definitions.options.preview', $definition), [
                'lock_version' => $definition->lock_version,
                'options' => [
                    ['key' => 'booly', 'label' => 'B', 'sort' => 1, 'is_active' => 1],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['options.0.is_active']);
    }

    public function test_deactivate_reactivate_and_immutable_key_and_zero_active(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $definition = $this->createSelectDefinition('lifecycle_opts');
        $writer = app(FieldDefinitionOptionsWriter::class);
        $writer->replace($definition, [
            'lock_version' => $definition->lock_version,
            'options' => [
                ['key' => 'keep', 'label' => 'Keep', 'sort' => 1, 'is_active' => true],
                ['key' => 'drop', 'label' => 'Drop', 'sort' => 2, 'is_active' => true],
            ],
        ], $admin);
        $definition->refresh();
        $pinnedRevisionId = (int) $definition->current_revision_id;

        $fieldSets = app(FieldSetVersionAdminWriter::class);
        $fieldSet = $fieldSets->createFreeFieldSet([
            'name' => 'Pin Set',
            'key' => 'pin_set_'.bin2hex(random_bytes(3)),
            'applies_to' => FieldAppliesTo::Both->value,
        ], $admin);
        $draft = $fieldSet->versions()->where('status', 'draft')->firstOrFail();
        $fieldSet->refresh();
        $fieldSets->addCustomMembership(
            $fieldSet,
            $draft,
            [
                'field_definition_id' => $definition->id,
                'field_definition_revision_id' => $pinnedRevisionId,
                'sort' => 10,
                'lock_version' => $fieldSet->lock_version,
            ],
            $admin,
        );

        $preview = $this->actingAs($admin)
            ->postJson(route('administration.dynamic-fields.definitions.options.preview', $definition), [
                'lock_version' => $definition->lock_version,
                'options' => [
                    ['key' => 'keep', 'label' => 'Keep Renamed', 'sort' => 5, 'is_active' => false],
                ],
            ])
            ->assertOk()
            ->json();

        $this->actingAs($admin)
            ->putJson(route('administration.dynamic-fields.definitions.options.replace', $definition), [
                'lock_version' => $definition->lock_version,
                'fingerprint' => $preview['fingerprint'],
                'options' => [
                    ['key' => 'keep', 'label' => 'Keep Renamed', 'sort' => 5, 'is_active' => false],
                ],
            ])
            ->assertOk();

        $definition->refresh();
        $options = FieldDefinitionOptionContract::fromRevisionOptions(
            $definition->currentRevision?->options ?? [],
        );
        $byKey = collect($options)->keyBy('key');
        $this->assertFalse($byKey['keep']['is_active']);
        $this->assertFalse($byKey['drop']['is_active']);
        $this->assertSame('Keep Renamed', $byKey['keep']['label']);
        $this->assertSame(2, FieldDefinitionRevisionOption::query()
            ->where('field_definition_revision_id', $definition->current_revision_id)
            ->count());

        $membership = FieldSetVersionField::query()
            ->where('field_definition_id', $definition->id)
            ->firstOrFail();
        $this->assertSame($pinnedRevisionId, (int) $membership->field_definition_revision_id);

        $reactivatePreview = $this->actingAs($admin)
            ->postJson(route('administration.dynamic-fields.definitions.options.preview', $definition), [
                'lock_version' => $definition->lock_version,
                'options' => [
                    ['key' => 'keep', 'label' => 'Keep Renamed', 'sort' => 5, 'is_active' => true],
                    ['key' => 'drop', 'label' => 'Drop', 'sort' => 2, 'is_active' => false],
                ],
            ])
            ->assertOk()
            ->json();
        $this->assertContains('keep', $reactivatePreview['summary']['reactivated']);

        $this->actingAs($admin)
            ->putJson(route('administration.dynamic-fields.definitions.options.replace', $definition), [
                'lock_version' => $definition->lock_version,
                'fingerprint' => $reactivatePreview['fingerprint'],
                'options' => [
                    ['key' => 'keep', 'label' => 'Keep Renamed', 'sort' => 5, 'is_active' => true],
                    ['key' => 'drop', 'label' => 'Drop', 'sort' => 2, 'is_active' => false],
                ],
            ])
            ->assertOk();

        // null aktive Optionen speicherbar
        $definition->refresh();
        $zeroPreview = app(FieldDefinitionOptionsWriter::class)->preview($definition, [
            ['key' => 'keep', 'label' => 'Keep Renamed', 'sort' => 5, 'is_active' => false],
            ['key' => 'drop', 'label' => 'Drop', 'sort' => 2, 'is_active' => false],
        ]);
        $this->actingAs($admin)
            ->putJson(route('administration.dynamic-fields.definitions.options.replace', $definition), [
                'lock_version' => $definition->lock_version,
                'fingerprint' => $zeroPreview['fingerprint'],
                'options' => [
                    ['key' => 'keep', 'label' => 'Keep Renamed', 'sort' => 5, 'is_active' => false],
                    ['key' => 'drop', 'label' => 'Drop', 'sort' => 2, 'is_active' => false],
                ],
            ])
            ->assertOk();

        $definition->refresh();
        $fieldSet->refresh();
        $draft->refresh();

        // Membership auf die Revision ohne aktive Optionen umbiegen (Pin-Nachweis zuvor).
        $membership->field_definition_revision_id = (int) $definition->current_revision_id;
        $membership->save();

        try {
            $fieldSets->activateDraft($fieldSet, $draft->fresh(), $admin, $fieldSet->lock_version);
            $this->fail('Aktivierung ohne aktive Option muss blockieren.');
        } catch (ValidationException $exception) {
            $this->assertNotEmpty($exception->errors());
        }
    }

    public function test_choice_types_can_be_created_and_revised_with_option_copy(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();

        $this->actingAs($admin)
            ->post(route('administration.dynamic-fields.definitions.store'), [
                'label' => 'Auswahl Select',
                'key' => 'choice_select_restb',
                'field_type' => 'select',
                'scope' => 'header',
                'applies_to' => 'both',
                'sort_default' => 50,
                'reportable' => true,
            ])
            ->assertRedirect();

        $select = FieldDefinition::query()->where('key', 'choice_select_restb')->firstOrFail();
        $this->assertSame(FieldType::Select, $select->field_type);
        $this->assertNull($select->currentRevision?->validation_json);

        $this->actingAs($admin)
            ->post(route('administration.dynamic-fields.definitions.store'), [
                'label' => 'Auswahl Multi',
                'key' => 'choice_multi_restb',
                'field_type' => 'multi_select',
                'scope' => 'position',
                'applies_to' => 'calculation',
                'sort_default' => 60,
                'reportable' => false,
            ])
            ->assertRedirect();

        $multi = FieldDefinition::query()->where('key', 'choice_multi_restb')->firstOrFail();
        $this->assertSame(FieldType::MultiSelect, $multi->field_type);

        app(FieldDefinitionOptionsWriter::class)->replace($select, [
            'lock_version' => $select->lock_version,
            'options' => [
                ['key' => 'a', 'label' => 'A', 'sort' => 1, 'is_active' => true],
                ['key' => 'b', 'label' => 'B', 'sort' => 2, 'is_active' => false],
            ],
        ], $admin);
        $select->refresh();
        $optionCountBefore = FieldDefinitionRevisionOption::query()
            ->where('field_definition_revision_id', $select->current_revision_id)
            ->count();

        $this->actingAs($admin)
            ->postJson(route('administration.dynamic-fields.definitions.revisions.store', $select), [
                'lock_version' => $select->lock_version,
                'label' => 'Auswahl Select v2',
                'help_text' => null,
                'group_key' => null,
                'sort_default' => 50,
                'reportable' => true,
            ])
            ->assertOk();

        $select->refresh();
        $this->assertSame(3, (int) $select->currentRevision?->revision);
        $this->assertSame(
            $optionCountBefore,
            FieldDefinitionRevisionOption::query()
                ->where('field_definition_revision_id', $select->current_revision_id)
                ->count(),
        );
        $copied = FieldDefinitionOptionContract::fromRevisionOptions(
            $select->currentRevision?->options ?? [],
        );
        $this->assertSame('A', $copied[0]['label']);
        $this->assertFalse($copied[1]['is_active']);
    }

    public function test_type_change_only_before_used_and_max_length_rejected_for_choice(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $definition = app(FieldDefinitionCustomWriter::class)->create([
            'label' => 'Wechselbar',
            'key' => 'wechselbar_restb',
            'field_type' => FieldType::ShortText,
            'scope' => FieldScope::Header,
            'applies_to' => FieldAppliesTo::Both,
            'max_length' => 40,
        ], $admin);

        $this->actingAs($admin)
            ->putJson(route('administration.dynamic-fields.definitions.update', $definition), [
                'lock_version' => $definition->lock_version,
                'field_type' => 'select',
                'label' => 'Wechselbar',
            ])
            ->assertOk();

        $definition->refresh();
        $this->assertSame(FieldType::Select, $definition->field_type);
        $this->assertNull($definition->currentRevision?->validation_json);

        $this->actingAs($admin)
            ->putJson(route('administration.dynamic-fields.definitions.update', $definition), [
                'lock_version' => $definition->lock_version,
                'label' => 'Wechselbar',
                'max_length' => 20,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['max_length']);

        // Nutzung erzeugen → Typänderung blockiert
        $fieldSets = app(FieldSetVersionAdminWriter::class);
        $fieldSet = $fieldSets->createFreeFieldSet([
            'name' => 'Used Set',
            'key' => 'used_set_'.bin2hex(random_bytes(3)),
            'applies_to' => FieldAppliesTo::Both->value,
        ], $admin);
        $draft = $fieldSet->versions()->where('status', 'draft')->firstOrFail();
        $fieldSet->refresh();
        app(FieldDefinitionOptionsWriter::class)->replace($definition, [
            'lock_version' => $definition->lock_version,
            'options' => [
                ['key' => 'x', 'label' => 'X', 'sort' => 1, 'is_active' => true],
            ],
        ], $admin);
        $definition->refresh();
        $fieldSets->addCustomMembership(
            $fieldSet,
            $draft,
            [
                'field_definition_id' => $definition->id,
                'field_definition_revision_id' => (int) $definition->current_revision_id,
                'sort' => 1,
                'lock_version' => $fieldSet->lock_version,
            ],
            $admin,
        );

        $definition->refresh();
        $this->actingAs($admin)
            ->putJson(route('administration.dynamic-fields.definitions.update', $definition), [
                'lock_version' => $definition->lock_version,
                'field_type' => 'short_text',
                'label' => 'Wechselbar',
                'max_length' => 40,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['definition']);
    }

    public function test_text_contracts_remain_unchanged(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $definition = app(FieldDefinitionCustomWriter::class)->create([
            'label' => 'Text bleibt',
            'key' => 'text_bleibt_restb',
            'field_type' => FieldType::ShortText,
            'scope' => FieldScope::Header,
            'applies_to' => FieldAppliesTo::Both,
            'max_length' => 12,
        ], $admin);

        $this->assertSame(
            ['max_length' => 12],
            $definition->currentRevision?->validation_json,
        );
    }

    private function createSelectDefinition(string $key): FieldDefinition
    {
        $definition = new FieldDefinition;
        $definition->key = $key;
        $definition->field_type = FieldType::Select;
        $definition->is_system = false;
        $definition->is_key_protected = false;
        $definition->scope = FieldScope::Header;
        $definition->applies_to = FieldAppliesTo::Calculation;
        $definition->is_active = true;
        $definition->lock_version = 1;
        $definition->save();

        $revision = new FieldDefinitionRevision;
        $revision->field_definition_id = $definition->id;
        $revision->revision = 1;
        $revision->label = 'Auswahl '.$key;
        $revision->help_text = null;
        $revision->validation_json = null;
        $revision->group_key = null;
        $revision->sort_default = 0;
        $revision->reportable = true;
        $revision->created_at = now();
        $revision->save();

        $definition->current_revision_id = $revision->id;
        $definition->save();

        return $definition->fresh() ?? $definition;
    }
}
