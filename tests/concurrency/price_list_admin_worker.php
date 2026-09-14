<?php

declare(strict_types=1);

/**
 * BL-P4-01a MySQL-Parallelitätsworker: Create/Copy/Update/Activate und Budget-Apply.
 * Orchestrierung nur in tests/ – keine Test-Hooks unter app/.
 */

use App\Enums\DayGroup;
use App\Exceptions\PriceListAdminConflictException;
use App\Exceptions\PriceListSelectionConflictException;
use App\Models\BudgetProposal;
use App\Models\Calculation;
use App\Models\Inventory;
use App\Models\PriceList;
use App\Models\PriceListImport;
use App\Models\User;
use App\Services\Calculation\CalculationWriter;
use App\Services\PriceList\Admin\PriceListAdminWriter;
use App\Services\PriceList\Admin\PriceListImpactPreviewService;
use App\Services\PriceList\Import\PriceListImportService;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

$runDir = $argv[1] ?? '';
$workerId = (int) ($argv[2] ?? -1);
$action = (string) ($argv[3] ?? '');
$payloadJson = (string) ($argv[4] ?? '{}');

if ($runDir === '' || $workerId < 0 || $action === '') {
    fwrite(STDERR, "Usage: price_list_admin_worker.php <run_dir> <worker_id> <action> <payload_json>\n");
    exit(1);
}

if (! is_dir($runDir) && ! mkdir($runDir, 0700, true) && ! is_dir($runDir)) {
    fwrite(STDERR, "Run directory could not be created: {$runDir}\n");
    exit(1);
}

/** @var array<string, mixed> $payload */
$payload = json_decode($payloadJson, true, 512, JSON_THROW_ON_ERROR);

/** @var Application $app */
$app = require __DIR__.'/bootstrap_mysql_worker.php';

$resultFile = $runDir.'/worker-'.$workerId.'.result';

/** @var array<string, mixed> $orch */
$orch = [];

/**
 * @param  list<string>  $files
 */
$waitForFiles = static function (array $files, float $seconds = 45.0) use ($runDir): void {
    $deadline = microtime(true) + $seconds;
    foreach ($files as $file) {
        while (! is_file($runDir.'/'.$file)) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException('Barrier timeout waiting for '.$file);
            }
            usleep(5_000);
        }
    }
};

$signal = static function (string $file) use ($runDir): void {
    file_put_contents($runDir.'/'.$file, '1');
};

$hourItems = static function (int $hour, string $price): array {
    return [
        ['hour' => $hour, 'day_group' => DayGroup::MoFr->value, 'second_price' => $price],
        ['hour' => $hour, 'day_group' => DayGroup::Sa->value, 'second_price' => $price],
        ['hour' => $hour, 'day_group' => DayGroup::So->value, 'second_price' => $price],
    ];
};

try {
    $actorId = (int) ($payload['actor_id'] ?? 0);
    $actor = $actorId > 0 ? User::query()->findOrFail($actorId) : null;

    $orch = is_array($payload['orchestration'] ?? null) ? $payload['orchestration'] : [];
    $useOuter = (bool) ($orch['outer_transaction'] ?? false);

    if (isset($orch['wait_before']) && is_array($orch['wait_before'])) {
        $waitForFiles(array_map('strval', $orch['wait_before']));
    }
    if (isset($orch['signal_before']) && is_string($orch['signal_before']) && $orch['signal_before'] !== '') {
        $signal($orch['signal_before']);
    }

    if ($useOuter) {
        DB::beginTransaction();
    }

    try {
        if ($useOuter && isset($orch['prelock']) && is_array($orch['prelock'])) {
            if (isset($orch['prelock']['inventory_id'])) {
                Inventory::query()
                    ->whereKey((int) $orch['prelock']['inventory_id'])
                    ->lockForUpdate()
                    ->firstOrFail();
            }
            if (isset($orch['signal_after_prelock']) && is_string($orch['signal_after_prelock']) && $orch['signal_after_prelock'] !== '') {
                $signal($orch['signal_after_prelock']);
            }
            if (isset($orch['wait_after_prelock']) && is_array($orch['wait_after_prelock'])) {
                $waitForFiles(array_map('strval', $orch['wait_after_prelock']));
            }
        }

        $writer = $app->make(PriceListAdminWriter::class);

        $result = match ($action) {
            'create_draft' => (function () use ($writer, $actor, $payload, $hourItems): string {
                $list = $writer->createDraft([
                    'inventory_id' => (int) $payload['inventory_id'],
                    'year' => (int) $payload['year'],
                    'name' => (string) $payload['name'],
                    'items' => is_array($payload['items'] ?? null)
                        ? $payload['items']
                        : $hourItems(8, '1.0000'),
                ], $actor);

                return 'OK:create_draft|'.$list->id.'|'.$list->version.'|'.$list->revision_number;
            })(),
            'copy_as_draft' => (function () use ($writer, $actor, $payload): string {
                $source = PriceList::query()->findOrFail((int) $payload['source_id']);
                $copy = $writer->copyAsDraft($source, [
                    'year' => (int) ($payload['year'] ?? $source->year),
                    'name' => (string) ($payload['name'] ?? $source->name.' Kopie'),
                ], $actor);

                return 'OK:copy_as_draft|'.$copy->id.'|'.$copy->version.'|'.$copy->year;
            })(),
            'update_draft' => (function () use ($writer, $actor, $payload, $hourItems): string {
                $list = PriceList::query()->findOrFail((int) $payload['price_list_id']);
                $updated = $writer->updateDraft($list, [
                    'name' => (string) $payload['name'],
                    'lock_version' => (int) $payload['lock_version'],
                    'items' => is_array($payload['items'] ?? null) ? $payload['items'] : $hourItems(8, '2.0000'),
                ], $actor);

                return 'OK:update_draft|'.$updated->id.'|'.$updated->lock_version.'|'.$updated->name;
            })(),
            'activate' => (function () use ($app, $writer, $actor, $payload): string {
                $list = PriceList::query()->findOrFail((int) $payload['price_list_id']);
                $fingerprint = (string) ($payload['fingerprint'] ?? '');
                if ($fingerprint === '') {
                    $preview = $app->make(PriceListImpactPreviewService::class)->previewActivate($list);
                    $fingerprint = (string) $preview['fingerprint'];
                }
                $updated = $writer->activate($list, [
                    'lock_version' => (int) ($payload['lock_version'] ?? $list->lock_version),
                    'fingerprint' => $fingerprint,
                ], $actor);

                return 'OK:activate|'.$updated->id.'|'.$updated->status->value.'|'.$updated->lock_version;
            })(),
            'apply_budget' => (function () use ($app, $actor, $payload): string {
                $calculation = Calculation::query()->findOrFail((int) $payload['calculation_id']);
                $proposal = BudgetProposal::query()->findOrFail((int) $payload['proposal_id']);
                $writer = $app->make(CalculationWriter::class);
                $updated = $writer->applyBudgetProposal($calculation, $proposal, $actor);
                $position = $updated->positions()->first();

                return 'OK:apply_budget|'.$updated->id.'|'.($position?->price_list_id ?? 0).'|'.$updated->positions()->count();
            })(),
            'rebind_calculation_year' => (function () use ($app, $actor, $payload): string {
                $calculation = Calculation::query()->with([
                    'positions.planRows',
                    'positions.timeRanges',
                    'positions.discounts',
                    'orderDiscounts',
                    'configurationSnapshot',
                    'fieldValues',
                ])->findOrFail((int) $payload['calculation_id']);
                $writer = $app->make(CalculationWriter::class);
                $body = $writer->payloadFromCalculation($calculation);
                $body['lock_version'] = (int) ($payload['lock_version'] ?? $calculation->lock_version);
                $body['positions'][0]['price_year'] = (int) $payload['price_year'];
                $body['positions'][0]['expected_price_list_id'] = (int) $payload['expected_price_list_id'];
                if (isset($payload['total_spot_count'])) {
                    $spotCount = (int) $payload['total_spot_count'];
                    $body['positions'][0]['total_spot_count'] = $spotCount;
                    if (isset($body['positions'][0]['time_ranges']) && is_array($body['positions'][0]['time_ranges'])) {
                        foreach ($body['positions'][0]['time_ranges'] as $rangeIndex => $range) {
                            if (is_array($range)) {
                                $body['positions'][0]['time_ranges'][$rangeIndex]['spot_count'] = $spotCount;
                            }
                        }
                    }
                }
                $updated = $writer->update($calculation, $body, $actor);
                $position = $updated->positions()->firstOrFail();

                return 'OK:rebind_calculation_year|'.$position->price_list_id.'|'.$position->total_spot_count;
            })(),
            'confirm_price_list_import' => (function () use ($app, $actor, $payload): string {
                $import = PriceListImport::query()->findOrFail((int) $payload['import_id']);
                $lists = $app->make(PriceListImportService::class)
                    ->confirm($import, (string) $payload['fingerprint'], $actor);

                return 'OK:confirm_price_list_import|'.$import->fresh()->status->value.'|'.count($lists);
            })(),
            default => throw new InvalidArgumentException('Unknown action: '.$action),
        };

        if ($useOuter) {
            DB::commit();
        }

        file_put_contents($resultFile, $result);
    } catch (Throwable $inner) {
        if ($useOuter && DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        throw $inner;
    }
} catch (Throwable $exception) {
    $class = $exception::class;
    $message = Str::limit($exception->getMessage(), 240);
    if ($exception instanceof ValidationException) {
        $message = Str::limit(json_encode($exception->errors(), JSON_UNESCAPED_UNICODE) ?: $message, 240);
    }
    if ($exception instanceof PriceListAdminConflictException) {
        $message = Str::limit($exception->getMessage(), 240);
    }
    if ($exception instanceof PriceListSelectionConflictException) {
        $message = Str::limit($exception->getMessage(), 240);
    }
    file_put_contents($resultFile, 'ERROR:'.$class.'|'.$message);
}
