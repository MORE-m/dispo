<?php

namespace App\Console\Commands\MasterDataTransfer;

use App\Services\MasterDataTransfer\Import\MasterDataImporter;
use App\Services\MasterDataTransfer\MasterDataTransferException;
use Illuminate\Console\Command;

/**
 * EPIC-03-B: Dry-Run / Apply Import mit --confirm-database.
 */
class ImportMasterDataCommand extends Command
{
    protected $signature = 'dispo:import-master-data
        {path : Pfad zum Exportpaket}
        {--dry-run : Nur prüfen und berichten (schreibfrei)}
        {--apply : Schreibenden Import ausführen}
        {--confirm-database= : Muss exakt dem aktiven DB-Namen entsprechen}';

    protected $description = 'EPIC-03-B: Stammdaten/Preislisten-Paket importieren (fail-closed, Draft-Preise)';

    public function handle(MasterDataImporter $importer): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $apply = (bool) $this->option('apply');
        if ($dryRun === $apply) {
            $this->error('Genau eine Option erforderlich: --dry-run oder --apply.');

            return self::FAILURE;
        }

        $confirmOption = $this->option('confirm-database');
        $confirm = is_string($confirmOption) ? $confirmOption : '';
        $pathArgument = $this->argument('path');
        if (! is_string($pathArgument) || $pathArgument === '') {
            $this->error('Pfad zum Exportpaket fehlt oder ist ungültig.');

            return self::FAILURE;
        }
        $path = $pathArgument;

        try {
            $report = $importer->import($path, $dryRun, $confirm);
        } catch (MasterDataTransferException $e) {
            $this->error($e->getMessage());
            foreach ($e->errors as $error) {
                $this->line(' - '.$error);
            }

            return self::FAILURE;
        }

        $this->info($dryRun ? 'Dry-Run OK' : 'Import Apply OK');
        $this->line('created='.$report['created'].' unchanged='.$report['unchanged']);
        if ($report['conflicts'] !== []) {
            $this->warn('conflicts='.count($report['conflicts']));
        }
        if ($report['skipped'] !== []) {
            $this->line('skipped='.count($report['skipped']));
        }

        $pathOut = storage_path('logs/master-data-import-report-'.now()->format('YmdHis').'.json');
        file_put_contents($pathOut, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n");
        $this->line('Report: '.$pathOut);

        return self::SUCCESS;
    }
}
