<?php

namespace App\Console\Commands\MasterDataTransfer;

use App\Services\MasterDataTransfer\Import\MasterDataImporter;
use App\Services\MasterDataTransfer\Import\MasterDataImporterV2;
use App\Services\MasterDataTransfer\MasterDataTransferContract;
use App\Services\MasterDataTransfer\MasterDataTransferContractV2;
use App\Services\MasterDataTransfer\MasterDataTransferException;
use App\Services\MasterDataTransfer\Support\MasterDataPackage;
use Illuminate\Console\Command;

/**
 * Stammdaten-Import: Schema 1.0.0 Dry-Run/Apply; Schema 2.0.0 nur Slice-6a-Dry-Run.
 */
class ImportMasterDataCommand extends Command
{
    protected $signature = 'dispo:import-master-data
        {path : Pfad zum Exportpaket}
        {--dry-run : Nur prüfen und berichten (schreibfrei)}
        {--apply : Schreibenden Import ausführen (nur Schema 1.0.0)}
        {--apply-price-status : Preisstatus-Phase (noch nicht freigegeben)}
        {--confirm-database= : Muss exakt dem aktiven DB-Namen entsprechen}
        {--require-package-sha256= : Pflicht für Schema 2.0.0 Dry-Run (64 Hex)}';

    protected $description = 'Stammdaten/Preislisten-Paket importieren (fail-closed; V2 nur Dry-Run)';

    public function handle(
        MasterDataImporter $importerV1,
        MasterDataImporterV2 $importerV2,
        MasterDataPackage $packages,
    ): int {
        $dryRun = (bool) $this->option('dry-run');
        $apply = (bool) $this->option('apply');
        $applyPriceStatus = (bool) $this->option('apply-price-status');
        $actionCount = (int) $dryRun + (int) $apply + (int) $applyPriceStatus;
        if ($actionCount !== 1) {
            $this->error('Genau eine Option erforderlich: --dry-run, --apply oder --apply-price-status.');

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

        $requireShaOption = $this->option('require-package-sha256');
        $requireSha = is_string($requireShaOption) ? $requireShaOption : '';

        try {
            $schemaVersion = $packages->detectSchemaVersion($path);

            if ($schemaVersion === MasterDataTransferContractV2::SCHEMA_VERSION) {
                $report = $this->handleV2($importerV2, $path, $dryRun, $apply, $applyPriceStatus, $confirm, $requireSha);
            } elseif ($schemaVersion === MasterDataTransferContract::SCHEMA_VERSION) {
                $report = $this->handleV1($importerV1, $path, $dryRun, $apply, $applyPriceStatus, $confirm, $requireSha);
            } else {
                throw new MasterDataTransferException(
                    'Unbekannte oder nicht freigegebene schema_version: '.$schemaVersion,
                );
            }
        } catch (MasterDataTransferException $e) {
            $this->error($e->getMessage());
            foreach ($e->errors as $error) {
                $this->line(' - '.$error);
            }

            return self::FAILURE;
        }

        $this->info($dryRun ? 'Dry-Run OK' : 'Import Apply OK');
        $this->line('created='.$report['created'].' unchanged='.$report['unchanged']);
        if (($report['counts_by_action']['update'] ?? 0) > 0) {
            $this->line('updated='.$report['counts_by_action']['update']);
        }
        if ($report['conflicts'] !== []) {
            $this->warn('conflicts='.count($report['conflicts']));
        }
        if ($report['skipped'] !== []) {
            $this->line('skipped='.count($report['skipped']));
        }
        if (isset($report['security']) && is_array($report['security'])) {
            $this->line('security.result='.($report['security']['result'] ?? 'unknown'));
            if (isset($report['security']['package_sha256']) && is_string($report['security']['package_sha256'])) {
                $this->line('package_sha256='.$report['security']['package_sha256']);
            }
        }

        $pathOut = storage_path('logs/master-data-import-report-'.now()->format('YmdHis').'.json');
        file_put_contents($pathOut, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n");
        $this->line('Report: '.$pathOut);

        $securityResult = is_array($report['security'] ?? null)
            ? ($report['security']['result'] ?? null)
            : null;
        if ($securityResult === 'STOP' || $report['conflicts'] !== []) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     */
    private function handleV1(
        MasterDataImporter $importer,
        string $path,
        bool $dryRun,
        bool $apply,
        bool $applyPriceStatus,
        string $confirm,
        string $requireSha,
    ): array {
        if ($applyPriceStatus) {
            throw new MasterDataTransferException(
                'Preisstatus-Phase ist für Schema 1.0.0 nicht vorgesehen.',
            );
        }
        if ($requireSha !== '') {
            throw new MasterDataTransferException(
                '--require-package-sha256 gilt nur für Schema 2.0.0.',
            );
        }

        return $importer->import($path, $dryRun, $confirm);
    }

    /**
     * @return array<string, mixed>
     */
    private function handleV2(
        MasterDataImporterV2 $importer,
        string $path,
        bool $dryRun,
        bool $apply,
        bool $applyPriceStatus,
        string $confirm,
        string $requireSha,
    ): array {
        if ($applyPriceStatus) {
            throw new MasterDataTransferException(
                'Preisstatus-Phase für schema_version=2.0.0 ist nicht freigegeben (Slice 7 fehlt).',
            );
        }
        if ($apply) {
            throw new MasterDataTransferException(
                'Apply für schema_version=2.0.0 ist operativ nicht freigegeben (Slice 6b fehlt).',
            );
        }
        if (! $dryRun) {
            throw new MasterDataTransferException(
                'Schema 2.0.0 erlaubt operativ nur --dry-run (Slice 6a).',
            );
        }

        return $importer->dryRunOperational($path, $confirm, $requireSha);
    }
}
