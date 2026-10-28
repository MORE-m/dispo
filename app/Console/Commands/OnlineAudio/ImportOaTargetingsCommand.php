<?php

namespace App\Console\Commands\OnlineAudio;

use App\Console\Commands\OnlineAudio\Concerns\HandlesOaTkpImportCli;
use App\Services\OnlineAudio\Import\OaTargetingDefinitionImporter;
use App\Services\OnlineAudio\Import\OaTkpImportJsonReader;
use App\Services\OnlineAudio\Import\OaTkpStammdatenImportException;
use Illuminate\Console\Command;

/**
 * PO-CALC-TKP-1D4c-2: Administrative CLI für optionale OA-Targeting-Definitionen.
 */
class ImportOaTargetingsCommand extends Command
{
    use HandlesOaTkpImportCli;

    protected $signature = 'oa:import-targetings
        {path : Absoluter oder relativer Pfad zur JSON-Datei (Array von Targetings)}
        {--dry-run : Validieren ohne Datenbankmutation}';

    protected $description = 'OA-Targeting-Definitionen aus JSON importieren (Domain-Importer, optional Dry-Run)';

    public function handle(OaTkpImportJsonReader $reader, OaTargetingDefinitionImporter $importer): int
    {
        $path = (string) $this->argument('path');
        $dryRun = (bool) $this->option('dry-run');
        $startedAt = now()->toIso8601String();
        $rows = [];

        try {
            $decoded = $reader->read($path);
            if ($decoded !== [] && array_is_list($decoded) === false) {
                throw new OaTkpStammdatenImportException(
                    'Targeting-Import ungültig.',
                    ['JSON-Wurzel muss ein Array von Targeting-Objekten sein.'],
                );
            }

            foreach ($decoded as $index => $row) {
                if (! is_array($row)) {
                    throw new OaTkpStammdatenImportException(
                        'Targeting-Import ungültig.',
                        ['Zeile '.($index + 1).': erwartet Objekt.'],
                    );
                }
                $rows[] = $row;
            }

            $result = $importer->import($rows, $dryRun);
        } catch (OaTkpStammdatenImportException $exception) {
            $this->logImportOutcome('oa_tkp.targetings.import_failed', [
                'at' => $startedAt,
                'path' => $path,
                'dry_run' => $dryRun,
                'error_count' => count($exception->errors),
            ]);

            return $this->failureFromException($exception);
        }

        $this->info(sprintf(
            'OA-Targetings %s: rows=%d created=%d updated=%d dry_run=%s',
            $dryRun ? 'Dry-Run OK' : 'Import OK',
            count($rows),
            $result['created'],
            $result['updated'],
            $dryRun ? 'yes' : 'no',
        ));

        $this->logImportOutcome('oa_tkp.targetings.import_ok', [
            'at' => $startedAt,
            'path' => $path,
            'dry_run' => $dryRun,
            'rows' => count($rows),
            'created' => $result['created'],
            'updated' => $result['updated'],
        ]);

        return self::SUCCESS;
    }
}
