<?php

namespace Tests\Feature\DynamicField;

use App\Enums\FieldAppliesTo;
use App\Enums\FieldScope;
use App\Enums\FieldType;
use App\Enums\Role;
use App\Models\FieldDefinition;
use App\Models\FieldSet;
use App\Models\FieldSetVersion;
use App\Models\User;
use App\Services\DynamicField\Admin\AdminFieldSetCatalog;
use App\Services\DynamicField\Admin\FieldDefinitionCustomWriter;
use App\Services\DynamicField\Admin\FieldSetVersionAdminWriter;
use App\Support\DynamicField\FieldRuleContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class DynamicFieldFileTypeAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_file_field_with_dispo_order_applies_to_succeeds(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();

        $definition = app(FieldDefinitionCustomWriter::class)->create([
            'label' => 'Anhang Dispo',
            'field_type' => FieldType::File,
            'scope' => FieldScope::Header,
            'applies_to' => FieldAppliesTo::DispoOrder,
            'allowed_mime_types' => ['application/pdf'],
        ], $admin);

        $this->assertSame(FieldType::File, $definition->field_type);
        $this->assertSame(FieldAppliesTo::DispoOrder, $definition->applies_to);
        $revision = $definition->currentRevision;
        $this->assertNotNull($revision);
        $this->assertSame(['allowed_mime_types' => ['application/pdf']], $revision->validation_json);
    }

    public function test_create_file_field_with_both_applies_to_rejected(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();

        $this->expectException(ValidationException::class);

        app(FieldDefinitionCustomWriter::class)->create([
            'label' => 'Anhang Both',
            'field_type' => FieldType::File,
            'scope' => FieldScope::Header,
            'applies_to' => FieldAppliesTo::Both,
        ], $admin);
    }

    public function test_create_file_field_with_calculation_applies_to_rejected(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();

        $this->expectException(ValidationException::class);

        app(FieldDefinitionCustomWriter::class)->create([
            'label' => 'Anhang Kalkulation',
            'field_type' => FieldType::File,
            'scope' => FieldScope::Header,
            'applies_to' => FieldAppliesTo::Calculation,
        ], $admin);
    }

    public function test_admin_http_create_file_field_with_pdf_allowlist(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();

        $response = $this->actingAs($admin)
            ->post(route('administration.dynamic-fields.definitions.store'), [
                'label' => 'Http Pdf Anhang Test',
                'key' => 'http_pdf_anhang_test',
                'field_type' => FieldType::File->value,
                'scope' => FieldScope::Header->value,
                'applies_to' => FieldAppliesTo::DispoOrder->value,
                'allowed_mime_types' => ['application/pdf'],
                'sort_default' => 50,
            ]);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect();

        $definition = FieldDefinition::query()->where('key', 'http_pdf_anhang_test')->firstOrFail();
        $this->assertSame(FieldType::File, $definition->field_type);
        $this->assertSame(['allowed_mime_types' => ['application/pdf']], $definition->currentRevision?->validation_json);
    }

    public function test_require_field_rule_on_file_target_rejected(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $definition = app(FieldDefinitionCustomWriter::class)->create([
            'label' => 'Datei Regel',
            'field_type' => FieldType::File,
            'scope' => FieldScope::Header,
            'applies_to' => FieldAppliesTo::DispoOrder,
        ], $admin);

        $defsByKey = [
            $definition->key => (object) [
                'key' => $definition->key,
                'field_type' => FieldType::File,
                'scope' => FieldScope::Header,
                'options_json' => null,
                'action_target_readonly' => false,
            ],
        ];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Datei-Felder können in V1/01c nicht per require_field-Regel verpflichtet werden.');

        FieldRuleContract::assertRuleStructure(
            $defsByKey,
            [
                'op' => FieldRuleContract::CONDITION_FIELD_EMPTY,
                'field_key' => $definition->key,
            ],
            [
                'op' => FieldRuleContract::ACTION_REQUIRE_FIELD,
                'field_key' => $definition->key,
            ],
        );
    }

    public function test_membership_required_override_true_on_file_rejected(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $definition = app(FieldDefinitionCustomWriter::class)->create([
            'label' => 'Datei Pflicht',
            'field_type' => FieldType::File,
            'scope' => FieldScope::Header,
            'applies_to' => FieldAppliesTo::DispoOrder,
        ], $admin);

        $fieldSet = FieldSet::query()->where('key', AdminFieldSetCatalog::DISPO_ORDER_CORE)->firstOrFail();
        $writer = app(FieldSetVersionAdminWriter::class);
        $draft = $writer->createDraftFromVersion(
            $fieldSet,
            FieldSetVersion::query()->whereKey($fieldSet->active_version_id)->firstOrFail(),
            $admin,
            $fieldSet->lock_version,
        );
        $fieldSet->refresh();

        $this->expectException(ValidationException::class);
        $writer->addCustomMembership($fieldSet, $draft, [
            'field_definition_id' => $definition->id,
            'field_definition_revision_id' => (int) $definition->current_revision_id,
            'sort' => 90,
            'required_override' => true,
            'lock_version' => $fieldSet->lock_version,
        ], $admin);
    }
}
