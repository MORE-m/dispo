<?php

namespace App\Console\Commands\OnlineAudio;

use App\Console\Commands\OnlineAudio\Concerns\HandlesOaTkpImportCli;
use App\Services\OnlineAudio\Import\OaTkpPriceListActivationService;
use App\Services\OnlineAudio\Import\OaTkpStammdatenImportException;
use Illuminate\Console\Command;

/**
 * PO-CALC-TKP-1D4c-2: Explizite Aktivierung einer OA-TKP-Draft-Revision.
 */
class ActivateOaTkpPriceListCommand extends Command
{
    use HandlesOaTkpImportCli;

    protected $signature = 'oa:activate-price-list
        {--year= : Preisjahr (Pflicht)}
        {--revision= : Revisionsnummer (Pflicht)}
        {--archive-previous : Vorhandene active Revision(en) desselben Jahres archivieren}
        {--dry-run : Validieren / Plan anzeigen ohne Mutation}';

    protected $description = 'OA-TKP-Preislistenrevision aktivieren (atomar, optional Vorgänger archivieren)';

    public function handle(OaTkpPriceListActivationService $activation): int
    {
        $yearOpt = $this->option('year');
        $revisionOpt = $this->option('revision');
        if ($yearOpt === null || $yearOpt === '' || $revisionOpt === null || $revisionOpt === '') {
            $this->error('Pflichtoptionen: --year und --revision.');

            return self::FAILURE;
        }

        $year = (int) $yearOpt;
        $revision = (int) $revisionOpt;
        $archivePrevious = (bool) $this->option('archive-previous');
        $dryRun = (bool) $this->option('dry-run');
        $startedAt = now()->toIso8601String();

        try {
            $result = $activation->activate($year, $revision, $archivePrevious, $dryRun);
        } catch (OaTkpStammdatenImportException $exception) {
            $this->logImportOutcome('oa_tkp.price_list.activate_failed', [
                'at' => $startedAt,
                'year' => $year,
                'revision_number' => $revision,
                'archive_previous' => $archivePrevious,
                'dry_run' => $dryRun,
                'error_count' => count($exception->errors),
            ]);

            return $this->failureFromException($exception);
        }

        if ($result['already_active']) {
            $this->info(sprintf(
                'OA-TKP-Preisliste bereits aktiv: year=%d revision=%d id=%s items=%d dry_run=%s',
                $result['year'],
                $result['revision_number'],
                (string) $result['price_list_id'],
                $result['items_count'],
                $dryRun ? 'yes' : 'no',
            ));
        } else {
            $this->info(sprintf(
                'OA-TKP-Preisliste %s: year=%d revision=%d id=%s archived_previous=%d items=%d dry_run=%s',
                $dryRun ? 'Activate Dry-Run OK' : 'Activate OK',
                $result['year'],
                $result['revision_number'],
                (string) $result['price_list_id'],
                count($result['archived_previous_ids']),
                $result['items_count'],
                $dryRun ? 'yes' : 'no',
            ));
        }

        $this->logImportOutcome('oa_tkp.price_list.activate_ok', [
            'at' => $startedAt,
            'year' => $result['year'],
            'revision_number' => $result['revision_number'],
            'price_list_id' => $result['price_list_id'],
            'activated' => $result['activated'],
            'already_active' => $result['already_active'],
            'archived_previous_ids' => $result['archived_previous_ids'],
            'items_count' => $result['items_count'],
            'dry_run' => $dryRun,
            'archive_previous' => $archivePrevious,
        ]);

        return self::SUCCESS;
    }
}
