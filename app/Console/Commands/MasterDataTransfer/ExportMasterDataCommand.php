<?php

namespace App\Console\Commands\MasterDataTransfer;

use App\Services\MasterDataTransfer\Export\MasterDataExporter;
use App\Services\MasterDataTransfer\Export\MasterDataExporterV2;
use App\Services\MasterDataTransfer\MasterDataTransferContract;
use App\Services\MasterDataTransfer\MasterDataTransferContractV2;
use App\Services\MasterDataTransfer\MasterDataTransferException;
use Illuminate\Console\Command;

/**
 * Stammdaten-Export: Schema 1.0.0 (Default) oder ausdrücklich 2.0.0.
 */
class ExportMasterDataCommand extends Command
{
    protected $signature = 'dispo:export-master-data
        {--path= : Zielverzeichnis für das Exportpaket}
        {--schema=1.0.0 : Paketschema (1.0.0 Default, 2.0.0 Gesamtexport)}
        {--inventory=* : Optional Inventar-Codes (nur Schema 1.0.0; Default: die 8 Spot-Inventare)}
        {--git-sha= : Optionaler Git-SHA fürs Manifest}';

    protected $description = 'Stammdaten/Preislisten als versioniertes JSON-Paket exportieren';

    public function handle(MasterDataExporter $exporterV1, MasterDataExporterV2 $exporterV2): int
    {
        $pathOption = $this->option('path');
        $path = is_string($pathOption) && $pathOption !== ''
            ? $pathOption
            : storage_path('app/master-data-export/'.now()->format('YmdHis'));

        $schemaOption = $this->option('schema');
        $schema = is_string($schemaOption) && $schemaOption !== ''
            ? $schemaOption
            : MasterDataTransferContract::SCHEMA_VERSION;

        $gitShaOption = $this->option('git-sha');
        $gitSha = is_string($gitShaOption) && $gitShaOption !== '' ? $gitShaOption : null;

        $inventories = $this->option('inventory');
        $codes = null;
        if (is_array($inventories) && $inventories !== []) {
            $codes = array_values(array_map('strval', $inventories));
        }

        try {
            if ($schema === MasterDataTransferContract::SCHEMA_VERSION) {
                $result = $exporterV1->export($path, $codes, $gitSha);
                $this->info('Export OK: '.$result['package_path']);
                $this->line('Schema: '.MasterDataTransferContract::SCHEMA_VERSION);
                foreach ($result['counts'] as $key => $count) {
                    $this->line(sprintf('  %s=%d', $key, $count));
                }

                return self::SUCCESS;
            }

            if ($schema === MasterDataTransferContractV2::SCHEMA_VERSION) {
                if ($codes !== null) {
                    throw new MasterDataTransferException(
                        'Schema 2.0.0 exportiert den Gesamtfachbestand; --inventory ist nicht zulässig.',
                    );
                }

                $result = $exporterV2->export($path, $gitSha);
                $this->info('Export OK: '.$result['package_path']);
                $this->line('Schema: '.MasterDataTransferContractV2::SCHEMA_VERSION);
                foreach ($result['counts'] as $key => $count) {
                    $this->line(sprintf('  %s=%d', $key, $count));
                }

                return self::SUCCESS;
            }

            throw new MasterDataTransferException(
                'Unbekanntes Schema: '.$schema,
                [
                    'Erlaubt: '.MasterDataTransferContract::SCHEMA_VERSION
                        .' oder '.MasterDataTransferContractV2::SCHEMA_VERSION,
                ],
            );
        } catch (MasterDataTransferException $e) {
            $this->error($e->getMessage());
            foreach ($e->errors as $error) {
                $this->line(' - '.$error);
            }

            return self::FAILURE;
        }
    }
}
