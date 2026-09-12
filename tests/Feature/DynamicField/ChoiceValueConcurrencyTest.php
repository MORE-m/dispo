<?php

namespace Tests\Feature\DynamicField;

use App\Enums\FieldAppliesTo;
use App\Enums\FieldScope;
use App\Enums\FieldType;
use App\Enums\Role;
use App\Models\Calculation;
use App\Models\CalculationFieldValue;
use App\Models\DispoOrder;
use App\Models\DispoOrderFieldValue;
use App\Models\FieldDefinition;
use App\Models\FieldSet;
use App\Models\FieldSetVersion;
use App\Models\SnapshotFieldDefinition;
use App\Models\User;
use App\Services\Calculation\CalculationWriter;
use App\Services\DispoOrder\DispoOrderWriter;
use App\Services\DynamicField\Admin\AdminFieldSetCatalog;
use App\Services\DynamicField\Admin\FieldDefinitionCustomWriter;
use App\Services\DynamicField\Admin\FieldDefinitionOptionsWriter;
use App\Services\DynamicField\Admin\FieldSetVersionAdminWriter;
use App\Services\DynamicField\ConfigurationSnapshotMaterializer;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Concerns\CreatesSpotClassicCatalog;
use Tests\TestCase;

/**
 * DF-3-REST-C1: echte parallele Choice-Saves gegen dispo_test.
 *
 * Isolation: jedes Szenario nutzt ein UUID-Run-Verzeichnis; Worker-Barrieren und
 * Result-Dateien teilen keine festen Pfade. MysqlTestDatabaseGuard greift im
 * Worker-Bootstrap und in TestCase::setUpTraits.
 */
class ChoiceValueConcurrencyTest extends TestCase
{
    use CreatesSpotClassicCatalog;
    use DatabaseMigrations;

    public function test_parallel_choice_updates_one_wins_other_conflicts(): void
    {
        $this->requireMysql();

        $admin = User::factory()->role(Role::Admin)->create();
        $definition = $this->activateChoice($admin, FieldType::Select, FieldAppliesTo::Both, [
            ['key' => 'opt_a', 'label' => 'A', 'sort' => 1],
            ['key' => 'opt_b', 'label' => 'B', 'sort' => 2],
            ['key' => 'opt_c', 'label' => 'C', 'sort' => 3],
        ]);
        $user = User::factory()->role(Role::Sales)->create();
        $calculation = $this->createCalcWithChoice($user, $definition->key, 'opt_a');

        $results = $this->runTwoCalcChoiceWorkers(
            $calculation->id,
            (int) $calculation->lock_version,
            $definition->key,
            'opt_b',
            'opt_c',
        );

        $this->assertOneOkOneConflict($results);
        $winner = $this->winnerPayload($results);
        $this->assertContains($winner, ['opt_b', 'opt_c']);
        $this->assertSame($winner, $this->calcHeaderChoice($calculation->id, $definition->key));
        $this->assertSame(
            (int) $calculation->lock_version + 1,
            (int) $calculation->fresh()->lock_version,
        );
    }

    public function test_parallel_multi_select_lists_do_not_merge(): void
    {
        $this->requireMysql();

        $admin = User::factory()->role(Role::Admin)->create();
        $definition = $this->activateChoice($admin, FieldType::MultiSelect, FieldAppliesTo::Both, [
            ['key' => 'opt_a', 'label' => 'A', 'sort' => 1],
            ['key' => 'opt_b', 'label' => 'B', 'sort' => 2],
            ['key' => 'opt_c', 'label' => 'C', 'sort' => 3],
            ['key' => 'opt_d', 'label' => 'D', 'sort' => 4],
        ]);
        $user = User::factory()->role(Role::Sales)->create();
        $calculation = $this->createCalcWithChoice($user, $definition->key, ['opt_a']);

        $left = json_encode(['opt_c', 'opt_a'], JSON_THROW_ON_ERROR);
        $right = json_encode(['opt_d', 'opt_b'], JSON_THROW_ON_ERROR);
        $results = $this->runTwoCalcChoiceWorkers(
            $calculation->id,
            (int) $calculation->lock_version,
            $definition->key,
            $left,
            $right,
        );

        $this->assertOneOkOneConflict($results);
        $winner = $this->winnerPayload($results);
        $decoded = json_decode($winner, true);
        $this->assertIsArray($decoded);
        sort($decoded);
        $this->assertTrue(
            $decoded === ['opt_a', 'opt_c'] || $decoded === ['opt_b', 'opt_d'],
            'Gewinnerliste muss eine der Payloads sein, keine Union.',
        );
        $stored = $this->calcHeaderChoice($calculation->id, $definition->key);
        $this->assertSame($decoded, $stored);
        $this->assertSame(
            (int) $calculation->lock_version + 1,
            (int) $calculation->fresh()->lock_version,
        );
    }

    public function test_parallel_native_dispo_header_choice_one_wins(): void
    {
        $this->requireMysql();

        $admin = User::factory()->role(Role::Admin)->create();
        $definition = $this->activateChoice($admin, FieldType::Select, FieldAppliesTo::DispoOrder, [
            ['key' => 'opt_a', 'label' => 'A', 'sort' => 1],
            ['key' => 'opt_b', 'label' => 'B', 'sort' => 2],
            ['key' => 'opt_c', 'label' => 'C', 'sort' => 3],
        ], calcSet: false, dispoSet: true);

        $user = User::factory()->role(Role::Sales)->create();
        $catalog = $this->createSpotClassicCatalog();
        $calculation = app(CalculationWriter::class)->create($this->withLiveSchemaFingerprint([
            'planning_mode' => 'manual',
            'customer_name' => 'Kunde',
            'agency_name' => 'Agentur',
            'campaign' => 'Kampagne',
            'product_title' => 'Produkt',
            'order_discount_percent' => '0',
            'ae_enabled' => false,
            'dynamic_field_values' => [],
            'positions' => [$this->positionPayload($catalog)],
        ]), $user);

        $order = app(DispoOrderWriter::class)
            ->createFromCalculation($calculation, $calculation->positions()->pluck('id')->all(), $user)
            ->order;

        // Header-Pfad: derselbe DispoOrder-lockForUpdate wie Position-Customs.
        $lockVersion = (int) $order->lock_version;
        $runDir = $this->newRunDir('dispo-hdr');
        try {
            $script = base_path('tests/concurrency/choice_value_dispo_update_worker.php');
            $env = $this->workerEnvironment();
            $w0 = new Process([PHP_BINARY, $script, $runDir, '0', (string) $order->id, (string) $lockVersion, $definition->key, 'opt_b'], null, $env);
            $w1 = new Process([PHP_BINARY, $script, $runDir, '1', (string) $order->id, (string) $lockVersion, $definition->key, 'opt_c'], null, $env);
            $w0->start();
            $w1->start();
            $this->assertSame(0, $w0->wait(), $w0->getErrorOutput() ?: $w0->getOutput());
            $this->assertSame(0, $w1->wait(), $w1->getErrorOutput() ?: $w1->getOutput());
            $results = $this->readResults($runDir);
            $this->assertOneOkOneConflict($results);
            $winner = $this->winnerPayload($results);
            $this->assertContains($winner, ['opt_b', 'opt_c']);
            $this->assertSame($winner, $this->dispoHeaderChoice($order->id, $definition->key));
            $this->assertSame($lockVersion + 1, (int) $order->fresh()->lock_version);
            $this->assertSame(
                1,
                DispoOrderFieldValue::query()
                    ->where('dispo_order_id', $order->id)
                    ->whereHas('snapshotFieldDefinition', fn ($q) => $q->where('key', $definition->key))
                    ->count(),
            );
        } finally {
            $this->cleanupRunDir($runDir);
        }
    }

    public function test_dispo_create_versus_calc_choice_update_is_consistent(): void
    {
        $this->requireMysql();

        $admin = User::factory()->role(Role::Admin)->create();
        $definition = $this->activateChoice($admin, FieldType::Select, FieldAppliesTo::Both, [
            ['key' => 'opt_a', 'label' => 'A', 'sort' => 1],
            ['key' => 'opt_b', 'label' => 'B', 'sort' => 2],
        ]);
        $user = User::factory()->role(Role::Sales)->create();
        $calculation = $this->createCalcWithChoice($user, $definition->key, 'opt_a');
        $positionId = (int) $calculation->positions()->value('id');
        $lockVersion = (int) $calculation->lock_version;

        $runDir = $this->newRunDir('create-vs-calc');
        try {
            $env = $this->workerEnvironment();
            $create = new Process([
                PHP_BINARY,
                base_path('tests/concurrency/choice_value_dispo_create_worker.php'),
                $runDir,
                '0',
                (string) $calculation->id,
                (string) $positionId,
                '2',
            ], null, $env);
            $update = new Process([
                PHP_BINARY,
                base_path('tests/concurrency/choice_value_calc_update_worker.php'),
                $runDir,
                '1',
                (string) $calculation->id,
                (string) $lockVersion,
                $definition->key,
                'opt_b',
                '2',
            ], null, $env);

            $create->start();
            $update->start();
            $this->assertSame(0, $create->wait(), $create->getErrorOutput() ?: $create->getOutput());
            $this->assertSame(0, $update->wait(), $update->getErrorOutput() ?: $update->getOutput());

            $results = $this->readResults($runDir);
            $createLines = array_values(array_filter($results, fn (string $r): bool => str_starts_with($r, 'ok:') && str_contains($r, '|')));
            $updateLines = array_values(array_filter($results, fn (string $r): bool => str_starts_with($r, 'ok:opt_') || str_starts_with($r, 'conflict:')));
            $this->assertCount(1, $createLines, 'Dispo-Create muss genau einmal erfolgreich sein.');
            $this->assertCount(1, $updateLines);

            $parts = explode('|', substr($createLines[0], 3));
            $orderId = (int) $parts[0];
            $positionCount = (int) $parts[1];
            $this->assertSame(1, $positionCount);
            $this->assertSame(1, DispoOrder::query()->where('calculation_id', $calculation->id)->count());

            $calcValue = $this->calcHeaderChoice($calculation->id, $definition->key);
            $dispoValue = $this->dispoHeaderChoice($orderId, $definition->key);
            $this->assertContains($dispoValue, ['opt_a', 'opt_b']);
            $this->assertContains($calcValue, ['opt_a', 'opt_b']);
            // Konsistenz: Dispo spiegelt einen vollständigen Calc-Stand (vor oder nach Update).
            if (str_starts_with($updateLines[0], 'ok:')) {
                $this->assertSame('opt_b', $calcValue);
            }
            $this->assertTrue(
                ($dispoValue === 'opt_a' && in_array($calcValue, ['opt_a', 'opt_b'], true))
                || ($dispoValue === 'opt_b' && $calcValue === 'opt_b'),
                'Dispo-Choice und Calc-Choice dürfen keinen gemischten Zwischenstand bilden.',
            );
        } finally {
            $this->cleanupRunDir($runDir);
        }
    }

    public function test_stale_lock_cannot_relegitimise_removed_inactive_multi_key(): void
    {
        $this->requireMysql();

        $admin = User::factory()->role(Role::Admin)->create();
        $definition = $this->activateChoice($admin, FieldType::MultiSelect, FieldAppliesTo::Both, [
            ['key' => 'opt_a', 'label' => 'A', 'sort' => 1],
            ['key' => 'opt_b', 'label' => 'B', 'sort' => 2],
        ]);
        $user = User::factory()->role(Role::Sales)->create();
        $calculation = $this->createCalcWithChoice($user, $definition->key, ['opt_a', 'opt_b']);

        SnapshotFieldDefinition::query()
            ->where('configuration_snapshot_id', $calculation->configuration_snapshot_id)
            ->where('key', $definition->key)
            ->update([
                'options_json' => json_encode([
                    ['key' => 'opt_a', 'label' => 'A', 'sort' => 1, 'is_active' => true],
                    ['key' => 'opt_b', 'label' => 'B', 'sort' => 2, 'is_active' => false],
                ], JSON_THROW_ON_ERROR),
            ]);

        $lockVersion = (int) $calculation->lock_version;
        $removeInactive = json_encode(['opt_a'], JSON_THROW_ON_ERROR);
        $readdInactive = json_encode(['opt_a', 'opt_b'], JSON_THROW_ON_ERROR);

        $results = $this->runTwoCalcChoiceWorkers(
            $calculation->id,
            $lockVersion,
            $definition->key,
            $removeInactive,
            $readdInactive,
        );

        $this->assertOneOkOneConflict($results);
        $winner = $this->winnerPayload($results);
        $decoded = json_decode($winner, true);
        $this->assertIsArray($decoded);
        // Gewinner ist entweder Entfernen oder Behalten; Verlierer hat stale lock.
        $stored = $this->calcHeaderChoice($calculation->id, $definition->key);
        $this->assertSame($decoded, $stored);
        $this->assertSame($lockVersion + 1, (int) $calculation->fresh()->lock_version);

        // Nach Entfernen: erneutes Hinzufügen mit frischer Version muss 422 sein.
        if ($stored === ['opt_a']) {
            $payload = app(CalculationWriter::class)->payloadFromCalculation($calculation->fresh([
                'positions.planRows',
                'positions.timeRanges',
                'positions.discounts',
                'orderDiscounts',
                'configurationSnapshot',
                'fieldValues',
            ]));
            $payload['lock_version'] = $calculation->fresh()->lock_version;
            $payload['dynamic_field_values'][$definition->key] = ['opt_a', 'opt_b'];
            $this->actingAs($user)
                ->put(route('calculations.update', $calculation->fresh()), $payload)
                ->assertSessionHasErrors('dynamic_field_values.'.$definition->key);
            $this->assertSame(['opt_a'], $this->calcHeaderChoice($calculation->id, $definition->key));
        }
    }

    private function requireMysql(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Choice-Concurrency erfordert MySQL (dispo_test).');
        }
    }

    /**
     * @param  list<array{key: string, label: string, sort: int}>  $options
     */
    private function activateChoice(
        User $admin,
        FieldType $fieldType,
        FieldAppliesTo $appliesTo,
        array $options,
        bool $calcSet = true,
        bool $dispoSet = true,
    ): FieldDefinition {
        $definition = app(FieldDefinitionCustomWriter::class)->create([
            'label' => 'Choice Race '.$fieldType->value.' '.bin2hex(random_bytes(2)),
            'field_type' => $fieldType,
            'scope' => FieldScope::Header,
            'applies_to' => $appliesTo,
            'sort_default' => 80,
            'reportable' => false,
        ], $admin);

        app(FieldDefinitionOptionsWriter::class)->replace($definition, [
            'lock_version' => $definition->lock_version,
            'options' => $options,
        ], $admin);
        $definition->refresh();

        $setWriter = app(FieldSetVersionAdminWriter::class);
        if ($calcSet) {
            $this->pinToSet($admin, $setWriter, AdminFieldSetCatalog::CALCULATION_CORE, $definition);
        }
        if ($dispoSet) {
            $this->pinToSet($admin, $setWriter, AdminFieldSetCatalog::DISPO_ORDER_CORE, $definition);
        }
        app(ConfigurationSnapshotMaterializer::class)
            ->materializeFromActiveSet(AdminFieldSetCatalog::CALCULATION_CORE);

        return $definition->fresh(['currentRevision']) ?? $definition;
    }

    private function pinToSet(
        User $admin,
        FieldSetVersionAdminWriter $setWriter,
        string $setKey,
        FieldDefinition $definition,
    ): void {
        $fieldSet = FieldSet::query()->where('key', $setKey)->firstOrFail();
        $source = FieldSetVersion::query()->whereKey($fieldSet->active_version_id)->firstOrFail();
        $draft = $setWriter->createDraftFromVersion($fieldSet, $source, $admin, $fieldSet->lock_version);
        $fieldSet->refresh();
        $setWriter->addCustomMembership($fieldSet, $draft, [
            'field_definition_id' => $definition->id,
            'field_definition_revision_id' => (int) $definition->current_revision_id,
            'sort' => 80,
            'lock_version' => $fieldSet->lock_version,
        ], $admin);
        $fieldSet->refresh();
        $setWriter->activateDraft($fieldSet, $draft, $admin, $fieldSet->lock_version);
    }

    /**
     * @param  string|list<string>  $value
     */
    private function createCalcWithChoice(User $user, string $key, string|array $value): Calculation
    {
        $catalog = $this->createSpotClassicCatalog();

        return app(CalculationWriter::class)->create($this->withLiveSchemaFingerprint([
            'planning_mode' => 'manual',
            'customer_name' => 'Kunde',
            'agency_name' => 'Agentur',
            'campaign' => 'Kampagne',
            'product_title' => 'Produkt',
            'order_discount_percent' => '0',
            'ae_enabled' => false,
            'dynamic_field_values' => [$key => $value],
            'positions' => [$this->positionPayload($catalog)],
        ]), $user);
    }

    /**
     * @return list<string>
     */
    private function runTwoCalcChoiceWorkers(
        int $calculationId,
        int $lockVersion,
        string $fieldKey,
        string $value0,
        string $value1,
    ): array {
        $runDir = $this->newRunDir('calc');
        try {
            $script = base_path('tests/concurrency/choice_value_calc_update_worker.php');
            $env = $this->workerEnvironment();
            $w0 = new Process([PHP_BINARY, $script, $runDir, '0', (string) $calculationId, (string) $lockVersion, $fieldKey, $value0], null, $env);
            $w1 = new Process([PHP_BINARY, $script, $runDir, '1', (string) $calculationId, (string) $lockVersion, $fieldKey, $value1], null, $env);
            $w0->start();
            $w1->start();
            $this->assertSame(0, $w0->wait(), $w0->getErrorOutput() ?: $w0->getOutput());
            $this->assertSame(0, $w1->wait(), $w1->getErrorOutput() ?: $w1->getOutput());

            return $this->readResults($runDir);
        } finally {
            $this->cleanupRunDir($runDir);
        }
    }

    /**
     * @param  list<string>  $results
     */
    private function assertOneOkOneConflict(array $results): void
    {
        $oks = array_values(array_filter($results, fn (string $row): bool => str_starts_with($row, 'ok:')));
        $conflicts = array_values(array_filter($results, fn (string $row): bool => str_starts_with($row, 'conflict:')));
        $this->assertCount(1, $oks, 'Ergebnisse: '.implode(',', $results));
        $this->assertCount(1, $conflicts, 'Ergebnisse: '.implode(',', $results));
    }

    /**
     * @param  list<string>  $results
     */
    private function winnerPayload(array $results): string
    {
        foreach ($results as $row) {
            if (str_starts_with($row, 'ok:')) {
                return substr($row, 3);
            }
        }
        $this->fail('Kein Winner.');
    }

    private function calcHeaderChoice(int $calculationId, string $key): mixed
    {
        $calculation = Calculation::query()->findOrFail($calculationId);
        $snapDef = SnapshotFieldDefinition::query()
            ->where('configuration_snapshot_id', $calculation->configuration_snapshot_id)
            ->where('key', $key)
            ->firstOrFail();

        return CalculationFieldValue::query()
            ->where('calculation_id', $calculationId)
            ->where('snapshot_field_definition_id', $snapDef->id)
            ->firstOrFail()
            ->value_json;
    }

    private function dispoHeaderChoice(int $orderId, string $key): mixed
    {
        $order = DispoOrder::query()->findOrFail($orderId);
        $snapDef = SnapshotFieldDefinition::query()
            ->where('configuration_snapshot_id', $order->configuration_snapshot_id)
            ->where('key', $key)
            ->firstOrFail();

        return DispoOrderFieldValue::query()
            ->where('dispo_order_id', $orderId)
            ->where('snapshot_field_definition_id', $snapDef->id)
            ->firstOrFail()
            ->value_json;
    }

    /**
     * @return list<string>
     */
    private function readResults(string $runDir): array
    {
        $results = [];
        foreach (glob($runDir.'/worker-*.result') ?: [] as $resultFile) {
            $results[] = trim((string) file_get_contents($resultFile));
        }
        sort($results);

        return $results;
    }

    private function newRunDir(string $label): string
    {
        $runDir = sys_get_temp_dir().'/dispo-c1-'.$label.'-'.Str::uuid();
        if (! mkdir($runDir, 0700, true) && ! is_dir($runDir)) {
            $this->fail('Temporäres Barrier-Verzeichnis konnte nicht erstellt werden.');
        }

        return $runDir;
    }

    private function cleanupRunDir(string $runDir): void
    {
        foreach (glob($runDir.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($runDir);
    }

    /**
     * @param  array<string, mixed>  $catalog
     * @return array<string, mixed>
     */
    private function positionPayload(array $catalog): array
    {
        return [
            'inventory_id' => $catalog['hamburg']->id,
            'advertising_medium_id' => $catalog['medium']->id,
            'spot_method' => 'average',
            'length_seconds' => 30,
            'total_spot_count' => 10,
            'position_discount_percent' => '0',
            'ae_percent' => '15',
            'plan_rows' => [['hour' => 8, 'day_group' => 'mo_fr']],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function workerEnvironment(): array
    {
        return [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'mysql',
            'DB_DATABASE' => 'dispo_test',
            'DB_HOST' => (string) env('DB_HOST', '127.0.0.1'),
            'DB_PORT' => (string) env('DB_PORT', '3306'),
            'DB_USERNAME' => (string) env('DB_USERNAME', 'root'),
            'DB_PASSWORD' => (string) env('DB_PASSWORD', ''),
        ];
    }
}
