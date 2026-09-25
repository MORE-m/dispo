<?php

namespace Tests\Feature\DispoOrder;

use App\Enums\Role;
use App\Exceptions\DispoOrderConflictException;
use App\Models\DispoOrder;
use App\Models\DispoOrderUpload;
use App\Models\User;
use App\Services\DispoOrder\DispoOrderUploadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesSavedCalculation;
use Tests\Concerns\CreatesSpotClassicCatalog;
use Tests\TestCase;

/**
 * MySQL concurrency for BL-P9-01a upload locking.
 */
class DispoOrderCustomerConfirmationUploadMysqlTest extends TestCase
{
    use CreatesSavedCalculation;
    use CreatesSpotClassicCatalog;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (config('database.default') !== 'mysql') {
            $this->markTestSkipped('MySQL-only concurrency.');
        }
    }

    public function test_concurrent_uploads_with_same_lock_version_one_wins(): void
    {
        Storage::fake((string) config('dispo.files_disk'));
        $catalog = $this->createSpotClassicCatalog();
        $creator = User::factory()->role(Role::Sales)->create();
        $calc = $this->createSavedCalculation($catalog, [
            ['inventory_id' => $catalog['hamburg']->id],
        ], $creator);
        $this->actingAs($creator)->post(route('dispo-orders.store', $calc), [
            'position_ids' => [$calc->positions()->first()->id],
        ])->assertRedirect();
        $order = DispoOrder::query()->latest('id')->firstOrFail();
        $lock = $order->lock_version;
        $fixture = base_path('tests/fixtures/customer-confirmation-sample.pdf');

        $results = [];
        DB::connection()->getPdo()->exec('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');

        $run = function (string $name) use ($order, $creator, $lock, $fixture, &$results): void {
            try {
                $file = new UploadedFile($fixture, $name, 'application/pdf', null, true);
                app(DispoOrderUploadService::class)->uploadCustomerConfirmation(
                    $order->fresh(),
                    $creator,
                    $lock,
                    $file,
                );
                $results[] = 'win';
            } catch (\Throwable $e) {
                $results[] = $e::class;
            }
        };

        // Sequential simulation of race: first succeeds, second with same lock fails 409.
        $run('a.pdf');
        $run('b.pdf');

        $this->assertContains('win', $results);
        $this->assertTrue(
            in_array(DispoOrderConflictException::class, $results, true)
            || count(array_filter($results, fn ($r) => $r === 'win')) === 1,
        );
        $this->assertSame(1, DispoOrderUpload::query()->where('dispo_order_id', $order->id)->count());
    }
}
