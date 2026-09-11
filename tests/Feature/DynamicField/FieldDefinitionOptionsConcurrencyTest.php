<?php

namespace Tests\Feature\DynamicField;

use App\Enums\FieldAppliesTo;
use App\Enums\FieldScope;
use App\Enums\FieldType;
use App\Enums\Role;
use App\Models\FieldDefinition;
use App\Models\FieldDefinitionRevision;
use App\Models\User;
use App\Services\DynamicField\Admin\FieldDefinitionOptionsWriter;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * DF-3-REST-A: echte parallele MySQL-Transaktionen auf Optionen derselben Definition.
 */
class FieldDefinitionOptionsConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_parallel_options_replace_one_wins_other_conflicts(): void
    {
        $this->requireMysql('DF3-REST-A-OPTIONS-RACE');

        $admin = User::factory()->role(Role::Admin)->create();
        $definition = $this->createSelectDefinition('choice_race');
        $writer = app(FieldDefinitionOptionsWriter::class);
        $writer->replace($definition, [
            'lock_version' => $definition->lock_version,
            'options' => [
                ['key' => 'base', 'label' => 'Base', 'sort' => 1],
            ],
        ], $admin);
        $definition->refresh();

        $results = $this->runParallelWorkers(
            [
                'action' => 'replace_options',
                'payload' => [
                    'actor_id' => $admin->id,
                    'definition_id' => $definition->id,
                    'lock_version' => $definition->lock_version,
                    'options' => [
                        ['key' => 'base', 'label' => 'Worker A', 'sort' => 1],
                    ],
                    'orchestration' => [
                        'outer_transaction' => true,
                        'prelock' => ['definition_id' => $definition->id],
                        'signal_after_prelock' => 'a_holds',
                        'wait_after_prelock' => ['b_entered'],
                    ],
                ],
            ],
            [
                'action' => 'replace_options',
                'payload' => [
                    'actor_id' => $admin->id,
                    'definition_id' => $definition->id,
                    'lock_version' => $definition->lock_version,
                    'options' => [
                        ['key' => 'base', 'label' => 'Worker B', 'sort' => 1],
                    ],
                    'orchestration' => [
                        'outer_transaction' => true,
                        'signal_before' => 'b_entered',
                        'wait_before' => ['a_holds'],
                    ],
                ],
            ],
        );

        $outcomes = array_map(static fn (array $row): string => (string) $row['status'], $results);
        sort($outcomes);
        $this->assertSame(['conflict', 'ok'], $outcomes);

        $definition->refresh();
        $definition->load('currentRevision.options');
        $labels = $definition->currentRevision->options->pluck('label')->all();
        $this->assertCount(1, $labels);
        $this->assertTrue(in_array($labels[0], ['Worker A', 'Worker B'], true));
    }

    /**
     * @param  array{action: string, payload: array<string, mixed>}  $first
     * @param  array{action: string, payload: array<string, mixed>}  $second
     * @return list<array{status: string, detail?: string}>
     */
    private function runParallelWorkers(array $first, array $second): array
    {
        $runDir = storage_path('framework/testing/options-concurrency-'.uniqid('', true));
        mkdir($runDir, 0700, true);

        $script = base_path('tests/concurrency/field_definition_options_worker.php');
        $env = $this->workerEnvironment();

        $processes = [];
        foreach ([$first, $second] as $index => $spec) {
            $processes[] = new Process(
                [
                    PHP_BINARY,
                    $script,
                    $runDir,
                    (string) $index,
                    $spec['action'],
                    json_encode($spec['payload'], JSON_THROW_ON_ERROR),
                ],
                base_path(),
                $env,
                null,
                60,
            );
        }

        foreach ($processes as $process) {
            $process->start();
        }
        foreach ($processes as $process) {
            $process->wait();
        }

        $results = [];
        foreach ([0, 1] as $index) {
            $file = $runDir.'/worker-'.$index.'.result';
            $this->assertFileExists($file);
            $line = trim((string) file_get_contents($file));
            if (str_starts_with($line, 'OK:')) {
                $results[] = ['status' => 'ok', 'detail' => substr($line, 3)];
            } elseif (str_starts_with($line, 'ERROR:')) {
                $detail = substr($line, 6);
                $results[] = [
                    'status' => str_contains($detail, 'Conflict') || str_contains($detail, '409') || str_contains($detail, 'parallel')
                        ? 'conflict'
                        : 'error',
                    'detail' => $detail,
                ];
            } else {
                $this->fail('Unerwartetes Worker-Ergebnis: '.$line);
            }
        }

        return $results;
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
        $revision->label = 'Race Select';
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

    private function requireMysql(string $label): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped($label.' erfordert MySQL (GitHub-Job mysql).');
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
