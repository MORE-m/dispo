<?php

namespace Tests\Feature\DispoOrder;

use App\Enums\DispoOrderUploadCategory;
use App\Enums\Role;
use App\Exceptions\DispoOrderConflictException;
use App\Models\AuditEvent;
use App\Models\DispoOrder;
use App\Models\DispoOrderUpload;
use App\Models\User;
use App\Services\DispoOrder\DispoOrderUploadService;
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
 * MySQL concurrency / locking for BL-P9-01b material uploads.
 */
class DispoOrderMaterialUploadMysqlTest extends TestCase
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

    public function test_stale_lock_version_after_successful_material_upload_conflicts(): void
    {
        ['order' => $order, 'creator' => $creator, 'lock' => $lock] = $this->seedDraft();
        $fixture = base_path('tests/fixtures/customer-confirmation-sample.pdf');
        $service = app(DispoOrderUploadService::class);

        $service->uploadMaterial(
            $order,
            $creator,
            $lock,
            DispoOrderUploadCategory::Briefing,
            new UploadedFile($fixture, 'a.pdf', 'application/pdf', null, true),
        );

        $this->expectException(DispoOrderConflictException::class);
        $service->uploadMaterial(
            $order->fresh(),
            $creator,
            $lock,
            DispoOrderUploadCategory::Briefing,
            new UploadedFile($fixture, 'b.pdf', 'application/pdf', null, true),
        );
    }

    public function test_mysql_parallel_material_uploads_yield_exactly_one_winner(): void
    {
        ['order' => $order, 'creator' => $creator, 'lock' => $lock] = $this->seedDraft();
        $fixture = base_path('tests/fixtures/customer-confirmation-sample.pdf');
        $initialLock = $lock;

        $results = $this->runParallelWorkers(
            (string) $order->id,
            (string) $creator->id,
            (string) $lock,
            $fixture,
            (string) $creator->id,
            (string) $lock,
            $fixture,
        );

        $ok = array_values(array_filter($results, fn (string $line): bool => str_starts_with($line, 'OK:')));
        $errors = array_values(array_filter($results, fn (string $line): bool => str_starts_with($line, 'ERROR:')));

        $this->assertCount(1, $ok, 'Expected exactly one upload winner, got: '.implode('; ', $results));
        $this->assertCount(1, $errors);
        $this->assertStringContainsString(DispoOrderConflictException::class, $errors[0]);

        $order->refresh();
        $this->assertSame($initialLock + 1, $order->lock_version);
        $this->assertSame(1, DispoOrderUpload::query()->where('dispo_order_id', $order->id)->count());
        $this->assertSame(
            1,
            AuditEvent::query()
                ->where('auditable_type', DispoOrder::class)
                ->where('auditable_id', $order->id)
                ->where('action', 'dispo_order.upload.created')
                ->count(),
        );

        $upload = DispoOrderUpload::query()->where('dispo_order_id', $order->id)->firstOrFail();
        $this->assertNull($upload->archived_at);
        $this->assertTrue(app(PrivateFileStorage::class)->exists($upload->storage_path));
        $this->assertSame(
            [$upload->storage_path],
            $this->storagePathsForOrder($order->id),
            'Loser storage must be cleaned; only the winner path may remain.',
        );
    }

    /**
     * @return array{order: DispoOrder, creator: User, lock: int}
     */
    private function seedDraft(): array
    {
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

        return [
            'order' => $order,
            'creator' => $creator,
            'lock' => $order->lock_version,
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
        string $userIdB,
        string $lockB,
        string $payloadB,
    ): array {
        $runDir = sys_get_temp_dir().'/dispo-material-upload-concurrency-'.Str::uuid();
        if (! mkdir($runDir, 0700, true) && ! is_dir($runDir)) {
            $this->fail('Temporäres Barrier-Verzeichnis konnte nicht erstellt werden.');
        }

        try {
            $script = base_path('tests/concurrency/dispo_order_material_upload_worker.php');
            $env = $this->workerEnvironment();

            $worker0 = new Process(
                [PHP_BINARY, $script, $runDir, '0', $orderId, $userIdA, $lockA, $payloadA],
                base_path(),
                $env,
            );
            $worker1 = new Process(
                [PHP_BINARY, $script, $runDir, '1', $orderId, $userIdB, $lockB, $payloadB],
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
