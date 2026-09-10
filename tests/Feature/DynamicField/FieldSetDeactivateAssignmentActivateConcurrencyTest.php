<?php

namespace Tests\Feature\DynamicField;

use App\Enums\FieldAppliesTo;
use App\Enums\FieldScope;
use App\Enums\FieldType;
use App\Enums\Role;
use App\Exceptions\FieldSetAssignmentConflictException;
use App\Models\FieldSet;
use App\Models\FieldSetAssignment;
use App\Models\FieldSetVersion;
use App\Models\User;
use App\Services\DynamicField\Admin\FieldDefinitionCustomWriter;
use App\Services\DynamicField\Assignment\FieldSetAssignmentAdminWriter;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * DF-3.3-fs-HF1/HF2: echte parallele MySQL-Transaktionen
 * Assignment-Activate vs. Feldset-Deactivate.
 *
 * HF2: Verlierer darf ValidationException oder Fingerprint-409
 * (FieldSetAssignmentConflictException) sein – timingabhängig, beide zulässig.
 *
 * Orchestrierung nur in tests/ – keine Test-Hooks unter app/.
 */
class FieldSetDeactivateAssignmentActivateConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_hf1_race_deactivate_holds_fieldset_before_activate(): void
    {
        $this->requireMysql('HF1-RACE-01');
        $this->runRaceOnce(holder: 'deactivate');
    }

    public function test_hf1_race_activate_holds_before_deactivate(): void
    {
        $this->requireMysql('HF1-RACE-02');
        $this->runRaceOnce(holder: 'activate');
    }

    public function test_hf1_race_deactivate_holds_fieldset_before_activate_repeat(): void
    {
        $this->requireMysql('HF1-RACE-01-REPEAT');
        $this->runRaceOnce(holder: 'deactivate');
    }

    public function test_hf1_race_activate_holds_before_deactivate_repeat(): void
    {
        $this->requireMysql('HF1-RACE-02-REPEAT');
        $this->runRaceOnce(holder: 'activate');
    }

    private function runRaceOnce(string $holder): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        [$fieldSet, $assignment, $fingerprint] = $this->prepareInactiveAssignment($admin);

        if ($holder === 'deactivate') {
            $results = $this->runParallelWorkers(
                [
                    'action' => 'deactivate_fieldset',
                    'payload' => [
                        'actor_id' => $admin->id,
                        'field_set_id' => $fieldSet->id,
                        'lock_version' => $fieldSet->lock_version,
                        'orchestration' => [
                            'outer_transaction' => true,
                            'prelock' => ['field_set_id' => $fieldSet->id],
                            'signal_after_prelock' => 'deactivate_holds_fieldset',
                            'wait_after_prelock' => ['activate_entered'],
                        ],
                    ],
                ],
                [
                    'action' => 'activate_assignment',
                    'payload' => [
                        'actor_id' => $admin->id,
                        'assignment_id' => $assignment->id,
                        'lock_version' => $assignment->lock_version,
                        'fingerprint' => $fingerprint,
                        'orchestration' => [
                            'wait_before' => ['deactivate_holds_fieldset'],
                            'signal_before' => 'activate_entered',
                        ],
                    ],
                ],
            );
        } else {
            // Freier Parallelstart ohne Lock über Barrieren (CI-sicher).
            $results = $this->runParallelWorkers(
                [
                    'action' => 'activate_assignment',
                    'payload' => [
                        'actor_id' => $admin->id,
                        'assignment_id' => $assignment->id,
                        'lock_version' => $assignment->lock_version,
                        'fingerprint' => $fingerprint,
                    ],
                ],
                [
                    'action' => 'deactivate_fieldset',
                    'payload' => [
                        'actor_id' => $admin->id,
                        'field_set_id' => $fieldSet->id,
                        'lock_version' => $fieldSet->lock_version,
                    ],
                ],
            );
        }

        $this->assertCount(2, $results);
        $this->assertNoDeadlockOrLockTimeout($results);
        $this->assertExactlyOneWinner($results);
        $this->assertInvariantNoActiveAssignmentOnNonAssignableFieldSet($fieldSet->id);
    }

    /**
     * @return array{0: FieldSet, 1: FieldSetAssignment, 2: string}
     */
    private function prepareInactiveAssignment(User $admin): array
    {
        $key = 'hf1_race_'.bin2hex(random_bytes(3));
        $this->actingAs($admin)
            ->post(route('administration.dynamic-fields.field-sets.store'), [
                'name' => 'Race '.$key,
                'key' => $key,
                'applies_to' => FieldAppliesTo::Both->value,
            ])
            ->assertRedirect();

        $fieldSet = FieldSet::query()->where('key', $key)->firstOrFail();
        $draft = FieldSetVersion::query()
            ->where('field_set_id', $fieldSet->id)
            ->where('status', 'draft')
            ->firstOrFail();

        $definition = app(FieldDefinitionCustomWriter::class)->create([
            'label' => 'Race Feld '.$key,
            'field_type' => FieldType::ShortText,
            'scope' => FieldScope::Header,
            'applies_to' => FieldAppliesTo::Both,
            'max_length' => 80,
        ], $admin);

        $this->actingAs($admin)
            ->post(route('administration.dynamic-fields.field-sets.versions.memberships.store', [$fieldSet, $draft]), [
                'lock_version' => $fieldSet->fresh()->lock_version,
                'field_definition_id' => $definition->id,
                'field_definition_revision_id' => $definition->current_revision_id,
                'sort' => 20,
            ])
            ->assertRedirect();

        $this->actingAs($admin)
            ->post(route('administration.dynamic-fields.field-sets.versions.activate', [$fieldSet, $draft]), [
                'lock_version' => $fieldSet->fresh()->lock_version,
            ])
            ->assertRedirect();

        $writer = app(FieldSetAssignmentAdminWriter::class);
        $assignment = $writer->create([
            'field_set_id' => $fieldSet->id,
            'target_layer' => 'global',
            'applies_to_process' => FieldAppliesTo::Calculation->value,
        ], $admin);

        $fingerprint = $writer->canonicalActivationFingerprint(
            $writer->previewAffectedContexts($assignment, asCandidate: true),
        );

        return [
            $fieldSet->fresh() ?? $fieldSet,
            $assignment->fresh() ?? $assignment,
            $fingerprint,
        ];
    }

    /**
     * @param  array{action: string, payload: array<string, mixed>}  $workerA
     * @param  array{action: string, payload: array<string, mixed>}  $workerB
     * @return list<string>
     */
    private function runParallelWorkers(array $workerA, array $workerB): array
    {
        $runDir = sys_get_temp_dir().'/dispo-hf1-concurrency-'.Str::uuid();
        if (! mkdir($runDir, 0700, true) && ! is_dir($runDir)) {
            $this->fail('Temporäres Barrier-Verzeichnis konnte nicht erstellt werden.');
        }

        try {
            $script = base_path('tests/concurrency/fieldset_deactivate_assignment_activate_worker.php');
            $baseEnv = $this->workerEnvironment();

            $processA = $this->makeWorkerProcess($script, $runDir, '0', $workerA, $baseEnv);
            $processB = $this->makeWorkerProcess($script, $runDir, '1', $workerB, $baseEnv);

            $processA->setTimeout(60);
            $processB->setTimeout(60);
            $processA->start();
            $processB->start();
            $processA->wait();
            $processB->wait();

            $results = [];
            foreach (glob($runDir.'/worker-*.result') ?: [] as $resultFile) {
                $results[] = trim((string) file_get_contents($resultFile));
            }

            $this->assertCount(2, $results, 'Beide Worker müssen ein Resultat schreiben. stderr='
                .$processA->getErrorOutput().' | '.$processB->getErrorOutput());

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
     * @param  array{action: string, payload: array<string, mixed>}  $worker
     * @param  array<string, string>  $baseEnv
     */
    private function makeWorkerProcess(
        string $script,
        string $runDir,
        string $workerId,
        array $worker,
        array $baseEnv,
    ): Process {
        return new Process(
            [
                PHP_BINARY,
                $script,
                $runDir,
                $workerId,
                $worker['action'],
                json_encode($worker['payload'], JSON_THROW_ON_ERROR),
            ],
            null,
            $baseEnv,
        );
    }

    /**
     * @param  list<string>  $results
     */
    private function assertExactlyOneWinner(array $results): void
    {
        $ok = array_values(array_filter($results, fn (string $line): bool => str_starts_with($line, 'OK:')));
        $errors = array_values(array_filter($results, fn (string $line): bool => str_starts_with($line, 'ERROR:')));

        $this->assertCount(1, $ok, 'Genau eine Mutation muss erfolgreich sein: '.implode(' || ', $results));
        $this->assertCount(1, $errors, 'Genau eine Mutation muss kontrolliert scheitern: '.implode(' || ', $results));
        $this->assertTrue(
            str_starts_with($ok[0], 'OK:deactivate_fieldset') || str_starts_with($ok[0], 'OK:activate_assignment'),
            'Unerwarteter Winner: '.$ok[0],
        );
        $this->assertValidRaceLoser($errors[0]);
    }

    /**
     * DF-3.3fs-HF2: abhängig vom Race-Timing sind beide kontrollierten Verliererpfade zulässig.
     */
    private function assertValidRaceLoser(string $errorLine): void
    {
        $payload = substr($errorLine, strlen('ERROR:'));
        $separator = strpos($payload, '|');
        $this->assertNotFalse($separator, 'ERROR-Zeile ohne Klassen-/Nachrichten-Trenner: '.$errorLine);

        $class = substr($payload, 0, $separator);
        $message = substr($payload, $separator + 1);

        if ($class === ValidationException::class) {
            return;
        }

        if ($class === FieldSetAssignmentConflictException::class) {
            $this->assertSame(
                'Preview-Fingerprint veraltet',
                $message,
                'FieldSetAssignmentConflictException muss den Fingerprint-Konflikt melden: '.$errorLine,
            );

            return;
        }

        $this->fail(
            'Verlierer muss ValidationException oder FieldSetAssignmentConflictException sein, got: '.$errorLine,
        );
    }

    /**
     * @param  list<string>  $results
     */
    private function assertNoDeadlockOrLockTimeout(array $results): void
    {
        foreach ($results as $line) {
            $this->assertTrue(
                str_starts_with($line, 'OK:') || str_starts_with($line, 'ERROR:'),
                'Unerwartetes Worker-Resultat: '.$line,
            );
            foreach (['Deadlock', '1213', '1205', 'Lock wait timeout', 'lock wait timeout'] as $needle) {
                $this->assertStringNotContainsString($needle, $line);
            }
        }
    }

    private function assertInvariantNoActiveAssignmentOnNonAssignableFieldSet(int $fieldSetId): void
    {
        $violations = DB::table('field_set_assignments as a')
            ->join('field_sets as f', 'f.id', '=', 'a.field_set_id')
            ->where('a.field_set_id', $fieldSetId)
            ->where('a.is_active', true)
            ->where('f.is_assignable', false)
            ->count();

        $this->assertSame(
            0,
            $violations,
            'Invariante verletzt: aktives Assignment auf nicht assignierbarem Feldset.',
        );
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
