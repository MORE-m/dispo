<?php

namespace App\Console\Commands\OnlineAudio;

use App\Console\Commands\OnlineAudio\Concerns\HandlesOaTkpImportCli;
use App\Services\OnlineAudio\Import\OaTkpImportJsonReader;
use App\Services\OnlineAudio\Import\OaTkpPriceListDraftImportGuard;
use App\Services\OnlineAudio\Import\OaTkpStammdatenImportException;
use Illuminate\Console\Command;

/**
 * PO-CALC-TKP-1D4c-2: Administrative CLI für OA-TKP-Preislisten (nur Draft).
 */
class ImportOaTkpPriceListCommand extends Command
{
    use HandlesOaTkpImportCli;

    protected $signature = 'oa:import-price-list
        {path : Absoluter oder relativer Pfad zur JSON-Datei (Preislisten-Objekt)}
        {--dry-run : Validieren ohne Datenbankmutation}';

    protected $description = 'OA-TKP-Preisliste als Draft aus JSON importieren (kein Activate)';

    public function handle(OaTkpImportJsonReader $reader, OaTkpPriceListDraftImportGuard $guard): int
    {
        $path = (string) $this->argument('path');
        $dryRun = (bool) $this->option('dry-run');
        $startedAt = now()->toIso8601String();

        try {
            $decoded = $reader->read($path);
            if (array_is_list($decoded)) {
                throw new OaTkpStammdatenImportException(
                    'TKP-Preislisten-Import ungültig.',
                    ['JSON-Wurzel muss ein Preislisten-Objekt sein (kein Array).'],
                );
            }

            /** @var array<string, mixed> $payload */
            $payload = $decoded;
            // Meta-Keys aus Skeleton-Vorlagen entfernen (kein Importer-Vertrag).
            unset($payload['_readme'], $payload['_validation']);

            $result = $guard->importDraftOnly($payload, $dryRun);
        } catch (OaTkpStammdatenImportException $exception) {
            $this->logImportOutcome('oa_tkp.price_list.import_failed', [
                'at' => $startedAt,
                'path' => $path,
                'dry_run' => $dryRun,
                'error_count' => count($exception->errors),
            ]);

            return $this->failureFromException($exception);
        }

        $this->info(sprintf(
            'OA-TKP-Preisliste %s: price_list_id=%s items_written=%d dry_run=%s year=%s revision=%s',
            $dryRun ? 'Dry-Run OK' : 'Draft-Import OK',
            $result['price_list_id'] === null ? 'null' : (string) $result['price_list_id'],
            (int) $result['items_written'],
            $dryRun ? 'yes' : 'no',
            (string) ($payload['year'] ?? ''),
            (string) ($payload['revision_number'] ?? '1'),
        ));

        $this->logImportOutcome('oa_tkp.price_list.import_ok', [
            'at' => $startedAt,
            'path' => $path,
            'dry_run' => $dryRun,
            'year' => (int) ($payload['year'] ?? 0),
            'revision_number' => (int) ($payload['revision_number'] ?? 1),
            'price_list_id' => $result['price_list_id'],
            'items_written' => (int) $result['items_written'],
        ]);

        return self::SUCCESS;
    }
}
