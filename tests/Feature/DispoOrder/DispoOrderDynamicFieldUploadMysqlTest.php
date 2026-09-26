<?php

namespace Tests\Feature\DispoOrder;

use App\Enums\DispoOrderUploadCategory;
use App\Enums\FieldAppliesTo;
use App\Enums\FieldScope;
use App\Enums\FieldType;
use App\Enums\Role;
use App\Exceptions\DispoOrderConflictException;
use App\Models\DispoOrder;
use App\Models\DispoOrderFieldValue;
use App\Models\DispoOrderUpload;
use App\Models\FieldSet;
use App\Models\FieldSetVersion;
use App\Models\SnapshotFieldDefinition;
use App\Models\User;
use App\Services\DispoOrder\DispoOrderUploadService;
use App\Services\DynamicField\Admin\AdminFieldSetCatalog;
use App\Services\DynamicField\Admin\FieldDefinitionCustomWriter;
use App\Services\DynamicField\Admin\FieldSetVersionAdminWriter;
use App\Services\DynamicField\ConfigurationSnapshotMaterializer;
use App\Support\PrivateFileStorage;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Concerns\CreatesSavedCalculation;
use Tests\Concerns\CreatesSpotClassicCatalog;
use Tests\TestCase;

/**
 * MySQL concurrency / locking for BL-P9-01c dynamic field uploads.
 */
class DispoOrderDynamicFieldUploadMysqlTest extends TestCase
{
    use CreatesSavedCalculation;
    use CreatesSpotClassicCatalog;
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('MySQL-only concurrency / locking.');
        }
    }

    public function test_stale_lock_version_after_successful_dynamic_field_upload_conflicts(): void
    {
        ['order' => $order, 'creator' => $creator, 'lock' => $lock, 'field_key' => $fieldKey] = $this->seedDraft();
        $fixture = base_path('tests/fixtures/customer-confirmation-sample.pdf');
        $service = app(DispoOrderUploadService::class);

        $service->uploadDynamicFieldFile(
            $order,
            $creator,
            $lock,
            new UploadedFile($fixture, 'a.pdf', 'application/pdf', null, true),
            $fieldKey,
            null,
        );

        $this->expectException(DispoOrderConflictException::class);
        $service->uploadDynamicFieldFile(
            $order->fresh(),
            $creator,
            $lock,
            new UploadedFile($fixture, 'b.pdf', 'application/pdf', null, true),
            $fieldKey,
            null,
        );
    }

    public function test_mysql_parallel_dynamic_field_uploads_yield_exactly_one_winner(): void
    {
        ['order' => $order, 'creator' => $creator, 'lock' => $lock, 'field_key' => $fieldKey, 'snap_def_id' => $snapDefId] = $this->seedDraft();
        $fixture = base_path('tests/fixtures/customer-confirmation-sample.pdf');
        $initialLock = $lock;

        $results = $this->runParallelWorkers(
            (string) $order->id,
            (string) $creator->id,
            (string) $lock,
            $fixture,
            $fieldKey,
            '',
            (string) $creator->id,
            (string) $lock,
            $fixture,
            $fieldKey,
            '',
        );

        $ok = array_values(array_filter($results, fn (string $line): bool => str_starts_with($line, 'OK:')));
        $errors = array_values(array_filter($results, fn (string $line): bool => str_starts_with($line, 'ERROR:')));

        $this->assertCount(1, $ok, 'Expected exactly one upload winner, got: '.implode('; ', $results));
        $this->assertCount(1, $errors);
        $this->assertStringContainsString(DispoOrderConflictException::class, $errors[0]);

        $order->refresh();
        $this->assertSame($initialLock + 1, $order->lock_version);

        $activeForField = DispoOrderUpload::query()
            ->where('dispo_order_id', $order->id)
            ->where('category', DispoOrderUploadCategory::DynamicField->value)
            ->where('field_key', $fieldKey)
            ->whereNull('archived_at')
            ->get();
        $this->assertCount(1, $activeForField);

        $valueRow = DispoOrderFieldValue::query()
            ->where('dispo_order_id', $order->id)
            ->where('snapshot_field_definition_id', $snapDefId)
            ->firstOrFail();
        $this->assertSame(['upload_id' => $activeForField->first()->id], $valueRow->value_json);

        $upload = $activeForField->first();
        $this->assertTrue(app(PrivateFileStorage::class)->exists($upload->storage_path));
        $this->assertSame(
            [$upload->storage_path],
            $this->storagePathsForOrder($order->id),
            'Loser storage must be cleaned; only the winner path may remain.',
        );
    }

    /**
     * @return array{order: DispoOrder, creator: User, lock: int, field_key: string, snap_def_id: int}
     */
    private function seedDraft(): array
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $definition = app(FieldDefinitionCustomWriter::class)->create([
            'label' => 'Anhang Dispo',
            'key' => 'dispo_anhang_mysql',
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
        $writer->addCustomMembership($fieldSet, $draft, [
            'field_definition_id' => $definition->id,
            'field_definition_revision_id' => (int) $definition->current_revision_id,
            'sort' => 75,
            'lock_version' => $fieldSet->lock_version,
        ], $admin);
        $fieldSet->refresh();
        $writer->activateDraft($fieldSet, $draft, $admin, $fieldSet->lock_version);

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
        $this->purgeOrderUploadStorage((int) $order->id);

        $snapDef = SnapshotFieldDefinition::query()
            ->where('configuration_snapshot_id', $order->configuration_snapshot_id)
            ->where('key', $definition->key)
            ->firstOrFail();

        return [
            'order' => $order,
            'creator' => $creator,
            'lock' => $order->lock_version,
            'field_key' => $definition->key,
            'snap_def_id' => (int) $snapDef->id,
        ];
    }

    private function purgeOrderUploadStorage(int $orderId): void
    {
        $disk = app(PrivateFileStorage::class)->disk();
        $disk->deleteDirectory('dispo-orders/'.$orderId);
    }

    /**
     * @return list<string>
     */
    private function storagePathsForOrder(int $orderId): array
    {
        $disk = app(PrivateFileStorage::class)->disk();
        $prefix = 'dispo-orders/'.$orderId.'/uploads';
        $paths = $disk->allFiles($prefix);
        sort($paths);

        return array_values($paths);
    }

    /**
     * @return list<string>
     */
    private function runParallelWorkers(
        string $orderId,
        string $userIdA,
        string $lockA,
        string $payloadA,
        string $fieldKeyA,
        string $positionIdA,
        string $userIdB,
        string $lockB,
        string $payloadB,
        string $fieldKeyB,
        string $positionIdB,
    ): array {
        $runDir = sys_get_temp_dir().'/dispo-dynamic-field-upload-concurrency-'.Str::uuid();
        if (! mkdir($runDir, 0700, true) && ! is_dir($runDir)) {
            $this->fail('Temporäres Barrier-Verzeichnis konnte nicht erstellt werden.');
        }

        try {
            $script = base_path('tests/concurrency/dispo_order_dynamic_field_upload_worker.php');
            $env = $this->workerEnvironment();

            $worker0 = new Process(
                [PHP_BINARY, $script, $runDir, '0', $orderId, $userIdA, $lockA, $payloadA, $fieldKeyA, $positionIdA],
                base_path(),
                $env,
            );
            $worker1 = new Process(
                [PHP_BINARY, $script, $runDir, '1', $orderId, $userIdB, $lockB, $payloadB, $fieldKeyB, $positionIdB],
                base_path(),
                $env,
            );

            $worker0->start();
            $worker1->start();
            $worker0->wait();
            $worker1->wait();

            $results = [];
            foreach ([0, 1] as $id) {
                $file = $runDir.'/worker-'.$id.'.result';
                $this->assertFileExists($file, 'Worker '.$id.' result missing. stderr0='.$worker0->getErrorOutput().' stderr1='.$worker1->getErrorOutput());
                $results[] = trim((string) file_get_contents($file));
            }

            return $results;
        } finally {
            foreach (glob($runDir.'/*') ?: [] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            if (is_dir($runDir)) {
                rmdir($runDir);
            }
        }
    }

    /**
     * @return array<string, string>
     */
    private function workerEnvironment(): array
    {
        $vars = [
            'APP_KEY', 'APP_ENV', 'DB_CONNECTION', 'DB_HOST', 'DB_PORT',
            'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD', 'DB_URL',
            'DISPO_FILES_DISK',
        ];

        $env = [];
        foreach ($vars as $key) {
            $value = $_SERVER[$key] ?? $_ENV[$key] ?? getenv($key);
            if ($value !== false && $value !== null) {
                $env[$key] = (string) $value;
            }
        }

        return $env;
    }
}
