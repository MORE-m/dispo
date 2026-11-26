<?php

namespace App\Console\Commands\MasterDataTransfer;

use App\Services\MasterDataTransfer\Import\MasterDataImporter;
use App\Services\MasterDataTransfer\Import\MasterDataImporterV2;
use App\Services\MasterDataTransfer\Import\MasterDataPriceStatusApplierV2;
use App\Services\MasterDataTransfer\MasterDataTransferContract;
use App\Services\MasterDataTransfer\MasterDataTransferContractV2;
use App\Services\MasterDataTransfer\MasterDataTransferException;
use App\Services\MasterDataTransfer\Support\MasterDataPackage;
use Illuminate\Console\Command;

/**
 * Stammdaten-Import: Schema 1.0.0 Dry-Run/Apply; Schema 2.0.0 Dry-Run (6a), Apply (6b)
 * sowie Preisstatus-Phase (Slice 7: --dry-run-price-status / --apply-price-status).
 */
class ImportMasterDataCommand extends Command
{
    protected $signature = 'dispo:import-master-data
        {path : Pfad zum Exportpaket}
        {--dry-run : Nur prüfen und berichten (schreibfrei)}
        {--apply : Schreibenden Import ausführen}
        {--dry-run-price-status : Preisstatus-Plan erzeugen (schreibfrei, liefert Plan-Hash)}
        {--apply-price-status : Preisstatus-Phase ausführen (Slice 7; braucht Plan-Hash aus dem Dry-Run)}
        {--confirm-database= : Muss exakt dem aktiven DB-Namen entsprechen}
        {--require-package-sha256= : Pflicht für Schema 2.0.0 (64 Hex)}
        {--require-backup-proof= : Pflicht für Schema 2.0.0 Apply (Backup A) und Preisstatus-Modi (Backup B)}
        {--require-content-apply-receipt= : Pfad zum Content-Apply-Receipt (Preisstatus-Modi)}
        {--require-price-status-plan-hash= : Plan-Hash aus --dry-run-price-status (nur --apply-price-status)}';

    protected $description = 'Stammdaten/Preislisten-Paket importieren (fail-closed; V2 Dry-Run/Apply und Preisstatus-Phase)';

    public function handle(
        MasterDataImporter $importerV1,
        MasterDataImporterV2 $importerV2,
        MasterDataPackage $packages,
        MasterDataPriceStatusApplierV2 $priceStatus,
    ): int {
        $dryRun = (bool) $this->option('dry-run');
        $apply = (bool) $this->option('apply');
        $dryRunPriceStatus = (bool) $this->option('dry-run-price-status');
        $applyPriceStatus = (bool) $this->option('apply-price-status');
        $actionCount = (int) $dryRun + (int) $apply + (int) $dryRunPriceStatus + (int) $applyPriceStatus;
        if ($actionCount !== 1) {
            $this->error('Genau eine Option erforderlich: --dry-run, --apply, --dry-run-price-status oder --apply-price-status.');

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
        $requireBackupOption = $this->option('require-backup-proof');
        $requireBackup = is_string($requireBackupOption) ? $requireBackupOption : '';
        $requireReceiptOption = $this->option('require-content-apply-receipt');
        $requireReceipt = is_string($requireReceiptOption) ? $requireReceiptOption : '';
        $requirePlanHashOption = $this->option('require-price-status-plan-hash');
        $requirePlanHash = is_string($requirePlanHashOption) ? $requirePlanHashOption : '';

        try {
            $schemaVersion = $packages->detectSchemaVersion($path);

            if ($schemaVersion === MasterDataTransferContractV2::SCHEMA_VERSION) {
                $report = $this->handleV2(
                    $importerV2,
                    $priceStatus,
                    $path,
                    $dryRun,
                    $apply,
                    $dryRunPriceStatus,
                    $applyPriceStatus,
                    $confirm,
                    $requireSha,
                    $requireBackup,
                    $requireReceipt,
                    $requirePlanHash,
                );
            } elseif ($schemaVersion === MasterDataTransferContract::SCHEMA_VERSION) {
                $report = $this->handleV1(
                    $importerV1,
                    $path,
                    $dryRun,
                    $apply,
                    $dryRunPriceStatus || $applyPriceStatus,
                    $confirm,
                    $requireSha,
                    $requireBackup,
                    $requireReceipt !== '' || $requirePlanHash !== '',
                );
            } else {
                throw new MasterDataTransferException(
                    'Unbekannte oder nicht freigegebene schema_version: '.$schemaVersion,
                );
            }
        } catch (MasterDataTransferException $e) {
            $this->error($e->getMessage());
            foreach ($e->errors as $error) {
                $this->line(' - '.$this->sanitizeReportText($error));
            }

            $stopReport = $this->buildSanitizedStopReport($e, $dryRun || $dryRunPriceStatus);
            try {
                $stopPath = $this->persistReport($stopReport);
                $this->line('Report: '.$stopPath);
            } catch (MasterDataTransferException $persistError) {
                $this->error($persistError->getMessage());
            }

            return self::FAILURE;
        }

        try {
            $pathOut = $this->persistReport($report);
        } catch (MasterDataTransferException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($dryRunPriceStatus || $applyPriceStatus) {
            $statusStopped = ($report['security']['result'] ?? null) === 'STOP';
            $label = $dryRunPriceStatus ? 'Preisstatus Dry-Run' : 'Preisstatus Apply';
            if ($statusStopped) {
                $this->warn($label.' STOP (blockierte Listen)');
            } else {
                $this->info($label.' OK');
            }
            $this->line('price_status_plan_hash='.($report['price_status']['plan_hash'] ?? ''));
            foreach (($report['counts_by_action'] ?? []) as $action => $count) {
                $this->line('price_status.'.$action.'='.$count);
            }
            foreach ($report['rows'] ?? [] as $row) {
                $this->line(sprintf(
                    ' - [%s] %s: %s%s',
                    $row['entity'],
                    $row['key'],
                    $row['action'],
                    $row['action'] === 'blocked' || str_starts_with((string) ($row['detail'] ?? ''), 'wird atomar')
                        ? ' — '.$this->sanitizeReportText((string) ($row['detail'] ?? ''))
                        : '',
                ));
            }
        } else {
            $this->info($dryRun ? 'Dry-Run OK' : 'Import Apply OK');
        }
        $this->line('created='.$report['created'].' unchanged='.$report['unchanged']);
        if (($report['counts_by_action']['update'] ?? 0) > 0) {
            $this->line('updated='.$report['counts_by_action']['update']);
        }
        $blocked = count($report['conflicts'] ?? []);
        if ($blocked > 0) {
            $this->warn('blocked='.$blocked);
            $this->warn('conflicts='.$blocked);
        }
        if ($report['skipped'] !== []) {
            $this->line('skipped='.count($report['skipped']));
        }
        if (isset($report['security']) && is_array($report['security'])) {
            $this->line('security.result='.($report['security']['result'] ?? 'unknown'));
            if (isset($report['security']['package_sha256']) && is_string($report['security']['package_sha256'])) {
                $this->line('package_sha256='.$report['security']['package_sha256']);
            }
            if (isset($report['security']['backup_proof']) && is_string($report['security']['backup_proof'])) {
                $this->line('backup_proof='.$report['security']['backup_proof']);
            }
        }
        if (isset($report['receipt']) && is_array($report['receipt'])) {
            $this->line('receipt_id='.($report['receipt']['receipt_id'] ?? ''));
            $this->line('receipt='.($report['receipt']['receipt_path'] ?? ''));
        }
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
        bool $priceStatusMode,
        string $confirm,
        string $requireSha,
        string $requireBackup,
        bool $statusOnlyOptions,
    ): array {
        if ($priceStatusMode || $statusOnlyOptions) {
            throw new MasterDataTransferException(
                'Preisstatus-Phase ist für Schema 1.0.0 nicht vorgesehen.',
            );
        }
        if ($requireSha !== '') {
            throw new MasterDataTransferException(
                '--require-package-sha256 gilt nur für Schema 2.0.0.',
            );
        }
        if ($requireBackup !== '') {
            throw new MasterDataTransferException(
                '--require-backup-proof gilt nur für Schema 2.0.0 Apply.',
            );
        }

        return $importer->import($path, $dryRun, $confirm);
    }

    /**
     * @return array<string, mixed>
     */
    private function handleV2(
        MasterDataImporterV2 $importer,
        MasterDataPriceStatusApplierV2 $priceStatus,
        string $path,
        bool $dryRun,
        bool $apply,
        bool $dryRunPriceStatus,
        bool $applyPriceStatus,
        string $confirm,
        string $requireSha,
        string $requireBackup,
        string $requireReceipt,
        string $requirePlanHash,
    ): array {
        if ($dryRunPriceStatus || $applyPriceStatus) {
            if ($dryRunPriceStatus && $requirePlanHash !== '') {
                throw new MasterDataTransferException(
                    '--require-price-status-plan-hash gilt nur für --apply-price-status (der Dry-Run erzeugt den Plan-Hash).',
                );
            }

            return $dryRunPriceStatus
                ? $priceStatus->dryRun($path, $confirm, $requireSha, $requireBackup, $requireReceipt)
                : $priceStatus->apply($path, $confirm, $requireSha, $requireBackup, $requireReceipt, $requirePlanHash);
        }
        if ($requireReceipt !== '' || $requirePlanHash !== '') {
            throw new MasterDataTransferException(
                '--require-content-apply-receipt/--require-price-status-plan-hash gelten nur für die Preisstatus-Modi.',
            );
        }
        if ($dryRun) {
            if ($requireBackup !== '') {
                throw new MasterDataTransferException(
                    '--require-backup-proof gilt nur für Schema 2.0.0 Apply (nicht für Dry-Run).',
                );
            }

            return $importer->dryRunOperational($path, $confirm, $requireSha);
        }
        if (! $apply) {
            throw new MasterDataTransferException(
                'Schema 2.0.0 erlaubt operativ --dry-run, --apply, --dry-run-price-status oder --apply-price-status.',
            );
        }

        return $importer->applyOperational($path, $confirm, $requireSha, $requireBackup);
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function persistReport(array $report): string
    {
        $dir = storage_path('logs');
        if (! is_dir($dir) && ! mkdir($dir, 0755, true) && ! is_dir($dir)) {
            throw new MasterDataTransferException(
                'Report-Verzeichnis fehlt und konnte nicht angelegt werden.',
            );
        }
        if (! is_writable($dir)) {
            throw new MasterDataTransferException(
                'Report-Verzeichnis ist nicht beschreibbar.',
            );
        }

        $pathOut = $dir.DIRECTORY_SEPARATOR
            .'master-data-import-report-'
            .now()->format('YmdHis')
            .'-'
            .bin2hex(random_bytes(4))
            .'.json';
        $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new MasterDataTransferException('Report-JSON konnte nicht kodiert werden.');
        }

        $written = @file_put_contents($pathOut, $json."\n");
        if ($written === false || ! is_file($pathOut)) {
            throw new MasterDataTransferException(
                'Report konnte nicht geschrieben werden.',
            );
        }

        $verify = @file_get_contents($pathOut);
        if ($verify === false || $verify === '') {
            throw new MasterDataTransferException(
                'Report-Persistierung fehlgeschlagen (Datei leer oder nicht lesbar).',
            );
        }

        return $pathOut;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildSanitizedStopReport(MasterDataTransferException $e, bool $dryRun): array
    {
        $conflicts = [];
        foreach ($e->errors as $error) {
            if ($error !== '') {
                $conflicts[] = $this->sanitizeReportText($error);
            }
        }

        return [
            'dry_run' => $dryRun,
            'created' => 0,
            'unchanged' => 0,
            'conflicts' => $conflicts,
            'skipped' => [],
            'rows' => [],
            'counts_by_action' => [],
            'security' => [
                'package_integrity' => 'stop',
                'package_sha256' => null,
                'operational_database' => null,
                'protection_snapshots' => 'stop',
                'protected_table_count' => 0,
                'protected_table_counts' => [],
                'price_list_pins' => 'stop',
                'price_list_binding_count' => 0,
                'price_list_pin_count' => 0,
                'whitelist' => 'stop',
                'referential_integrity' => 'stop',
                'backup_proof' => 'stop',
                'backup_proof_dump_sha256' => null,
                'result' => 'STOP',
                'abort_reason' => $this->sanitizeReportText($e->getMessage()),
            ],
        ];
    }

    private function sanitizeReportText(string $text): string
    {
        $clean = preg_replace('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', '[redacted-email]', $text) ?? $text;
        $clean = preg_replace('/\b(password|passwd|secret|token|api[_-]?key)\s*[:=]\s*\S+/i', '$1=[redacted]', $clean) ?? $clean;

        return mb_substr($clean, 0, 500);
    }
}
