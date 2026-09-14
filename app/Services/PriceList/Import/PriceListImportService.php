<?php

namespace App\Services\PriceList\Import;

use App\Enums\DayGroup;
use App\Enums\PriceListImportStatus;
use App\Exceptions\PriceListAdminConflictException;
use App\Models\Inventory;
use App\Models\PriceList;
use App\Models\PriceListImport;
use App\Models\User;
use App\Services\Advertising\Admin\CatalogImpactPreviewService;
use App\Services\Audit\AuditLogger;
use App\Services\PriceList\Admin\PriceListAdminWriter;
use App\Support\PriceList\Import\InventoryAliasResolver;
use App\Support\PriceList\Import\PriceListImportLimits;
use App\Support\PriceList\Import\PriceListWorkbookParser;
use App\Support\PriceList\PriceListItemContract;
use App\Support\PrivateFileStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * BL-P4-01b: Upload → Preview → atomare Draft-Erzeugung. Keine Auto-Aktivierung.
 */
final class PriceListImportService
{
    public function __construct(
        private readonly PrivateFileStorage $files,
        private readonly PriceListWorkbookParser $parser,
        private readonly PriceListAdminWriter $writer,
        private readonly AuditLogger $audit,
        private readonly CatalogImpactPreviewService $fingerprints,
    ) {}

    public function upload(UploadedFile $file, int $year, User $actor): PriceListImport
    {
        $year = $this->assertYear($year);
        $this->assertUpload($file);

        $extension = strtolower($file->getClientOriginalExtension());
        $binary = file_get_contents($file->getRealPath());
        if ($binary === false || $binary === '') {
            throw ValidationException::withMessages([
                'file' => 'Die Datei konnte nicht gelesen werden.',
            ]);
        }

        $checksum = hash('sha256', $binary);
        $storedName = Str::uuid()->toString().'.'.$extension;
        $temporaryPath = PriceListImportLimits::TEMPORARY_UPLOAD_PREFIX.$storedName;
        $finalPath = PriceListImportLimits::STORAGE_PREFIX.$storedName;

        $written = $this->files->put($temporaryPath, $binary);
        if ($written === false) {
            throw ValidationException::withMessages([
                'file' => 'Die Datei konnte nicht gespeichert werden.',
            ]);
        }

        try {
            $import = DB::transaction(function () use ($actor, $year, $file, $finalPath, $checksum, $binary): PriceListImport {
                $import = PriceListImport::query()->create([
                    'user_id' => $actor->id,
                    'year' => $year,
                    'status' => PriceListImportStatus::Uploaded,
                    'original_filename' => mb_substr($file->getClientOriginalName(), 0, 255),
                    'stored_path' => $finalPath,
                    'checksum_sha256' => $checksum,
                    'mime_type' => $file->getMimeType(),
                    'file_size' => strlen($binary),
                ]);

                $this->audit->record($import, 'price_list_import.uploaded', $actor, null, [
                    'year' => $year,
                    'original_filename' => $import->original_filename,
                    'checksum_sha256' => $checksum,
                    'file_size' => $import->file_size,
                ]);

                return $import;
            });

            try {
                $moved = $this->files->move($temporaryPath, $finalPath);
            } catch (Throwable) {
                $this->markUploadArchiveFailed($import, $actor, $temporaryPath);

                throw ValidationException::withMessages([
                    'file' => 'Die Datei konnte nicht in den privaten Archivpfad übernommen werden.',
                ]);
            }

            if ($moved === false) {
                $this->markUploadArchiveFailed($import, $actor, $temporaryPath);

                throw ValidationException::withMessages([
                    'file' => 'Die Datei konnte nicht in den privaten Archivpfad übernommen werden.',
                ]);
            }
        } catch (ValidationException $exception) {
            $this->cleanupTemporaryUpload($temporaryPath);

            throw $exception;
        } catch (Throwable $exception) {
            $this->cleanupTemporaryUpload($temporaryPath);

            throw ValidationException::withMessages([
                'file' => 'Der Upload konnte nicht persistent gespeichert werden.',
            ]);
        }

        $this->cleanupTemporaryUpload($temporaryPath);

        return $import;
    }

    /**
     * @return array<string, mixed>
     */
    public function validate(PriceListImport $import, User $actor): array
    {
        $this->assertActor($import, $actor);
        if ($import->status === PriceListImportStatus::Imported) {
            throw ValidationException::withMessages([
                'import' => 'Dieser Importlauf wurde bereits übernommen.',
            ]);
        }
        if ($import->status === PriceListImportStatus::Failed) {
            throw ValidationException::withMessages([
                'import' => 'Dieser Importlauf ist fehlgeschlagen und kann nicht geprüft werden.',
            ]);
        }

        $absolute = $this->absolutePath($import);
        $extension = pathinfo($import->original_filename, PATHINFO_EXTENSION);
        $parsed = $this->parser->parse($absolute, $extension);
        $resolver = InventoryAliasResolver::fromDatabase();
        $preview = $this->buildPreview($import, $parsed, $resolver);

        $import->status = PriceListImportStatus::Validated;
        $import->sheet_count = $preview['sheet_count'];
        $import->row_count = $preview['row_count'];
        $import->valid_row_count = $preview['valid_row_count'];
        $import->error_count = $preview['error_count'];
        $import->warning_count = $preview['warning_count'];
        $import->report = $preview;
        $import->fingerprint = $preview['fingerprint'];
        $import->validated_at = now();
        $import->failed_at = null;
        $import->save();

        $this->audit->record($import, 'price_list_import.validated', $actor, null, [
            'year' => (int) $import->year,
            'checksum_sha256' => $import->checksum_sha256,
            'error_count' => $preview['error_count'],
            'warning_count' => $preview['warning_count'],
            'valid_row_count' => $preview['valid_row_count'],
            'inventory_count' => count($preview['inventories']),
            'can_proceed' => $preview['can_proceed'],
            'fingerprint' => $preview['fingerprint'],
        ]);

        return $preview;
    }

    /**
     * @return list<PriceList>
     */
    public function confirm(PriceListImport $import, string $fingerprint, User $actor): array
    {
        $this->assertActor($import, $actor);

        if ($import->status === PriceListImportStatus::Imported) {
            throw ValidationException::withMessages([
                'import' => 'Dieser Importlauf wurde bereits übernommen. Es werden keine weiteren Entwürfe erzeugt.',
            ]);
        }

        if ($import->status === PriceListImportStatus::Failed) {
            throw ValidationException::withMessages([
                'import' => 'Dieser Importlauf ist fehlgeschlagen und kann nicht übernommen werden.',
            ]);
        }

        if ($import->status !== PriceListImportStatus::Validated) {
            throw ValidationException::withMessages([
                'import' => 'Bitte zuerst die Datei prüfen.',
            ]);
        }

        // TOCTOU: Datei + Mapping erneut prüfen
        $absolute = $this->absolutePath($import);
        $currentChecksum = hash_file('sha256', $absolute);
        if ($currentChecksum === false || $currentChecksum !== $import->checksum_sha256) {
            throw new PriceListAdminConflictException(
                'Die Importdatei hat sich geändert. Bitte erneut prüfen.',
            );
        }

        $extension = pathinfo($import->original_filename, PATHINFO_EXTENSION);
        $parsed = $this->parser->parse($absolute, $extension);
        $resolver = InventoryAliasResolver::fromDatabase();
        $preview = $this->buildPreview($import, $parsed, $resolver);

        if (! $preview['can_proceed']) {
            $import->report = $preview;
            $import->fingerprint = $preview['fingerprint'];
            $import->error_count = $preview['error_count'];
            $import->warning_count = $preview['warning_count'];
            $import->save();
            throw ValidationException::withMessages([
                'import' => 'Der Import enthält Fehler und kann nicht übernommen werden.',
            ]);
        }

        if (! hash_equals((string) $fingerprint, (string) $preview['fingerprint'])) {
            $import->report = $preview;
            $import->fingerprint = $preview['fingerprint'];
            $import->save();
            throw new PriceListAdminConflictException(
                'Die Importvorschau ist veraltet. Bitte erneut prüfen.',
            );
        }

        try {
            return DB::transaction(function () use ($import, $preview, $actor): array {
                /** @var PriceListImport $locked */
                $locked = PriceListImport::query()->whereKey($import->id)->lockForUpdate()->firstOrFail();
                if ($locked->status === PriceListImportStatus::Imported) {
                    throw ValidationException::withMessages([
                        'import' => 'Dieser Importlauf wurde bereits übernommen. Es werden keine weiteren Entwürfe erzeugt.',
                    ]);
                }

                $inventoryIds = [];
                foreach ($preview['drafts'] as $draft) {
                    $inventoryIds[] = (int) $draft['inventory_id'];
                }
                $inventoryIds = array_values(array_unique($inventoryIds));
                sort($inventoryIds);

                foreach ($inventoryIds as $inventoryId) {
                    Inventory::query()->whereKey($inventoryId)->lockForUpdate()->firstOrFail();
                    PriceList::query()
                        ->where('inventory_id', $inventoryId)
                        ->where('year', (int) $locked->year)
                        ->orderBy('id')
                        ->lockForUpdate()
                        ->get();
                }

                $lists = [];
                foreach ($preview['drafts'] as $draft) {
                    $lists[] = $this->writer->createDraft([
                        'inventory_id' => $draft['inventory_id'],
                        'year' => (int) $locked->year,
                        'name' => $draft['name'],
                        'items' => $draft['items'],
                    ], $actor);
                }

                $priceListIds = array_map(
                    fn (PriceList $list): int => (int) $list->id,
                    $lists,
                );

                $locked->status = PriceListImportStatus::Imported;
                $locked->report = $preview;
                $locked->fingerprint = $preview['fingerprint'];
                $locked->created_price_list_ids = $priceListIds;
                $locked->confirmed_at = now();
                $locked->completed_at = now();
                $locked->save();

                $this->audit->record($locked, 'price_list_import.confirmed', $actor, null, [
                    'year' => (int) $locked->year,
                    'checksum_sha256' => $locked->checksum_sha256,
                    'price_list_ids' => $priceListIds,
                    'inventory_ids' => array_values(array_unique(array_map(
                        fn (array $draft): int => (int) $draft['inventory_id'],
                        $preview['drafts'],
                    ))),
                    'valid_row_count' => $preview['valid_row_count'],
                    'warning_count' => $preview['warning_count'],
                ]);

                return $lists;
            });
        } catch (ValidationException|PriceListAdminConflictException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            $import->refresh();
            if ($import->status !== PriceListImportStatus::Imported) {
                $import->status = PriceListImportStatus::Failed;
                $import->failed_at = now();
                $import->save();
            }
            throw $exception;
        }
    }

    /**
     * @param  array{
     *     sheet_count: int,
     *     issues: list<array{severity: string, code: string, message: string, sheet: ?string, row: ?int, column: ?string}>,
     *     rows: list<array<string, mixed>>
     * }  $parsed
     * @return array<string, mixed>
     */
    private function buildPreview(PriceListImport $import, array $parsed, InventoryAliasResolver $resolver): array
    {
        $issues = $parsed['issues'];
        $previewRows = [];
        $draftBuckets = [];
        /** @var array<string, true> $seenKeys */
        $seenKeys = [];

        foreach ($parsed['rows'] as $raw) {
            $sheet = (string) $raw['sheet'];
            $sourceRow = (int) $raw['source_row'];

            $resolved = $resolver->resolve((string) $raw['inventory_raw']);
            if (isset($resolved['error'])) {
                $issues[] = $this->issue('error', 'unknown_inventory', $resolved['error'], $sheet, $sourceRow, 'inventory');

                continue;
            }
            /** @var Inventory $inventory */
            $inventory = $resolved['inventory'];

            if ($raw['year_raw'] !== null && trim((string) $raw['year_raw']) !== '') {
                $fileYear = (int) preg_replace('/\D+/', '', (string) $raw['year_raw']);
                if ($fileYear !== (int) $import->year) {
                    $issues[] = $this->issue(
                        'error',
                        'year_mismatch',
                        'Jahr in der Datei ('.$fileYear.') weicht vom Importjahr ('.$import->year.') ab.',
                        $sheet,
                        $sourceRow,
                        'year',
                    );

                    continue;
                }
            }

            $hourParsed = PriceListWorkbookParser::parseHour($raw['hour_raw']);
            if ($hourParsed === 'missing') {
                $issues[] = $this->issue('error', 'hour_missing', 'Stunde fehlt.', $sheet, $sourceRow, 'hour');

                continue;
            }
            if ($hourParsed === 'invalid' || $hourParsed < 0 || $hourParsed > 23) {
                $issues[] = $this->issue('error', 'hour_invalid', 'Stunde muss zwischen 0 und 23 liegen.', $sheet, $sourceRow, 'hour');

                continue;
            }

            $dayParsed = PriceListWorkbookParser::parseDayGroup($raw['day_group_raw']);
            if ($dayParsed === 'missing') {
                $issues[] = $this->issue('error', 'day_group_missing', 'Tagesgruppe fehlt.', $sheet, $sourceRow, 'day_group');

                continue;
            }
            if ($dayParsed === 'derived') {
                $issues[] = $this->issue(
                    'error',
                    'derived_day_group',
                    'Mo–Sa und Mo–So werden abgeleitet und nicht importiert.',
                    $sheet,
                    $sourceRow,
                    'day_group',
                );

                continue;
            }
            if ($dayParsed === 'invalid' || ! $dayParsed instanceof DayGroup || $dayParsed->isDerived()) {
                $issues[] = $this->issue('error', 'day_group_invalid', 'Ungültige Tagesgruppe.', $sheet, $sourceRow, 'day_group');

                continue;
            }

            if (PriceListItemContract::isMissingPrice($raw['second_price_raw'])) {
                $issues[] = $this->issue(
                    'warning',
                    'price_missing',
                    'Leerer Preis – Zeile wird nicht importiert (nicht buchbar, nicht 0).',
                    $sheet,
                    $sourceRow,
                    'second_price',
                );

                continue;
            }

            try {
                $normalizedPrice = PriceListItemContract::normalizeBaseItems([
                    [
                        'hour' => $hourParsed,
                        'day_group' => $dayParsed->value,
                        'second_price' => $raw['second_price_raw'],
                    ],
                ], forActivation: false)[0]['second_price'];
            } catch (ValidationException $exception) {
                $message = $exception->errors()['items'][0] ?? 'Ungültiger Preis.';
                $issues[] = $this->issue('error', 'price_invalid', $message, $sheet, $sourceRow, 'second_price');

                continue;
            }

            $dupKey = $inventory->id.'|'.$import->year.'|'.$hourParsed.'|'.$dayParsed->value;
            if (isset($seenKeys[$dupKey])) {
                $issues[] = $this->issue(
                    'error',
                    'duplicate',
                    'Doppelter Schlüssel Inventar/Jahr/Stunde/Tagesgruppe.',
                    $sheet,
                    $sourceRow,
                    null,
                );

                continue;
            }
            $seenKeys[$dupKey] = true;

            $previewRows[] = [
                'inventory_id' => (int) $inventory->id,
                'inventory_code' => (string) $inventory->code,
                'inventory_name' => (string) $inventory->name,
                'year' => (int) $import->year,
                'hour' => $hourParsed,
                'day_group' => $dayParsed->value,
                'day_group_label' => $dayParsed->label(),
                'second_price' => $normalizedPrice,
                'sheet' => $sheet,
                'source_row' => $sourceRow,
            ];

            $draftBuckets[$inventory->id]['inventory'] = $inventory;
            $draftBuckets[$inventory->id]['items'][] = [
                'hour' => $hourParsed,
                'day_group' => $dayParsed->value,
                'second_price' => $normalizedPrice,
            ];
        }

        if ($previewRows === [] && ! $this->hasError($issues)) {
            $issues[] = $this->issue(
                'error',
                'no_rows',
                'Kein importierbarer Datensatz gefunden.',
                null,
                null,
                null,
            );
        }

        $errorCount = count(array_filter($issues, fn (array $i): bool => $i['severity'] === 'error'));
        $warningCount = count(array_filter($issues, fn (array $i): bool => $i['severity'] === 'warning'));

        $drafts = [];
        foreach ($draftBuckets as $bucket) {
            /** @var Inventory $inventory */
            $inventory = $bucket['inventory'];
            $drafts[] = [
                'inventory_id' => (int) $inventory->id,
                'inventory_code' => (string) $inventory->code,
                'inventory_name' => (string) $inventory->name,
                'name' => 'Import '.$import->year.' '.$inventory->code,
                'items' => $bucket['items'],
            ];
        }

        usort($drafts, fn (array $a, array $b): int => $a['inventory_id'] <=> $b['inventory_id']);

        $body = [
            'entity' => 'price_list_import',
            'action' => 'confirm',
            'import_id' => (int) $import->id,
            'year' => (int) $import->year,
            'checksum_sha256' => $import->checksum_sha256,
            'original_filename' => $import->original_filename,
            'sheet_count' => $parsed['sheet_count'],
            'row_count' => count($parsed['rows']),
            'valid_row_count' => count($previewRows),
            'error_count' => $errorCount,
            'warning_count' => $warningCount,
            'inventories' => array_map(fn (array $d): array => [
                'id' => $d['inventory_id'],
                'code' => $d['inventory_code'],
                'name' => $d['inventory_name'],
                'item_count' => count($d['items']),
            ], $drafts),
            'rows' => $previewRows,
            'issues' => $issues,
            'drafts' => $drafts,
            'can_proceed' => $errorCount === 0 && $previewRows !== [],
        ];
        $body['fingerprint'] = $this->fingerprints->fingerprint($body);

        return $body;
    }

    /**
     * @return array{severity: string, code: string, message: string, sheet: ?string, row: ?int, column: ?string}
     */
    private function issue(
        string $severity,
        string $code,
        string $message,
        ?string $sheet,
        ?int $row,
        ?string $column,
    ): array {
        return [
            'severity' => $severity,
            'code' => $code,
            'message' => $message,
            'sheet' => $sheet,
            'row' => $row,
            'column' => $column,
        ];
    }

    /**
     * @param  list<array{severity: string}>  $issues
     */
    private function hasError(array $issues): bool
    {
        foreach ($issues as $issue) {
            if ($issue['severity'] === 'error') {
                return true;
            }
        }

        return false;
    }

    private function assertUpload(UploadedFile $file): void
    {
        if (! $file->isValid()) {
            throw ValidationException::withMessages([
                'file' => 'Der Upload ist ungültig.',
            ]);
        }

        $extension = strtolower($file->getClientOriginalExtension());
        if (! in_array($extension, PriceListImportLimits::ALLOWED_EXTENSIONS, true)) {
            throw ValidationException::withMessages([
                'file' => 'Nur XLSX und XLS sind zulässig.',
            ]);
        }

        if ($file->getSize() !== false && $file->getSize() > PriceListImportLimits::MAX_BYTES) {
            throw ValidationException::withMessages([
                'file' => 'Die Datei überschreitet die maximale Größe von 50 MB.',
            ]);
        }

        $mime = (string) $file->getMimeType();
        if ($mime !== '' && ! in_array($mime, PriceListImportLimits::ALLOWED_MIME_TYPES, true)) {
            // Manche Browser liefern generische MIME; Extension bleibt maßgeblich,
            // explizit bekannte Fremdtypen ablehnen.
            if (str_starts_with($mime, 'text/') || str_contains($mime, 'csv')) {
                throw ValidationException::withMessages([
                    'file' => 'CSV ist kein zulässiges Preislisten-Importformat.',
                ]);
            }
        }
    }

    private function assertYear(int $year): int
    {
        if ($year < 1990 || $year > 2100) {
            throw ValidationException::withMessages([
                'year' => 'Das Kalenderjahr muss zwischen 1990 und 2100 liegen.',
            ]);
        }

        return $year;
    }

    private function assertActor(PriceListImport $import, User $actor): void
    {
        if ((int) $import->user_id !== (int) $actor->id && ! $actor->canAccessAdministration()) {
            throw ValidationException::withMessages([
                'import' => 'Keine Berechtigung für diesen Importlauf.',
            ]);
        }
    }

    private function absolutePath(PriceListImport $import): string
    {
        $diskRoot = $this->files->disk()->path('');
        $path = $import->stored_path;
        if (! $this->files->exists($path)) {
            throw ValidationException::withMessages([
                'file' => 'Die Importdatei wurde nicht gefunden.',
            ]);
        }

        return rtrim($diskRoot, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.ltrim($path, DIRECTORY_SEPARATOR);
    }

    private function cleanupTemporaryUpload(string $temporaryPath): void
    {
        if (! $this->files->exists($temporaryPath)) {
            return;
        }

        try {
            $this->files->deleteTemporary($temporaryPath);
        } catch (Throwable) {
            // Best effort: nur temporäre Pfade, niemals persistierte Archive.
        }
    }

    /**
     * Archiv-Move fehlgeschlagen nachdem Import + uploaded-Audit committed sind.
     * AUD-001: bestehende Audits bleiben; Kompensation nur append-only + Failed-Status.
     */
    private function markUploadArchiveFailed(PriceListImport $import, User $actor, string $temporaryPath): void
    {
        $this->cleanupTemporaryUpload($temporaryPath);

        $import->status = PriceListImportStatus::Failed;
        $import->failed_at = now();
        $import->created_price_list_ids = null;
        $import->confirmed_at = null;
        $import->completed_at = null;
        $import->report = [
            'failure' => [
                'code' => 'archive_move_failed',
                'message' => 'Die Datei konnte nicht in den privaten Archivpfad übernommen werden.',
            ],
        ];
        $import->save();

        $this->audit->record($import, 'price_list_import.failed', $actor, null, [
            'code' => 'archive_move_failed',
            'year' => (int) $import->year,
            'checksum_sha256' => $import->checksum_sha256,
            'original_filename' => $import->original_filename,
        ]);
    }
}
