<?php

namespace Tests\Feature\Calculation;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class CalculationNumberConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        Schema::dropIfExists('calc_number_concurrency_results');
        Schema::dropIfExists('calc_number_concurrency_barrier');

        Schema::create('calc_number_concurrency_barrier', function ($table): void {
            $table->string('run_id');
            $table->unsignedTinyInteger('worker_id');
            $table->string('status', 16);
            $table->timestamp('updated_at')->nullable();
            $table->primary(['run_id', 'worker_id']);
        });

        Schema::create('calc_number_concurrency_results', function ($table): void {
            $table->id();
            $table->string('run_id');
            $table->unsignedTinyInteger('worker_id');
            $table->string('number');
            $table->timestamp('created_at')->nullable();
            $table->index('run_id');
        });
    }

    public function test_gen_001_mysql_parallel_workers_assign_unique_sequential_numbers(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Paralleler Sequenztest erfordert MySQL (GitHub-Job mysql).');
        }

        $runId = (string) Str::uuid();
        $script = base_path('tests/concurrency/calculation_number_worker.php');
        $env = $this->workerEnvironment();

        $worker0 = new Process([PHP_BINARY, $script, $runId, '0'], null, $env);
        $worker1 = new Process([PHP_BINARY, $script, $runId, '1'], null, $env);

        $worker0->start();
        $worker1->start();

        $this->assertSame(0, $worker0->wait()->getExitCode(), $worker0->getErrorOutput());
        $this->assertSame(0, $worker1->wait()->getExitCode(), $worker1->getErrorOutput());

        $numbers = DB::table('calc_number_concurrency_results')
            ->where('run_id', $runId)
            ->orderBy('worker_id')
            ->pluck('number')
            ->all();

        $this->assertCount(2, $numbers);
        $this->assertSame(2, count(array_unique($numbers)));
        foreach ($numbers as $number) {
            $this->assertDoesNotMatchRegularExpression('/^ERROR:/', $number);
            $this->assertMatchesRegularExpression('/^K-\d{4}-\d{5}$/', $number);
        }

        preg_match('/^K-(\d{4})-(\d{5})$/', $numbers[0], $first);
        preg_match('/^K-(\d{4})-(\d{5})$/', $numbers[1], $second);
        $this->assertSame($first[1], $second[1], 'Beide Nummern müssen im selben Jahr liegen.');
        $this->assertSame(1, abs((int) $first[2] - (int) $second[2]), 'Sequenz muss fortlaufend ohne Doppelvergabe sein.');
    }

    /**
     * @return array<string, string>
     */
    private function workerEnvironment(): array
    {
        $vars = [
            'APP_KEY', 'APP_ENV', 'DB_CONNECTION', 'DB_HOST', 'DB_PORT',
            'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD', 'DB_URL',
        ];

        $env = [];
        foreach ($vars as $var) {
            $value = getenv($var);
            if ($value !== false) {
                $env[$var] = (string) $value;
            }
        }

        return $env;
    }
}
