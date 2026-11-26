<?php

namespace App\Services\MasterDataTransfer\Import;

use App\Enums\CalculationKind;
use App\Enums\CalculationMethodMode;
use App\Enums\ComponentCalculationStrategy;
use App\Enums\FieldAppliesTo;
use App\Enums\FieldScope;
use App\Enums\FieldSetAssignmentTargetLayer;
use App\Enums\FieldSetVersionStatus;
use App\Enums\FieldType;
use App\Enums\InventoryType;
use App\Enums\OaTargetingCategory;
use App\Enums\PriceListStatus;
use App\Enums\ProductionType;
use App\Enums\SpotComponentProfile;
use App\Models\AdvertisingCategory;
use App\Models\AdvertisingCategoryCalculationMethod;
use App\Models\AdvertisingMedium;
use App\Models\AdvertisingMediumCalculationMethod;
use App\Models\CalculationMethod;
use App\Models\FieldDefinition;
use App\Models\FieldDefinitionRevision;
use App\Models\FieldDefinitionRevisionOption;
use App\Models\FieldRule;
use App\Models\FieldSet;
use App\Models\FieldSetAssignment;
use App\Models\FieldSetVersion;
use App\Models\FieldSetVersionField;
use App\Models\Inventory;
use App\Models\InventoryMediumRule;
use App\Models\OaAdserverSurcharge;
use App\Models\OaPlatform;
use App\Models\OaPlatformMediumRule;
use App\Models\OaTargetingDefinition;
use App\Models\OaTkpPriceList;
use App\Models\OaTkpPriceListItem;
use App\Models\Organization;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\ProductionPriceList;
use App\Models\User;
use App\Services\MasterDataTransfer\MasterDataTransferContractV2;
use App\Services\MasterDataTransfer\MasterDataTransferException;
use App\Services\MasterDataTransfer\Support\MasterDataBackupProofV2;
use App\Services\MasterDataTransfer\Support\MasterDataExportDecimal;
use App\Services\MasterDataTransfer\Support\MasterDataImportRecordValidatorV2;
use App\Services\MasterDataTransfer\Support\MasterDataOperationalDatabaseGuardV2;
use App\Services\MasterDataTransfer\Support\MasterDataOperationalDryRunReadGuardV2;
use App\Services\MasterDataTransfer\Support\MasterDataPackage;
use App\Services\MasterDataTransfer\Support\MasterDataPlannedWriteV2;
use App\Services\MasterDataTransfer\Support\MasterDataPriceListPinGuardV2;
use App\Services\MasterDataTransfer\Support\MasterDataProtectedStateSnapshotV2;
use App\Services\MasterDataTransfer\Support\MasterDataWriteWhitelistGuardV2;
use App\Services\MasterDataTransfer\Support\PriceListFingerprint;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use PDO;
use Throwable;

/**
 * EPIC-03-F Slice 5+6a+6b: Inhaltsimport 2.0.0 inkl. Schutz-Snapshots, Pins, Whitelist.
 * Slice 6a: operativer schreibfreier Dry-Run.
 * Slice 6b: operativer Inhalts-Apply (Draft-Preise) mit Backup-Nachweis.
 * Slice 7 (Preisstatus) bleibt gesperrt.
 */
final class MasterDataImporterV2
{
    public function __construct(
        private readonly MasterDataPackage $packages,
        private readonly MasterDataProtectedStateSnapshotV2 $protectedSnapshots = new MasterDataProtectedStateSnapshotV2,
        private readonly MasterDataPriceListPinGuardV2 $priceListPins = new MasterDataPriceListPinGuardV2,
        private readonly MasterDataWriteWhitelistGuardV2 $writeGuard = new MasterDataWriteWhitelistGuardV2,
        private readonly MasterDataOperationalDatabaseGuardV2 $operationalDatabaseGuard = new MasterDataOperationalDatabaseGuardV2,
        private readonly MasterDataOperationalDryRunReadGuardV2 $operationalReadGuard = new MasterDataOperationalDryRunReadGuardV2,
        private readonly MasterDataBackupProofV2 $backupProof = new MasterDataBackupProofV2,
    ) {}

    /**
     * Generischer Import-Einstieg bleibt gesperrt.
     * Operativer Dry-Run: {@see dryRunOperational()}.
     * Operativer Apply: {@see applyOperational()}.
     *
     * @return array<string, mixed>
     */
    public function import(string $packagePath, bool $dryRun, string $confirmDatabase): array
    {
        if ($dryRun) {
            throw new MasterDataTransferException(
                'Operativer V2-Dry-Run nur über dryRunOperational() mit --require-package-sha256 (Slice 6a).',
            );
        }

        throw new MasterDataTransferException(
            'Operativer V2-Apply nur über applyOperational() mit --require-package-sha256 und --require-backup-proof (Slice 6b).',
        );
    }

    /**
     * Slice 6a: operativer, schreibfreier Dry-Run gegen die Staging-Allowlist.
     * Schwächt den Test-Isolations-Guard nicht ab (eigener Einstieg).
     *
     * @return array<string, mixed> MasterDataImportReport::toArray
     */
    public function dryRunOperational(
        string $packagePath,
        string $confirmDatabase,
        string $requirePackageSha256,
    ): array {
        $normalizedRequired = $this->assertValidPackageSha256Option($requirePackageSha256);
        $activeDatabase = $this->operationalDatabaseGuard->assertDryRunAllowed($confirmDatabase);

        $digestBefore = strtolower($this->packages->computePackageSha256($packagePath));
        if (! hash_equals($digestBefore, $normalizedRequired)) {
            throw new MasterDataTransferException(sprintf(
                'Paket-SHA256 stimmt nicht (--require-package-sha256). erwartet=%s berechnet=%s',
                $normalizedRequired,
                $digestBefore,
            ));
        }

        $this->operationalReadGuard->enter();
        try {
            $report = $this->executePhases(
                $packagePath,
                dryRun: true,
                includePhaseB: true,
                includePhaseC: true,
                requireIsolatedTestGuard: false,
                packageSha256: $digestBefore,
                operationalDatabase: $activeDatabase,
            );

            $digestAfter = strtolower($this->packages->computePackageSha256($packagePath));
            if (! hash_equals($digestBefore, $digestAfter)) {
                throw new MasterDataTransferException(
                    'Paket wurde während des Dry-Runs verändert (SHA256-Drift) — Abbruch.',
                    [
                        'before='.$digestBefore,
                        'after='.$digestAfter,
                    ],
                );
            }
            if (! hash_equals($digestAfter, $normalizedRequired)) {
                throw new MasterDataTransferException(
                    'Paket-SHA256 nach Dry-Run weicht von --require-package-sha256 ab — Abbruch.',
                );
            }

            return $report;
        } catch (Throwable $e) {
            throw $e;
        } finally {
            $this->operationalReadGuard->leave();
        }
    }

    /**
     * Slice 6b: operativer Inhalts-Apply gegen die Staging-Allowlist.
     * Preise nur als draft; keine Status-Phase. Backup-Nachweis Pflicht vor Schreibbeginn.
     *
     * @return array<string, mixed> MasterDataImportReport::toArray
     */
    public function applyOperational(
        string $packagePath,
        string $confirmDatabase,
        string $requirePackageSha256,
        string $requireBackupProof,
    ): array {
        $normalizedRequired = $this->assertValidPackageSha256Option($requirePackageSha256);
        $activeDatabase = $this->operationalDatabaseGuard->assertApplyAllowed($confirmDatabase);
        $proof = $this->backupProof->assertValid($requireBackupProof);

        $digestBefore = strtolower($this->packages->computePackageSha256($packagePath));
        if (! hash_equals($digestBefore, $normalizedRequired)) {
            throw new MasterDataTransferException(sprintf(
                'Paket-SHA256 stimmt nicht (--require-package-sha256). erwartet=%s berechnet=%s',
                $normalizedRequired,
                $digestBefore,
            ));
        }

        $report = $this->executePhases(
            $packagePath,
            dryRun: false,
            includePhaseB: true,
            includePhaseC: true,
            requireIsolatedTestGuard: false,
            packageSha256: $digestBefore,
            operationalDatabase: $activeDatabase,
            operationalApply: true,
            backupProofDumpSha256: $proof['dump_sha256'],
        );

        $digestAfter = strtolower($this->packages->computePackageSha256($packagePath));
        if (! hash_equals($digestBefore, $digestAfter)) {
            throw new MasterDataTransferException(
                'Paket wurde während des Applys verändert (SHA256-Drift) — Abbruch.',
                [
                    'before='.$digestBefore,
                    'after='.$digestAfter,
                ],
            );
        }
        if (! hash_equals($digestAfter, $normalizedRequired)) {
            throw new MasterDataTransferException(
                'Paket-SHA256 nach Apply weicht von --require-package-sha256 ab — Abbruch.',
            );
        }

        return $report;
    }

    private function assertValidPackageSha256Option(string $requirePackageSha256): string
    {
        $normalized = strtolower(trim($requirePackageSha256));
        if ($normalized === '' || preg_match('/^[a-f0-9]{64}$/', $normalized) !== 1) {
            throw new MasterDataTransferException(
                '--require-package-sha256 ist Pflicht und muss ein 64-stelliger Hex-SHA256 sein.',
            );
        }

        return $normalized;
    }

    /** @var list<string> */
    private const FORBIDDEN_TEST_DATABASES = [
        'dispo_mat_core',
        'dispo',
        'staging',
        'dispo_staging',
        'production',
        'dispo_production',
        'prod',
    ];

    /**
     * Isolierte Test-/interne Ausführung von Phase A. Nicht für CLI/Staging.
     *
     * @return array<string, mixed> MasterDataImportReport::toArray
     */
    public function executePhaseAForIsolatedTests(string $packagePath, bool $dryRun): array
    {
        return $this->executePhases(
            $packagePath,
            $dryRun,
            includePhaseB: false,
            includePhaseC: false,
            requireIsolatedTestGuard: true,
        );
    }

    /**
     * Isolierte Test-/interne Ausführung von Phase A+B in einer Transaktion.
     * Nicht für CLI/Staging.
     *
     * @return array<string, mixed> MasterDataImportReport::toArray
     */
    public function executePhasesABForIsolatedTests(string $packagePath, bool $dryRun): array
    {
        return $this->executePhases(
            $packagePath,
            $dryRun,
            includePhaseB: true,
            includePhaseC: false,
            requireIsolatedTestGuard: true,
        );
    }

    /**
     * Isolierte Test-/interne Ausführung von Phase A+B+C (Schutz) in einer Transaktion.
     * Nicht für CLI/Staging.
     *
     * @return array<string, mixed> MasterDataImportReport::toArray
     */
    public function executePhasesABCForIsolatedTests(string $packagePath, bool $dryRun): array
    {
        return $this->executePhases(
            $packagePath,
            $dryRun,
            includePhaseB: true,
            includePhaseC: true,
            requireIsolatedTestGuard: true,
        );
    }

    /**
     * @return array<string, mixed> MasterDataImportReport::toArray
     */
    private function executePhases(
        string $packagePath,
        bool $dryRun,
        bool $includePhaseB,
        bool $includePhaseC,
        bool $requireIsolatedTestGuard,
        ?string $packageSha256 = null,
        ?string $operationalDatabase = null,
        bool $operationalApply = false,
        ?string $backupProofDumpSha256 = null,
    ): array {
        if ($requireIsolatedTestGuard) {
            $this->assertIsolatedTestExecutionAllowed();
        } elseif (! $dryRun && ! $operationalApply) {
            throw new MasterDataTransferException(
                'Operativer V2-Pfad ohne Isolations-Guard darf nur schreibfreien Dry-Run oder applyOperational() ausführen.',
            );
        } elseif ($dryRun && $operationalApply) {
            throw new MasterDataTransferException(
                'Interner Konflikt: Dry-Run und operationalApply gleichzeitig gesetzt.',
            );
        }

        $package = $this->packages->load($packagePath);
        if ($package['schema_version'] !== MasterDataTransferContractV2::SCHEMA_VERSION) {
            throw new MasterDataTransferException(sprintf(
                'Import für schema_version=%s ist in diesem Slice nicht freigegeben (erwartet %s).',
                $package['schema_version'],
                MasterDataTransferContractV2::SCHEMA_VERSION,
            ));
        }

        MasterDataImportRecordValidatorV2::validate($package['data']);

        $usersBefore = $this->snapshotUsers();
        $report = new MasterDataImportReport;
        $report->setPackageIntegrity('pass');
        if ($packageSha256 !== null) {
            $report->setPackageSha256($packageSha256);
        }
        if ($operationalDatabase !== null) {
            $report->setOperationalDatabase($operationalDatabase);
        }
        if ($operationalApply) {
            $report->setBackupProof('pass');
            if ($backupProofDumpSha256 !== null) {
                $report->setBackupProofDumpSha256($backupProofDumpSha256);
            }
        }
        $context = new MasterDataImportPlanContext($package['data']);

        $protectionBefore = null;
        $pinsBefore = null;
        if ($includePhaseC) {
            $protectionBefore = $this->protectedSnapshots->capture();
            $pinsBefore = $this->priceListPins->capture();
            $report->setProtectedTableStats($protectionBefore['tables']);
            $report->setPriceListPinStats($pinsBefore['binding_count'], $pinsBefore['list_count']);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $ops = $this->planPhaseA($package['data'], $report, $context);
            if ($includePhaseB) {
                $ops = array_merge($ops, $this->planPhaseB($package['data'], $report, $context));
                $this->assertReferentialIntegrity($package['data'], $report, $context);
                if ($report->hasConflicts()) {
                    $report->setReferentialIntegrity('stop');
                } else {
                    $report->setReferentialIntegrity('pass');
                }
            }

            // Präventiv: tatsächlich geplante Writes gegen Whitelist, unabhängig von Phase C.
            $this->writeGuard->assertPlannedWritesAllowed($ops);

            if ($includePhaseC) {
                $report->setWhitelist('pass');

                foreach ($this->priceListPins->conflictsAgainstPackage($pinsBefore, $package['data']) as $msg) {
                    $report->conflict($msg);
                }
                $protectionAfterPlan = $this->protectedSnapshots->capture();
                $pinsAfterPlan = $this->priceListPins->capture();
                foreach ($this->protectedSnapshots->diff($protectionBefore, $protectionAfterPlan) as $msg) {
                    $report->conflict($msg);
                }
                foreach ($this->priceListPins->diff($pinsBefore, $pinsAfterPlan) as $msg) {
                    $report->conflict($msg);
                }
                if ($report->hasConflicts()) {
                    $report->setProtectionSnapshots('stop');
                    $report->setPriceListPins('stop');
                } else {
                    $report->setProtectionSnapshots('pass');
                    $report->setPriceListPins('pass');
                }
            }

            if ($report->hasConflicts()) {
                throw new MasterDataTransferException('Import fail-closed wegen Konflikten.', $report->conflictMessages());
            }

            if ($this->snapshotUsers() !== $usersBefore) {
                throw new MasterDataTransferException('Benutzerbestand wurde verändert — Abbruch.');
            }

            if ($dryRun) {
                $this->assertDryRunQueryLogIsReadOnly();
                if ($includePhaseC) {
                    $protectionDry = $this->protectedSnapshots->capture();
                    foreach ($this->protectedSnapshots->diff($protectionBefore, $protectionDry) as $msg) {
                        throw new MasterDataTransferException($msg);
                    }
                }

                return $report->toArray(true);
            }
        } finally {
            DB::disableQueryLog();
        }

        return DB::transaction(function () use (
            $ops,
            $report,
            $usersBefore,
            $package,
            $includePhaseB,
            $includePhaseC,
            $protectionBefore,
            $pinsBefore,
        ): array {
            if ($includePhaseC) {
                // Plan darf nicht veraltet sein: Schutzbestand unmittelbar vor Apply erneut prüfen.
                $protectionPreApply = $this->protectedSnapshots->capture();
                $pinsPreApply = $this->priceListPins->capture();
                $drift = array_merge(
                    $this->protectedSnapshots->diff($protectionBefore, $protectionPreApply),
                    $this->priceListPins->diff($pinsBefore, $pinsPreApply),
                );
                if ($drift !== []) {
                    throw new MasterDataTransferException(
                        'Import fail-closed: Schutzbestand zwischen Planung und Apply verändert.',
                        $drift,
                    );
                }
                $this->writeGuard->startMonitoring();
            }

            try {
                $this->apply($ops);

                if ($includePhaseB) {
                    $this->assertPersistedIntegrityAfterApply($package['data'], $report);
                    if ($report->hasConflicts()) {
                        $report->setReferentialIntegrity('stop');
                        throw new MasterDataTransferException(
                            'Import fail-closed wegen Integritätskonflikten nach Apply.',
                            $report->conflictMessages(),
                        );
                    }
                }

                if ($includePhaseC) {
                    $this->writeGuard->assertObservedWritesAllowed();
                    $report->setWhitelist('pass');

                    $protectionAfter = $this->protectedSnapshots->capture();
                    $pinsAfter = $this->priceListPins->capture();
                    $postConflicts = array_merge(
                        $this->protectedSnapshots->diff($protectionBefore, $protectionAfter),
                        $this->priceListPins->diff($pinsBefore, $pinsAfter),
                    );
                    foreach ($postConflicts as $msg) {
                        $report->conflict($msg);
                    }
                    if ($postConflicts !== []) {
                        $report->setProtectionSnapshots('stop');
                        $report->setPriceListPins('stop');
                        throw new MasterDataTransferException(
                            'Import fail-closed wegen Schutz-/Pin-Drift nach Apply.',
                            $report->conflictMessages(),
                        );
                    }
                    $report->setProtectionSnapshots('pass');
                    $report->setPriceListPins('pass');
                    $report->setProtectedTableStats($protectionAfter['tables']);
                    $report->setPriceListPinStats($pinsAfter['binding_count'], $pinsAfter['list_count']);
                }
            } finally {
                if ($includePhaseC) {
                    $this->writeGuard->stopMonitoring();
                }
            }

            if ($this->snapshotUsers() !== $usersBefore) {
                throw new MasterDataTransferException('Benutzerbestand wurde verändert — Abbruch.');
            }

            return $report->toArray(false);
        });
    }

    /**
     * Fail-closed: nur APP_ENV=testing und isolierte Testdatenbanken.
     * Gilt für dry-run und apply gleichermaßen.
     */
    private function assertIsolatedTestExecutionAllowed(): void
    {
        if (! app()->environment('testing')) {
            throw new MasterDataTransferException(
                'Isolierte Testausführung nur in APP_ENV=testing erlaubt (operativer Pfad bleibt gesperrt).',
            );
        }

        $connection = (string) config('database.default');
        $driver = (string) config('database.connections.'.$connection.'.driver');
        $database = config('database.connections.'.$connection.'.database');
        $normalized = is_string($database) || is_numeric($database)
            ? trim((string) $database)
            : '';

        if ($normalized === '') {
            throw new MasterDataTransferException(
                'Isolierte Testausführung abgebrochen: aktive Datenbank ist leer oder unbestimmt.',
            );
        }

        if ($this->isForbiddenTestDatabase($normalized, $driver)) {
            throw new MasterDataTransferException(
                'Isolierte Testausführung gegen geschützte/operative Datenbank gesperrt: '.$normalized,
            );
        }

        if ($driver === 'mysql' && strcasecmp($normalized, 'dispo_test') !== 0) {
            throw new MasterDataTransferException(
                'Isolierte Testausführung unter MySQL nur gegen dispo_test erlaubt (aktiv: '.$normalized.').',
            );
        }
    }

    private function isForbiddenTestDatabase(string $database, string $driver): bool
    {
        foreach (self::FORBIDDEN_TEST_DATABASES as $forbidden) {
            if (strcasecmp($database, $forbidden) === 0) {
                return true;
            }
        }

        if ($database === ':memory:') {
            return false;
        }

        if ($driver === 'sqlite' || str_contains($database, DIRECTORY_SEPARATOR) || str_contains($database, '/')) {
            $filename = pathinfo($database, PATHINFO_FILENAME);
            foreach (self::FORBIDDEN_TEST_DATABASES as $forbidden) {
                if (strcasecmp($filename, $forbidden) === 0) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return list<array{id: int, email: string, role: string, is_active: bool}>
     */
    private function snapshotUsers(): array
    {
        $rows = [];
        foreach (User::query()->orderBy('id')->get(['id', 'email', 'role', 'is_active']) as $u) {
            $rows[] = [
                'id' => (int) $u->id,
                'email' => (string) $u->email,
                'role' => (string) $u->role->value,
                'is_active' => (bool) $u->is_active,
            ];
        }

        return $rows;
    }

    private function assertDryRunQueryLogIsReadOnly(): void
    {
        $writes = collect(DB::getQueryLog())->filter(function (array $entry): bool {
            $sql = strtolower(trim((string) $entry['query']));

            foreach ([
                'insert ',
                'update ',
                'delete ',
                'replace ',
                'alter ',
                'drop ',
                'create ',
                'truncate ',
                'rename ',
                'grant ',
                'revoke ',
                'lock tables',
                'unlock tables',
                'call ',
                'load data',
                'handler ',
                'merge ',
            ] as $needle) {
                if (str_contains($sql, $needle)) {
                    return true;
                }
            }

            return (bool) preg_match('/^(insert|update|delete|replace|alter|drop|create|truncate|rename)\b/', $sql);
        });

        if ($writes->isNotEmpty()) {
            throw new MasterDataTransferException('Dry-Run hat Schreibzugriffe ausgelöst — Abbruch.');
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<MasterDataPlannedWriteV2>
     */
    private function planPhaseA(
        array $data,
        MasterDataImportReport $report,
        MasterDataImportPlanContext $context,
    ): array {
        $ops = [];
        $ops = array_merge($ops, $this->planOrganization($data['data/organization.json'], $report));
        $ops = array_merge($ops, $this->planCalculationMethods($data['data/calculation_methods.json'], $report, $context));
        $ops = array_merge($ops, $this->planCategories($data['data/advertising_categories.json'], $report, $context));
        $ops = array_merge($ops, $this->planMedia($data['data/advertising_media.json'], $report, $context));
        $ops = array_merge($ops, $this->planInventories($data['data/inventories.json'], $report, $context));
        $ops = array_merge($ops, $this->planCategoryMethods($data['data/advertising_category_calculation_methods.json'], $report, $context));
        $ops = array_merge($ops, $this->planMediumMethods($data['data/advertising_medium_calculation_methods.json'], $report, $context));
        $ops = array_merge($ops, $this->planRules($data['data/inventory_medium_rules.json'], $report, $context));
        $ops = array_merge($ops, $this->planFieldDefinitions($data['data/field_definitions.json'], $report, $context));
        $ops = array_merge($ops, $this->planFieldDefinitionRevisions($data['data/field_definition_revisions.json'], $report, $context));
        $ops = array_merge($ops, $this->planFieldDefinitionRevisionOptions($data['data/field_definition_revision_options.json'], $report, $context));
        $ops = array_merge($ops, $this->planFieldSets($data['data/field_sets.json'], $report, $context));
        $ops = array_merge($ops, $this->planFieldSetVersions($data['data/field_set_versions.json'], $report, $context));
        $ops = array_merge($ops, $this->planFieldSetVersionFields($data['data/field_set_version_fields.json'], $report, $context));
        $ops = array_merge($ops, $this->planFieldRules($data['data/field_rules.json'], $report, $context));
        $ops = array_merge($ops, $this->planOaPlatforms($data['data/oa_platforms.json'], $report, $context));
        $ops = array_merge($ops, $this->planOaPlatformMediumRules($data['data/oa_platform_medium_rules.json'], $report, $context));
        $ops = array_merge($ops, $this->planOaTargetingDefinitions($data['data/oa_targeting_definitions.json'], $report, $context));
        $ops = array_merge($ops, $this->planPriceLists($data['data/price_lists.json'], $report, $context));
        $ops = array_merge($ops, $this->planProductionPriceLists($data['data/production_price_lists.json'], $report, $context));
        $ops = array_merge($ops, $this->planOaTkpPriceLists($data['data/oa_tkp_price_lists.json'], $report, $context));
        $ops = array_merge($ops, $this->planOaAdserverSurcharges($data['data/oa_adserver_surcharges.json'], $report, $context));

        return $ops;
    }

    /**
     * @param  list<mixed>  $ops
     */
    private function apply(array $ops): void
    {
        foreach ($ops as $op) {
            if (! $op instanceof MasterDataPlannedWriteV2) {
                throw new MasterDataTransferException(
                    'Whitelist-STOP: ungeprüfte Operation im Apply (kein PlannedWrite).',
                );
            }
            $op->run();
        }
    }

    /**
     * @param  list<string>|string  $tables
     */
    private function plannedWrite(array|string $tables, string $operation, \Closure $execute): MasterDataPlannedWriteV2
    {
        return new MasterDataPlannedWriteV2($operation, $tables, $execute);
    }

    /**
     * @param  array<string, mixed>  $row
     * @return list<MasterDataPlannedWriteV2>
     */
    private function planOrganization(array $row, MasterDataImportReport $report): array
    {
        $name = trim((string) ($row['name'] ?? ''));
        if ($name === '') {
            $report->conflict('organization.name fehlt.');

            return [];
        }

        $orgCount = Organization::query()->count();
        if ($orgCount > 1) {
            $report->conflict('Mehr als eine Organisation im Ziel — Import nicht möglich.');

            return [];
        }

        $existing = Organization::query()->orderBy('id')->first();
        if ($existing === null) {
            $report->add('organization', $name, 'create');

            return [$this->plannedWrite('organizations', 'create', function () use ($name): void {
                Organization::query()->create(['name' => $name]);
            })];
        }

        if ($existing->name !== $name) {
            $report->conflict(sprintf(
                'Organisation abweichend (vorhanden=%s, paket=%s) — keine stille Umbenennung.',
                $existing->name,
                $name,
            ));

            return [];
        }

        $report->add('organization', $name, 'unchanged');

        return [];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<MasterDataPlannedWriteV2>
     */
    private function planCalculationMethods(
        array $rows,
        MasterDataImportReport $report,
        MasterDataImportPlanContext $context,
    ): array {
        $ops = [];
        foreach ($rows as $row) {
            $key = (string) ($row['key'] ?? '');
            if ($key === '') {
                $report->conflict('calculation_method.key fehlt.');

                continue;
            }
            $payload = [
                'name' => (string) $row['name'],
                'help_text' => $row['help_text'] ?? null,
                'is_active' => (bool) $row['is_active'],
                'sort' => (int) $row['sort'],
            ];
            $existing = CalculationMethod::query()->where('key', $key)->first();
            if ($existing === null) {
                $report->add('calculation_method', $key, 'create');
                $context->markMethod($key);
                $ops[] = $this->plannedWrite('calculation_methods', 'create', function () use ($key, $payload): void {
                    $model = new CalculationMethod;
                    $model->forceFill(['key' => $key, ...$payload, 'lock_version' => 1])->save();
                });

                continue;
            }
            $actual = [
                'name' => $existing->name,
                'help_text' => $existing->help_text,
                'is_active' => (bool) $existing->is_active,
                'sort' => (int) $existing->sort,
            ];
            if ($actual !== $payload) {
                $report->conflict('calculation_method abweichend (kein Überschreiben): '.$key);

                continue;
            }
            $report->add('calculation_method', $key, 'unchanged');
        }

        return $ops;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<MasterDataPlannedWriteV2>
     */
    private function planCategories(
        array $rows,
        MasterDataImportReport $report,
        MasterDataImportPlanContext $context,
    ): array {
        $ops = [];
        foreach ($rows as $row) {
            $key = (string) ($row['key'] ?? '');
            if ($key === '') {
                $report->conflict('category.key fehlt.');

                continue;
            }
            $defaultMethodKey = $row['default_calculation_method_key'] ?? null;
            $defaultMethodId = null;
            $packageDefaultKey = is_string($defaultMethodKey) && $defaultMethodKey !== ''
                ? $defaultMethodKey
                : null;
            if ($packageDefaultKey !== null) {
                $defaultMethodId = $this->resolveMethodId($packageDefaultKey, $context, $report);
                if ($defaultMethodId === false) {
                    continue;
                }
            }

            $payload = [
                'name' => (string) $row['name'],
                'is_active' => (bool) $row['is_active'],
                'sort' => (int) $row['sort'],
                'default_calculation_method_id' => $defaultMethodId,
            ];

            $existing = AdvertisingCategory::query()->where('key', $key)->first();
            if ($existing === null) {
                $report->add('advertising_category', $key, 'create');
                $context->markCategory($key);
                $ops[] = $this->plannedWrite('advertising_categories', 'create', function () use ($key, $payload, $packageDefaultKey): void {
                    if ($packageDefaultKey !== null) {
                        $resolvedId = CalculationMethod::query()->where('key', $packageDefaultKey)->value('id');
                        if ($resolvedId === null) {
                            throw new MasterDataTransferException(
                                'Kategorie-Default-Methode fehlt beim Apply: '.$packageDefaultKey,
                            );
                        }
                        $payload['default_calculation_method_id'] = (int) $resolvedId;
                    } else {
                        $payload['default_calculation_method_id'] = null;
                    }
                    $category = new AdvertisingCategory;
                    $category->forceFill(['key' => $key, ...$payload, 'lock_version' => 1])->save();
                });

                continue;
            }

            if ($existing->name !== $payload['name']) {
                $report->conflict('advertising_category Namenskonflikt: '.$key);

                continue;
            }

            $existingDefaultKey = null;
            if ($existing->default_calculation_method_id !== null) {
                $existingDefaultKey = CalculationMethod::query()
                    ->where('id', $existing->default_calculation_method_id)
                    ->value('key');
                $existingDefaultKey = is_string($existingDefaultKey) ? $existingDefaultKey : null;
            }
            $defaultMatches = $packageDefaultKey === null
                ? $existing->default_calculation_method_id === null
                : (
                    $defaultMethodId !== null
                        ? (int) $existing->default_calculation_method_id === (int) $defaultMethodId
                        : $existingDefaultKey === $packageDefaultKey
                );

            $metaMatches = (bool) $existing->is_active === $payload['is_active']
                && (int) $existing->sort === $payload['sort']
                && $defaultMatches;
            if ($metaMatches) {
                $report->add('advertising_category', $key, 'unchanged');

                continue;
            }

            $report->add('advertising_category', $key, 'update', 'erlaubtes Metadaten-Angleichen');
            $ops[] = $this->plannedWrite('advertising_categories', 'update', function () use ($existing, $payload, $packageDefaultKey): void {
                $defaultId = null;
                if ($packageDefaultKey !== null) {
                    $resolvedId = CalculationMethod::query()->where('key', $packageDefaultKey)->value('id');
                    if ($resolvedId === null) {
                        throw new MasterDataTransferException(
                            'Kategorie-Default-Methode fehlt beim Apply: '.$packageDefaultKey,
                        );
                    }
                    $defaultId = (int) $resolvedId;
                }
                $existing->forceFill([
                    'is_active' => $payload['is_active'],
                    'sort' => $payload['sort'],
                    'default_calculation_method_id' => $defaultId,
                ])->save();
            });
        }

        return $ops;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<MasterDataPlannedWriteV2>
     */
    private function planInventories(
        array $rows,
        MasterDataImportReport $report,
        MasterDataImportPlanContext $context,
    ): array {
        $ops = [];

        foreach ($rows as $row) {
            $code = (string) ($row['code'] ?? '');
            if ($code === '') {
                $report->conflict('inventory.code fehlt.');

                continue;
            }
            $type = InventoryType::tryFrom((string) ($row['type'] ?? ''));
            if ($type === null) {
                $report->conflict('inventory.type ungültig: '.$code);

                continue;
            }
            $payload = [
                'name' => (string) $row['name'],
                'type' => $type,
                'is_active' => (bool) $row['is_active'],
                'sort' => (int) $row['sort'],
                'logo_path' => $row['logo_path'] ?? null,
            ];
            $existing = Inventory::query()->where('code', $code)->first();
            if ($existing === null) {
                $report->add('inventory', $code, 'create');
                $context->markInventory($code);
                $ops[] = $this->plannedWrite('inventories', 'create', function () use ($code, $payload): void {
                    $organization = Organization::query()->orderBy('id')->first();
                    if ($organization === null) {
                        throw new MasterDataTransferException('Organisation fehlt vor Inventar-Import.');
                    }
                    $inventory = new Inventory;
                    $inventory->forceFill([
                        'organization_id' => $organization->id,
                        'code' => $code,
                        ...$payload,
                        'lock_version' => 1,
                    ])->save();
                });

                continue;
            }
            $actual = [
                'name' => $existing->name,
                'type' => $existing->type,
                'is_active' => (bool) $existing->is_active,
                'sort' => (int) $existing->sort,
                'logo_path' => $existing->logo_path,
            ];
            if ($actual !== $payload) {
                $report->conflict('inventory Konflikt: '.$code);

                continue;
            }
            $report->add('inventory', $code, 'unchanged');
        }

        return $ops;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<MasterDataPlannedWriteV2>
     */
    private function planMedia(
        array $rows,
        MasterDataImportReport $report,
        MasterDataImportPlanContext $context,
    ): array {
        $ops = [];
        foreach ($rows as $row) {
            $code = (string) ($row['code'] ?? '');
            $categoryKey = (string) ($row['category_key'] ?? '');
            if ($code === '' || $categoryKey === '') {
                $report->conflict('advertising_medium code/category_key fehlt.');

                continue;
            }
            if (! $this->categoryResolvable($categoryKey, $context, $report)) {
                continue;
            }
            $category = AdvertisingCategory::query()->where('key', $categoryKey)->first();
            $kind = ($row['kind'] ?? null) === null || $row['kind'] === ''
                ? null
                : CalculationKind::tryFrom((string) $row['kind']);
            if (($row['kind'] ?? null) !== null && $row['kind'] !== '' && $kind === null) {
                $report->conflict('kind ungültig: '.$code);

                continue;
            }
            $mode = CalculationMethodMode::tryFrom((string) ($row['calculation_method_mode'] ?? 'inherit'));
            if ($mode === null) {
                $report->conflict('calculation_method_mode ungültig: '.$code);

                continue;
            }
            $profile = ($row['component_profile'] ?? null) === null || $row['component_profile'] === ''
                ? null
                : SpotComponentProfile::tryFrom((string) $row['component_profile']);
            if (($row['component_profile'] ?? null) !== null && $row['component_profile'] !== '' && $profile === null) {
                $report->conflict('component_profile ungültig: '.$code);

                continue;
            }
            $defaultMethodKey = $row['default_calculation_method_key'] ?? null;
            $defaultMethodId = null;
            if (is_string($defaultMethodKey) && $defaultMethodKey !== '') {
                $resolved = $this->resolveMethodId($defaultMethodKey, $context, $report);
                if ($resolved === false) {
                    continue;
                }
                $defaultMethodId = $resolved;
            }

            $existing = AdvertisingMedium::query()->where('code', $code)->first();
            if ($existing === null) {
                $report->add('advertising_medium', $code, 'create');
                $context->markMedium($code);
                $ops[] = $this->plannedWrite('advertising_media', 'create', function () use ($code, $categoryKey, $kind, $profile, $mode, $defaultMethodKey, $row): void {
                    $category = AdvertisingCategory::query()->where('key', $categoryKey)->first();
                    if ($category === null) {
                        throw new MasterDataTransferException('Kategorie für Medium fehlt: '.$categoryKey);
                    }
                    $defaultMethodId = null;
                    if (is_string($defaultMethodKey) && $defaultMethodKey !== '') {
                        $defaultMethodId = CalculationMethod::query()->where('key', $defaultMethodKey)->value('id');
                    }
                    $medium = new AdvertisingMedium;
                    $medium->forceFill([
                        'code' => $code,
                        'category_id' => $category->id,
                        'name' => (string) $row['name'],
                        'kind' => $kind,
                        'component_profile' => $profile,
                        'calculation_method_mode' => $mode,
                        'default_calculation_method_id' => $defaultMethodId,
                        'default_length_seconds' => (int) $row['default_length_seconds'],
                        'is_discountable' => (bool) $row['is_discountable'],
                        'is_ae_eligible' => (bool) $row['is_ae_eligible'],
                        'is_active' => (bool) $row['is_active'],
                        'sort' => (int) $row['sort'],
                        'lock_version' => 1,
                    ])->save();
                });

                continue;
            }

            if ($category === null) {
                $report->conflict('Kategorie für Medium fehlt: '.$categoryKey);

                continue;
            }

            $payload = [
                'category_id' => $category->id,
                'name' => (string) $row['name'],
                'kind' => $kind,
                'component_profile' => $profile,
                'calculation_method_mode' => $mode,
                'default_calculation_method_id' => $defaultMethodId,
                'default_length_seconds' => (int) $row['default_length_seconds'],
                'is_discountable' => (bool) $row['is_discountable'],
                'is_ae_eligible' => (bool) $row['is_ae_eligible'],
                'is_active' => (bool) $row['is_active'],
                'sort' => (int) $row['sort'],
            ];

            $same = $existing->name === $payload['name']
                && $existing->category_id === $payload['category_id']
                && $existing->kind === $payload['kind']
                && $existing->component_profile === $payload['component_profile']
                && $existing->calculation_method_mode === $payload['calculation_method_mode']
                && $existing->default_calculation_method_id === $payload['default_calculation_method_id']
                && (int) $existing->default_length_seconds === $payload['default_length_seconds']
                && (bool) $existing->is_discountable === $payload['is_discountable']
                && (bool) $existing->is_ae_eligible === $payload['is_ae_eligible']
                && (bool) $existing->is_active === $payload['is_active']
                && (int) $existing->sort === $payload['sort'];

            if (! $same) {
                $report->conflict('advertising_medium Konflikt: '.$code);

                continue;
            }
            $report->add('advertising_medium', $code, 'unchanged');
        }

        return $ops;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<MasterDataPlannedWriteV2>
     */
    private function planCategoryMethods(
        array $rows,
        MasterDataImportReport $report,
        MasterDataImportPlanContext $context,
    ): array {
        $ops = [];
        foreach ($rows as $row) {
            $categoryKey = (string) ($row['category_key'] ?? '');
            $methodKey = (string) ($row['method_key'] ?? '');
            if ($categoryKey === '' || $methodKey === '') {
                $report->conflict('category_method category_key/method_key fehlt.');

                continue;
            }
            if (! $this->categoryResolvable($categoryKey, $context, $report)) {
                continue;
            }
            if ($this->resolveMethodId($methodKey, $context, $report) === false) {
                continue;
            }
            $engine = $row['engine_profile_key'] ?? null;
            $engineNorm = $engine === null || $engine === '' ? null : (string) $engine;
            $key = $categoryKey.'|'.$methodKey;
            $payload = [
                'engine_profile_key' => $engineNorm,
                'is_active' => (bool) $row['is_active'],
                'sort' => (int) $row['sort'],
            ];

            $category = AdvertisingCategory::query()->where('key', $categoryKey)->first();
            $methodId = CalculationMethod::query()->where('key', $methodKey)->value('id');

            if ($category === null || $methodId === null) {
                $report->add('category_method', $key, 'create');
                $ops[] = $this->plannedWrite('advertising_category_calculation_methods', 'create', function () use ($categoryKey, $methodKey, $payload): void {
                    $category = AdvertisingCategory::query()->where('key', $categoryKey)->first();
                    $method = CalculationMethod::query()->where('key', $methodKey)->first();
                    if ($category === null || $method === null) {
                        throw new MasterDataTransferException('category_method Referenz fehlt: '.$categoryKey.'/'.$methodKey);
                    }
                    $model = new AdvertisingCategoryCalculationMethod;
                    $model->forceFill([
                        'advertising_category_id' => $category->id,
                        'calculation_method_id' => $method->id,
                        ...$payload,
                        'lock_version' => 1,
                    ])->save();
                });

                continue;
            }

            $existing = AdvertisingCategoryCalculationMethod::query()
                ->where('advertising_category_id', $category->id)
                ->where('calculation_method_id', $methodId)
                ->first();

            if ($existing === null) {
                $report->add('category_method', $key, 'create');
                $ops[] = $this->plannedWrite('advertising_category_calculation_methods', 'create', function () use ($category, $methodKey, $payload): void {
                    $method = CalculationMethod::query()->where('key', $methodKey)->first();
                    if ($method === null) {
                        throw new MasterDataTransferException('method_key fehlt: '.$methodKey);
                    }
                    $model = new AdvertisingCategoryCalculationMethod;
                    $model->forceFill([
                        'advertising_category_id' => $category->id,
                        'calculation_method_id' => $method->id,
                        ...$payload,
                        'lock_version' => 1,
                    ])->save();
                });

                continue;
            }

            $actual = [
                'engine_profile_key' => $existing->engine_profile_key === null ? null : (string) $existing->engine_profile_key,
                'is_active' => (bool) $existing->is_active,
                'sort' => (int) $existing->sort,
            ];
            if ($actual !== $payload) {
                $report->conflict('category_method Konflikt: '.$key);

                continue;
            }
            $report->add('category_method', $key, 'unchanged');
        }

        return $ops;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<MasterDataPlannedWriteV2>
     */
    private function planMediumMethods(
        array $rows,
        MasterDataImportReport $report,
        MasterDataImportPlanContext $context,
    ): array {
        $ops = [];
        foreach ($rows as $row) {
            $mediumCode = (string) ($row['medium_code'] ?? '');
            $methodKey = (string) ($row['method_key'] ?? '');
            if ($mediumCode === '' || $methodKey === '') {
                $report->conflict('medium_method medium_code/method_key fehlt.');

                continue;
            }
            if (! $this->mediumResolvable($mediumCode, $context, $report)) {
                continue;
            }
            if ($this->resolveMethodId($methodKey, $context, $report) === false) {
                continue;
            }
            $engine = $row['engine_profile_key'] ?? null;
            $engineNorm = $engine === null || $engine === '' ? null : (string) $engine;
            $key = $mediumCode.'|'.$methodKey;
            $payload = [
                'engine_profile_key' => $engineNorm,
                'is_active' => (bool) $row['is_active'],
                'sort' => (int) $row['sort'],
            ];

            $medium = AdvertisingMedium::query()->where('code', $mediumCode)->first();
            $methodId = CalculationMethod::query()->where('key', $methodKey)->value('id');

            if ($medium === null || $methodId === null) {
                $report->add('medium_method', $key, 'create');
                $ops[] = $this->plannedWrite('advertising_medium_calculation_methods', 'create', function () use ($mediumCode, $methodKey, $payload): void {
                    $medium = AdvertisingMedium::query()->where('code', $mediumCode)->first();
                    $method = CalculationMethod::query()->where('key', $methodKey)->first();
                    if ($medium === null || $method === null) {
                        throw new MasterDataTransferException('medium_method Referenz fehlt: '.$mediumCode.'/'.$methodKey);
                    }
                    $model = new AdvertisingMediumCalculationMethod;
                    $model->forceFill([
                        'advertising_medium_id' => $medium->id,
                        'calculation_method_id' => $method->id,
                        ...$payload,
                        'lock_version' => 1,
                    ])->save();
                });

                continue;
            }

            $existing = AdvertisingMediumCalculationMethod::query()
                ->where('advertising_medium_id', $medium->id)
                ->where('calculation_method_id', $methodId)
                ->first();

            if ($existing === null) {
                $report->add('medium_method', $key, 'create');
                $ops[] = $this->plannedWrite('advertising_medium_calculation_methods', 'create', function () use ($medium, $methodKey, $payload): void {
                    $method = CalculationMethod::query()->where('key', $methodKey)->first();
                    if ($method === null) {
                        throw new MasterDataTransferException('method_key fehlt: '.$methodKey);
                    }
                    $model = new AdvertisingMediumCalculationMethod;
                    $model->forceFill([
                        'advertising_medium_id' => $medium->id,
                        'calculation_method_id' => $method->id,
                        ...$payload,
                        'lock_version' => 1,
                    ])->save();
                });

                continue;
            }

            $actual = [
                'engine_profile_key' => $existing->engine_profile_key === null ? null : (string) $existing->engine_profile_key,
                'is_active' => (bool) $existing->is_active,
                'sort' => (int) $existing->sort,
            ];
            if ($actual !== $payload) {
                $report->conflict('medium_method Konflikt: '.$key);

                continue;
            }
            $report->add('medium_method', $key, 'unchanged');
        }

        return $ops;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<MasterDataPlannedWriteV2>
     */
    private function planRules(
        array $rows,
        MasterDataImportReport $report,
        MasterDataImportPlanContext $context,
    ): array {
        $ops = [];
        foreach ($rows as $row) {
            $inventoryCode = (string) ($row['inventory_code'] ?? '');
            $mediumCode = (string) ($row['medium_code'] ?? '');
            if ($inventoryCode === '' || $mediumCode === '') {
                $report->conflict('inventory_medium_rule inventory_code/medium_code fehlt.');

                continue;
            }
            if (! $this->inventoryResolvable($inventoryCode, $context, $report)) {
                continue;
            }
            if (! $this->mediumResolvable($mediumCode, $context, $report)) {
                continue;
            }
            $strategy = ComponentCalculationStrategy::tryFrom((string) ($row['component_calculation_strategy'] ?? ''));
            if ($strategy === null) {
                $report->conflict('component_calculation_strategy ungültig: '.$inventoryCode.'/'.$mediumCode);

                continue;
            }
            $key = $inventoryCode.'|'.$mediumCode;
            $payload = [
                'is_active' => (bool) $row['is_active'],
                'booking_code' => $row['booking_code'] ?? null,
                'planning_responsibility_key' => $row['planning_responsibility_key'] ?? null,
                'hint_text' => $row['hint_text'] ?? null,
                'sort' => (int) ($row['sort'] ?? 0),
                'default_length_seconds' => $row['default_length_seconds'] === null ? null : (int) $row['default_length_seconds'],
                'surcharge_percent' => MasterDataExportDecimal::canonicalizeNullable(
                    $row['surcharge_percent'] ?? null,
                    MasterDataExportDecimal::PRICE_SCALE,
                ),
                'is_discountable' => (bool) $row['is_discountable'],
                'is_ae_eligible' => (bool) $row['is_ae_eligible'],
                'component_calculation_strategy' => $strategy,
                'swf_allonge_confirmed_at' => $row['swf_allonge_confirmed_at'] ?? null,
            ];

            $inventory = Inventory::query()->where('code', $inventoryCode)->first();
            $medium = AdvertisingMedium::query()->where('code', $mediumCode)->first();

            if ($inventory === null || $medium === null) {
                $report->add('inventory_medium_rule', $key, 'create');
                $ops[] = $this->plannedWrite('inventory_medium_rules', 'create', function () use ($inventoryCode, $mediumCode, $payload): void {
                    $inventory = Inventory::query()->where('code', $inventoryCode)->first();
                    $medium = AdvertisingMedium::query()->where('code', $mediumCode)->first();
                    if ($inventory === null || $medium === null) {
                        throw new MasterDataTransferException(sprintf('rule Referenz fehlt: %s/%s', $inventoryCode, $mediumCode));
                    }
                    $rule = new InventoryMediumRule;
                    $rule->forceFill([
                        'inventory_id' => $inventory->id,
                        'advertising_medium_id' => $medium->id,
                        ...$payload,
                        'lock_version' => 1,
                    ])->save();
                });

                continue;
            }

            $existing = InventoryMediumRule::query()
                ->where('inventory_id', $inventory->id)
                ->where('advertising_medium_id', $medium->id)
                ->first();
            if ($existing === null) {
                $report->add('inventory_medium_rule', $key, 'create');
                $ops[] = $this->plannedWrite('inventory_medium_rules', 'create', function () use ($inventory, $medium, $payload): void {
                    $rule = new InventoryMediumRule;
                    $rule->forceFill([
                        'inventory_id' => $inventory->id,
                        'advertising_medium_id' => $medium->id,
                        ...$payload,
                        'lock_version' => 1,
                    ])->save();
                });

                continue;
            }

            $existingSurcharge = MasterDataExportDecimal::canonicalizeNullable(
                $this->rawDecimalAttribute($existing, 'surcharge_percent'),
                MasterDataExportDecimal::PRICE_SCALE,
            );
            $existingConfirmed = $existing->swf_allonge_confirmed_at?->utc()->toIso8601String();
            $same = (bool) $existing->is_active === $payload['is_active']
                && (string) ($existing->booking_code ?? '') === (string) ($payload['booking_code'] ?? '')
                && (string) ($existing->planning_responsibility_key ?? '') === (string) ($payload['planning_responsibility_key'] ?? '')
                && (string) ($existing->hint_text ?? '') === (string) ($payload['hint_text'] ?? '')
                && (int) $existing->sort === $payload['sort']
                && $existing->default_length_seconds === $payload['default_length_seconds']
                && $existingSurcharge === $payload['surcharge_percent']
                && (bool) $existing->is_discountable === $payload['is_discountable']
                && (bool) $existing->is_ae_eligible === $payload['is_ae_eligible']
                && $existing->component_calculation_strategy === $payload['component_calculation_strategy']
                && $existingConfirmed === $payload['swf_allonge_confirmed_at'];

            if (! $same) {
                $report->conflict('inventory_medium_rule Konflikt: '.$key);

                continue;
            }
            $report->add('inventory_medium_rule', $key, 'unchanged');
        }

        return $ops;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<MasterDataPlannedWriteV2>
     */
    private function planPriceLists(
        array $rows,
        MasterDataImportReport $report,
        MasterDataImportPlanContext $context,
    ): array {
        $ops = [];
        foreach ($rows as $row) {
            $inventoryCode = (string) ($row['inventory_code'] ?? '');
            $year = (int) ($row['year'] ?? 0);
            $revision = (int) ($row['revision_number'] ?? 0);
            $version = (string) ($row['version'] ?? '');
            if (! $this->inventoryResolvable($inventoryCode, $context, $report)) {
                continue;
            }
            /** @var list<array{hour: int, day_group: string, second_price: string}> $incoming */
            $incoming = array_values(array_map(fn (array $i): array => [
                'hour' => (int) $i['hour'],
                'day_group' => (string) $i['day_group'],
                'second_price' => (string) $i['second_price'],
            ], is_array($row['items'] ?? null) ? $row['items'] : []));
            $fingerprint = PriceListFingerprint::forSpotItems($incoming);
            $meta = PriceListFingerprint::spotMetadataTuple(
                (string) $row['name'],
                $version,
                $row['valid_from'] ?? null,
            );
            $key = sprintf('%s|%d|r%d', $inventoryCode, $year, $revision);

            $inventory = Inventory::query()->where('code', $inventoryCode)->first();
            if ($inventory !== null) {
                $sameRevision = PriceList::query()
                    ->with(['items' => fn ($q) => $q->orderBy('day_group')->orderBy('hour')])
                    ->where('inventory_id', $inventory->id)
                    ->where('year', $year)
                    ->where('revision_number', $revision)
                    ->first();

                if ($sameRevision !== null) {
                    /** @var list<array{hour: int, day_group: string, second_price: string}> $existingItems */
                    $existingItems = array_values($sameRevision->items->map(fn (PriceListItem $i) => [
                        'hour' => (int) $i->hour,
                        'day_group' => $i->day_group->value,
                        'second_price' => MasterDataExportDecimal::canonicalize(
                            $this->rawDecimalAttribute($i, 'second_price'),
                            MasterDataExportDecimal::PRICE_SCALE,
                        ),
                    ])->all());
                    $existingFp = PriceListFingerprint::forSpotItems($existingItems);
                    $existingMeta = PriceListFingerprint::spotMetadataTuple(
                        $sameRevision->name,
                        (string) $sameRevision->version,
                        $sameRevision->valid_from?->toDateString(),
                    );
                    if ($existingFp === $fingerprint && PriceListFingerprint::spotMetadataMatches($meta, $existingMeta)) {
                        $report->add('price_list', $key, 'unchanged');

                        continue;
                    }
                    $report->conflict('price_list Konflikt (Schlüssel/Inhalt/Metadaten): '.$key);

                    continue;
                }

                $versionCollision = PriceList::query()
                    ->where('inventory_id', $inventory->id)
                    ->where('year', $year)
                    ->where('version', $version)
                    ->where('revision_number', '!=', $revision)
                    ->exists();
                if ($versionCollision) {
                    $report->conflict(sprintf(
                        'price_list Version-Kollision (inventory/year/version): %s|%d|%s',
                        $inventoryCode,
                        $year,
                        $version,
                    ));

                    continue;
                }
            }

            $report->add('price_list', $key, 'create', 'status=draft');
            $name = (string) $row['name'];
            $validFrom = $row['valid_from'] ?? null;
            $ops[] = $this->plannedWrite(['price_lists', 'price_list_items'], 'create', function () use ($inventoryCode, $year, $revision, $version, $name, $validFrom, $incoming, $key): void {
                $inventory = Inventory::query()->where('code', $inventoryCode)->first();
                if ($inventory === null) {
                    throw new MasterDataTransferException('price_list Inventar fehlt: '.$inventoryCode);
                }
                $exists = PriceList::query()
                    ->where('inventory_id', $inventory->id)
                    ->where('year', $year)
                    ->where('revision_number', $revision)
                    ->exists();
                if ($exists) {
                    throw new MasterDataTransferException('price_list existiert bereits: '.$key);
                }
                $versionCollision = PriceList::query()
                    ->where('inventory_id', $inventory->id)
                    ->where('year', $year)
                    ->where('version', $version)
                    ->exists();
                if ($versionCollision) {
                    throw new MasterDataTransferException(
                        'price_list Version-Kollision beim Apply: '.$inventoryCode.'|'.$year.'|'.$version,
                    );
                }
                $list = new PriceList;
                $list->forceFill([
                    'inventory_id' => $inventory->id,
                    'name' => $name,
                    'year' => $year,
                    'version' => $version,
                    'revision_number' => $revision,
                    'status' => PriceListStatus::Draft,
                    'valid_from' => $validFrom,
                    'lock_version' => 1,
                    'published_at' => null,
                    'archived_at' => null,
                ])->save();
                foreach ($incoming as $item) {
                    PriceListItem::query()->create([
                        'price_list_id' => $list->id,
                        'hour' => $item['hour'],
                        'day_group' => $item['day_group'],
                        'second_price' => $item['second_price'],
                    ]);
                }
            });
        }

        return $ops;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<MasterDataPlannedWriteV2>
     */
    private function planProductionPriceLists(
        array $rows,
        MasterDataImportReport $report,
        MasterDataImportPlanContext $context,
    ): array {
        $ops = [];
        foreach ($rows as $row) {
            $inventoryCode = (string) ($row['inventory_code'] ?? '');
            $year = (int) ($row['year'] ?? 0);
            $revision = (int) ($row['revision_number'] ?? 0);
            $type = ProductionType::tryFrom((string) ($row['production_type'] ?? ''));
            if ($type === null) {
                $report->conflict('production_price_list production_type ungültig: '.$inventoryCode);

                continue;
            }
            if (! $this->inventoryResolvable($inventoryCode, $context, $report)) {
                continue;
            }

            $unitPrice = MasterDataExportDecimal::canonicalize(
                $row['unit_price'] ?? null,
                MasterDataExportDecimal::MONEY_SCALE,
            );
            $isDiscountable = (bool) $row['is_discountable'];
            $isAeEligible = (bool) $row['is_ae_eligible'];
            $fingerprint = PriceListFingerprint::forProduction($unitPrice, $isDiscountable, $isAeEligible);
            $version = (string) $row['version'];
            $meta = PriceListFingerprint::productionMetadataTuple((string) $row['name'], $version);
            $key = sprintf('%s|%s|%d|r%d', $inventoryCode, $type->value, $year, $revision);

            $inventory = Inventory::query()->where('code', $inventoryCode)->first();
            if ($inventory !== null) {
                $sameRevision = ProductionPriceList::query()
                    ->where('inventory_id', $inventory->id)
                    ->where('production_type', $type)
                    ->where('year', $year)
                    ->where('revision_number', $revision)
                    ->first();

                if ($sameRevision !== null) {
                    $existingFp = PriceListFingerprint::forProduction(
                        MasterDataExportDecimal::canonicalize(
                            $this->rawDecimalAttribute($sameRevision, 'unit_price'),
                            MasterDataExportDecimal::MONEY_SCALE,
                        ),
                        (bool) $sameRevision->is_discountable,
                        (bool) $sameRevision->is_ae_eligible,
                    );
                    $existingMeta = PriceListFingerprint::productionMetadataTuple(
                        $sameRevision->name,
                        (string) $sameRevision->version,
                    );
                    if ($existingFp === $fingerprint && PriceListFingerprint::productionMetadataMatches($meta, $existingMeta)) {
                        $report->add('production_price_list', $key, 'unchanged');

                        continue;
                    }
                    $report->conflict('production_price_list Konflikt: '.$key);

                    continue;
                }
            }

            $report->add('production_price_list', $key, 'create', 'status=draft');
            $name = (string) $row['name'];
            $ops[] = $this->plannedWrite('production_price_lists', 'create', function () use ($inventoryCode, $type, $year, $revision, $version, $name, $unitPrice, $isDiscountable, $isAeEligible, $key): void {
                $inventory = Inventory::query()->where('code', $inventoryCode)->first();
                if ($inventory === null) {
                    throw new MasterDataTransferException('production_price_list Inventar fehlt: '.$inventoryCode);
                }
                if (ProductionPriceList::query()
                    ->where('inventory_id', $inventory->id)
                    ->where('production_type', $type)
                    ->where('year', $year)
                    ->where('revision_number', $revision)
                    ->exists()) {
                    throw new MasterDataTransferException('production_price_list existiert bereits: '.$key);
                }
                $list = new ProductionPriceList;
                $list->forceFill([
                    'inventory_id' => $inventory->id,
                    'production_type' => $type,
                    'year' => $year,
                    'name' => $name,
                    'version' => $version,
                    'revision_number' => $revision,
                    'status' => PriceListStatus::Draft,
                    'unit_price' => $unitPrice,
                    'is_discountable' => $isDiscountable,
                    'is_ae_eligible' => $isAeEligible,
                    'lock_version' => 1,
                    'published_at' => null,
                    'archived_at' => null,
                ])->save();
            });
        }

        return $ops;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<MasterDataPlannedWriteV2>
     */
    private function planFieldDefinitions(
        array $rows,
        MasterDataImportReport $report,
        MasterDataImportPlanContext $context,
    ): array {
        $ops = [];
        foreach ($rows as $row) {
            $key = (string) ($row['key'] ?? '');
            if ($key === '') {
                $report->conflict('field_definition.key fehlt.');

                continue;
            }
            $fieldType = FieldType::tryFrom((string) ($row['field_type'] ?? ''));
            $scope = FieldScope::tryFrom((string) ($row['scope'] ?? ''));
            $appliesTo = FieldAppliesTo::tryFrom((string) ($row['applies_to'] ?? ''));
            if ($fieldType === null || $scope === null || $appliesTo === null) {
                $report->conflict('field_definition Enum ungültig: '.$key);

                continue;
            }
            $payload = [
                'field_type' => $fieldType,
                'is_system' => (bool) $row['is_system'],
                'is_key_protected' => (bool) $row['is_key_protected'],
                'scope' => $scope,
                'applies_to' => $appliesTo,
                'is_active' => (bool) $row['is_active'],
            ];
            $existing = FieldDefinition::query()->where('key', $key)->first();
            if ($existing === null) {
                $report->add('field_definition', $key, 'create');
                $context->markFieldDefinition($key);
                $ops[] = $this->plannedWrite('field_definitions', 'create', function () use ($key, $payload): void {
                    $model = new FieldDefinition;
                    $model->forceFill([
                        'key' => $key,
                        ...$payload,
                        'current_revision_id' => null,
                        'lock_version' => 1,
                    ])->save();
                });

                continue;
            }
            $actual = [
                'field_type' => $existing->field_type,
                'is_system' => (bool) $existing->is_system,
                'is_key_protected' => (bool) $existing->is_key_protected,
                'scope' => $existing->scope,
                'applies_to' => $existing->applies_to,
                'is_active' => (bool) $existing->is_active,
            ];
            if ($actual !== $payload) {
                $report->conflict('field_definition Konflikt: '.$key);

                continue;
            }
            $report->add('field_definition', $key, 'unchanged');
        }

        return $ops;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<MasterDataPlannedWriteV2>
     */
    private function planFieldDefinitionRevisions(
        array $rows,
        MasterDataImportReport $report,
        MasterDataImportPlanContext $context,
    ): array {
        $ops = [];
        foreach ($rows as $row) {
            $defKey = (string) ($row['field_definition_key'] ?? '');
            $revision = (int) ($row['revision'] ?? 0);
            if ($defKey === '' || $revision < 1) {
                $report->conflict('field_definition_revision Schlüssel unvollständig.');

                continue;
            }
            if (! $this->fieldDefinitionResolvable($defKey, $context, $report)) {
                continue;
            }
            $key = $defKey.'|'.$revision;
            $payload = [
                'label' => (string) $row['label'],
                'help_text' => $row['help_text'] ?? null,
                'validation_json' => $row['validation_json'] ?? null,
                'group_key' => $row['group_key'] ?? null,
                'sort_default' => (int) $row['sort_default'],
                'reportable' => (bool) $row['reportable'],
            ];
            $definition = FieldDefinition::query()->where('key', $defKey)->first();
            if ($definition === null) {
                $report->add('field_definition_revision', $key, 'create');
                $context->markFieldDefinitionRevision($defKey, $revision);
                $ops[] = $this->plannedWrite('field_definition_revisions', 'create', function () use ($defKey, $revision, $payload): void {
                    $definition = FieldDefinition::query()->where('key', $defKey)->firstOrFail();
                    $model = new FieldDefinitionRevision;
                    $model->forceFill([
                        'field_definition_id' => $definition->id,
                        'revision' => $revision,
                        ...$payload,
                    ])->save();
                });

                continue;
            }
            $existing = FieldDefinitionRevision::query()
                ->where('field_definition_id', $definition->id)
                ->where('revision', $revision)
                ->first();
            if ($existing === null) {
                $report->add('field_definition_revision', $key, 'create');
                $context->markFieldDefinitionRevision($defKey, $revision);
                $ops[] = $this->plannedWrite('field_definition_revisions', 'create', function () use ($definition, $revision, $payload): void {
                    $model = new FieldDefinitionRevision;
                    $model->forceFill([
                        'field_definition_id' => $definition->id,
                        'revision' => $revision,
                        ...$payload,
                    ])->save();
                });

                continue;
            }
            $actual = [
                'label' => $existing->label,
                'help_text' => $existing->help_text,
                'validation_json' => $existing->validation_json,
                'group_key' => $existing->group_key,
                'sort_default' => (int) $existing->sort_default,
                'reportable' => (bool) $existing->reportable,
            ];
            if ($this->canonicalJson($actual['validation_json']) !== $this->canonicalJson($payload['validation_json'])
                || $actual['label'] !== $payload['label']
                || $actual['help_text'] !== $payload['help_text']
                || $actual['group_key'] !== $payload['group_key']
                || $actual['sort_default'] !== $payload['sort_default']
                || $actual['reportable'] !== $payload['reportable']) {
                $report->conflict('field_definition_revision Konflikt: '.$key);

                continue;
            }
            $report->add('field_definition_revision', $key, 'unchanged');
        }

        return $ops;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<MasterDataPlannedWriteV2>
     */
    private function planFieldDefinitionRevisionOptions(
        array $rows,
        MasterDataImportReport $report,
        MasterDataImportPlanContext $context,
    ): array {
        $ops = [];
        foreach ($rows as $row) {
            $defKey = (string) ($row['field_definition_key'] ?? '');
            $revision = (int) ($row['revision'] ?? 0);
            $optKey = (string) ($row['key'] ?? '');
            if ($defKey === '' || $revision < 1 || $optKey === '') {
                $report->conflict('field_definition_revision_option unvollständig.');

                continue;
            }
            if (! $this->fieldDefinitionRevisionResolvable($defKey, $revision, $context, $report)) {
                continue;
            }
            $biz = $defKey.'|'.$revision.'|'.$optKey;
            $payload = [
                'label' => (string) $row['label'],
                'sort' => (int) $row['sort'],
                'is_active' => (bool) $row['is_active'],
            ];
            $revisionModel = $this->findFieldDefinitionRevision($defKey, $revision);
            if ($revisionModel === null) {
                $report->add('field_definition_revision_option', $biz, 'create');
                $ops[] = $this->plannedWrite('field_definition_revision_options', 'create', function () use ($defKey, $revision, $optKey, $payload): void {
                    $revisionModel = $this->findFieldDefinitionRevision($defKey, $revision);
                    if ($revisionModel === null) {
                        throw new MasterDataTransferException('Revision fehlt: '.$defKey.'|'.$revision);
                    }
                    FieldDefinitionRevisionOption::query()->create([
                        'field_definition_revision_id' => $revisionModel->id,
                        'key' => $optKey,
                        ...$payload,
                    ]);
                });

                continue;
            }
            $existing = FieldDefinitionRevisionOption::query()
                ->where('field_definition_revision_id', $revisionModel->id)
                ->where('key', $optKey)
                ->first();
            if ($existing === null) {
                $report->add('field_definition_revision_option', $biz, 'create');
                $ops[] = $this->plannedWrite('field_definition_revision_options', 'create', function () use ($revisionModel, $optKey, $payload): void {
                    FieldDefinitionRevisionOption::query()->create([
                        'field_definition_revision_id' => $revisionModel->id,
                        'key' => $optKey,
                        ...$payload,
                    ]);
                });

                continue;
            }
            $actual = ['label' => $existing->label, 'sort' => (int) $existing->sort, 'is_active' => (bool) $existing->is_active];
            if ($actual !== $payload) {
                $report->conflict('field_definition_revision_option Konflikt: '.$biz);

                continue;
            }
            $report->add('field_definition_revision_option', $biz, 'unchanged');
        }

        return $ops;
    }

    private function findFieldDefinitionRevision(string $defKey, int $revision): ?FieldDefinitionRevision
    {
        $definition = FieldDefinition::query()->where('key', $defKey)->first();
        if ($definition === null) {
            return null;
        }

        return FieldDefinitionRevision::query()
            ->where('field_definition_id', $definition->id)
            ->where('revision', $revision)
            ->first();
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<MasterDataPlannedWriteV2>
     */
    private function planFieldSets(
        array $rows,
        MasterDataImportReport $report,
        MasterDataImportPlanContext $context,
    ): array {
        $ops = [];
        foreach ($rows as $row) {
            $key = (string) ($row['key'] ?? '');
            if ($key === '') {
                $report->conflict('field_set.key fehlt.');

                continue;
            }
            $appliesTo = FieldAppliesTo::tryFrom((string) ($row['applies_to'] ?? ''));
            if ($appliesTo === null) {
                $report->conflict('field_set applies_to ungültig: '.$key);

                continue;
            }
            $payload = [
                'name' => (string) $row['name'],
                'is_system' => (bool) $row['is_system'],
                'applies_to' => $appliesTo,
                'is_assignable' => (bool) $row['is_assignable'],
            ];
            $existing = FieldSet::query()->where('key', $key)->first();
            if ($existing === null) {
                $report->add('field_set', $key, 'create');
                $context->markFieldSet($key);
                $ops[] = $this->plannedWrite('field_sets', 'create', function () use ($key, $payload): void {
                    $model = new FieldSet;
                    $model->forceFill([
                        'key' => $key,
                        ...$payload,
                        'active_version_id' => null,
                        'lock_version' => 1,
                    ])->save();
                });

                continue;
            }
            $actual = [
                'name' => $existing->name,
                'is_system' => (bool) $existing->is_system,
                'applies_to' => $existing->applies_to,
                'is_assignable' => (bool) $existing->is_assignable,
            ];
            if ($actual !== $payload) {
                $report->conflict('field_set Konflikt: '.$key);

                continue;
            }
            $report->add('field_set', $key, 'unchanged');
        }

        return $ops;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<MasterDataPlannedWriteV2>
     */
    private function planFieldSetVersions(
        array $rows,
        MasterDataImportReport $report,
        MasterDataImportPlanContext $context,
    ): array {
        $ops = [];
        foreach ($rows as $row) {
            $setKey = (string) ($row['field_set_key'] ?? '');
            $version = (int) ($row['version'] ?? 0);
            if ($setKey === '' || $version < 1) {
                $report->conflict('field_set_version unvollständig.');

                continue;
            }
            if (! $this->fieldSetResolvable($setKey, $context, $report)) {
                continue;
            }
            $status = FieldSetVersionStatus::tryFrom((string) ($row['status'] ?? ''));
            if ($status === null) {
                $report->conflict('field_set_version status ungültig: '.$setKey.'|'.$version);

                continue;
            }
            $key = $setKey.'|'.$version;
            $payload = ['status' => $status];
            $set = FieldSet::query()->where('key', $setKey)->first();
            if ($set === null) {
                $report->add('field_set_version', $key, 'create');
                $context->markFieldSetVersion($setKey, $version);
                $ops[] = $this->plannedWrite('field_set_versions', 'create', function () use ($setKey, $version, $payload): void {
                    $set = FieldSet::query()->where('key', $setKey)->firstOrFail();
                    FieldSetVersion::query()->create([
                        'field_set_id' => $set->id,
                        'version' => $version,
                        'status' => $payload['status'],
                    ]);
                });

                continue;
            }
            $existing = FieldSetVersion::query()
                ->where('field_set_id', $set->id)
                ->where('version', $version)
                ->first();
            if ($existing === null) {
                $report->add('field_set_version', $key, 'create');
                $context->markFieldSetVersion($setKey, $version);
                $ops[] = $this->plannedWrite('field_set_versions', 'create', function () use ($set, $version, $payload): void {
                    FieldSetVersion::query()->create([
                        'field_set_id' => $set->id,
                        'version' => $version,
                        'status' => $payload['status'],
                    ]);
                });

                continue;
            }
            if ($existing->status !== $payload['status']) {
                $report->conflict('field_set_version Konflikt: '.$key);

                continue;
            }
            $report->add('field_set_version', $key, 'unchanged');
        }

        return $ops;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<MasterDataPlannedWriteV2>
     */
    private function planFieldSetVersionFields(
        array $rows,
        MasterDataImportReport $report,
        MasterDataImportPlanContext $context,
    ): array {
        $ops = [];
        foreach ($rows as $row) {
            $setKey = (string) ($row['field_set_key'] ?? '');
            $version = (int) ($row['version'] ?? 0);
            $defKey = (string) ($row['field_definition_key'] ?? '');
            $revision = (int) ($row['revision'] ?? 0);
            if ($setKey === '' || $version < 1 || $defKey === '' || $revision < 1) {
                $report->conflict('field_set_version_field unvollständig.');

                continue;
            }
            if (! $this->fieldSetVersionResolvable($setKey, $version, $context, $report)) {
                continue;
            }
            if (! $this->fieldDefinitionRevisionResolvable($defKey, $revision, $context, $report)) {
                continue;
            }
            $key = $setKey.'|'.$version.'|'.$defKey;
            $payload = [
                'sort' => (int) $row['sort'],
                'required_override' => $row['required_override'] ?? null,
                'visible_override' => $row['visible_override'] ?? null,
                'revision' => $revision,
            ];
            $setVersion = $this->findFieldSetVersion($setKey, $version);
            $definition = FieldDefinition::query()->where('key', $defKey)->first();
            if ($setVersion === null || $definition === null) {
                $report->add('field_set_version_field', $key, 'create');
                $ops[] = $this->plannedWrite('field_set_version_fields', 'create', function () use ($setKey, $version, $defKey, $payload): void {
                    $setVersion = $this->findFieldSetVersion($setKey, $version);
                    $revisionModel = $this->findFieldDefinitionRevision($defKey, $payload['revision']);
                    $definition = FieldDefinition::query()->where('key', $defKey)->first();
                    if ($setVersion === null || $revisionModel === null || $definition === null) {
                        throw new MasterDataTransferException('field_set_version_field Referenz fehlt: '.$setKey);
                    }
                    FieldSetVersionField::query()->create([
                        'field_set_version_id' => $setVersion->id,
                        'field_definition_id' => $definition->id,
                        'field_definition_revision_id' => $revisionModel->id,
                        'sort' => $payload['sort'],
                        'required_override' => $payload['required_override'],
                        'visible_override' => $payload['visible_override'],
                    ]);
                });

                continue;
            }
            $revisionModel = $this->findFieldDefinitionRevision($defKey, $revision);
            $existing = FieldSetVersionField::query()
                ->where('field_set_version_id', $setVersion->id)
                ->where('field_definition_id', $definition->id)
                ->first();
            if ($existing === null) {
                if ($revisionModel === null) {
                    $report->conflict('field_set_version_field Revision fehlt: '.$defKey.'|'.$revision);

                    continue;
                }
                $report->add('field_set_version_field', $key, 'create');
                $ops[] = $this->plannedWrite('field_set_version_fields', 'create', function () use ($setVersion, $definition, $revisionModel, $payload): void {
                    FieldSetVersionField::query()->create([
                        'field_set_version_id' => $setVersion->id,
                        'field_definition_id' => $definition->id,
                        'field_definition_revision_id' => $revisionModel->id,
                        'sort' => $payload['sort'],
                        'required_override' => $payload['required_override'],
                        'visible_override' => $payload['visible_override'],
                    ]);
                });

                continue;
            }
            $actual = [
                'sort' => (int) $existing->sort,
                'required_override' => $existing->required_override,
                'visible_override' => $existing->visible_override,
                'revision_id' => (int) $existing->field_definition_revision_id,
            ];
            $expectedRevisionId = $revisionModel?->id;
            if ($expectedRevisionId === null
                || $actual['sort'] !== $payload['sort']
                || $actual['required_override'] !== $payload['required_override']
                || $actual['visible_override'] !== $payload['visible_override']
                || $actual['revision_id'] !== $expectedRevisionId) {
                $report->conflict('field_set_version_field Konflikt: '.$key);

                continue;
            }
            $report->add('field_set_version_field', $key, 'unchanged');
        }

        return $ops;
    }

    private function findFieldSetVersion(string $setKey, int $version): ?FieldSetVersion
    {
        $set = FieldSet::query()->where('key', $setKey)->first();
        if ($set === null) {
            return null;
        }

        return FieldSetVersion::query()
            ->where('field_set_id', $set->id)
            ->where('version', $version)
            ->first();
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<MasterDataPlannedWriteV2>
     */
    private function planFieldRules(
        array $rows,
        MasterDataImportReport $report,
        MasterDataImportPlanContext $context,
    ): array {
        $ops = [];
        /** @var array<string, list<array<string, mixed>>> $grouped */
        $grouped = [];
        foreach ($rows as $row) {
            $setKey = (string) ($row['field_set_key'] ?? '');
            $version = (int) ($row['version'] ?? 0);
            if ($setKey === '' || $version < 1) {
                $report->conflict('field_rule Schlüssel unvollständig.');

                continue;
            }
            if (! $this->fieldSetVersionResolvable($setKey, $version, $context, $report)) {
                continue;
            }
            $grouped[$setKey.'|'.$version][] = $row;
        }

        foreach ($grouped as $groupKey => $packageRows) {
            $packageSet = $this->normalizeFieldRuleSet($packageRows);
            [$setKey, $versionStr] = explode('|', $groupKey, 2);
            $version = (int) $versionStr;
            $versionModel = $this->findFieldSetVersion($setKey, $version);
            if ($versionModel === null) {
                if ($packageSet === []) {
                    continue;
                }
                $report->add('field_rules', $groupKey, 'create', 'volle Regelmenge');
                $ops[] = $this->plannedWrite('field_rules', 'create', function () use ($setKey, $version, $packageRows): void {
                    $versionModel = $this->findFieldSetVersion($setKey, $version);
                    if ($versionModel === null) {
                        throw new MasterDataTransferException('field_rules Version fehlt: '.$setKey.'|'.$version);
                    }
                    foreach ($packageRows as $ruleRow) {
                        FieldRule::query()->create([
                            'field_set_version_id' => $versionModel->id,
                            'sort' => (int) $ruleRow['sort'],
                            'condition_json' => $ruleRow['condition_json'],
                            'action_json' => $ruleRow['action_json'],
                        ]);
                    }
                });

                continue;
            }

            $dbRows = FieldRule::query()->where('field_set_version_id', $versionModel->id)->get()->all();
            $dbSet = $this->normalizeFieldRuleSet(array_values(array_map(fn (FieldRule $r): array => [
                'sort' => $r->sort,
                'condition_json' => $r->condition_json,
                'action_json' => $r->action_json,
            ], $dbRows)));

            if ($dbSet === [] && $packageSet !== []) {
                $report->add('field_rules', $groupKey, 'create', 'volle Regelmenge');
                $ops[] = $this->plannedWrite('field_rules', 'create', function () use ($versionModel, $packageRows): void {
                    foreach ($packageRows as $ruleRow) {
                        FieldRule::query()->create([
                            'field_set_version_id' => $versionModel->id,
                            'sort' => (int) $ruleRow['sort'],
                            'condition_json' => $ruleRow['condition_json'],
                            'action_json' => $ruleRow['action_json'],
                        ]);
                    }
                });

                continue;
            }

            if ($dbSet === $packageSet) {
                $report->add('field_rules', $groupKey, 'unchanged');

                continue;
            }

            if ($dbSet !== [] && $dbSet !== $packageSet) {
                $report->conflict('field_rules Mengenkonflikt: '.$groupKey);
            }
        }

        return $ops;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<string>
     */
    private function normalizeFieldRuleSet(array $rows): array
    {
        usort($rows, function (array $a, array $b): int {
            $condA = $this->canonicalJson($a['condition_json'] ?? null);
            $condB = $this->canonicalJson($b['condition_json'] ?? null);
            $actA = $this->canonicalJson($a['action_json'] ?? null);
            $actB = $this->canonicalJson($b['action_json'] ?? null);

            return [(int) ($a['sort'] ?? 0), $condA, $actA] <=> [(int) ($b['sort'] ?? 0), $condB, $actB];
        });

        return array_map(
            fn (array $r): string => (int) $r['sort'].'|'.$this->canonicalJson($r['condition_json'] ?? null).'|'.$this->canonicalJson($r['action_json'] ?? null),
            $rows,
        );
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<MasterDataPlannedWriteV2>
     */
    private function planOaPlatforms(
        array $rows,
        MasterDataImportReport $report,
        MasterDataImportPlanContext $context,
    ): array {
        $ops = [];
        foreach ($rows as $row) {
            $code = (string) ($row['code'] ?? '');
            if ($code === '') {
                $report->conflict('oa_platform.code fehlt.');

                continue;
            }
            $payload = [
                'name' => (string) $row['name'],
                'is_active' => (bool) $row['is_active'],
                'sort' => (int) $row['sort'],
                'is_adserver_relevant' => (bool) $row['is_adserver_relevant'],
            ];
            $existing = OaPlatform::query()->where('code', $code)->first();
            if ($existing === null) {
                $report->add('oa_platform', $code, 'create');
                $context->markPlatform($code);
                $ops[] = $this->plannedWrite('oa_platforms', 'create', function () use ($code, $payload): void {
                    OaPlatform::query()->create(['code' => $code, ...$payload]);
                });

                continue;
            }
            $actual = [
                'name' => $existing->name,
                'is_active' => (bool) $existing->is_active,
                'sort' => (int) $existing->sort,
                'is_adserver_relevant' => (bool) $existing->is_adserver_relevant,
            ];
            if ($actual !== $payload) {
                $report->conflict('oa_platform Konflikt: '.$code);

                continue;
            }
            $report->add('oa_platform', $code, 'unchanged');
        }

        return $ops;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<MasterDataPlannedWriteV2>
     */
    private function planOaPlatformMediumRules(
        array $rows,
        MasterDataImportReport $report,
        MasterDataImportPlanContext $context,
    ): array {
        $ops = [];
        foreach ($rows as $row) {
            $platformCode = (string) ($row['platform_code'] ?? '');
            $mediumCode = (string) ($row['medium_code'] ?? '');
            if ($platformCode === '' || $mediumCode === '') {
                $report->conflict('oa_platform_medium_rule Schlüssel unvollständig.');

                continue;
            }
            if (! $this->platformResolvable($platformCode, $context, $report)) {
                continue;
            }
            if (! $this->mediumResolvable($mediumCode, $context, $report)) {
                continue;
            }
            $key = $platformCode.'|'.$mediumCode;
            $payload = ['is_active' => (bool) $row['is_active']];
            $platform = OaPlatform::query()->where('code', $platformCode)->first();
            $medium = AdvertisingMedium::query()->where('code', $mediumCode)->first();
            if ($platform === null || $medium === null) {
                $report->add('oa_platform_medium_rule', $key, 'create');
                $ops[] = $this->plannedWrite('oa_platform_medium_rules', 'create', function () use ($platformCode, $mediumCode, $payload): void {
                    $platform = OaPlatform::query()->where('code', $platformCode)->first();
                    $medium = AdvertisingMedium::query()->where('code', $mediumCode)->first();
                    if ($platform === null || $medium === null) {
                        throw new MasterDataTransferException('oa_platform_medium_rule Referenz fehlt');
                    }
                    OaPlatformMediumRule::query()->create([
                        'oa_platform_id' => $platform->id,
                        'advertising_medium_id' => $medium->id,
                        ...$payload,
                    ]);
                });

                continue;
            }
            $existing = OaPlatformMediumRule::query()
                ->where('oa_platform_id', $platform->id)
                ->where('advertising_medium_id', $medium->id)
                ->first();
            if ($existing === null) {
                $report->add('oa_platform_medium_rule', $key, 'create');
                $ops[] = $this->plannedWrite('oa_platform_medium_rules', 'create', function () use ($platform, $medium, $payload): void {
                    OaPlatformMediumRule::query()->create([
                        'oa_platform_id' => $platform->id,
                        'advertising_medium_id' => $medium->id,
                        ...$payload,
                    ]);
                });

                continue;
            }
            if ((bool) $existing->is_active !== $payload['is_active']) {
                $report->conflict('oa_platform_medium_rule Konflikt: '.$key);

                continue;
            }
            $report->add('oa_platform_medium_rule', $key, 'unchanged');
        }

        return $ops;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<MasterDataPlannedWriteV2>
     */
    private function planOaTargetingDefinitions(
        array $rows,
        MasterDataImportReport $report,
        MasterDataImportPlanContext $context,
    ): array {
        $ops = [];
        foreach ($rows as $row) {
            $code = (string) ($row['code'] ?? '');
            $revision = (int) ($row['revision_number'] ?? 0);
            if ($code === '' || $revision < 1) {
                $report->conflict('oa_targeting_definition unvollständig.');

                continue;
            }
            $category = OaTargetingCategory::tryFrom((string) ($row['category'] ?? ''));
            if ($category === null) {
                $report->conflict('oa_targeting category ungültig: '.$code);

                continue;
            }
            $key = $code.'|'.$revision;
            $payload = [
                'name' => (string) $row['name'],
                'category' => $category,
                'tkp_surcharge' => MasterDataExportDecimal::canonicalize($row['tkp_surcharge'] ?? null, MasterDataExportDecimal::PRICE_SCALE),
                'minimum_tkp_surcharge' => MasterDataExportDecimal::canonicalize($row['minimum_tkp_surcharge'] ?? null, MasterDataExportDecimal::PRICE_SCALE),
                'is_active' => (bool) $row['is_active'],
                'valid_from' => $row['valid_from'] ?? null,
                'valid_to' => $row['valid_to'] ?? null,
            ];
            $existing = OaTargetingDefinition::query()->where('code', $code)->where('revision_number', $revision)->first();
            if ($existing === null) {
                $report->add('oa_targeting_definition', $key, 'create');
                $ops[] = $this->plannedWrite('oa_targeting_definitions', 'create', function () use ($code, $revision, $payload): void {
                    OaTargetingDefinition::query()->create([
                        'code' => $code,
                        'revision_number' => $revision,
                        ...$payload,
                    ]);
                });

                continue;
            }
            $actual = [
                'name' => $existing->name,
                'category' => $existing->category,
                'tkp_surcharge' => MasterDataExportDecimal::canonicalize($this->rawDecimalAttribute($existing, 'tkp_surcharge'), MasterDataExportDecimal::PRICE_SCALE),
                'minimum_tkp_surcharge' => MasterDataExportDecimal::canonicalize($this->rawDecimalAttribute($existing, 'minimum_tkp_surcharge'), MasterDataExportDecimal::PRICE_SCALE),
                'is_active' => (bool) $existing->is_active,
                'valid_from' => $existing->valid_from?->toDateString(),
                'valid_to' => $existing->valid_to?->toDateString(),
            ];
            if ($actual !== $payload) {
                $report->conflict('oa_targeting_definition Konflikt: '.$key);

                continue;
            }
            $report->add('oa_targeting_definition', $key, 'unchanged');
        }

        return $ops;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<MasterDataPlannedWriteV2>
     */
    private function planOaTkpPriceLists(
        array $rows,
        MasterDataImportReport $report,
        MasterDataImportPlanContext $context,
    ): array {
        $ops = [];
        foreach ($rows as $row) {
            $year = (int) ($row['year'] ?? 0);
            $revision = (int) ($row['revision_number'] ?? 0);
            $version = (string) ($row['version'] ?? '');
            /** @var list<array{inventory_code: string, medium_code: string, list_tkp: string, minimum_tkp: string}> $items */
            $items = array_values(array_map(fn (array $i): array => [
                'inventory_code' => (string) $i['inventory_code'],
                'medium_code' => (string) $i['medium_code'],
                'list_tkp' => (string) $i['list_tkp'],
                'minimum_tkp' => (string) $i['minimum_tkp'],
            ], is_array($row['items'] ?? null) ? $row['items'] : []));
            $fingerprint = PriceListFingerprint::forOaTkpItems($items);
            $meta = PriceListFingerprint::spotMetadataTuple((string) $row['name'], $version, $row['valid_from'] ?? null);
            $key = $year.'|r'.$revision;

            $sameRevision = OaTkpPriceList::query()
                ->with('items')
                ->where('year', $year)
                ->where('revision_number', $revision)
                ->first();

            if ($sameRevision !== null) {
                /** @var list<array{inventory_code: string, medium_code: string, list_tkp: string, minimum_tkp: string}> $existingItems */
                $existingItems = array_values($sameRevision->items
                    ->map(fn (OaTkpPriceListItem $item): array => [
                        'inventory_code' => (string) Inventory::query()->where('id', $item->inventory_id)->value('code'),
                        'medium_code' => (string) AdvertisingMedium::query()->where('id', $item->advertising_medium_id)->value('code'),
                        'list_tkp' => MasterDataExportDecimal::canonicalize($this->rawDecimalAttribute($item, 'list_tkp'), MasterDataExportDecimal::PRICE_SCALE),
                        'minimum_tkp' => MasterDataExportDecimal::canonicalize($this->rawDecimalAttribute($item, 'minimum_tkp'), MasterDataExportDecimal::PRICE_SCALE),
                    ])
                    ->filter(fn (array $i) => $i['inventory_code'] !== '' && $i['medium_code'] !== '')
                    ->all());
                $existingFp = PriceListFingerprint::forOaTkpItems($existingItems);
                $existingMeta = PriceListFingerprint::spotMetadataTuple(
                    $sameRevision->name,
                    (string) $sameRevision->version,
                    $sameRevision->valid_from?->toDateString(),
                );
                if ($existingFp === $fingerprint && PriceListFingerprint::oaTkpMetadataMatches($meta, $existingMeta)) {
                    $report->add('oa_tkp_price_list', $key, 'unchanged');

                    continue;
                }
                $report->conflict('oa_tkp_price_list Konflikt: '.$key);

                continue;
            }

            $report->add('oa_tkp_price_list', $key, 'create', 'status=draft');
            $name = (string) $row['name'];
            $validFrom = $row['valid_from'] ?? null;
            $ops[] = $this->plannedWrite(['oa_tkp_price_lists', 'oa_tkp_price_list_items'], 'create', function () use ($year, $revision, $version, $name, $validFrom, $items, $key): void {
                if (OaTkpPriceList::query()->where('year', $year)->where('revision_number', $revision)->exists()) {
                    throw new MasterDataTransferException('oa_tkp_price_list existiert bereits: '.$key);
                }
                $list = OaTkpPriceList::query()->create([
                    'year' => $year,
                    'name' => $name,
                    'version' => $version,
                    'revision_number' => $revision,
                    'status' => PriceListStatus::Draft,
                    'valid_from' => $validFrom,
                    'lock_version' => 1,
                    'published_at' => null,
                    'archived_at' => null,
                ]);
                foreach ($items as $item) {
                    $inventory = Inventory::query()->where('code', $item['inventory_code'])->first();
                    $medium = AdvertisingMedium::query()->where('code', $item['medium_code'])->first();
                    if ($inventory === null || $medium === null) {
                        throw new MasterDataTransferException('oa_tkp_price_list_item Referenz fehlt');
                    }
                    OaTkpPriceListItem::query()->create([
                        'oa_tkp_price_list_id' => $list->id,
                        'inventory_id' => $inventory->id,
                        'advertising_medium_id' => $medium->id,
                        'list_tkp' => $item['list_tkp'],
                        'minimum_tkp' => $item['minimum_tkp'],
                    ]);
                }
            });
        }

        return $ops;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<MasterDataPlannedWriteV2>
     */
    private function planOaAdserverSurcharges(
        array $rows,
        MasterDataImportReport $report,
        MasterDataImportPlanContext $context,
    ): array {
        $ops = [];
        foreach ($rows as $row) {
            $code = (string) ($row['code'] ?? '');
            $revision = (int) ($row['revision_number'] ?? 0);
            if ($code === '' || $revision < 1) {
                $report->conflict('oa_adserver_surcharge unvollständig.');

                continue;
            }
            $key = $code.'|'.$revision;
            $payload = [
                'name' => (string) $row['name'],
                'tkp_amount' => MasterDataExportDecimal::canonicalize($row['tkp_amount'] ?? null, MasterDataExportDecimal::PRICE_SCALE),
                'is_active' => (bool) $row['is_active'],
                'scope_json' => $row['scope_json'] ?? null,
                'valid_from' => $row['valid_from'] ?? null,
                'valid_to' => $row['valid_to'] ?? null,
            ];
            $existing = OaAdserverSurcharge::query()->where('code', $code)->where('revision_number', $revision)->first();
            if ($existing === null) {
                $report->add('oa_adserver_surcharge', $key, 'create');
                $ops[] = $this->plannedWrite('oa_adserver_surcharges', 'create', function () use ($code, $revision, $payload): void {
                    OaAdserverSurcharge::query()->create([
                        'code' => $code,
                        'revision_number' => $revision,
                        ...$payload,
                    ]);
                });

                continue;
            }
            $actual = [
                'name' => $existing->name,
                'tkp_amount' => MasterDataExportDecimal::canonicalize($this->rawDecimalAttribute($existing, 'tkp_amount'), MasterDataExportDecimal::PRICE_SCALE),
                'is_active' => (bool) $existing->is_active,
                'scope_json' => $existing->scope_json,
                'valid_from' => $existing->valid_from?->toDateString(),
                'valid_to' => $existing->valid_to?->toDateString(),
            ];
            if ($this->canonicalJson($actual['scope_json']) !== $this->canonicalJson($payload['scope_json'])
                || $actual['name'] !== $payload['name']
                || $actual['tkp_amount'] !== $payload['tkp_amount']
                || $actual['is_active'] !== $payload['is_active']
                || $actual['valid_from'] !== $payload['valid_from']
                || $actual['valid_to'] !== $payload['valid_to']) {
                $report->conflict('oa_adserver_surcharge Konflikt: '.$key);

                continue;
            }
            $report->add('oa_adserver_surcharge', $key, 'unchanged');
        }

        return $ops;
    }

    /**
     * Phase B: nachlaufende Zeiger, Zuordnungen und Integrität (schreibfreie Planung).
     *
     * @param  array<string, mixed>  $data
     * @return list<MasterDataPlannedWriteV2>
     */
    private function planPhaseB(
        array $data,
        MasterDataImportReport $report,
        MasterDataImportPlanContext $context,
    ): array {
        $ops = [];
        $ops = array_merge($ops, $this->planCurrentRevisionPointers($data['data/field_definitions.json'], $report, $context));
        $ops = array_merge($ops, $this->planActiveVersionPointers($data['data/field_sets.json'], $report, $context));
        $ops = array_merge($ops, $this->planFieldSetAssignments($data['data/field_set_assignments.json'], $report, $context));

        return $ops;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<MasterDataPlannedWriteV2>
     */
    private function planCurrentRevisionPointers(
        array $rows,
        MasterDataImportReport $report,
        MasterDataImportPlanContext $context,
    ): array {
        $ops = [];
        foreach ($rows as $row) {
            $key = (string) ($row['key'] ?? '');
            if ($key === '') {
                continue;
            }
            if (! array_key_exists('current_revision', $row)) {
                continue;
            }

            $packageRevision = $row['current_revision'];
            $definition = FieldDefinition::query()->where('key', $key)->first();
            $currentId = $definition !== null
                ? (int) ($definition->getAttributes()['current_revision_id'] ?? 0)
                : 0;

            if ($packageRevision === null) {
                if ($definition === null || $currentId < 1) {
                    $report->add('field_definition_current_revision', $key, 'unchanged');
                } else {
                    $report->conflict('field_definition.current_revision Zielzeiger gesetzt, Paket NULL: '.$key);
                }

                continue;
            }

            $revision = (int) $packageRevision;
            if ($revision < 1) {
                $report->conflict('field_definition.current_revision ungültig: '.$key);

                continue;
            }
            if (! $this->fieldDefinitionResolvable($key, $context, $report)) {
                continue;
            }
            if (! $this->fieldDefinitionRevisionResolvable($key, $revision, $context, $report)) {
                continue;
            }

            $revisionModel = $this->findFieldDefinitionRevision($key, $revision);

            if ($definition === null) {
                $report->add('field_definition_current_revision', $key, 'update', 'revision='.$revision);
                $ops[] = $this->plannedWrite('field_definitions', 'update', function () use ($key, $revision): void {
                    $this->applyCurrentRevisionPointer($key, $revision);
                });

                continue;
            }

            if ($revisionModel === null) {
                if ($currentId > 0) {
                    $existingRev = FieldDefinitionRevision::query()->find($currentId);
                    if ($existingRev === null
                        || (int) $existingRev->field_definition_id !== (int) $definition->id
                        || (int) $existingRev->revision !== $revision) {
                        $report->conflict('field_definition.current_revision abweichend: '.$key);

                        continue;
                    }
                }
                $report->add('field_definition_current_revision', $key, 'update', 'revision='.$revision);
                $ops[] = $this->plannedWrite('field_definitions', 'update', function () use ($key, $revision): void {
                    $this->applyCurrentRevisionPointer($key, $revision);
                });

                continue;
            }

            if ((int) $revisionModel->field_definition_id !== (int) $definition->id) {
                $report->conflict('field_definition.current_revision zeigt auf fremde Revision: '.$key);

                continue;
            }

            if ($currentId < 1) {
                $report->add('field_definition_current_revision', $key, 'update', 'revision='.$revision);
                $ops[] = $this->plannedWrite('field_definitions', 'update', function () use ($definition, $revisionModel): void {
                    $definition->forceFill(['current_revision_id' => $revisionModel->id])->save();
                });

                continue;
            }

            if ($currentId === (int) $revisionModel->id) {
                $report->add('field_definition_current_revision', $key, 'unchanged');

                continue;
            }

            $report->conflict('field_definition.current_revision abweichend: '.$key);
        }

        return $ops;
    }

    private function applyCurrentRevisionPointer(string $definitionKey, int $revision): void
    {
        $definition = FieldDefinition::query()->where('key', $definitionKey)->firstOrFail();
        $revisionModel = $this->findFieldDefinitionRevision($definitionKey, $revision);
        if ($revisionModel === null || (int) $revisionModel->field_definition_id !== (int) $definition->id) {
            throw new MasterDataTransferException(
                'current_revision_id nicht auflösbar: '.$definitionKey.'|'.$revision,
            );
        }
        $definition->forceFill(['current_revision_id' => $revisionModel->id])->save();
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<MasterDataPlannedWriteV2>
     */
    private function planActiveVersionPointers(
        array $rows,
        MasterDataImportReport $report,
        MasterDataImportPlanContext $context,
    ): array {
        $ops = [];
        foreach ($rows as $row) {
            $key = (string) ($row['key'] ?? '');
            if ($key === '') {
                continue;
            }
            if (! array_key_exists('active_version', $row)) {
                continue;
            }

            $packageVersion = $row['active_version'];
            $set = FieldSet::query()->where('key', $key)->first();
            $currentId = $set !== null
                ? (int) ($set->getAttributes()['active_version_id'] ?? 0)
                : 0;

            if ($packageVersion === null) {
                if ($set === null || $currentId < 1) {
                    $report->add('field_set_active_version', $key, 'unchanged');
                } else {
                    $report->conflict('field_set.active_version Zielzeiger gesetzt, Paket NULL: '.$key);
                }

                continue;
            }

            $version = (int) $packageVersion;
            if ($version < 1) {
                $report->conflict('field_set.active_version ungültig: '.$key);

                continue;
            }
            if (! $this->fieldSetResolvable($key, $context, $report)) {
                continue;
            }
            if (! $this->fieldSetVersionResolvable($key, $version, $context, $report)) {
                continue;
            }

            $versionModel = $this->findFieldSetVersion($key, $version);

            if ($set === null) {
                $report->add('field_set_active_version', $key, 'update', 'version='.$version);
                $ops[] = $this->plannedWrite('field_sets', 'update', function () use ($key, $version): void {
                    $this->applyActiveVersionPointer($key, $version);
                });

                continue;
            }

            if ($versionModel === null) {
                if ($currentId > 0) {
                    $existingVersion = FieldSetVersion::query()->find($currentId);
                    if ($existingVersion === null
                        || (int) $existingVersion->field_set_id !== (int) $set->id
                        || (int) $existingVersion->version !== $version) {
                        $report->conflict('field_set.active_version abweichend: '.$key);

                        continue;
                    }
                }
                $report->add('field_set_active_version', $key, 'update', 'version='.$version);
                $ops[] = $this->plannedWrite('field_sets', 'update', function () use ($key, $version): void {
                    $this->applyActiveVersionPointer($key, $version);
                });

                continue;
            }

            if ((int) $versionModel->field_set_id !== (int) $set->id) {
                $report->conflict('field_set.active_version zeigt auf fremde Version: '.$key);

                continue;
            }

            if ($currentId < 1) {
                $report->add('field_set_active_version', $key, 'update', 'version='.$version);
                $ops[] = $this->plannedWrite('field_sets', 'update', function () use ($set, $versionModel): void {
                    $set->forceFill(['active_version_id' => $versionModel->id])->save();
                });

                continue;
            }

            if ($currentId === (int) $versionModel->id) {
                $report->add('field_set_active_version', $key, 'unchanged');

                continue;
            }

            $report->conflict('field_set.active_version abweichend: '.$key);
        }

        return $ops;
    }

    private function applyActiveVersionPointer(string $setKey, int $version): void
    {
        $set = FieldSet::query()->where('key', $setKey)->firstOrFail();
        $versionModel = $this->findFieldSetVersion($setKey, $version);
        if ($versionModel === null || (int) $versionModel->field_set_id !== (int) $set->id) {
            throw new MasterDataTransferException(
                'active_version_id nicht auflösbar: '.$setKey.'|'.$version,
            );
        }
        $set->forceFill(['active_version_id' => $versionModel->id])->save();
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<MasterDataPlannedWriteV2>
     */
    private function planFieldSetAssignments(
        array $rows,
        MasterDataImportReport $report,
        MasterDataImportPlanContext $context,
    ): array {
        $ops = [];
        foreach ($rows as $row) {
            $setKey = (string) ($row['field_set_key'] ?? '');
            $processRaw = (string) ($row['applies_to_process'] ?? '');
            $layerRaw = (string) ($row['target_layer'] ?? '');
            $process = FieldAppliesTo::tryFrom($processRaw);
            $layer = FieldSetAssignmentTargetLayer::tryFrom($layerRaw);
            if ($setKey === '' || $process === null || $layer === null) {
                $report->conflict('field_set_assignment Schlüssel unvollständig.');

                continue;
            }
            if (! $this->fieldSetResolvable($setKey, $context, $report)) {
                continue;
            }

            $categoryKey = isset($row['category_key']) && is_string($row['category_key']) ? $row['category_key'] : null;
            $mediumCode = isset($row['medium_code']) && is_string($row['medium_code']) ? $row['medium_code'] : null;

            if ($layer === FieldSetAssignmentTargetLayer::AdvertisingCategory) {
                if ($categoryKey === null || $categoryKey === '') {
                    $report->conflict('field_set_assignment category_key fehlt.');

                    continue;
                }
                if (! $this->categoryResolvable($categoryKey, $context, $report)) {
                    continue;
                }
            } elseif ($layer === FieldSetAssignmentTargetLayer::AdvertisingMedium) {
                if ($mediumCode === null || $mediumCode === '') {
                    $report->conflict('field_set_assignment medium_code fehlt.');

                    continue;
                }
                if (! $this->mediumResolvable($mediumCode, $context, $report)) {
                    continue;
                }
            }

            $semantic = $this->assignmentSemanticKey($setKey, $processRaw, $layer, $categoryKey, $mediumCode);
            $payload = [
                'target_layer' => $layer,
                'is_active' => (bool) $row['is_active'],
                'sort' => (int) $row['sort'],
            ];

            $matches = $this->findAssignmentsBySemantic($setKey, $process, $layer, $categoryKey, $mediumCode);
            if ($matches->count() > 1) {
                $report->conflict('field_set_assignment mehrdeutig: '.$semantic);

                continue;
            }
            $existing = $matches->first();
            if ($existing === null) {
                $report->add('field_set_assignment', $semantic, 'create');
                $ops[] = $this->plannedWrite('field_set_assignments', 'create', function () use ($setKey, $process, $layer, $categoryKey, $mediumCode, $payload): void {
                    $this->applyFieldSetAssignmentCreate($setKey, $process, $layer, $categoryKey, $mediumCode, $payload);
                });

                continue;
            }

            if (! $this->assignmentTargetMatchesPackage($existing, $layer, $categoryKey, $mediumCode, $report, $semantic)) {
                continue;
            }

            $actual = [
                'target_layer' => $existing->target_layer,
                'is_active' => (bool) $existing->is_active,
                'sort' => (int) $existing->sort,
            ];
            if ($actual['target_layer'] !== $payload['target_layer']
                || $actual['is_active'] !== $payload['is_active']
                || $actual['sort'] !== $payload['sort']) {
                $report->conflict('field_set_assignment Konflikt: '.$semantic);

                continue;
            }
            $report->add('field_set_assignment', $semantic, 'unchanged');
        }

        return $ops;
    }

    /**
     * Prüft target_identity und Ziel-FKs gegen aktuelle Ziel-IDs und target_layer.
     */
    private function assignmentTargetMatchesPackage(
        FieldSetAssignment $existing,
        FieldSetAssignmentTargetLayer $layer,
        ?string $categoryKey,
        ?string $mediumCode,
        MasterDataImportReport $report,
        string $semantic,
    ): bool {
        $attrs = $existing->getAttributes();
        $categoryId = array_key_exists('advertising_category_id', $attrs) && $attrs['advertising_category_id'] !== null
            ? (int) $attrs['advertising_category_id']
            : null;
        $mediumId = array_key_exists('advertising_medium_id', $attrs) && $attrs['advertising_medium_id'] !== null
            ? (int) $attrs['advertising_medium_id']
            : null;

        $expectedCategoryId = null;
        $expectedMediumId = null;
        if ($layer === FieldSetAssignmentTargetLayer::AdvertisingCategory) {
            $expectedCategoryId = (int) (AdvertisingCategory::query()->where('key', $categoryKey)->value('id') ?? 0);
            if ($expectedCategoryId < 1) {
                $report->conflict('field_set_assignment Kategorie-Ziel fehlt: '.$semantic);

                return false;
            }
            if ($categoryId !== $expectedCategoryId || $mediumId !== null) {
                $report->conflict('field_set_assignment Ziel-FKs passen nicht zu target_layer: '.$semantic);

                return false;
            }
        } elseif ($layer === FieldSetAssignmentTargetLayer::AdvertisingMedium) {
            $expectedMediumId = (int) (AdvertisingMedium::query()->where('code', $mediumCode)->value('id') ?? 0);
            if ($expectedMediumId < 1) {
                $report->conflict('field_set_assignment Medium-Ziel fehlt: '.$semantic);

                return false;
            }
            if ($mediumId !== $expectedMediumId || $categoryId !== null) {
                $report->conflict('field_set_assignment Ziel-FKs passen nicht zu target_layer: '.$semantic);

                return false;
            }
        } elseif ($categoryId !== null || $mediumId !== null) {
            $report->conflict('field_set_assignment Ziel-FKs passen nicht zu target_layer: '.$semantic);

            return false;
        }

        $expectedIdentity = FieldSetAssignment::buildTargetIdentity($layer, $expectedCategoryId, $expectedMediumId);
        if ((string) $existing->target_identity !== $expectedIdentity) {
            $report->conflict('field_set_assignment target_identity inkonsistent: '.$semantic);

            return false;
        }

        return true;
    }

    private function assignmentSemanticKey(
        string $setKey,
        string $process,
        FieldSetAssignmentTargetLayer $layer,
        ?string $categoryKey,
        ?string $mediumCode,
    ): string {
        return match ($layer) {
            FieldSetAssignmentTargetLayer::Global => $setKey.'|'.$process.'|global',
            FieldSetAssignmentTargetLayer::AdvertisingCategory => $setKey.'|'.$process.'|category|'.($categoryKey ?? ''),
            FieldSetAssignmentTargetLayer::AdvertisingMedium => $setKey.'|'.$process.'|medium|'.($mediumCode ?? ''),
        };
    }

    /**
     * @return Collection<int, FieldSetAssignment>
     */
    private function findAssignmentsBySemantic(
        string $setKey,
        FieldAppliesTo $process,
        FieldSetAssignmentTargetLayer $layer,
        ?string $categoryKey,
        ?string $mediumCode,
    ) {
        $query = FieldSetAssignment::query()
            ->where('applies_to_process', $process)
            ->where('target_layer', $layer)
            ->whereHas('fieldSet', fn ($q) => $q->where('key', $setKey));

        // Global: nicht nur über target_identity=g filtern, damit inkonsistente Identitäten gefunden werden.
        if ($layer === FieldSetAssignmentTargetLayer::AdvertisingCategory) {
            $query->whereHas('advertisingCategory', fn ($q) => $q->where('key', $categoryKey));
        } elseif ($layer === FieldSetAssignmentTargetLayer::AdvertisingMedium) {
            $query->whereHas('advertisingMedium', fn ($q) => $q->where('code', $mediumCode));
        }

        return $query->get();
    }

    /**
     * @param  array{target_layer: FieldSetAssignmentTargetLayer, is_active: bool, sort: int}  $payload
     */
    private function applyFieldSetAssignmentCreate(
        string $setKey,
        FieldAppliesTo $process,
        FieldSetAssignmentTargetLayer $layer,
        ?string $categoryKey,
        ?string $mediumCode,
        array $payload,
    ): void {
        $set = FieldSet::query()->where('key', $setKey)->firstOrFail();
        $categoryId = null;
        $mediumId = null;
        if ($layer === FieldSetAssignmentTargetLayer::AdvertisingCategory) {
            $categoryId = (int) AdvertisingCategory::query()->where('key', $categoryKey)->value('id');
            if ($categoryId < 1) {
                throw new MasterDataTransferException('Assignment-Kategorie fehlt: '.$categoryKey);
            }
        } elseif ($layer === FieldSetAssignmentTargetLayer::AdvertisingMedium) {
            $mediumId = (int) AdvertisingMedium::query()->where('code', $mediumCode)->value('id');
            if ($mediumId < 1) {
                throw new MasterDataTransferException('Assignment-Medium fehlt: '.$mediumCode);
            }
        }

        $identity = FieldSetAssignment::buildTargetIdentity($layer, $categoryId, $mediumId);
        $assignment = new FieldSetAssignment;
        $assignment->field_set_id = $set->id;
        $assignment->target_layer = $payload['target_layer'];
        $assignment->advertising_category_id = $categoryId;
        $assignment->advertising_medium_id = $mediumId;
        $assignment->target_identity = $identity;
        $assignment->applies_to_process = $process;
        $assignment->is_active = $payload['is_active'];
        $assignment->sort = $payload['sort'];
        $assignment->lock_version = 1;
        $assignment->save();
    }

    /**
     * Abschließende Referenz-/Integritätsprüfung über Paket + virtuelle Planung + Ziel-DB.
     *
     * @param  array<string, mixed>  $data
     */
    private function assertReferentialIntegrity(
        array $data,
        MasterDataImportReport $report,
        MasterDataImportPlanContext $context,
    ): void {
        $orgCount = Organization::query()->count();
        if ($orgCount > 1) {
            $report->conflict('Mehr als eine Organisation im Ziel — Konflikt.');
        }
        if ($orgCount === 0 && ! $this->packageWillCreateOrganization($data)) {
            $report->conflict('Organisation fehlt für Inventar-Referenzen.');
        }

        foreach ($data['data/inventories.json'] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $code = (string) ($row['code'] ?? '');
            if ($code === '') {
                continue;
            }
            $existing = Inventory::query()->where('code', $code)->first();
            if ($existing !== null) {
                $orgId = (int) ($existing->getAttributes()['organization_id'] ?? 0);
                if ($orgId < 1 || ! Organization::query()->whereKey($orgId)->exists()) {
                    $report->conflict('Inventar ohne Organisation: '.$code);
                }
            }
        }

        foreach ($data['data/advertising_media.json'] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $code = (string) ($row['code'] ?? '');
            $categoryKey = (string) ($row['category_key'] ?? '');
            if ($code === '' || $categoryKey === '') {
                continue;
            }
            $this->categoryResolvable($categoryKey, $context, $report);
            $methodKey = $row['default_calculation_method_key'] ?? null;
            if (is_string($methodKey) && $methodKey !== '') {
                $this->resolveMethodId($methodKey, $context, $report);
            }
        }

        foreach ($data['data/advertising_categories.json'] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $methodKey = $row['default_calculation_method_key'] ?? null;
            if (is_string($methodKey) && $methodKey !== '') {
                $this->resolveMethodId($methodKey, $context, $report);
            }
        }

        foreach ($data['data/inventory_medium_rules.json'] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $this->inventoryResolvable((string) ($row['inventory_code'] ?? ''), $context, $report);
            $this->mediumResolvable((string) ($row['medium_code'] ?? ''), $context, $report);
        }

        foreach ($data['data/oa_platform_medium_rules.json'] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $this->platformResolvable((string) ($row['platform_code'] ?? ''), $context, $report);
            $this->mediumResolvable((string) ($row['medium_code'] ?? ''), $context, $report);
        }

        foreach ($data['data/price_lists.json'] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $this->inventoryResolvable((string) ($row['inventory_code'] ?? ''), $context, $report);
        }

        foreach ($data['data/production_price_lists.json'] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $this->inventoryResolvable((string) ($row['inventory_code'] ?? ''), $context, $report);
        }

        foreach ($data['data/oa_tkp_price_lists.json'] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $items = $row['items'] ?? [];
            if (! is_array($items)) {
                continue;
            }
            foreach ($items as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $this->inventoryResolvable((string) ($item['inventory_code'] ?? ''), $context, $report);
                $this->mediumResolvable((string) ($item['medium_code'] ?? ''), $context, $report);
            }
        }

        foreach ($data['data/field_definition_revisions.json'] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $this->fieldDefinitionResolvable((string) ($row['field_definition_key'] ?? ''), $context, $report);
        }

        foreach ($data['data/field_set_version_fields.json'] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $setKey = (string) ($row['field_set_key'] ?? '');
            $version = (int) ($row['version'] ?? 0);
            $defKey = (string) ($row['field_definition_key'] ?? '');
            $revision = (int) ($row['revision'] ?? 0);
            $this->fieldSetVersionResolvable($setKey, $version, $context, $report);
            $this->fieldDefinitionRevisionResolvable($defKey, $revision, $context, $report);

            $link = null;
            $setVersion = $this->findFieldSetVersion($setKey, $version);
            $definition = FieldDefinition::query()->where('key', $defKey)->first();
            $revisionModel = $this->findFieldDefinitionRevision($defKey, $revision);
            if ($setVersion !== null && $definition !== null) {
                $link = FieldSetVersionField::query()
                    ->where('field_set_version_id', $setVersion->id)
                    ->where('field_definition_id', $definition->id)
                    ->first();
            }
            if ($link !== null) {
                $linkedRevisionId = (int) ($link->getAttributes()['field_definition_revision_id'] ?? 0);
                if ($linkedRevisionId < 1) {
                    $report->conflict('field_set_version_fields.field_definition_revision_id ist NULL: '.$setKey.'|'.$version.'|'.$defKey);
                } elseif ($revisionModel !== null
                    && $linkedRevisionId !== (int) $revisionModel->id) {
                    $report->conflict('field_set_version_fields Revisions-FK passt nicht zum Fachschlüssel: '.$setKey.'|'.$version.'|'.$defKey);
                } elseif ($revisionModel !== null
                    && (int) $revisionModel->field_definition_id !== (int) $definition->id) {
                    $report->conflict('field_set_version_fields Revision gehört nicht zur Definition: '.$defKey);
                }
            }
        }

        foreach ($data['data/field_rules.json'] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $this->fieldSetVersionResolvable(
                (string) ($row['field_set_key'] ?? ''),
                (int) ($row['version'] ?? 0),
                $context,
                $report,
            );
        }

        foreach ($data['data/field_definitions.json'] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $key = (string) ($row['key'] ?? '');
            if ($key === '' || ! array_key_exists('current_revision', $row) || $row['current_revision'] === null) {
                continue;
            }
            $revision = (int) $row['current_revision'];
            $definition = FieldDefinition::query()->where('key', $key)->first();
            $revisionModel = $this->findFieldDefinitionRevision($key, $revision);
            if ($definition !== null && $definition->current_revision_id !== null) {
                $current = FieldDefinitionRevision::query()->find($definition->current_revision_id);
                if ($current === null || (int) $current->field_definition_id !== (int) $definition->id) {
                    $report->conflict('current_revision_id ungültig nach Planung: '.$key);
                }
            }
            if ($revisionModel !== null && $definition !== null
                && (int) $revisionModel->field_definition_id !== (int) $definition->id) {
                $report->conflict('current_revision zeigt auf fremde Revision: '.$key);
            }
            if ($revisionModel === null
                && ! $context->willHaveFieldDefinitionRevision($key, $revision)
                && ! $context->packageHasFieldDefinitionRevision($key, $revision)) {
                $report->conflict('current_revision ohne Zielrevision: '.$key.'|'.$revision);
            }
        }

        foreach ($data['data/field_sets.json'] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $key = (string) ($row['key'] ?? '');
            if ($key === '' || ! array_key_exists('active_version', $row) || $row['active_version'] === null) {
                continue;
            }
            $version = (int) $row['active_version'];
            $set = FieldSet::query()->where('key', $key)->first();
            $versionModel = $this->findFieldSetVersion($key, $version);
            if ($set !== null && $set->active_version_id !== null) {
                $current = FieldSetVersion::query()->find($set->active_version_id);
                if ($current === null || (int) $current->field_set_id !== (int) $set->id) {
                    $report->conflict('active_version_id ungültig nach Planung: '.$key);
                }
            }
            if ($versionModel !== null && $set !== null
                && (int) $versionModel->field_set_id !== (int) $set->id) {
                $report->conflict('active_version zeigt auf fremde Version: '.$key);
            }
            if ($versionModel === null && ! $context->willHaveFieldSetVersion($key, $version)) {
                $report->conflict('active_version ohne Zielversion: '.$key.'|'.$version);
            }
        }

        foreach ($data['data/field_set_assignments.json'] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $setKey = (string) ($row['field_set_key'] ?? '');
            $this->fieldSetResolvable($setKey, $context, $report);
            $layer = FieldSetAssignmentTargetLayer::tryFrom((string) ($row['target_layer'] ?? ''));
            if ($layer === FieldSetAssignmentTargetLayer::AdvertisingCategory) {
                $this->categoryResolvable((string) ($row['category_key'] ?? ''), $context, $report);
            } elseif ($layer === FieldSetAssignmentTargetLayer::AdvertisingMedium) {
                $this->mediumResolvable((string) ($row['medium_code'] ?? ''), $context, $report);
            }
        }
    }

    /**
     * Post-Apply-Integrität: gespeicherte FKs/Zeiger gegen Paket (innerhalb derselben Transaktion).
     *
     * @param  array<string, mixed>  $data
     */
    private function assertPersistedIntegrityAfterApply(array $data, MasterDataImportReport $report): void
    {
        foreach ($data['data/field_definitions.json'] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $key = (string) ($row['key'] ?? '');
            if ($key === '' || ! array_key_exists('current_revision', $row)) {
                continue;
            }
            $definition = FieldDefinition::query()->where('key', $key)->first();
            if ($definition === null) {
                $report->conflict('Nach Apply fehlt field_definition: '.$key);

                continue;
            }
            $currentId = (int) ($definition->getAttributes()['current_revision_id'] ?? 0);
            if ($row['current_revision'] === null) {
                if ($currentId > 0) {
                    $report->conflict('Nach Apply: current_revision_id gesetzt trotz Paket NULL: '.$key);
                }

                continue;
            }
            $revision = (int) $row['current_revision'];
            $revisionModel = $this->findFieldDefinitionRevision($key, $revision);
            if ($revisionModel === null || $currentId !== (int) $revisionModel->id
                || (int) $revisionModel->field_definition_id !== (int) $definition->id) {
                $report->conflict('Nach Apply: current_revision_id passt nicht zum Paket: '.$key);
            }
        }

        foreach ($data['data/field_sets.json'] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $key = (string) ($row['key'] ?? '');
            if ($key === '' || ! array_key_exists('active_version', $row)) {
                continue;
            }
            $set = FieldSet::query()->where('key', $key)->first();
            if ($set === null) {
                $report->conflict('Nach Apply fehlt field_set: '.$key);

                continue;
            }
            $currentId = (int) ($set->getAttributes()['active_version_id'] ?? 0);
            if ($row['active_version'] === null) {
                if ($currentId > 0) {
                    $report->conflict('Nach Apply: active_version_id gesetzt trotz Paket NULL: '.$key);
                }

                continue;
            }
            $version = (int) $row['active_version'];
            $versionModel = $this->findFieldSetVersion($key, $version);
            if ($versionModel === null || $currentId !== (int) $versionModel->id
                || (int) $versionModel->field_set_id !== (int) $set->id) {
                $report->conflict('Nach Apply: active_version_id passt nicht zum Paket: '.$key);
            }
        }

        foreach ($data['data/field_set_version_fields.json'] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $setKey = (string) ($row['field_set_key'] ?? '');
            $version = (int) ($row['version'] ?? 0);
            $defKey = (string) ($row['field_definition_key'] ?? '');
            $revision = (int) ($row['revision'] ?? 0);
            $setVersion = $this->findFieldSetVersion($setKey, $version);
            $definition = FieldDefinition::query()->where('key', $defKey)->first();
            $revisionModel = $this->findFieldDefinitionRevision($defKey, $revision);
            if ($setVersion === null || $definition === null || $revisionModel === null) {
                $report->conflict('Nach Apply fehlt field_set_version_field-Referenz: '.$setKey.'|'.$version.'|'.$defKey);

                continue;
            }
            $link = FieldSetVersionField::query()
                ->where('field_set_version_id', $setVersion->id)
                ->where('field_definition_id', $definition->id)
                ->first();
            if ($link === null) {
                $report->conflict('Nach Apply fehlt field_set_version_field: '.$setKey.'|'.$version.'|'.$defKey);

                continue;
            }
            $linkedRevisionId = (int) ($link->getAttributes()['field_definition_revision_id'] ?? 0);
            if ($linkedRevisionId !== (int) $revisionModel->id
                || (int) $revisionModel->field_definition_id !== (int) $definition->id) {
                $report->conflict('Nach Apply: field_definition_revision_id passt nicht: '.$setKey.'|'.$version.'|'.$defKey);
            }
        }

        foreach ($data['data/field_set_assignments.json'] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $setKey = (string) ($row['field_set_key'] ?? '');
            $process = FieldAppliesTo::tryFrom((string) ($row['applies_to_process'] ?? ''));
            $layer = FieldSetAssignmentTargetLayer::tryFrom((string) ($row['target_layer'] ?? ''));
            if ($process === null || $layer === null) {
                $report->conflict('Nach Apply: field_set_assignment Schlüssel ungültig.');

                continue;
            }
            $categoryKey = isset($row['category_key']) && is_string($row['category_key']) ? $row['category_key'] : null;
            $mediumCode = isset($row['medium_code']) && is_string($row['medium_code']) ? $row['medium_code'] : null;
            $semantic = $this->assignmentSemanticKey($setKey, $process->value, $layer, $categoryKey, $mediumCode);
            $matches = $this->findAssignmentsBySemantic($setKey, $process, $layer, $categoryKey, $mediumCode);
            if ($matches->count() !== 1) {
                $report->conflict('Nach Apply: field_set_assignment nicht eindeutig: '.$semantic);

                continue;
            }
            $existing = $matches->first();
            if ($existing === null
                || ! $this->assignmentTargetMatchesPackage($existing, $layer, $categoryKey, $mediumCode, $report, $semantic)) {
                continue;
            }
            if ((bool) $existing->is_active !== (bool) $row['is_active']
                || (int) $existing->sort !== (int) $row['sort']) {
                $report->conflict('Nach Apply: field_set_assignment Attribute abweichend: '.$semantic);
            }
        }

        foreach ($data['data/inventories.json'] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $code = (string) ($row['code'] ?? '');
            if ($code === '') {
                continue;
            }
            $inventory = Inventory::query()->where('code', $code)->first();
            if ($inventory === null) {
                $report->conflict('Nach Apply fehlt Inventar: '.$code);

                continue;
            }
            $orgId = (int) ($inventory->getAttributes()['organization_id'] ?? 0);
            if ($orgId < 1 || ! Organization::query()->whereKey($orgId)->exists()) {
                $report->conflict('Nach Apply: Inventar ohne Organisation: '.$code);
            }
        }

        foreach ($data['data/advertising_media.json'] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $code = (string) ($row['code'] ?? '');
            $categoryKey = (string) ($row['category_key'] ?? '');
            $medium = AdvertisingMedium::query()->where('code', $code)->first();
            $category = AdvertisingCategory::query()->where('key', $categoryKey)->first();
            if ($medium === null || $category === null
                || (int) $medium->category_id !== (int) $category->id) {
                $report->conflict('Nach Apply: Medium-Kategorie-Referenz fehlerhaft: '.$code);
            }
        }

        foreach ($data['data/inventory_medium_rules.json'] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $inventory = Inventory::query()->where('code', (string) ($row['inventory_code'] ?? ''))->first();
            $medium = AdvertisingMedium::query()->where('code', (string) ($row['medium_code'] ?? ''))->first();
            if ($inventory === null || $medium === null) {
                $report->conflict('Nach Apply: inventory_medium_rule Referenz fehlt.');

                continue;
            }
            $exists = InventoryMediumRule::query()
                ->where('inventory_id', $inventory->id)
                ->where('advertising_medium_id', $medium->id)
                ->exists();
            if (! $exists) {
                $report->conflict('Nach Apply fehlt inventory_medium_rule: '.$inventory->code.'|'.$medium->code);
            }
        }

        foreach ($data['data/price_lists.json'] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $inventory = Inventory::query()->where('code', (string) ($row['inventory_code'] ?? ''))->first();
            if ($inventory === null) {
                $report->conflict('Nach Apply: Preisliste ohne Inventar.');

                continue;
            }
            $list = PriceList::query()
                ->where('inventory_id', $inventory->id)
                ->where('year', (int) ($row['year'] ?? 0))
                ->where('revision_number', (int) ($row['revision_number'] ?? 0))
                ->first();
            if ($list === null) {
                $report->conflict('Nach Apply fehlt Preisliste für Inventar '.$inventory->code);
            }
        }

        foreach ($data['data/oa_tkp_price_lists.json'] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $list = OaTkpPriceList::query()
                ->where('year', (int) ($row['year'] ?? 0))
                ->where('revision_number', (int) ($row['revision_number'] ?? 0))
                ->first();
            if ($list === null) {
                $report->conflict('Nach Apply fehlt OA-TKP-Liste.');

                continue;
            }
            $items = $row['items'] ?? [];
            if (! is_array($items)) {
                continue;
            }
            foreach ($items as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $inventory = Inventory::query()->where('code', (string) ($item['inventory_code'] ?? ''))->first();
                $medium = AdvertisingMedium::query()->where('code', (string) ($item['medium_code'] ?? ''))->first();
                if ($inventory === null || $medium === null) {
                    $report->conflict('Nach Apply: OA-TKP-Item-Referenz fehlt.');

                    continue;
                }
                $exists = OaTkpPriceListItem::query()
                    ->where('oa_tkp_price_list_id', $list->id)
                    ->where('inventory_id', $inventory->id)
                    ->where('advertising_medium_id', $medium->id)
                    ->exists();
                if (! $exists) {
                    $report->conflict('Nach Apply fehlt OA-TKP-Item.');
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function packageWillCreateOrganization(array $data): bool
    {
        $org = $data['data/organization.json'] ?? null;

        return is_array($org) && isset($org['name']) && is_string($org['name']) && $org['name'] !== '';
    }

    /**
     * @return int|null|false false = Konflikt gemeldet
     */
    private function resolveMethodId(
        string $key,
        MasterDataImportPlanContext $context,
        MasterDataImportReport $report,
    ): int|null|false {
        $id = CalculationMethod::query()->where('key', $key)->value('id');
        if ($id !== null) {
            return (int) $id;
        }
        if ($context->willHaveMethod($key)) {
            return null;
        }
        $report->conflict('method_key unbekannt: '.$key);

        return false;
    }

    private function categoryResolvable(
        string $key,
        MasterDataImportPlanContext $context,
        MasterDataImportReport $report,
    ): bool {
        if (AdvertisingCategory::query()->where('key', $key)->exists() || $context->willHaveCategory($key)) {
            return true;
        }
        $report->conflict('category_key unbekannt: '.$key);

        return false;
    }

    private function mediumResolvable(
        string $code,
        MasterDataImportPlanContext $context,
        MasterDataImportReport $report,
    ): bool {
        if (AdvertisingMedium::query()->where('code', $code)->exists() || $context->willHaveMedium($code)) {
            return true;
        }
        $report->conflict('medium_code unbekannt: '.$code);

        return false;
    }

    private function inventoryResolvable(
        string $code,
        MasterDataImportPlanContext $context,
        MasterDataImportReport $report,
    ): bool {
        if ($code === '') {
            $report->conflict('inventory_code fehlt.');

            return false;
        }
        if (Inventory::query()->where('code', $code)->exists() || $context->willHaveInventory($code)) {
            return true;
        }
        $report->conflict('inventory_code unbekannt: '.$code);

        return false;
    }

    private function fieldDefinitionResolvable(
        string $key,
        MasterDataImportPlanContext $context,
        MasterDataImportReport $report,
    ): bool {
        if (FieldDefinition::query()->where('key', $key)->exists() || $context->willHaveFieldDefinition($key)) {
            return true;
        }
        $report->conflict('field_definition_key unbekannt: '.$key);

        return false;
    }

    private function fieldDefinitionRevisionResolvable(
        string $defKey,
        int $revision,
        MasterDataImportPlanContext $context,
        MasterDataImportReport $report,
    ): bool {
        if ($this->findFieldDefinitionRevision($defKey, $revision) !== null
            || $context->willHaveFieldDefinitionRevision($defKey, $revision)
            || $context->packageHasFieldDefinitionRevision($defKey, $revision)) {
            return true;
        }
        $report->conflict('field_definition_revision unbekannt: '.$defKey.'|'.$revision);

        return false;
    }

    private function fieldSetResolvable(
        string $key,
        MasterDataImportPlanContext $context,
        MasterDataImportReport $report,
    ): bool {
        if (FieldSet::query()->where('key', $key)->exists() || $context->willHaveFieldSet($key)) {
            return true;
        }
        $report->conflict('field_set_key unbekannt: '.$key);

        return false;
    }

    private function fieldSetVersionResolvable(
        string $setKey,
        int $version,
        MasterDataImportPlanContext $context,
        MasterDataImportReport $report,
    ): bool {
        if ($this->findFieldSetVersion($setKey, $version) !== null
            || $context->willHaveFieldSetVersion($setKey, $version)) {
            return true;
        }
        $report->conflict('field_set_version unbekannt: '.$setKey.'|'.$version);

        return false;
    }

    private function platformResolvable(
        string $code,
        MasterDataImportPlanContext $context,
        MasterDataImportReport $report,
    ): bool {
        if (OaPlatform::query()->where('code', $code)->exists() || $context->willHavePlatform($code)) {
            return true;
        }
        $report->conflict('platform_code unbekannt: '.$code);

        return false;
    }

    private function rawDecimalAttribute(object $model, string $attribute): mixed
    {
        if (! method_exists($model, 'getAttributes') || ! method_exists($model, 'getTable')) {
            $value = $model->{$attribute} ?? null;

            return is_float($value) ? $this->decimalFloatToCanonicalString($value) : $value;
        }

        /** @var array<string, mixed> $attributes */
        $attributes = $model->getAttributes();
        $value = $attributes[$attribute] ?? null;
        if (! is_float($value)) {
            return $value;
        }

        // SQLite/PDO liefert DECIMAL ggf. als Float — als Text nachlesen.
        if (! method_exists($model, 'getKey') || ! method_exists($model, 'getKeyName')) {
            return $this->decimalFloatToCanonicalString($value);
        }

        $key = $model->getKey();
        if ($key === null) {
            return $this->decimalFloatToCanonicalString($value);
        }

        $pdo = DB::connection()->getPdo();
        $previous = $pdo->getAttribute(PDO::ATTR_STRINGIFY_FETCHES);
        $pdo->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, true);
        try {
            $raw = DB::table($model->getTable())
                ->where($model->getKeyName(), $key)
                ->value($attribute);
        } finally {
            $pdo->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, $previous);
        }

        return is_float($raw) ? $this->decimalFloatToCanonicalString($raw) : $raw;
    }

    /**
     * Letzter Ausweg nur für Driver-Floats; keine Rechenoperationen.
     */
    private function decimalFloatToCanonicalString(float $value): string
    {
        $formatted = sprintf('%.16F', $value);
        $trimmed = rtrim(rtrim($formatted, '0'), '.');

        return $trimmed === '-0' ? '0' : $trimmed;
    }

    private function canonicalJson(mixed $payload): string
    {
        if ($payload === null) {
            return 'null';
        }
        $normalized = is_array($payload) ? $this->canonicalizeJsonArray($payload) : $payload;
        $json = json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new MasterDataTransferException('JSON-Kanonisierung fehlgeschlagen.');
        }

        return $json;
    }

    /**
     * @param  array<string, mixed>|list<mixed>  $payload
     * @return array<string, mixed>|list<mixed>
     */
    private function canonicalizeJsonArray(array $payload): array
    {
        if (! array_is_list($payload)) {
            ksort($payload);
        }
        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $payload[$key] = $this->canonicalizeJsonArray($value);
            }
        }

        return $payload;
    }
}
