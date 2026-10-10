<?php

namespace App\Services\Crm;

use App\Enums\CrmAccountType;
use App\Enums\CrmConflictType;
use App\Enums\CrmImportStatus;
use App\Exceptions\CrmImportConflictException;
use App\Models\CrmAccount;
use App\Models\CrmImport;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\Crm\CrmSalesforceCsvParser;
use App\Support\PrivateFileStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class CrmImportService
{
    public const string STORAGE_PREFIX = 'crm-imports/';

    public function __construct(
        private readonly PrivateFileStorage $files,
        private readonly CrmSalesforceCsvParser $parser,
        private readonly CrmAccountService $accounts,
        private readonly CrmMeridianSupplementService $meridian,
        private readonly AuditLogger $audit,
    ) {}

    public function upload(UploadedFile $file, User $actor): CrmImport
    {
        $extension = strtolower($file->getClientOriginalExtension());
        if ($extension !== 'csv') {
            throw ValidationException::withMessages([
                'file' => 'Nur CSV-Dateien sind in diesem Slice zulässig.',
            ]);
        }

        $binary = file_get_contents($file->getRealPath());
        if ($binary === false || $binary === '') {
            throw ValidationException::withMessages([
                'file' => 'Die Datei konnte nicht gelesen werden.',
            ]);
        }

        $checksum = hash('sha256', $binary);
        $storedName = Str::uuid()->toString().'.csv';
        $temporaryPath = PrivateFileStorage::TEMPORARY_PREFIX.'crm-'.$storedName;
        $finalPath = self::STORAGE_PREFIX.$storedName;

        if ($this->files->put($temporaryPath, $binary) === false) {
            throw ValidationException::withMessages([
                'file' => 'Die Datei konnte nicht gespeichert werden.',
            ]);
        }

        try {
            $import = DB::transaction(function () use ($actor, $file, $finalPath, $checksum, $binary): CrmImport {
                $import = CrmImport::query()->create([
                    'user_id' => $actor->id,
                    'status' => CrmImportStatus::Uploaded,
                    'original_filename' => mb_substr($file->getClientOriginalName(), 0, 255),
                    'stored_path' => $finalPath,
                    'checksum_sha256' => $checksum,
                    'mime_type' => $file->getMimeType(),
                    'file_size' => strlen($binary),
                ]);

                $this->audit->record($import, 'crm_import.uploaded', $actor, null, [
                    'original_filename' => $import->original_filename,
                    'checksum_sha256' => $checksum,
                    'file_size' => $import->file_size,
                ]);

                return $import;
            });

            $this->files->put($finalPath, $binary);
            $this->files->deleteTemporary($temporaryPath);

            return $this->validate($import, $actor);
        } catch (\Throwable $e) {
            $this->files->deleteTemporary($temporaryPath);
            throw $e;
        }
    }

    public function validate(CrmImport $import, User $actor): CrmImport
    {
        $binary = $this->files->get($import->stored_path);
        if ($binary === null || $binary === '') {
            throw ValidationException::withMessages([
                'file' => 'ImportDatei nicht gefunden.',
            ]);
        }

        $parsed = $this->parser->parse($binary);
        $preview = $this->buildPreview($parsed);

        $import->status = CrmImportStatus::Validated;
        $import->row_count = count($parsed['rows']);
        $import->valid_row_count = $preview['stats']['valid_rows'];
        $import->error_count = $preview['stats']['error_rows'];
        $import->warning_count = $preview['stats']['warning_rows'];
        $import->preview = $preview;
        $import->fingerprint = $preview['fingerprint'];
        $import->catalog_fingerprint = $this->accounts->catalogFingerprint();
        $import->validated_at = now();
        $import->save();

        $this->audit->record($import, 'crm_import.validated', $actor, null, [
            'fingerprint' => $import->fingerprint,
            'catalog_fingerprint' => $import->catalog_fingerprint,
            'stats' => $preview['stats'],
        ]);

        return $import->fresh() ?? $import;
    }

    public function apply(
        CrmImport $import,
        User $actor,
        string $expectedFingerprint,
        string $expectedCatalogFingerprint,
    ): CrmImport {
        if ($import->status !== CrmImportStatus::Validated && $import->status !== CrmImportStatus::Applied) {
            throw ValidationException::withMessages([
                'import' => 'Import ist nicht zur Anwendung bereit.',
            ]);
        }

        /** @var array{kind: string, import: CrmImport} $outcome */
        $outcome = DB::transaction(function () use (
            $import,
            $actor,
            $expectedFingerprint,
            $expectedCatalogFingerprint,
        ): array {
            $import = CrmImport::query()->lockForUpdate()->findOrFail($import->id);

            if ($import->status === CrmImportStatus::Applied) {
                return ['kind' => 'applied', 'import' => $import];
            }

            // Serialisiert Katalogänderungen gegen parallele Links/Imports.
            CrmAccount::query()->orderBy('id')->lockForUpdate()->get(['id']);

            $binary = $this->files->get($import->stored_path);
            if ($binary === null) {
                throw ValidationException::withMessages(['file' => 'ImportDatei nicht gefunden.']);
            }
            if (hash('sha256', $binary) !== $import->checksum_sha256) {
                throw new CrmImportConflictException('Importdatei wurde verändert.');
            }

            $parsed = $this->parser->parse($binary);
            $preview = $this->buildPreview($parsed);
            $catalogFp = $this->accounts->catalogFingerprint();

            if ($preview['fingerprint'] !== $expectedFingerprint
                || $preview['fingerprint'] !== $import->fingerprint
                || $catalogFp !== $expectedCatalogFingerprint
                || $catalogFp !== $import->catalog_fingerprint) {
                $import->preview = $preview;
                $import->fingerprint = $preview['fingerprint'];
                $import->catalog_fingerprint = $catalogFp;
                $import->valid_row_count = $preview['stats']['valid_rows'];
                $import->error_count = $preview['stats']['error_rows'];
                $import->warning_count = $preview['stats']['warning_rows'];
                $import->save();

                // Transaktion commitet die aktualisierte Vorschau; Exception danach.
                return ['kind' => 'stale', 'import' => $import->fresh() ?? $import];
            }

            if ($preview['stats']['blocking_errors'] > 0) {
                throw ValidationException::withMessages([
                    'import' => 'Import enthält blockierende Fehler und kann nicht angewendet werden.',
                ]);
            }

            $reportRows = [];
            $supplements = ['calculations' => 0, 'dispo_orders' => 0, 'conflicts' => 0];

            foreach ($preview['actions'] as $action) {
                $result = $this->applyAction($action, $actor, $import->id);
                $reportRows[] = $result;
                if (isset($result['supplement'])) {
                    $supplements['calculations'] += $result['supplement']['calculations'];
                    $supplements['dispo_orders'] += $result['supplement']['dispo_orders'];
                    $supplements['conflicts'] += $result['supplement']['conflicts'] ?? 0;
                }
            }

            $matchReport = $this->autoMatchProvisionals($actor, $import->id);
            foreach ($matchReport as $match) {
                if (($match['mode'] ?? null) !== 'auto' || empty($match['linked_account_id'])) {
                    continue;
                }
                $linked = CrmAccount::query()
                    ->with('currentVersion')
                    ->whereKey((int) $match['linked_account_id'])
                    ->first();
                $meridian = $linked?->currentVersion?->meridian_number;
                if ($linked !== null && $meridian !== null && $meridian !== '') {
                    $extra = $this->meridian->supplementMissing($linked, $meridian, $actor, $import->id);
                    $supplements['calculations'] += $extra['calculations'];
                    $supplements['dispo_orders'] += $extra['dispo_orders'];
                    $supplements['conflicts'] += $extra['conflicts'];
                } elseif ($linked !== null) {
                    $sf = $this->meridian->supplementSalesforceIds($linked, $actor, $import->id);
                    $supplements['calculations'] += $sf['calculations'];
                    $supplements['dispo_orders'] += $sf['dispo_orders'];
                }
            }

            // Folgeimport: fehlende Nachträge auch bei unveränderten Stammdaten nachholen.
            foreach ($preview['actions'] as $action) {
                $account = CrmAccount::query()
                    ->with('currentVersion')
                    ->where('salesforce_account_id_canonical', $action['salesforce']['canonical'])
                    ->whereNull('merged_into_account_id')
                    ->first();
                if ($account === null) {
                    continue;
                }
                $number = $account->currentVersion?->meridian_number;
                if ($number !== null && $number !== '') {
                    $extra = $this->meridian->supplementMissing($account, $number, $actor, $import->id);
                    $supplements['calculations'] += $extra['calculations'];
                    $supplements['dispo_orders'] += $extra['dispo_orders'];
                    $supplements['conflicts'] += $extra['conflicts'];
                }
            }

            $import->status = CrmImportStatus::Applied;
            $import->applied_at = now();
            $import->report = [
                'actions' => $reportRows,
                'auto_matches' => $matchReport,
                'planned_auto_matches' => $preview['planned_auto_matches'] ?? [],
                'supplements' => $supplements,
                'applied_at' => now()->toIso8601String(),
            ];
            $import->save();

            $this->audit->record($import, 'crm_import.applied', $actor, null, [
                'action_count' => count($reportRows),
                'auto_matches' => count($matchReport),
                'supplements' => $supplements,
            ]);

            return ['kind' => 'applied', 'import' => $import->fresh() ?? $import];
        });

        if ($outcome['kind'] === 'stale') {
            throw new CrmImportConflictException(
                'Bestand oder Vorschau hat sich geändert. Bitte Vorschau erneut prüfen.',
                $outcome['import'],
            );
        }

        return $outcome['import'];
    }

    /**
     * @param  array{rows: list<array<string, mixed>>, header_ok: bool, errors: list<string>}  $parsed
     * @return array<string, mixed>
     */
    private function buildPreview(array $parsed): array
    {
        $issues = [];
        foreach ($parsed['errors'] as $error) {
            $issues[] = ['severity' => 'error', 'line' => null, 'message' => $error];
        }

        /** @var array<string, list<int>> $canonicalLines */
        $canonicalLines = [];
        $actions = [];
        $valid = 0;
        $errorRows = 0;
        $warningRows = 0;
        $blocking = count($parsed['errors']);

        foreach ($parsed['rows'] as $row) {
            $lineIssues = [];
            foreach ($row['errors'] as $error) {
                $lineIssues[] = ['severity' => 'error', 'line' => $row['line'], 'message' => $error];
                $blocking++;
            }
            foreach ($row['warnings'] as $warning) {
                $lineIssues[] = ['severity' => 'warning', 'line' => $row['line'], 'message' => $warning];
            }

            if ($row['errors'] !== []) {
                $errorRows++;
                $issues = array_merge($issues, $lineIssues);

                continue;
            }

            $valid++;
            if ($row['warnings'] !== []) {
                $warningRows++;
                $issues = array_merge($issues, $lineIssues);
            }

            /** @var CrmAccountType $type */
            $type = $row['type'];
            $sf = $row['salesforce'];
            $canonical = $sf['canonical'];
            $canonicalLines[$canonical][] = $row['line'];

            $emailAnalysis = $row['email_analysis'];
            $domain = $emailAnalysis['unique_domain'];
            if ($emailAnalysis['divergent']) {
                $domain = null;
            }
            $billingEmail = null;
            if (count($emailAnalysis['emails']) >= 1 && ! $emailAnalysis['divergent']) {
                $billingEmail = $emailAnalysis['emails'][0];
            }

            $meridian = $row['meridian_raw'] === '' ? null : $row['meridian_raw'];

            $effect = $this->planUpsertEffect(
                $canonical,
                $type,
                (string) $row['name'],
                $billingEmail,
                $domain,
                $meridian,
            );

            $actions[] = [
                'line' => $row['line'],
                'kind' => 'upsert_salesforce',
                'type' => $type->value,
                'name' => $row['name'],
                'salesforce' => $sf,
                'billing_email' => $billingEmail,
                'matching_domain' => $domain,
                'meridian_number' => $meridian,
                'divergent_domains' => $emailAnalysis['divergent'],
                'domains' => $emailAnalysis['domains'],
                'effect' => $effect['effect'],
                'effect_label' => $effect['effect_label'],
                'existing_account_id' => $effect['existing_account_id'],
                'existing_version' => $effect['existing_version'],
                'meridian_kept' => $effect['meridian_kept'],
                'planned_supplement' => $effect['planned_supplement'],
            ];
        }

        foreach ($canonicalLines as $canonical => $lines) {
            if (count($lines) > 1) {
                $subset = array_values(array_filter(
                    $parsed['rows'],
                    fn (array $r): bool => ($r['salesforce']['canonical'] ?? null) === $canonical && $r['errors'] === [],
                ));
                $signatures = array_unique(array_map(
                    fn (array $r): string => implode("\0", [
                        $r['name'],
                        $r['meridian_raw'],
                        $r['billing_email_raw'],
                        $r['record_type_raw'],
                    ]),
                    $subset,
                ));
                if (count($signatures) > 1) {
                    $blocking++;
                    $issues[] = [
                        'severity' => 'error',
                        'line' => $lines[0],
                        'message' => 'Widersprüchliche Zeilen für dieselbe Salesforce-ID (Zeilen '.implode(', ', $lines).').',
                    ];
                    $actions = array_values(array_filter(
                        $actions,
                        fn (array $a): bool => ($a['salesforce']['canonical'] ?? null) !== $canonical,
                    ));
                } else {
                    $seen = false;
                    $actions = array_values(array_filter($actions, function (array $a) use ($canonical, &$seen): bool {
                        if (($a['salesforce']['canonical'] ?? null) !== $canonical) {
                            return true;
                        }
                        if ($seen) {
                            return false;
                        }
                        $seen = true;

                        return true;
                    }));
                }
            }
        }

        $plannedMatches = $this->planAutoMatches($actions);

        $fingerprint = hash('sha256', json_encode([
            'actions' => $actions,
            'issues' => $issues,
            'planned_auto_matches' => $plannedMatches,
        ], JSON_THROW_ON_ERROR));

        return [
            'actions' => $actions,
            'issues' => $issues,
            'planned_auto_matches' => $plannedMatches,
            'stats' => [
                'valid_rows' => $valid,
                'error_rows' => $errorRows,
                'warning_rows' => $warningRows,
                'blocking_errors' => $blocking,
                'action_count' => count($actions),
                'create_count' => count(array_filter($actions, fn ($a) => $a['effect'] === 'create')),
                'unchanged_count' => count(array_filter($actions, fn ($a) => $a['effect'] === 'unchanged')),
                'new_version_count' => count(array_filter($actions, fn ($a) => $a['effect'] === 'new_version')),
                'conflict_effect_count' => count(array_filter(
                    $actions,
                    fn ($a) => in_array($a['effect'], ['meridian_conflict', 'type_conflict'], true),
                )),
                'planned_auto_match_count' => count(array_filter(
                    $plannedMatches,
                    fn ($m) => ($m['mode'] ?? '') === 'auto',
                )),
                'planned_ambiguous_count' => count(array_filter(
                    $plannedMatches,
                    fn ($m) => ($m['mode'] ?? '') === 'ambiguous',
                )),
            ],
            'fingerprint' => $fingerprint,
            'notes' => [
                'addresses' => 'CSV enthält keine Adressspalten; Adressen werden weder erfunden noch geleert.',
                'format' => 'UTF-8 CSV mit Semikolon; Typen Account KUNDE / Account AGENTUR.',
            ],
        ];
    }

    /**
     * @return array{
     *     effect: string,
     *     effect_label: string,
     *     existing_account_id: ?int,
     *     existing_version: ?int,
     *     meridian_kept: bool,
     *     planned_supplement: bool
     * }
     */
    private function planUpsertEffect(
        string $canonical,
        CrmAccountType $type,
        string $name,
        ?string $billingEmail,
        ?string $domain,
        ?string $meridian,
    ): array {
        $existing = CrmAccount::query()
            ->with('currentVersion')
            ->where('salesforce_account_id_canonical', $canonical)
            ->whereNull('merged_into_account_id')
            ->first();

        if ($existing === null) {
            return [
                'effect' => 'create',
                'effect_label' => 'Neuanlage',
                'existing_account_id' => null,
                'existing_version' => null,
                'meridian_kept' => false,
                'planned_supplement' => $meridian !== null && $meridian !== '',
            ];
        }

        if ($existing->type !== $type) {
            return [
                'effect' => 'type_conflict',
                'effect_label' => 'Typkonflikt',
                'existing_account_id' => $existing->id,
                'existing_version' => $existing->currentVersion?->version_number,
                'meridian_kept' => true,
                'planned_supplement' => false,
            ];
        }

        $current = $existing->currentVersion;
        $existingMeridian = $current?->meridian_number;
        $meridianConflict = $meridian !== null && $meridian !== ''
            && $existingMeridian !== null && $existingMeridian !== ''
            && $meridian !== $existingMeridian;
        $meridianKept = ($meridian === null || $meridian === '')
            && $existingMeridian !== null && $existingMeridian !== '';
        $effectiveMeridian = $meridianConflict || $meridianKept
            ? $existingMeridian
            : (($meridian === '') ? null : $meridian);

        $unchanged = $current !== null
            && $current->name === $name
            && ($current->billing_email ?? null) === ($billingEmail ?? null)
            && ($current->matching_domain ?? null) === ($domain ?? null)
            && ($current->meridian_number ?? null) === ($effectiveMeridian ?? null);

        $effect = $meridianConflict
            ? 'meridian_conflict'
            : ($unchanged ? 'unchanged' : 'new_version');
        $label = match ($effect) {
            'meridian_conflict' => 'Meridian-Konflikt (bestehende Nummer bleibt)',
            'unchanged' => $meridianKept
                ? 'Unverändert (Meridian-ID beibehalten)'
                : 'Unverändert',
            default => 'Neue Stammdatenversion',
        };

        $effectiveForSupplement = $effectiveMeridian ?? $existingMeridian;

        return [
            'effect' => $effect,
            'effect_label' => $label,
            'existing_account_id' => $existing->id,
            'existing_version' => $current?->version_number,
            'meridian_kept' => $meridianKept,
            'planned_supplement' => $effectiveForSupplement !== null && $effectiveForSupplement !== '',
        ];
    }

    /**
     * Geplante Auto-Matches anhand des Salesforce-Bestands nach allen zulässigen
     * Importänderungen (gleiche Regeln wie {@see autoMatchProvisionals}).
     *
     * @param  list<array<string, mixed>>  $actions
     * @return list<array<string, mixed>>
     */
    private function planAutoMatches(array $actions): array
    {
        $projected = $this->projectSalesforceCatalogAfterActions($actions);

        $planned = [];
        $provisionals = CrmAccount::query()
            ->with('currentVersion')
            ->where('is_provisional', true)
            ->whereNull('salesforce_account_id_canonical')
            ->whereNull('merged_into_account_id')
            ->whereNotNull('matching_domain')
            ->orderBy('id')
            ->get();

        foreach ($provisionals as $provisional) {
            $domain = $provisional->matching_domain;
            if ($domain === null || $this->accounts->isSharedDomain($domain)) {
                continue;
            }

            $typeValue = $provisional->type->value;
            $candidates = array_values(array_filter(
                $projected,
                static fn (array $row): bool => $row['type'] === $typeValue
                    && ($row['matching_domain'] ?? null) === $domain,
            ));

            if (count($candidates) === 1) {
                $target = $candidates[0];
                $planned[] = [
                    'provisional_id' => $provisional->id,
                    'provisional_name' => $provisional->currentVersion?->name,
                    'domain' => $domain,
                    'mode' => 'auto',
                    'target_account_id' => $target['id'],
                    'target_from_import_line' => $target['from_import_line'],
                    'target_salesforce_canonical' => $target['canonical'],
                ];
            } elseif (count($candidates) > 1) {
                $planned[] = [
                    'provisional_id' => $provisional->id,
                    'provisional_name' => $provisional->currentVersion?->name,
                    'domain' => $domain,
                    'mode' => 'ambiguous',
                    'candidates' => count($candidates),
                    'candidate_canonicals' => array_map(
                        static fn (array $row): string => (string) $row['canonical'],
                        $candidates,
                    ),
                ];
            }
        }

        return $planned;
    }

    /**
     * Effektiver Salesforce-Katalog nach Apply der Importaktionen (ohne DB-Schreiben).
     * Typkonflikte ändern den Bestand nicht; divergierende Domains setzen matching_domain auf null.
     *
     * @param  list<array<string, mixed>>  $actions
     * @return list<array{id: ?int, canonical: string, type: string, matching_domain: ?string, from_import_line: ?int}>
     */
    private function projectSalesforceCatalogAfterActions(array $actions): array
    {
        /** @var array<string, array{id: ?int, canonical: string, type: string, matching_domain: ?string, from_import_line: ?int}> $byCanonical */
        $byCanonical = [];

        foreach (
            CrmAccount::query()
                ->whereNotNull('salesforce_account_id_canonical')
                ->whereNull('merged_into_account_id')
                ->orderBy('id')
                ->get(['id', 'type', 'salesforce_account_id_canonical', 'matching_domain']) as $account
        ) {
            $canonical = (string) $account->salesforce_account_id_canonical;
            $byCanonical[$canonical] = [
                'id' => (int) $account->id,
                'canonical' => $canonical,
                'type' => $account->type->value,
                'matching_domain' => $account->matching_domain,
                'from_import_line' => null,
            ];
        }

        foreach ($actions as $action) {
            if (($action['effect'] ?? null) === 'type_conflict') {
                // Apply lässt den bestehenden Account unverändert.
                continue;
            }

            $canonical = (string) ($action['salesforce']['canonical'] ?? '');
            if ($canonical === '') {
                continue;
            }

            $domain = ! empty($action['divergent_domains'])
                ? null
                : (($action['matching_domain'] ?? null) === '' ? null : ($action['matching_domain'] ?? null));

            if (isset($byCanonical[$canonical])) {
                $byCanonical[$canonical]['matching_domain'] = $domain;

                continue;
            }

            $byCanonical[$canonical] = [
                'id' => isset($action['existing_account_id']) ? (int) $action['existing_account_id'] : null,
                'canonical' => $canonical,
                'type' => (string) $action['type'],
                'matching_domain' => $domain,
                'from_import_line' => isset($action['line']) ? (int) $action['line'] : null,
            ];
        }

        return array_values($byCanonical);
    }

    /**
     * @param  array<string, mixed>  $action
     * @return array<string, mixed>
     */
    private function applyAction(array $action, User $actor, int $importId): array
    {
        $type = CrmAccountType::from((string) $action['type']);
        $payload = [
            'name' => (string) $action['name'],
            'billing_email' => $action['billing_email'],
            'matching_domain' => $action['matching_domain'],
            'meridian_number' => $action['meridian_number'],
        ];

        if (! empty($action['divergent_domains'])) {
            $this->accounts->openConflict(CrmConflictType::DivergentDomains, null, $importId, [
                'line' => $action['line'],
                'domains' => $action['domains'],
                'salesforce_canonical' => $action['salesforce']['canonical'],
            ]);
            $payload['matching_domain'] = null;
        }

        $before = CrmAccount::query()
            ->where('salesforce_account_id_canonical', $action['salesforce']['canonical'])
            ->whereNull('merged_into_account_id')
            ->first();

        $account = $this->accounts->upsertSalesforceAccount(
            $action['salesforce'],
            $type,
            $payload,
            $actor,
            $importId,
        );

        $supplement = ['calculations' => 0, 'dispo_orders' => 0, 'conflicts' => 0];
        $account->loadMissing('currentVersion');
        $newMeridian = $account->currentVersion?->meridian_number;
        if ($newMeridian !== null && $newMeridian !== '') {
            $supplement = $this->meridian->supplementMissing($account, $newMeridian, $actor, $importId);
        } else {
            $sf = $this->meridian->supplementSalesforceIds($account, $actor, $importId);
            $supplement['calculations'] = $sf['calculations'];
            $supplement['dispo_orders'] = $sf['dispo_orders'];
        }

        return [
            'line' => $action['line'],
            'account_id' => $account->id,
            'salesforce_canonical' => $action['salesforce']['canonical'],
            'created' => $before === null,
            'effect' => $action['effect'] ?? null,
            'version' => $account->currentVersion?->version_number,
            'supplement' => $supplement,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function autoMatchProvisionals(User $actor, int $importId): array
    {
        $report = [];
        $provisionals = CrmAccount::query()
            ->where('is_provisional', true)
            ->whereNull('salesforce_account_id_canonical')
            ->whereNull('merged_into_account_id')
            ->whereNotNull('matching_domain')
            ->orderBy('id')
            ->get();

        foreach ($provisionals as $provisional) {
            $domain = $provisional->matching_domain;
            if ($domain === null || $this->accounts->isSharedDomain($domain)) {
                continue;
            }

            $candidates = $this->accounts->findSalesforceCandidatesByDomain($provisional->type, $domain);
            if (count($candidates) === 1) {
                $target = $candidates[0];
                $linked = $this->accounts->linkProvisionalToSalesforce($provisional, $target, $actor);
                $report[] = [
                    'provisional_id' => $provisional->id,
                    'linked_account_id' => $linked->id,
                    'domain' => $domain,
                    'mode' => 'auto',
                ];
                $this->audit->record($provisional, 'crm_account.auto_linked', $actor, null, [
                    'linked_account_id' => $linked->id,
                    'domain' => $domain,
                    'import_id' => $importId,
                ]);
            } elseif (count($candidates) > 1) {
                $this->accounts->openConflict(CrmConflictType::AmbiguousDomain, $provisional, $importId, [
                    'domain' => $domain,
                    'candidate_ids' => array_map(fn ($c) => $c->id, $candidates),
                ]);
                $report[] = [
                    'provisional_id' => $provisional->id,
                    'domain' => $domain,
                    'mode' => 'ambiguous',
                    'candidates' => count($candidates),
                ];
            }
        }

        return $report;
    }
}
