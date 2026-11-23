<?php

namespace App\Console\Commands\MasterDataTransfer;

use App\Services\MasterDataTransfer\Export\MasterDataExporter;
use App\Services\MasterDataTransfer\MasterDataTransferContract;
use App\Services\MasterDataTransfer\MasterDataTransferException;
use Illuminate\Console\Command;

/**
 * EPIC-03-B: selektiver Stammdaten-Export (read-only auf Quell-DB).
 */
class ExportMasterDataCommand extends Command
{
    protected $signature = 'dispo:export-master-data
        {--path= : Zielverzeichnis für das Exportpaket}
        {--inventory=* : Optional Inventar-Codes (Default: die 8 Spot-Inventare)}
        {--git-sha= : Optionaler Git-SHA fürs Manifest}';

    protected $description = 'EPIC-03-B: Stammdaten/Preislisten als versioniertes JSON-Paket exportieren';

    public function handle(MasterDataExporter $exporter): int
    {
        $pathOption = $this->option('path');
        $path = is_string($pathOption) && $pathOption !== ''
            ? $pathOption
            : storage_path('app/master-data-export/'.now()->format('YmdHis'));

        $inventories = $this->option('inventory');
        $codes = null;
        if (is_array($inventories) && $inventories !== []) {
            $codes = array_values(array_map('strval', $inventories));
        }

        $gitShaOption = $this->option('git-sha');
        $gitSha = is_string($gitShaOption) && $gitShaOption !== '' ? $gitShaOption : null;

        try {
            $result = $exporter->export($path, $codes, $gitSha);
        } catch (MasterDataTransferException $e) {
            $this->error($e->getMessage());
            foreach ($e->errors as $error) {
                $this->line(' - '.$error);
            }

            return self::FAILURE;
        }

        $this->info('Export OK: '.$result['package_path']);
        $this->line('Schema: '.MasterDataTransferContract::SCHEMA_VERSION);
        foreach ($result['counts'] as $key => $count) {
            $this->line(sprintf('  %s=%d', $key, $count));
        }

        return self::SUCCESS;
    }
}
