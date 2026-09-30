<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\PriceList\Import\MoreSpotkalkulationMatCoreImporter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * PRI-OPS-1: MORE Spotkalkulation 2026 → Drafts (+ optional Aktivierung) nur auf bestätigter DB.
 */
class ImportMoreSpotkalkulationMatCoreCommand extends Command
{
    protected $signature = 'pri-ops:import-more-spotkalkulation
        {--path= : Absoluter Pfad zum Workbook (Default database/data/Spotkalkulation_2026.xlsx)}
        {--year=2026 : Preisjahr}
        {--activate : Nach Draft-Erzeugung aktivieren}
        {--confirm-database= : Muss exakt dem Ziel-Datenbanknamen entsprechen}
        {--actor-email=admin@example.com : Benutzer für Audit}';

    protected $description = 'PRI-OPS-1: MORE Spotkalkulation-Workbook in Draft-Preislisten importieren (fail-closed, keine .env-Änderung)';

    public function handle(MoreSpotkalkulationMatCoreImporter $importer): int
    {
        $confirm = (string) $this->option('confirm-database');
        $current = (string) config('database.connections.'.config('database.default').'.database');

        if ($confirm === '' || $confirm !== $current) {
            $this->error(sprintf(
                'Abbruch: --confirm-database muss exakt die aktive DB sein (aktiv=%s, confirm=%s).',
                $current,
                $confirm === '' ? '(leer)' : $confirm,
            ));
            $this->line('Beispiel: DB_DATABASE=dispo_mat_core php artisan pri-ops:import-more-spotkalkulation --confirm-database=dispo_mat_core --activate');

            return self::FAILURE;
        }

        if ($confirm === 'dispo') {
            $this->error('Abbruch: Import gegen die shared DB „dispo“ ist nicht erlaubt.');

            return self::FAILURE;
        }

        $actorEmail = (string) $this->option('actor-email');
        $actor = User::query()->where('email', $actorEmail)->first();
        if ($actor === null) {
            $this->error('Audit-Benutzer nicht gefunden: '.$actorEmail);

            return self::FAILURE;
        }

        $path = $this->option('path');
        $path = is_string($path) && $path !== '' ? $path : null;
        $year = (int) $this->option('year');
        $activate = (bool) $this->option('activate');

        $this->info(sprintf(
            'Ziel-DB=%s year=%d activate=%s path=%s',
            $current,
            $year,
            $activate ? 'yes' : 'no',
            $path ?? MoreSpotkalkulationMatCoreImporter::defaultWorkbookPath(),
        ));

        try {
            $result = $importer->import($actor, $year, $activate, $path);
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $messages) {
                foreach ($messages as $message) {
                    $this->error($message);
                }
            }

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Parsed=%d drafts=%d activated=%d skipped_avg_rows=%d skipped_empty=%d',
            $result['parsed_rows'],
            count($result['created_drafts']),
            count($result['activated']),
            $result['skipped_average_rows'],
            $result['skipped_empty_cells'],
        ));

        foreach ($result['created_drafts'] as $draft) {
            $this->line(sprintf(
                '  draft %s id=%d items=%d',
                $draft['inventory_code'],
                $draft['price_list_id'],
                $draft['item_count'],
            ));
        }

        $this->line('DB ping: '.DB::connection()->getDatabaseName());

        return self::SUCCESS;
    }
}
