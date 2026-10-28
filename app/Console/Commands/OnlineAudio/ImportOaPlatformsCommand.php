<?php

namespace App\Console\Commands\OnlineAudio;

use App\Console\Commands\OnlineAudio\Concerns\HandlesOaTkpImportCli;
use App\Services\OnlineAudio\Import\OaPlatformImporter;
use App\Services\OnlineAudio\Import\OaTkpImportJsonReader;
use App\Services\OnlineAudio\Import\OaTkpStammdatenImportException;
use Illuminate\Console\Command;

/**
 * PO-CALC-TKP-1D4c-2: Administrative CLI für OA-Plattform-Stammdaten.
 */
class ImportOaPlatformsCommand extends Command
{
    use HandlesOaTkpImportCli;

    protected $signature = 'oa:import-platforms
        {path : Absoluter oder relativer Pfad zur JSON-Datei (Array von Plattformen)}
        {--dry-run : Validieren ohne Datenbankmutation}';

    protected $description = 'OA-Plattformen aus JSON importieren (Domain-Importer, optional Dry-Run)';

    public function handle(OaTkpImportJsonReader $reader, OaPlatformImporter $importer): int
    {
        $path = (string) $this->argument('path');
        $dryRun = (bool) $this->option('dry-run');
        $startedAt = now()->toIso8601String();
        $rows = [];

        try {
            $decoded = $reader->read($path);
            if ($decoded !== [] && array_is_list($decoded) === false) {
                throw new OaTkpStammdatenImportException(
                    'Plattform-Import ungültig.',
                    ['JSON-Wurzel muss ein Array von Plattform-Objekten sein.'],
                );
            }

            foreach ($decoded as $index => $row) {
                if (! is_array($row)) {
                    throw new OaTkpStammdatenImportException(
                        'Plattform-Import ungültig.',
                        ['Zeile '.($index + 1).': erwartet Objekt.'],
                    );
                }
                $rows[] = $row;
            }

            $result = $importer->import($rows, $dryRun);
        } catch (OaTkpStammdatenImportException $exception) {
            $this->logImportOutcome('oa_tkp.platforms.import_failed', [
                'at' => $startedAt,
                'path' => $path,
                'dry_run' => $dryRun,
                'error_count' => count($exception->errors),
            ]);

            return $this->failureFromException($exception);
        }

        $this->info(sprintf(
            'OA-Plattformen %s: rows=%d created=%d updated=%d dry_run=%s',
            $dryRun ? 'Dry-Run OK' : 'Import OK',
            count($rows),
            $result['created'],
            $result['updated'],
            $dryRun ? 'yes' : 'no',
        ));

        $this->logImportOutcome('oa_tkp.platforms.import_ok', [
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
