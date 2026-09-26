<?php

namespace Tests\Feature\DispoOrder;

use App\Enums\DispoOrderStatus;
use App\Enums\DispoOrderUploadCategory;
use App\Enums\FieldAppliesTo;
use App\Enums\FieldScope;
use App\Enums\FieldType;
use App\Enums\Role;
use App\Models\DispoOrder;
use App\Models\DispoOrderFieldValue;
use App\Models\DispoOrderUpload;
use App\Models\FieldDefinition;
use App\Models\FieldSet;
use App\Models\FieldSetVersion;
use App\Models\SnapshotFieldDefinition;
use App\Models\User;
use App\Services\DispoOrder\DispoOrderApprovalService;
use App\Services\DispoOrder\DispoOrderOperationalStatusService;
use App\Services\DispoOrder\DispoOrderUploadService;
use App\Services\DynamicField\Admin\AdminFieldSetCatalog;
use App\Services\DynamicField\Admin\FieldDefinitionCustomWriter;
use App\Services\DynamicField\Admin\FieldSetVersionAdminWriter;
use App\Services\DynamicField\ConfigurationSnapshotMaterializer;
use App\Services\DynamicField\DispoOrderDynamicFieldWriter;
use App\Support\DynamicField\FileFieldValueContract;
use App\Support\PrivateFileStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesSavedCalculation;
use Tests\Concerns\CreatesSpotClassicCatalog;
use Tests\Concerns\EnsuresCustomerConfirmationException;
use Tests\TestCase;

class DispoOrderDynamicFieldUploadTest extends TestCase
{
    use CreatesSavedCalculation;
    use CreatesSpotClassicCatalog;
    use EnsuresCustomerConfirmationException;
    use RefreshDatabase;

    /** @var array<string, mixed>|null */
    private ?array $sharedSpotCatalog = null;

    public function test_happy_path_header_file_upload_sets_value_json_and_upload_meta(): void
    {
        Storage::fake((string) config('dispo.files_disk'));

        $admin = User::factory()->role(Role::Admin)->create();
        $definition = $this->activateFileFieldOnDispoSet($admin, 'dispo_anhang');

        app(ConfigurationSnapshotMaterializer::class)
            ->materializeFromActiveSet(AdminFieldSetCatalog::DISPO_ORDER_CORE);

        $catalog = $this->createSpotClassicCatalog();
        $creator = User::factory()->role(Role::Sales)->create();
        $calculation = $this->createSavedCalculation($catalog, [
            ['inventory_id' => $catalog['hamburg']->id],
        ], $creator);

        $this->actingAs($creator)->post(route('dispo-orders.store', $calculation), [
            'position_ids' => [$calculation->positions()->first()->id],
        ])->assertRedirect();

        $order = DispoOrder::query()->latest('id')->firstOrFail();
        $snapDef = SnapshotFieldDefinition::query()
            ->where('configuration_snapshot_id', $order->configuration_snapshot_id)
            ->where('key', $definition->key)
            ->firstOrFail();

        $file = $this->pdfFile('anhang.pdf');

        $this->postDynamicField($creator, $order, [
            'lock_version' => $order->lock_version,
            'field_key' => $definition->key,
            'file' => $file,
        ])->assertOk();

        $order->refresh();
        $upload = DispoOrderUpload::query()
            ->where('dispo_order_id', $order->id)
            ->where('category', DispoOrderUploadCategory::DynamicField->value)
            ->firstOrFail();

        $this->assertSame($definition->key, $upload->field_key);
        $this->assertSame('Anhang Dispo', $upload->field_label_snapshot);
        $this->assertSame($snapDef->id, $upload->snapshot_field_definition_id);
        $this->assertNull($upload->dispo_order_position_id);
        $this->assertNull($upload->position_label_snapshot);
        $this->assertTrue(app(PrivateFileStorage::class)->exists($upload->storage_path));

        $valueRow = DispoOrderFieldValue::query()
            ->where('dispo_order_id', $order->id)
            ->where('snapshot_field_definition_id', $snapDef->id)
            ->firstOrFail();

        $this->assertSame(['upload_id' => $upload->id], $valueRow->value_json);
        $this->assertSame($upload->id, FileFieldValueContract::readUploadId($valueRow));
    }

    public function test_role_matrix_for_dynamic_field_upload(): void
    {
        Storage::fake((string) config('dispo.files_disk'));
        ['order' => $order, 'creator' => $creator, 'definition' => $definition] = $this->seedOrderWithFileField();
        ['order' => $order] = $this->approvedOrderFrom($order, $creator);

        $allowed = [
            User::factory()->role(Role::Sales)->create(),
            User::factory()->role(Role::Disposition)->create(),
            User::factory()->role(Role::Admin)->create(),
            User::factory()->role(Role::Management)->create(),
        ];

        foreach ($allowed as $actor) {
            $order->refresh();
            $this->postDynamicField($actor, $order, [
                'lock_version' => $order->lock_version,
                'field_key' => $definition->key,
                'file' => $this->pdfFile($actor->role->value.'.pdf'),
            ])->assertOk();
        }

        $this->postDynamicField(
            User::factory()->role(Role::ProductManagement)->create(),
            $order->fresh(),
            [
                'lock_version' => $order->fresh()->lock_version,
                'field_key' => $definition->key,
                'file' => $this->pdfFile('pm.pdf'),
            ],
        )->assertForbidden();
    }

    public function test_status_matrix_allows_and_blocks_dynamic_field_upload(): void
    {
        Storage::fake((string) config('dispo.files_disk'));
        ['order' => $draftOrder, 'creator' => $creator, 'definition' => $definition] = $this->seedOrderWithFileField(
            fieldKey: 'status_matrix_field',
        );

        $this->postDynamicField($creator, $draftOrder, [
            'lock_version' => $draftOrder->lock_version,
            'field_key' => $definition->key,
            'file' => $this->pdfFile('draft.pdf'),
        ])->assertOk();

        ['order' => $approvedBase, 'creator' => $approvedCreator, 'definition' => $approvedDefinition] = $this->seedOrderWithFileField(
            fieldKey: 'status_matrix_approved',
            reuseCatalog: true,
        );
        ['order' => $approved, 'disposition' => $disposition] = $this->approvedOrderFrom($approvedBase, $approvedCreator);
        $ops = app(DispoOrderOperationalStatusService::class);

        $this->postDynamicField($approvedCreator, $approved->fresh(), [
            'lock_version' => $approved->fresh()->lock_version,
            'field_key' => $approvedDefinition->key,
            'file' => $this->pdfFile('at-disposition.pdf'),
        ])->assertOk();

        $inProgress = $ops->transition($approved->fresh(), $disposition, $approved->fresh()->lock_version, DispoOrderStatus::InProgress);
        $this->postDynamicField($approvedCreator, $inProgress, [
            'lock_version' => $inProgress->lock_version,
            'field_key' => $approvedDefinition->key,
            'file' => $this->pdfFile('in-progress.pdf'),
        ])->assertOk();

        $materialMissing = $ops->transition($inProgress->fresh(), $disposition, $inProgress->fresh()->lock_version, DispoOrderStatus::MaterialMissing);
        $this->postDynamicField($approvedCreator, $materialMissing, [
            'lock_version' => $materialMissing->lock_version,
            'field_key' => $approvedDefinition->key,
            'file' => $this->pdfFile('material-missing.pdf'),
        ])->assertOk();

        $materialReceived = $ops->transition($materialMissing->fresh(), $disposition, $materialMissing->fresh()->lock_version, DispoOrderStatus::MaterialReceived);
        $this->postDynamicField($approvedCreator, $materialReceived, [
            'lock_version' => $materialReceived->lock_version,
            'field_key' => $approvedDefinition->key,
            'file' => $this->pdfFile('material-received.pdf'),
        ])->assertOk();

        $inquiry = $materialReceived->fresh();
        $inquiry->forceFill(['status' => DispoOrderStatus::SalesInquiry])->save();
        $this->postDynamicField($approvedCreator, $inquiry->fresh(), [
            'lock_version' => $inquiry->fresh()->lock_version,
            'field_key' => $approvedDefinition->key,
            'file' => $this->pdfFile('sales-inquiry.pdf'),
        ])->assertOk();

        $blocked = [
            DispoOrderStatus::AwaitingSalesApproval,
            DispoOrderStatus::ApprovalRejected,
            DispoOrderStatus::Disposed,
            DispoOrderStatus::Completed,
            DispoOrderStatus::Cancelled,
        ];

        foreach ($blocked as $status) {
            $draftOrder->forceFill(['status' => $status])->save();
            $fresh = $draftOrder->fresh();
            $this->postDynamicField($creator, $fresh, [
                'lock_version' => $fresh->lock_version,
                'field_key' => $definition->key,
                'file' => $this->pdfFile('blocked-'.$status->value.'.pdf'),
            ])->assertStatus(422)->assertJsonValidationErrors('order');
        }
    }

    public function test_second_header_upload_archives_first_and_updates_value_json(): void
    {
        Storage::fake((string) config('dispo.files_disk'));
        ['order' => $order, 'creator' => $creator, 'definition' => $definition, 'snap_def' => $snapDef] = $this->seedOrderWithFileField();

        $this->postDynamicField($creator, $order, [
            'lock_version' => $order->lock_version,
            'field_key' => $definition->key,
            'file' => $this->pdfFile('first.pdf'),
        ])->assertOk();

        $order->refresh();
        $first = DispoOrderUpload::query()
            ->where('dispo_order_id', $order->id)
            ->where('category', DispoOrderUploadCategory::DynamicField->value)
            ->whereNull('archived_at')
            ->firstOrFail();

        $this->postDynamicField($creator, $order, [
            'lock_version' => $order->lock_version,
            'field_key' => $definition->key,
            'file' => $this->pdfFile('second.pdf'),
        ])->assertOk();

        $first->refresh();
        $second = DispoOrderUpload::query()
            ->where('dispo_order_id', $order->id)
            ->where('category', DispoOrderUploadCategory::DynamicField->value)
            ->whereNull('archived_at')
            ->firstOrFail();

        $this->assertNotSame($first->id, $second->id);
        $this->assertNotNull($first->archived_at);
        $this->assertNull($second->archived_at);

        $valueRow = DispoOrderFieldValue::query()
            ->where('dispo_order_id', $order->id)
            ->where('snapshot_field_definition_id', $snapDef->id)
            ->firstOrFail();
        $this->assertSame(['upload_id' => $second->id], $valueRow->value_json);

        $listed = app(DispoOrderUploadService::class)->listProp($order->fresh());
        $this->assertCount(2, $listed);
        $archivedRow = collect($listed)->firstWhere('id', $first->id);
        $currentRow = collect($listed)->firstWhere('id', $second->id);
        $this->assertTrue($archivedRow['archived']);
        $this->assertFalse($currentRow['archived']);
    }

    public function test_position_file_field_upload_sets_position_label_and_rejects_foreign_position(): void
    {
        Storage::fake((string) config('dispo.files_disk'));
        ['order' => $order, 'creator' => $creator, 'definition' => $definition] = $this->seedOrderWithFileField(
            fieldKey: 'pos_anhang',
            scope: FieldScope::Position,
        );

        $position = $order->positions()->firstOrFail();
        $this->assertNotEmpty($position->inventory_name);

        $this->postDynamicField($creator, $order, [
            'lock_version' => $order->lock_version,
            'field_key' => $definition->key,
            'position_id' => $position->id,
            'file' => $this->pdfFile('pos.pdf'),
        ])->assertOk();

        $upload = DispoOrderUpload::query()
            ->where('dispo_order_id', $order->id)
            ->where('field_key', $definition->key)
            ->firstOrFail();

        $this->assertSame($position->id, $upload->dispo_order_position_id);
        $this->assertSame(
            trim((string) $position->inventory_name).' · '.trim((string) $position->advertising_medium_name),
            $upload->position_label_snapshot,
        );

        ['order' => $otherOrder] = $this->seedOrderWithFileField(
            fieldKey: 'pos_anhang_other',
            scope: FieldScope::Position,
            reuseCatalog: true,
        );
        $foreignPosition = $otherOrder->positions()->firstOrFail();

        $this->postDynamicField($creator, $order->fresh(), [
            'lock_version' => $order->fresh()->lock_version,
            'field_key' => $definition->key,
            'position_id' => $foreignPosition->id,
            'file' => $this->pdfFile('wrong-pos.pdf'),
        ])->assertStatus(422)->assertJsonValidationErrors('position_id');
    }

    public function test_mime_allowlist_accepts_pdf_and_rejects_other_types(): void
    {
        Storage::fake((string) config('dispo.files_disk'));
        ['order' => $order, 'creator' => $creator, 'definition' => $definition] = $this->seedOrderWithFileField(
            fieldKey: 'pdf_only',
            allowedMimeTypes: ['application/pdf'],
        );

        $this->postDynamicField($creator, $order, [
            'lock_version' => $order->lock_version,
            'field_key' => $definition->key,
            'file' => $this->pdfFile('ok.pdf'),
        ])->assertOk();

        $order->refresh();
        $before = DispoOrderUpload::query()->where('dispo_order_id', $order->id)->count();

        $mp3 = new UploadedFile(
            base_path('tests/fixtures/audio-motif-sample.mp3'),
            'not-allowed.mp3',
            'audio/mpeg',
            null,
            true,
        );

        $this->postDynamicField($creator, $order, [
            'lock_version' => $order->lock_version,
            'field_key' => $definition->key,
            'file' => $mp3,
        ])->assertStatus(422)->assertJsonValidationErrors('file');

        $this->assertSame($before, DispoOrderUpload::query()->where('dispo_order_id', $order->id)->count());
    }

    public function test_blocklist_rejects_executable_without_allowlist(): void
    {
        Storage::fake((string) config('dispo.files_disk'));
        ['order' => $order, 'creator' => $creator, 'definition' => $definition] = $this->seedOrderWithFileField();

        $exe = UploadedFile::fake()->create('evil.exe', 10, 'application/x-msdownload');

        $this->postDynamicField($creator, $order, [
            'lock_version' => $order->lock_version,
            'field_key' => $definition->key,
            'file' => $exe,
        ])->assertStatus(422)->assertJsonValidationErrors('file');

        $pe = UploadedFile::fake()->create(
            'evil-pe.exe',
            10,
            'application/vnd.microsoft.portable-executable',
        );

        $this->postDynamicField($creator, $order->fresh(), [
            'lock_version' => $order->fresh()->lock_version,
            'field_key' => $definition->key,
            'file' => $pe,
        ])->assertStatus(422)->assertJsonValidationErrors('file');
    }

    public function test_blocklist_still_rejects_when_type_is_explicitly_allowlisted(): void
    {
        Storage::fake((string) config('dispo.files_disk'));
        ['order' => $order, 'creator' => $creator, 'definition' => $definition] = $this->seedOrderWithFileField(
            fieldKey: 'allow_but_blocked',
            allowedMimeTypes: ['application/x-msdownload', 'application/pdf'],
        );

        $before = DispoOrderUpload::query()->where('dispo_order_id', $order->id)->count();
        $exe = UploadedFile::fake()->create('evil.exe', 10, 'application/x-msdownload');

        $response = $this->postDynamicField($creator, $order, [
            'lock_version' => $order->lock_version,
            'field_key' => $definition->key,
            'file' => $exe,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('file');
        $this->assertStringContainsString(
            'Sicherheitsgründen',
            (string) data_get($response->json(), 'errors.file.0'),
        );
        $this->assertSame($before, DispoOrderUpload::query()->where('dispo_order_id', $order->id)->count());
    }

    public function test_upload_and_schema_reject_file_field_with_applies_to_both(): void
    {
        Storage::fake((string) config('dispo.files_disk'));
        ['order' => $order, 'creator' => $creator, 'definition' => $definition, 'snap_def' => $snapDef] = $this->seedOrderWithFileField(
            fieldKey: 'both_invalid',
        );

        $snapDef->applies_to = FieldAppliesTo::Both;
        $snapDef->save();

        $schema = app(DispoOrderDynamicFieldWriter::class)->fieldSchemaProp($order->fresh());
        $editableKeys = array_column($schema['editable_custom_header_fields'], 'key');
        $this->assertNotContains($definition->key, $editableKeys);

        $allFields = collect($schema['fields'])->firstWhere('key', $definition->key);
        $this->assertNotNull($allFields);
        $this->assertSame(FieldAppliesTo::Both->value, $allFields['applies_to']);
        $this->assertFalse($allFields['editable']);

        $before = DispoOrderUpload::query()->where('dispo_order_id', $order->id)->count();
        $this->postDynamicField($creator, $order->fresh(), [
            'lock_version' => $order->fresh()->lock_version,
            'field_key' => $definition->key,
            'file' => $this->pdfFile('both.pdf'),
        ])->assertStatus(422)->assertJsonValidationErrors('field_key');

        $this->assertSame($before, DispoOrderUpload::query()->where('dispo_order_id', $order->id)->count());
    }

    public function test_rejects_over_50mb(): void
    {
        Storage::fake((string) config('dispo.files_disk'));
        ['order' => $order, 'creator' => $creator, 'definition' => $definition] = $this->seedOrderWithFileField();

        $tooBig = UploadedFile::fake()->create(
            'big.bin',
            (int) (DispoOrderUploadService::MAX_BYTES / 1024) + 1,
        );

        $this->postDynamicField($creator, $order, [
            'lock_version' => $order->lock_version,
            'field_key' => $definition->key,
            'file' => $tooBig,
        ])->assertStatus(422)->assertJsonValidationErrors('file');
    }

    public function test_admin_archive_clears_value_json_and_non_admin_forbidden(): void
    {
        Storage::fake((string) config('dispo.files_disk'));
        ['order' => $order, 'creator' => $creator, 'definition' => $definition, 'snap_def' => $snapDef] = $this->seedOrderWithFileField();
        $admin = User::factory()->role(Role::Admin)->create();

        $this->postDynamicField($creator, $order, [
            'lock_version' => $order->lock_version,
            'field_key' => $definition->key,
            'file' => $this->pdfFile('archive-me.pdf'),
        ])->assertOk();

        $order->refresh();
        $upload = DispoOrderUpload::query()
            ->where('dispo_order_id', $order->id)
            ->whereNull('archived_at')
            ->firstOrFail();

        $this->actingAs($creator)->postJson(
            route('dispo-orders.uploads.archive', [$order, $upload]),
            ['lock_version' => $order->lock_version],
        )->assertForbidden();

        $this->actingAs($admin)->postJson(
            route('dispo-orders.uploads.archive', [$order->fresh(), $upload]),
            ['lock_version' => $order->fresh()->lock_version],
        )->assertOk();

        $upload->refresh();
        $this->assertNotNull($upload->archived_at);

        $valueRow = DispoOrderFieldValue::query()
            ->where('dispo_order_id', $order->id)
            ->where('snapshot_field_definition_id', $snapDef->id)
            ->first();
        if ($valueRow !== null) {
            $this->assertNull(FileFieldValueContract::readUploadId($valueRow));
        }
    }

    public function test_stale_lock_version_returns_409(): void
    {
        Storage::fake((string) config('dispo.files_disk'));
        ['order' => $order, 'creator' => $creator, 'definition' => $definition] = $this->seedOrderWithFileField();
        $stale = $order->lock_version;

        $this->postDynamicField($creator, $order, [
            'lock_version' => $stale,
            'field_key' => $definition->key,
            'file' => $this->pdfFile('a.pdf'),
        ])->assertOk();

        $this->postDynamicField($creator, $order->fresh(), [
            'lock_version' => $stale,
            'field_key' => $definition->key,
            'file' => $this->pdfFile('b.pdf'),
        ])->assertStatus(409);
    }

    public function test_sequential_double_post_with_same_lock_version_after_first_succeeds_conflicts(): void
    {
        Storage::fake((string) config('dispo.files_disk'));
        ['order' => $order, 'creator' => $creator, 'definition' => $definition] = $this->seedOrderWithFileField();
        $sharedLock = $order->lock_version;

        $this->postDynamicField($creator, $order, [
            'lock_version' => $sharedLock,
            'field_key' => $definition->key,
            'file' => $this->pdfFile('winner.pdf'),
        ])->assertOk();

        $this->postDynamicField($creator, $order, [
            'lock_version' => $sharedLock,
            'field_key' => $definition->key,
            'file' => $this->pdfFile('loser.pdf'),
        ])->assertStatus(409);

        $this->assertSame(
            1,
            DispoOrderUpload::query()
                ->where('dispo_order_id', $order->id)
                ->where('category', DispoOrderUploadCategory::DynamicField->value)
                ->whereNull('archived_at')
                ->count(),
        );
    }

    public function test_older_order_without_file_field_still_loads_after_new_file_definition(): void
    {
        app(ConfigurationSnapshotMaterializer::class)
            ->materializeFromActiveSet(AdminFieldSetCatalog::DISPO_ORDER_CORE);

        $catalog = $this->createSpotClassicCatalog();
        $creator = User::factory()->role(Role::Sales)->create();
        $calculation = $this->createSavedCalculation($catalog, [
            ['inventory_id' => $catalog['hamburg']->id],
        ], $creator);

        $this->actingAs($creator)->post(route('dispo-orders.store', $calculation), [
            'position_ids' => [$calculation->positions()->first()->id],
        ])->assertRedirect();

        $order = DispoOrder::query()->latest('id')->firstOrFail();

        $admin = User::factory()->role(Role::Admin)->create();
        $this->activateFileFieldOnDispoSet($admin, 'new_file_after_order');
        app(ConfigurationSnapshotMaterializer::class)
            ->materializeFromActiveSet(AdminFieldSetCatalog::DISPO_ORDER_CORE);

        $this->actingAs($creator)
            ->get(route('dispo-orders.show', $order))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('fieldSchema.fields')
                ->has('uploads'));
    }

    public function test_empty_file_field_not_listed_as_missing_required(): void
    {
        Storage::fake((string) config('dispo.files_disk'));
        ['order' => $order, 'definition' => $definition] = $this->seedOrderWithFileField();

        $missing = app(DispoOrderDynamicFieldWriter::class)->collectMissingRequiredFields($order->fresh());

        $this->assertSame([], $missing['errors']);
        $labels = array_column($missing['violations'], 'field_label');
        $this->assertNotContains($definition->label, $labels);
    }

    public function test_list_serialization_includes_dynamic_field_labels(): void
    {
        Storage::fake((string) config('dispo.files_disk'));
        ['order' => $order, 'creator' => $creator, 'definition' => $definition] = $this->seedOrderWithFileField();

        $this->postDynamicField($creator, $order, [
            'lock_version' => $order->lock_version,
            'field_key' => $definition->key,
            'file' => $this->pdfFile('listed.pdf'),
        ])->assertOk();

        $listed = app(DispoOrderUploadService::class)->listProp($order->fresh());
        $row = collect($listed)->firstWhere('field_key', $definition->key);
        $this->assertNotNull($row);
        $this->assertSame('Dynamisches Feld', $row['category_label']);
        $this->assertSame('Anhang Dispo', $row['field_label']);

        $this->actingAs($creator)
            ->get(route('dispo-orders.show', $order->fresh()))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('uploads.0.category_label', 'Dynamisches Feld')
                ->where('uploads.0.field_label', 'Anhang Dispo'));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function postDynamicField(User $actor, DispoOrder $order, array $payload)
    {
        return $this->actingAs($actor)
            ->withHeader('Accept', 'application/json')
            ->post(route('dispo-orders.uploads.dynamic-field', $order), $payload);
    }

    private function pdfFile(string $name = 'file.pdf'): UploadedFile
    {
        return new UploadedFile(
            base_path('tests/fixtures/customer-confirmation-sample.pdf'),
            $name,
            'application/pdf',
            null,
            true,
        );
    }

    /**
     * @return array{
     *     order: DispoOrder,
     *     creator: User,
     *     definition: FieldDefinition,
     *     snap_def: SnapshotFieldDefinition
     * }
     */
    private function seedOrderWithFileField(
        string $fieldKey = 'dispo_anhang_seed',
        FieldScope $scope = FieldScope::Header,
        ?array $allowedMimeTypes = null,
        bool $reuseCatalog = false,
    ): array {
        $admin = User::factory()->role(Role::Admin)->create();
        $definition = $this->activateFileFieldOnDispoSet($admin, $fieldKey, $scope, $allowedMimeTypes);

        app(ConfigurationSnapshotMaterializer::class)
            ->materializeFromActiveSet(AdminFieldSetCatalog::DISPO_ORDER_CORE);

        if ($reuseCatalog && $this->sharedSpotCatalog !== null) {
            $catalog = $this->sharedSpotCatalog;
        } else {
            $catalog = $this->createSpotClassicCatalog();
            $this->sharedSpotCatalog = $catalog;
        }

        $creator = User::factory()->role(Role::Sales)->create();
        $calculation = $this->createSavedCalculation($catalog, [
            ['inventory_id' => $catalog['hamburg']->id],
        ], $creator);

        $this->actingAs($creator)->post(route('dispo-orders.store', $calculation), [
            'position_ids' => [$calculation->positions()->first()->id],
        ])->assertRedirect();

        $order = DispoOrder::query()->latest('id')->firstOrFail();
        $snapDef = SnapshotFieldDefinition::query()
            ->where('configuration_snapshot_id', $order->configuration_snapshot_id)
            ->where('key', $definition->key)
            ->first();

        if ($snapDef === null && $scope === FieldScope::Position) {
            $position = $order->positions()->firstOrFail();
            $position->loadMissing('effectiveConfigurationSnapshot');
            $effectiveSnapshotId = $position->effective_configuration_snapshot_id;
            $this->assertNotNull($effectiveSnapshotId);
            $snapDef = SnapshotFieldDefinition::query()
                ->where('configuration_snapshot_id', $effectiveSnapshotId)
                ->where('key', $definition->key)
                ->firstOrFail();
        } elseif ($snapDef === null) {
            $this->fail('Snapshot-Felddefinition fehlt für '.$definition->key);
        }

        return [
            'order' => $order,
            'creator' => $creator,
            'definition' => $definition,
            'snap_def' => $snapDef,
        ];
    }

    /**
     * @return array{order: DispoOrder, disposition: User}
     */
    private function approvedOrderFrom(DispoOrder $order, User $creator): array
    {
        $approver = User::factory()->role(Role::Sales)->create();
        $disposition = User::factory()->role(Role::Disposition)->create();
        $service = app(DispoOrderApprovalService::class);

        $order = $this->seedCustomerConfirmationException($order, $creator);
        $submitted = $service->submit($order, $creator, $order->lock_version);
        $approved = $service->approve($submitted, $approver, $submitted->lock_version, null, true);

        return [
            'order' => $approved,
            'disposition' => $disposition,
        ];
    }

    private function activateFileFieldOnDispoSet(
        User $admin,
        string $key,
        FieldScope $scope = FieldScope::Header,
        ?array $allowedMimeTypes = null,
        string $label = 'Anhang Dispo',
    ): FieldDefinition {
        $payload = [
            'label' => $label,
            'key' => $key,
            'field_type' => FieldType::File,
            'scope' => $scope,
            'applies_to' => FieldAppliesTo::DispoOrder,
        ];
        if ($allowedMimeTypes !== null) {
            $payload['allowed_mime_types'] = $allowedMimeTypes;
        }

        $definition = app(FieldDefinitionCustomWriter::class)->create($payload, $admin);

        $fieldSet = FieldSet::query()->where('key', AdminFieldSetCatalog::DISPO_ORDER_CORE)->firstOrFail();
        $writer = app(FieldSetVersionAdminWriter::class);
        $draft = $writer->createDraftFromVersion(
            $fieldSet,
            FieldSetVersion::query()->whereKey($fieldSet->active_version_id)->firstOrFail(),
            $admin,
            $fieldSet->lock_version,
        );
        $fieldSet->refresh();
        $writer->addCustomMembership($fieldSet, $draft, [
            'field_definition_id' => $definition->id,
            'field_definition_revision_id' => (int) $definition->current_revision_id,
            'sort' => 75,
            'lock_version' => $fieldSet->lock_version,
        ], $admin);
        $fieldSet->refresh();
        $writer->activateDraft($fieldSet, $draft, $admin, $fieldSet->lock_version);

        return $definition->fresh(['currentRevision']) ?? $definition;
    }
}
