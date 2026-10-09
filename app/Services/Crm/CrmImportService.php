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

    public function apply(CrmImport $import, User $actor, string $expectedFingerprint, string $expectedCatalogFingerprint): CrmImport
    {
        if ($import->status !== CrmImportStatus::Validated && $import->status !== CrmImportStatus::Applied) {
            throw ValidationException::withMessages([
                'import' => 'Import ist nicht zur Anwendung bereit.',
            ]);
        }

        return DB::transaction(function () use ($import, $actor, $expectedFingerprint, $expectedCatalogFingerprint): CrmImport {
            $import = CrmImport::query()->lockForUpdate()->findOrFail($import->id);

            if ($import->status === CrmImportStatus::Applied) {
                return $import;
            }

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
                $import->save();
                throw new CrmImportConflictException(
                    'Bestand oder Vorschau hat sich geändert. Bitte Vorschau erneut prüfen.'
                );
            }

            if ($preview['stats']['blocking_errors'] > 0) {
                throw ValidationException::withMessages([
                    'import' => 'Import enthält blockierende Fehler und kann nicht angewendet werden.',
                ]);
            }

            $reportRows = [];
            $supplements = ['calculations' => 0, 'dispo_orders' => 0];

            foreach ($preview['actions'] as $action) {
                $result = $this->applyAction($action, $actor, $import->id);
                $reportRows[] = $result;
                if (isset($result['supplement'])) {
                    $supplements['calculations'] += $result['supplement']['calculations'];
                    $supplements['dispo_orders'] += $result['supplement']['dispo_orders'];
                }
            }

            // Auto-Match vorläufiger Accounts gegen gesamten SF-Bestand (nach Upserts).
            $matchReport = $this->autoMatchProvisionals($actor, $import->id);
            foreach ($matchReport as $match) {
                if (($match['mode'] ?? null) !== 'auto' || empty($match['linked_account_id'])) {
                    continue;
                }
                $linked = CrmAccount::query()->with('currentVersion')->find($match['linked_account_id']);
                $meridian = $linked?->currentVersion?->meridian_number;
                if ($linked !== null && $meridian !== null && $meridian !== '') {
                    $extra = $this->meridian->supplementMissing($linked, $meridian, $actor, $import->id);
                    $supplements['calculations'] += $extra['calculations'];
                    $supplements['dispo_orders'] += $extra['dispo_orders'];
                }
            }

            $import->status = CrmImportStatus::Applied;
            $import->applied_at = now();
            $import->report = [
                'actions' => $reportRows,
                'auto_matches' => $matchReport,
                'supplements' => $supplements,
                'applied_at' => now()->toIso8601String(),
            ];
            $import->save();

            $this->audit->record($import, 'crm_import.applied', $actor, null, [
                'action_count' => count($reportRows),
                'auto_matches' => count($matchReport),
                'supplements' => $supplements,
            ]);

            return $import->fresh() ?? $import;
        });
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
            $billingEmail = $emailAnalysis['emails'][0] ?? null;
            if (count($emailAnalysis['emails']) === 1) {
                $billingEmail = $emailAnalysis['emails'][0];
            } elseif (count($emailAnalysis['emails']) > 1 && ! $emailAnalysis['divergent']) {
                $billingEmail = $emailAnalysis['emails'][0];
            } elseif ($emailAnalysis['divergent']) {
                $billingEmail = null;
            }

            $meridian = $row['meridian_raw'] === '' ? null : $row['meridian_raw'];

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
                    // idempotent: nur erste Action behalten
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

        $fingerprint = hash('sha256', json_encode([
            'actions' => $actions,
            'issues' => $issues,
        ], JSON_THROW_ON_ERROR));

        return [
            'actions' => $actions,
            'issues' => $issues,
            'stats' => [
                'valid_rows' => $valid,
                'error_rows' => $errorRows,
                'warning_rows' => $warningRows,
                'blocking_errors' => $blocking,
                'action_count' => count($actions),
            ],
            'fingerprint' => $fingerprint,
            'notes' => [
                'addresses' => 'CSV enthält keine Adressspalten; Adressen werden weder erfunden noch geleert.',
                'format' => 'UTF-8 CSV mit Semikolon; Typen Account KUNDE / Account AGENTUR.',
            ],
        ];
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
            // Domain nicht für Auto-Match nutzen, Account trotzdem anlegen/aktualisieren.
            $payload['matching_domain'] = null;
        }

        $before = CrmAccount::query()
            ->where('salesforce_account_id_canonical', $action['salesforce']['canonical'])
            ->whereNull('merged_into_account_id')
            ->first();

        $beforeMeridian = $before?->currentVersion?->meridian_number;
        $account = $this->accounts->upsertSalesforceAccount(
            $action['salesforce'],
            $type,
            $payload,
            $actor,
            $importId,
        );

        $supplement = ['calculations' => 0, 'dispo_orders' => 0];
        $account->loadMissing('currentVersion');
        $newMeridian = $account->currentVersion?->meridian_number;
        if (($beforeMeridian === null || $beforeMeridian === '')
            && $newMeridian !== null && $newMeridian !== '') {
            $supplement = $this->meridian->supplementMissing($account, $newMeridian, $actor, $importId);
        }

        return [
            'line' => $action['line'],
            'account_id' => $account->id,
            'salesforce_canonical' => $action['salesforce']['canonical'],
            'created' => $before === null,
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
