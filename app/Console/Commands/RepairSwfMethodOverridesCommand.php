<?php

namespace App\Console\Commands;

use App\Services\InventoryMediumRule\Catalog\SwfMediumMethodOverrideRepairer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * PO-REFOCUS-6: Gezielte SWF-Methoden-Overrides für Allonge + Promo reparieren.
 */
class RepairSwfMethodOverridesCommand extends Command
{
    protected $signature = 'dispo:repair-swf-method-overrides
        {--dry-run : Nur Ist-/Soll-Abgleich, keine Datenänderungen}
        {--apply : Korrektur ausführen (nur mit --confirm-database)}
        {--confirm-database= : Muss exakt konfigurierter und verbundener DB entsprechen}
        {--report-dir= : Optionaler Report-Ordner (Default: storage/logs)}';

    protected $description = 'PO-REFOCUS-6: Allonge/Promo Medium-Methoden-Overrides gezielt reparieren';

    public function handle(SwfMediumMethodOverrideRepairer $repairer): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $apply = (bool) $this->option('apply');
        if ($dryRun === $apply) {
            $this->error('STOP: Genau eine Option erforderlich: --dry-run oder --apply.');

            return self::FAILURE;
        }

        $confirmOption = $this->option('confirm-database');
        $confirm = is_string($confirmOption) ? $confirmOption : '';
        $gate = $this->assertDatabaseGate($confirm);
        if ($gate !== null) {
            $this->error('STOP: '.$gate);

            return self::FAILURE;
        }

        $reportDir = $this->resolveReportDirectory();
        $preflight = $this->assertReportDirectoryWritable($reportDir);
        if ($preflight !== null) {
            $this->error('STOP: '.$preflight);

            return self::FAILURE;
        }

        $report = $repairer->repair($dryRun);
        $this->renderReport($report);

        $pathOut = rtrim($reportDir, DIRECTORY_SEPARATOR)
            .DIRECTORY_SEPARATOR
            .'swf-method-override-repair-'.now()->format('YmdHis').'.json';

        $writeError = $this->persistReport($pathOut, $report);
        if ($writeError !== null) {
            $this->error('STOP: Report konnte nicht gespeichert werden.');
            $errorPayload = json_encode([
                'error' => 'report_write_failed',
                'message' => $writeError,
                'report_path' => $pathOut,
                'repair_status' => $report['status'],
                'repair_mode' => $report['mode'],
                'database_must_be_verified' => ! $dryRun,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $this->line(is_string($errorPayload) ? $errorPayload : '{"error":"report_write_failed"}');

            if (! $dryRun) {
                $this->error(
                    'Datenbankänderungen können bereits erfolgt sein. Zustand gesondert prüfen; Apply nicht automatisch wiederholen.',
                );
            }

            return self::FAILURE;
        }

        $this->line('Report persistiert: '.$pathOut);

        return $report['status'] === 'PASS' ? self::SUCCESS : self::FAILURE;
    }

    private function assertDatabaseGate(string $confirm): ?string
    {
        $connection = (string) config('database.default');
        if ($connection === '') {
            return 'Datenbank-Connection ist leer oder nicht konfiguriert.';
        }

        $connectionConfig = config('database.connections.'.$connection);
        if (! is_array($connectionConfig)) {
            return sprintf('Unbekannte oder fehlerhafte Connection-Konfiguration: %s.', $connection);
        }

        $driver = (string) ($connectionConfig['driver'] ?? '');
        $configured = (string) ($connectionConfig['database'] ?? '');
        $env = (string) app()->environment();

        if ($confirm === '') {
            return '--confirm-database ist Pflicht und muss exakt dem verbundenen DB-Namen entsprechen.';
        }

        if (in_array($env, ['production', 'prod'], true)) {
            return 'Produktion ist für diesen Befehl grundsätzlich verboten.';
        }

        if (! in_array($driver, ['mysql', 'mariadb', 'sqlite'], true)) {
            return sprintf('Unbekannter oder nicht freigegebener DB-Treiber: %s.', $driver === '' ? '(leer)' : $driver);
        }

        $liveDatabase = $this->resolveLiveDatabaseName($driver, $configured);
        if ($liveDatabase === null) {
            return 'Live-Datenbankname konnte nicht fail-closed ermittelt werden.';
        }

        if ($confirm !== $configured || $confirm !== $liveDatabase || $configured !== $liveDatabase) {
            return sprintf(
                '--confirm-database muss exakt mit Konfiguration und Live-Verbindung übereinstimmen (confirm=%s, config=%s, live=%s, connection=%s, driver=%s).',
                $confirm,
                $configured === '' ? '(leer)' : $configured,
                $liveDatabase === '' ? '(leer)' : $liveDatabase,
                $connection,
                $driver,
            );
        }

        if ($env === 'testing') {
            return $this->assertTestingDatabaseAllowed($driver, $liveDatabase);
        }

        $allowed = match ($env) {
            'local' => ['dispo_mat_core'],
            'staging' => ['spn26jz7_dispo_staging'],
            default => null,
        };

        if ($allowed === null) {
            return sprintf('APP_ENV=%s ist für diesen Befehl nicht freigegeben.', $env === '' ? '(leer)' : $env);
        }

        if (! in_array($liveDatabase, $allowed, true)) {
            return sprintf(
                'Verbundene DB „%s“ ist unter APP_ENV=%s nicht erlaubt (Allowlist: %s).',
                $liveDatabase,
                $env,
                implode(', ', $allowed),
            );
        }

        return null;
    }

    private function resolveLiveDatabaseName(string $driver, string $configured): ?string
    {
        try {
            if (in_array($driver, ['mysql', 'mariadb'], true)) {
                $row = DB::selectOne('select database() as name');
                if ($row === null || ! isset($row->name) || ! is_string($row->name) || $row->name === '') {
                    return null;
                }

                return $row->name;
            }

            if ($driver === 'sqlite') {
                // SQLite hat kein SELECT DATABASE(); Config-Pfad ist die verbundene DB.
                return $configured !== '' ? $configured : null;
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }

    private function assertTestingDatabaseAllowed(string $driver, string $database): ?string
    {
        $forbidden = ['dispo', 'dispo_mat_core', 'spn26jz7_dispo_staging', 'production', 'dispo_staging'];
        if (in_array($database, $forbidden, true)) {
            return sprintf(
                'APP_ENV=testing erlaubt keine operative DB „%s“. Nur isolierte Testdatenbanken.',
                $database,
            );
        }

        if ($driver === 'sqlite') {
            if ($database === ':memory:') {
                return null;
            }

            $normalized = str_replace('\\', '/', $database);
            if (
                str_contains($normalized, '/testing/')
                || str_contains($normalized, '/tests/')
                || preg_match('/(^|\\/)(test|testing|phpunit)[^\\/]*\\.sqlite$/i', $normalized) === 1
            ) {
                return null;
            }

            return sprintf(
                'APP_ENV=testing/SQLite: DB „%s“ ist nicht als isolierte Testdatenbank erkennbar.',
                $database,
            );
        }

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            if (preg_match('/(^|_)(test|testing)(_|$)/i', $database) === 1) {
                return null;
            }

            return sprintf(
                'APP_ENV=testing/MySQL: DB „%s“ ist keine dedizierte Testdatenbank (erwartet …test…).',
                $database,
            );
        }

        return sprintf('APP_ENV=testing: Treiber %s ist nicht freigegeben.', $driver);
    }

    private function resolveReportDirectory(): string
    {
        $option = $this->option('report-dir');
        if (is_string($option) && $option !== '') {
            return $option;
        }

        return storage_path('logs');
    }

    private function assertReportDirectoryWritable(string $directory): ?string
    {
        if (! is_dir($directory) && ! @mkdir($directory, 0775, true) && ! is_dir($directory)) {
            return sprintf('Report-Verzeichnis fehlt und konnte nicht angelegt werden: %s.', $directory);
        }

        if (! is_writable($directory)) {
            return sprintf('Report-Verzeichnis ist nicht beschreibbar: %s.', $directory);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function persistReport(string $pathOut, array $report): ?string
    {
        try {
            $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            if ($json === false) {
                return 'Report-JSON konnte nicht kodiert werden.';
            }

            $bytes = @file_put_contents($pathOut, $json."\n");
            if ($bytes === false) {
                return 'file_put_contents fehlgeschlagen.';
            }

            if (! is_file($pathOut) || ! is_readable($pathOut)) {
                return 'Report-Datei nach dem Schreiben nicht lesbar.';
            }

            return null;
        } catch (Throwable $e) {
            return 'Report-Schreiben abgebrochen: '.$e->getMessage();
        }
    }

    /**
     * @param  array{
     *     status: string,
     *     mode: string,
     *     media: list<array<string, mixed>>,
     *     unchanged: list<string>,
     *     changed: list<string>,
     *     conflicts: list<string>,
     *     message: string
     * }  $report
     */
    private function renderReport(array $report): void
    {
        $this->line('Status: '.$report['status']);
        $this->line('Mode: '.$report['mode']);
        $this->line($report['message']);

        foreach ($report['media'] as $row) {
            $this->newLine();
            $this->info(sprintf(
                '%s (id=%s, outcome=%s)',
                (string) $row['code'],
                $row['medium_id'] === null ? 'n/a' : (string) $row['medium_id'],
                (string) $row['outcome'],
            ));
            $this->line('  before: '.json_encode($row['before'], JSON_UNESCAPED_UNICODE));
            $this->line('  after:  '.json_encode($row['after'], JSON_UNESCAPED_UNICODE));
            if ($row['changes'] !== []) {
                foreach ($row['changes'] as $change) {
                    $this->line('  change: '.$change);
                }
            }
        }

        if ($report['unchanged'] !== []) {
            $this->newLine();
            $this->line('Unchanged: '.implode(', ', $report['unchanged']));
        }
        if ($report['changed'] !== []) {
            $this->line('Changed/Planned: '.implode(', ', $report['changed']));
        }
        if ($report['conflicts'] !== []) {
            $this->newLine();
            $this->error('Conflicts:');
            foreach ($report['conflicts'] as $conflict) {
                $this->line(' - '.$conflict);
            }
        }
    }
}
