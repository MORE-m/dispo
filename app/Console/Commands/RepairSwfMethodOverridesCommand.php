<?php

namespace App\Console\Commands;

use App\Services\InventoryMediumRule\Catalog\SwfMediumMethodOverrideRepairer;
use Illuminate\Console\Command;

/**
 * PO-REFOCUS-6: Gezielte SWF-Methoden-Overrides für Allonge + Promo reparieren.
 */
class RepairSwfMethodOverridesCommand extends Command
{
    protected $signature = 'dispo:repair-swf-method-overrides
        {--dry-run : Nur Ist-/Soll-Abgleich, keine Datenänderungen}
        {--apply : Korrektur ausführen (nur mit --confirm-database)}
        {--confirm-database= : Muss exakt dem verbundenen DB-Namen entsprechen}';

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

        $report = $repairer->repair($dryRun);
        $this->renderReport($report);

        $pathOut = storage_path('logs/swf-method-override-repair-'.now()->format('YmdHis').'.json');
        file_put_contents($pathOut, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n");
        $this->line('Report: '.$pathOut);

        return $report['status'] === 'PASS' ? self::SUCCESS : self::FAILURE;
    }

    private function assertDatabaseGate(string $confirm): ?string
    {
        $connection = (string) config('database.default');
        $configured = (string) config('database.connections.'.$connection.'.database');
        $env = (string) app()->environment();

        if ($confirm === '') {
            return '--confirm-database ist Pflicht und muss exakt dem verbundenen DB-Namen entsprechen.';
        }

        if ($confirm !== $configured) {
            return sprintf(
                '--confirm-database muss exakt die aktive DB sein (aktiv=%s, confirm=%s, connection=%s).',
                $configured === '' ? '(leer)' : $configured,
                $confirm,
                $connection,
            );
        }

        if (in_array($env, ['production', 'prod'], true)) {
            return 'Produktion ist für diesen Befehl grundsätzlich verboten.';
        }

        if ($env === 'testing') {
            // Isolierte Testdatenbanken: Confirm muss nur zur aktiven DB passen.
            return null;
        }

        $allowed = match ($env) {
            'local' => ['dispo_mat_core'],
            'staging' => ['spn26jz7_dispo_staging'],
            default => null,
        };

        if ($allowed === null) {
            return sprintf('APP_ENV=%s ist für diesen Befehl nicht freigegeben.', $env === '' ? '(leer)' : $env);
        }

        if (! in_array($configured, $allowed, true)) {
            return sprintf(
                'Verbundene DB „%s“ ist unter APP_ENV=%s nicht erlaubt (Allowlist: %s).',
                $configured,
                $env,
                implode(', ', $allowed),
            );
        }

        if (! in_array($confirm, $allowed, true)) {
            return sprintf(
                '--confirm-database „%s“ ist unter APP_ENV=%s nicht erlaubt (Allowlist: %s).',
                $confirm,
                $env,
                implode(', ', $allowed),
            );
        }

        return null;
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
