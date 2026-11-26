<?php

namespace App\Services\MasterDataTransfer\Import;

use App\Models\Inventory;
use App\Models\OaTkpPriceList;
use App\Models\PriceList;
use App\Models\ProductionPriceList;
use App\Services\MasterDataTransfer\MasterDataTransferContractV2;
use App\Services\MasterDataTransfer\MasterDataTransferException;
use App\Services\MasterDataTransfer\Support\MasterDataApplyReceiptV2;
use App\Services\MasterDataTransfer\Support\MasterDataBackupProofV2;
use App\Services\MasterDataTransfer\Support\MasterDataImportRecordValidatorV2;
use App\Services\MasterDataTransfer\Support\MasterDataOperationalDatabaseGuardV2;
use App\Services\MasterDataTransfer\Support\MasterDataOperationalDryRunReadGuardV2;
use App\Services\MasterDataTransfer\Support\MasterDataPackage;
use App\Services\MasterDataTransfer\Support\MasterDataPriceListIndexV2;
use App\Services\MasterDataTransfer\Support\MasterDataPriceListPinGuardV2;
use App\Services\MasterDataTransfer\Support\MasterDataPriceStatusPlannerV2;
use App\Services\MasterDataTransfer\Support\MasterDataProofSignerV2;
use App\Services\MasterDataTransfer\Support\MasterDataProtectedStateSnapshotV2;
use App\Services\MasterDataTransfer\Support\MasterDataWriteWhitelistGuardV2;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * EPIC-03-F Slice 7: operative Preisstatus-Phase (Dry-Run + Apply), paketgetrieben und transaktional.
 *
 * Bewusst KEIN Aufruf der Admin-Writer (User-Actor/Audit/Lock-Version-Formularfluss): Die Statusphase
 * arbeitet nur mit dem SHA256-gebundenen Paket, prüft dieselben Vollständigkeitsregeln (S7-PO-04) und
 * schreibt ausschließlich Statusfelder (status, published_at, archived_at, lock_version, updated_at).
 * Inhalte, Items, Pins, Benutzer, CRM, Kalkulationen und Dispo bleiben unverändert (Whitelist/Query-Log-Prüfung).
 */
final class MasterDataPriceStatusApplierV2
{
    private const STATUS_TABLES = [
        MasterDataPriceListIndexV2::FAMILY_SPOT => 'price_lists',
        MasterDataPriceListIndexV2::FAMILY_PRODUCTION => 'production_price_lists',
        MasterDataPriceListIndexV2::FAMILY_OA_TKP => 'oa_tkp_price_lists',
    ];

    private const ALLOWED_STATUS_COLUMNS = ['status', 'published_at', 'archived_at', 'lock_version', 'updated_at'];

    public function __construct(
        private readonly MasterDataPackage $packages,
        private readonly MasterDataProtectedStateSnapshotV2 $protectedSnapshots = new MasterDataProtectedStateSnapshotV2,
        private readonly MasterDataPriceListPinGuardV2 $priceListPins = new MasterDataPriceListPinGuardV2,
        private readonly MasterDataWriteWhitelistGuardV2 $writeGuard = new MasterDataWriteWhitelistGuardV2,
        private readonly MasterDataOperationalDatabaseGuardV2 $operationalDatabaseGuard = new MasterDataOperationalDatabaseGuardV2,
        private readonly MasterDataOperationalDryRunReadGuardV2 $operationalReadGuard = new MasterDataOperationalDryRunReadGuardV2,
        private readonly MasterDataBackupProofV2 $backupProof = new MasterDataBackupProofV2,
        private readonly MasterDataApplyReceiptV2 $receipts = new MasterDataApplyReceiptV2,
        private readonly MasterDataPriceStatusPlannerV2 $planner = new MasterDataPriceStatusPlannerV2,
        private readonly MasterDataPriceListIndexV2 $index = new MasterDataPriceListIndexV2,
    ) {}

    /**
     * Schreibfreier Plan der Statusphase.
     *
     * @return array<string, mixed>
     */
    public function dryRun(
        string $packagePath,
        string $confirmDatabase,
        string $requirePackageSha256,
        string $requireBackupProof,
        string $requireContentReceipt,
    ): array {
        $pre = $this->preflight(false, $packagePath, $confirmDatabase, $requirePackageSha256, $requireBackupProof, $requireContentReceipt);

        $this->operationalReadGuard->enter();
        try {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $protectedBefore = $this->protectedSnapshots->capture();
            $pins = $this->priceListPins->capture();
            $plan = $this->planner->plan($pre['package']['data'], $pre['sha'], $pre['database'], $pins);

            $protectedAfter = $this->protectedSnapshots->capture();
            $drift = array_merge(
                $this->protectedSnapshots->diff($protectedBefore, $protectedAfter),
                $this->priceListPins->diff($pins, $this->priceListPins->capture()),
            );
            if ($drift !== []) {
                throw new MasterDataTransferException('Dry-Run: Schutzbestand während der Planung verändert.', $drift);
            }
            $this->assertQueryLogIsReadOnly();
            $this->assertPackageUnchanged($packagePath, $pre['sha'], 'Dry-Run');

            return $this->buildReport(true, $plan, $pre, $protectedBefore, $pins);
        } finally {
            DB::disableQueryLog();
            $this->operationalReadGuard->leave();
        }
    }

    /**
     * Transaktionale Statusphase. Setzt einen zuvor per Dry-Run ermittelten, unveränderten Plan-Hash voraus.
     *
     * @return array<string, mixed>
     */
    public function apply(
        string $packagePath,
        string $confirmDatabase,
        string $requirePackageSha256,
        string $requireBackupProof,
        string $requireContentReceipt,
        string $requirePlanHash,
    ): array {
        $planHash = strtolower(trim($requirePlanHash));
        if (preg_match('/^[a-f0-9]{64}$/', $planHash) !== 1) {
            throw new MasterDataTransferException(
                '--require-price-status-plan-hash ist Pflicht und muss ein 64-stelliger Hex-SHA256 aus dem Dry-Run sein.',
            );
        }

        $pre = $this->preflight(true, $packagePath, $confirmDatabase, $requirePackageSha256, $requireBackupProof, $requireContentReceipt);

        $protectedBefore = $this->protectedSnapshots->capture();
        $pinsBefore = $this->priceListPins->capture();
        $plan = $this->planner->plan($pre['package']['data'], $pre['sha'], $pre['database'], $pinsBefore);
        $this->assertPlanApplicable($plan, $planHash);

        $applied = DB::transaction(function () use ($pre, $planHash, $protectedBefore, $pinsBefore, $plan): array {
            $this->lockScope($plan);

            // Plan darf nicht veraltet sein: unter Sperre erneut lesen und gegen Hash + Schutzbestand prüfen.
            $protectedPre = $this->protectedSnapshots->capture();
            $pinsPre = $this->priceListPins->capture();
            $drift = array_merge(
                $this->protectedSnapshots->diff($protectedBefore, $protectedPre),
                $this->priceListPins->diff($pinsBefore, $pinsPre),
            );
            if ($drift !== []) {
                throw new MasterDataTransferException('Statusphase fail-closed: Schutzbestand zwischen Planung und Apply verändert.', $drift);
            }
            $planInTx = $this->planner->plan($pre['package']['data'], $pre['sha'], $pre['database'], $pinsPre);
            $this->assertPlanApplicable($planInTx, $planHash);

            $this->writeGuard->startMonitoring();
            DB::flushQueryLog();
            DB::enableQueryLog();
            try {
                $changes = $this->executePlan($planInTx);
                $this->assertQueryLogIsStatusOnly();
                $this->writeGuard->assertObservedWritesAllowed();
                $this->verifyAfterApply($planInTx);

                $protectedAfter = $this->protectedSnapshots->capture();
                $pinsAfter = $this->priceListPins->capture();
                $postConflicts = array_merge(
                    $this->protectedSnapshots->diff($protectedBefore, $protectedAfter),
                    $this->priceListPins->diff($pinsBefore, $pinsAfter),
                );
                if ($postConflicts !== []) {
                    throw new MasterDataTransferException('Statusphase fail-closed: Schutz-/Pin-Drift nach Apply.', $postConflicts);
                }
            } finally {
                DB::disableQueryLog();
                $this->writeGuard->stopMonitoring();
            }

            return ['plan' => $planInTx, 'changes' => $changes, 'protected' => $protectedBefore, 'pins' => $pinsBefore];
        });

        $this->assertPackageUnchanged($packagePath, $pre['sha'], 'Apply');

        $report = $this->buildReport(false, $applied['plan'], $pre, $applied['protected'], $applied['pins']);
        $report['price_status']['applied_changes'] = $applied['changes'];

        try {
            $receipt = $this->receipts->writeStatusReceipt([
                'run_id' => 'run-'.Str::uuid()->toString(),
                'package_sha256' => $pre['sha'],
                'database_name' => $pre['database'],
                'result' => 'PASS',
                'report_hash' => hash('sha256', MasterDataProofSignerV2::canonicalJson($report)),
                'content_fingerprints_summary' => MasterDataPriceListIndexV2::packageFingerprintSummary($pre['package']['data']),
                'backup_proof' => [
                    'backup_run_id' => $pre['proof']['backup_run_id'],
                    'meta_sha256' => $pre['proof']['meta_sha256'],
                    'dump_sha256' => $pre['proof']['dump_sha256'],
                    'phase' => $pre['proof']['phase'],
                ],
                'plan_hash' => $planHash,
                'content_receipt_ref' => [
                    'receipt_id' => $pre['receipt']['receipt_id'],
                    'signature' => $pre['receipt']['signature'],
                ],
            ]);
        } catch (MasterDataTransferException $e) {
            throw new MasterDataTransferException(
                'Statusphase wurde committet, aber das Status-Receipt konnte nicht geschrieben werden: '.$e->getMessage()
                .' Neuen Dry-Run ausführen (alle Listen unchanged) und den Apply mit dessen Plan-Hash wiederholen erzeugt das Receipt nach.',
                $e->errors,
            );
        }

        $report['receipt'] = [
            'receipt_id' => $receipt['receipt_id'],
            'receipt_path' => $receipt['receipt_path'],
            'completed_at' => $receipt['completed_at'],
            'signature' => $receipt['signature'],
        ];

        return $report;
    }

    /**
     * @return array{database: string, sha: string, package: array{schema_version: string, data: array<string, mixed>}, receipt: array<string, mixed>, proof: array<string, mixed>}
     */
    private function preflight(
        bool $forApply,
        string $packagePath,
        string $confirmDatabase,
        string $requirePackageSha256,
        string $requireBackupProof,
        string $requireContentReceipt,
    ): array {
        $required = strtolower(trim($requirePackageSha256));
        if ($required === '' || preg_match('/^[a-f0-9]{64}$/', $required) !== 1) {
            throw new MasterDataTransferException(
                '--require-package-sha256 ist Pflicht und muss ein 64-stelliger Hex-SHA256 sein.',
            );
        }

        $database = $forApply
            ? $this->operationalDatabaseGuard->assertApplyAllowed($confirmDatabase)
            : $this->operationalDatabaseGuard->assertDryRunAllowed($confirmDatabase);

        $sha = strtolower($this->packages->computePackageSha256($packagePath));
        if (! hash_equals($sha, $required)) {
            throw new MasterDataTransferException(sprintf(
                'Paket-SHA256 stimmt nicht (--require-package-sha256). erwartet=%s berechnet=%s',
                $required,
                $sha,
            ));
        }

        $package = $this->packages->load($packagePath);
        if ($package['schema_version'] !== MasterDataTransferContractV2::SCHEMA_VERSION) {
            throw new MasterDataTransferException(sprintf(
                'Preisstatus-Phase für schema_version=%s nicht freigegeben (erwartet %s).',
                $package['schema_version'],
                MasterDataTransferContractV2::SCHEMA_VERSION,
            ));
        }
        MasterDataImportRecordValidatorV2::validate($package['data']);

        // S7-PO-03: Receipt desselben Pakets/DB/Umgebung; S7-PO-02: Backup B nach dem Inhalts-Apply.
        $receipt = $this->receipts->assertValidContentReceipt($requireContentReceipt, $sha, $database);
        $proof = $this->backupProof->assertValid(
            $requireBackupProof,
            $database,
            $sha,
            MasterDataBackupProofV2::PHASE_POST_CONTENT_APPLY,
            ['receipt_id' => $receipt['receipt_id'], 'signature' => $receipt['signature'], 'completed_at' => $receipt['completed_at']],
        );

        return ['database' => $database, 'sha' => $sha, 'package' => $package, 'receipt' => $receipt, 'proof' => $proof];
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    private function assertPlanApplicable(array $plan, string $requiredHash): void
    {
        if (! hash_equals($plan['plan_hash'], $requiredHash)) {
            throw new MasterDataTransferException(
                'Plan-Drift: --require-price-status-plan-hash stimmt nicht mit dem aktuellen Plan überein (Staging/Paket verändert seit Dry-Run).',
                ['erwartet='.$requiredHash, 'aktuell='.$plan['plan_hash']],
            );
        }
        if ($plan['blocked_count'] > 0) {
            throw new MasterDataTransferException(
                'Statusphase fail-closed: Plan enthält blockierte Listen — kein Apply.',
                $plan['blocked_messages'],
            );
        }
    }

    /**
     * Zeilensperren für alle betroffenen Slots (MySQL; unter SQLite No-Op). Der OA-Jahres-GET_LOCK wird bewusst
     * nicht verwendet (unterstützt nur ein Jahr gleichzeitig); stattdessen Zeilensperren über alle OA-Listen der Jahre.
     *
     * @param  array<string, mixed>  $plan
     */
    private function lockScope(array $plan): void
    {
        $writeActions = [
            MasterDataPriceStatusPlannerV2::ACTION_ACTIVATE,
            MasterDataPriceStatusPlannerV2::ACTION_ARCHIVE,
            MasterDataPriceStatusPlannerV2::ACTION_ACTIVATE_WITH_ARCHIVE_PREDECESSOR,
        ];
        $locked = [];
        foreach ($plan['lists'] as $row) {
            if (! in_array($row['action'], $writeActions, true)) {
                continue;
            }
            $lockKey = $row['family'].'|'.$row['slot'];
            if (isset($locked[$lockKey])) {
                continue;
            }
            $locked[$lockKey] = true;
            [$year, $inventoryCode, $type] = $this->slotParts($row);
            switch ($row['family']) {
                case MasterDataPriceListIndexV2::FAMILY_SPOT:
                    $inventory = Inventory::query()->where('code', $inventoryCode)->lockForUpdate()->first();
                    if ($inventory !== null) {
                        PriceList::query()->where('inventory_id', $inventory->id)->where('year', $year)->orderBy('id')->lockForUpdate()->get();
                    }
                    break;
                case MasterDataPriceListIndexV2::FAMILY_PRODUCTION:
                    $inventory = Inventory::query()->where('code', $inventoryCode)->lockForUpdate()->first();
                    if ($inventory !== null) {
                        ProductionPriceList::query()
                            ->where('inventory_id', $inventory->id)
                            ->where('production_type', $type)
                            ->where('year', $year)->orderBy('id')->lockForUpdate()->get();
                    }
                    break;
                case MasterDataPriceListIndexV2::FAMILY_OA_TKP:
                    OaTkpPriceList::query()->where('year', $year)->orderBy('id')->lockForUpdate()->get();
                    break;
            }
        }
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{0: int, 1: string, 2: string}
     */
    private function slotParts(array $row): array
    {
        $parts = explode('|', (string) $row['slot']);

        return match ($row['family']) {
            MasterDataPriceListIndexV2::FAMILY_SPOT => [(int) $parts[1], $parts[0], ''],
            MasterDataPriceListIndexV2::FAMILY_PRODUCTION => [(int) $parts[2], $parts[0], $parts[1]],
            default => [(int) $parts[0], '', ''],
        };
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return list<array<string, mixed>>
     */
    private function executePlan(array $plan): array
    {
        $order = array_flip(MasterDataPriceListIndexV2::FAMILIES);
        $rows = $plan['lists'];
        usort($rows, fn (array $a, array $b): int => [$order[$a['family']], $a['key']] <=> [$order[$b['family']], $b['key']]);

        $changes = [];
        foreach ($rows as $row) {
            $table = self::STATUS_TABLES[$row['family']];
            switch ($row['action']) {
                case MasterDataPriceStatusPlannerV2::ACTION_ARCHIVE:
                    $this->transition($table, (int) $row['staging_id'], (int) $row['staging_lock_version'], 'draft', 'archived');
                    $changes[] = ['family' => $row['family'], 'key' => $row['key'], 'from' => 'draft', 'to' => 'archived'];
                    break;
                case MasterDataPriceStatusPlannerV2::ACTION_ACTIVATE:
                    $this->transition($table, (int) $row['staging_id'], (int) $row['staging_lock_version'], 'draft', 'active');
                    $changes[] = ['family' => $row['family'], 'key' => $row['key'], 'from' => 'draft', 'to' => 'active'];
                    break;
                case MasterDataPriceStatusPlannerV2::ACTION_ACTIVATE_WITH_ARCHIVE_PREDECESSOR:
                    $predecessor = $row['predecessor'];
                    if (! is_array($predecessor)) {
                        throw new MasterDataTransferException('Plan fehlerhaft: Vorgänger fehlt für '.$row['key']);
                    }
                    // Vorgänger zuerst (One-Active-Constraint), danach Aktivierung — atomar in derselben Transaktion.
                    $this->transition($table, (int) $predecessor['id'], (int) $predecessor['lock_version'], 'active', 'archived');
                    $this->transition($table, (int) $row['staging_id'], (int) $row['staging_lock_version'], 'draft', 'active');
                    $changes[] = ['family' => $row['family'], 'key' => $predecessor['key'], 'from' => 'active', 'to' => 'archived', 'via' => 'activate_with_archive_predecessor:'.$row['key']];
                    $changes[] = ['family' => $row['family'], 'key' => $row['key'], 'from' => 'draft', 'to' => 'active'];
                    break;
                default:
                    break;
            }
        }

        return $changes;
    }

    /**
     * Compare-and-Set auf genau eine Zeile; nur Statusfelder.
     */
    private function transition(string $table, int $id, int $expectedLockVersion, string $from, string $to): void
    {
        $now = now();
        $payload = [
            'status' => $to,
            'lock_version' => DB::raw('lock_version + 1'),
            'updated_at' => $now,
        ];
        if ($to === 'active') {
            $payload['published_at'] = $now;
            $payload['archived_at'] = null;
        } else {
            $payload['archived_at'] = $now;
        }

        $affected = DB::table($table)
            ->where('id', $id)
            ->where('status', $from)
            ->where('lock_version', $expectedLockVersion)
            ->update($payload);
        if ($affected !== 1) {
            throw new MasterDataTransferException(sprintf(
                'Statuswechsel %s→%s für %s#%d fehlgeschlagen (Zeile verändert oder nicht vorhanden) — Rollback.',
                $from,
                $to,
                $table,
                $id,
            ));
        }
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    private function verifyAfterApply(array $plan): void
    {
        $touchedSlots = [];
        foreach ($plan['lists'] as $row) {
            $action = $row['action'];
            $expected = match ($action) {
                MasterDataPriceStatusPlannerV2::ACTION_ACTIVATE, MasterDataPriceStatusPlannerV2::ACTION_ACTIVATE_WITH_ARCHIVE_PREDECESSOR => 'active',
                MasterDataPriceStatusPlannerV2::ACTION_ARCHIVE => 'archived',
                default => (string) $row['staging_status'],
            };
            if ($row['archived_by_successor'] ?? null) {
                $expected = 'archived';
            }

            $entry = $this->entryForRow($row);
            $after = $this->index->stagingFor($entry);
            if ($after === null) {
                throw new MasterDataTransferException('Nachprüfung: Liste verschwunden: '.$row['key']);
            }
            if ($after['status'] !== $expected) {
                throw new MasterDataTransferException(sprintf('Nachprüfung: %s hat Status %s, erwartet %s.', $row['key'], $after['status'], $expected));
            }
            if ($after['fingerprint'] !== $row['staging_fingerprint']) {
                throw new MasterDataTransferException('Nachprüfung: Inhalt/Fingerabdruck verändert: '.$row['key']);
            }
            if (in_array($action, [
                MasterDataPriceStatusPlannerV2::ACTION_ACTIVATE,
                MasterDataPriceStatusPlannerV2::ACTION_ACTIVATE_WITH_ARCHIVE_PREDECESSOR,
                MasterDataPriceStatusPlannerV2::ACTION_ARCHIVE,
            ], true)) {
                $touchedSlots[$row['family'].'|'.$row['slot']] = $entry;
            }
            if (is_array($row['predecessor'] ?? null)) {
                $touchedSlots[$row['family'].'|'.$row['slot']] = $entry;
                $predecessorAfter = $this->index->stagingFor($this->entryForRow($row, (string) $row['predecessor']['key']));
                if ($predecessorAfter === null || $predecessorAfter['status'] !== 'archived') {
                    throw new MasterDataTransferException('Nachprüfung: Vorgänger '.$row['predecessor']['key'].' ist nicht archiviert.');
                }
                if ($predecessorAfter['fingerprint'] !== $row['predecessor']['fingerprint']) {
                    throw new MasterDataTransferException('Nachprüfung: Vorgänger-Inhalt verändert: '.$row['predecessor']['key']);
                }
            }
        }

        // One-Active je betroffenem Slot (S7-PO-01): höchstens eine aktive Liste.
        foreach ($touchedSlots as $slotKey => $entry) {
            $active = $this->index->activeInSlot($entry);
            if (count($active) > 1) {
                throw new MasterDataTransferException('Nachprüfung: mehr als eine aktive Liste im Slot '.$slotKey);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{family: string, year: int, revision: int, inventory_code: ?string, production_type: ?string}
     */
    private function entryForRow(array $row, ?string $keyOverride = null): array
    {
        [$year, $inventoryCode, $type] = $this->slotParts($row);
        if (preg_match('/r(\d+)$/', $keyOverride ?? (string) $row['key'], $m) !== 1) {
            throw new MasterDataTransferException('Plan fehlerhaft: Revision nicht ermittelbar: '.($keyOverride ?? $row['key']));
        }

        return [
            'family' => $row['family'],
            'year' => $year,
            'revision' => (int) $m[1],
            'inventory_code' => $inventoryCode === '' ? null : $inventoryCode,
            'production_type' => $type === '' ? null : $type,
        ];
    }

    private function assertQueryLogIsReadOnly(): void
    {
        foreach (DB::getQueryLog() as $entry) {
            $sql = strtolower(trim((string) $entry['query']));
            if (preg_match('/^(insert|update|delete|replace|alter|drop|create|truncate|rename|grant|revoke|call|load)\b/', $sql) === 1) {
                throw new MasterDataTransferException('Dry-Run hat Schreibzugriffe ausgelöst — Abbruch.');
            }
        }
    }

    /**
     * Innerhalb der Status-TX sind ausschließlich UPDATEs auf die drei Preislistentabellen mit Statusspalten erlaubt.
     */
    private function assertQueryLogIsStatusOnly(): void
    {
        foreach (DB::getQueryLog() as $entry) {
            $sql = strtolower(trim((string) $entry['query']));
            if (preg_match('/^(insert|update|delete|replace|alter|drop|create|truncate|rename|grant|revoke|call|load)\b/', $sql) !== 1) {
                continue;
            }
            if (preg_match('/^update\s+["`]?(price_lists|production_price_lists|oa_tkp_price_lists)["`]?\s+set\s+(.+?)\s+where\s/s', $sql, $m) !== 1) {
                throw new MasterDataTransferException('Statusphase STOP: nicht erlaubte Schreiboperation: '.mb_substr(preg_replace('/\s+/', ' ', $sql) ?? $sql, 0, 120));
            }
            preg_match_all('/["`]?([a-z_]+)["`]?\s*=/', $m[2], $columns);
            foreach ($columns[1] as $column) {
                if (! in_array($column, self::ALLOWED_STATUS_COLUMNS, true)) {
                    throw new MasterDataTransferException('Statusphase STOP: Spalte außerhalb der Status-Whitelist geändert: '.$column);
                }
            }
        }
    }

    private function assertPackageUnchanged(string $packagePath, string $expectedSha, string $label): void
    {
        $after = strtolower($this->packages->computePackageSha256($packagePath));
        if (! hash_equals($expectedSha, $after)) {
            throw new MasterDataTransferException(
                'Paket wurde während des '.$label.' verändert (SHA256-Drift) — Abbruch.',
                ['before='.$expectedSha, 'after='.$after],
            );
        }
    }

    /**
     * @param  array<string, mixed>  $plan
     * @param  array<string, mixed>  $pre
     * @param  array{tables: array<string, array{count: int, digest: string}>}  $protected
     * @param  array{binding_count: int, list_count: int}  $pins
     * @return array<string, mixed>
     */
    private function buildReport(bool $dryRun, array $plan, array $pre, array $protected, array $pins): array
    {
        $rows = [];
        $unchanged = 0;
        foreach ($plan['lists'] as $row) {
            $rows[] = [
                'entity' => 'price_status.'.$row['family'],
                'key' => $row['key'],
                'action' => $row['action'],
                'detail' => $row['reason'] ?? sprintf('%s→%s', (string) $row['staging_status'], (string) $row['source_status']),
            ];
            if (in_array($row['action'], [MasterDataPriceStatusPlannerV2::ACTION_UNCHANGED, MasterDataPriceStatusPlannerV2::ACTION_KEEP_DRAFT], true)) {
                $unchanged++;
            }
        }
        $blocked = $plan['blocked_count'] > 0;
        $tableCounts = [];
        foreach ($protected['tables'] as $name => $meta) {
            $tableCounts[$name] = (int) $meta['count'];
        }
        ksort($tableCounts);

        return [
            'mode' => $dryRun ? 'dry_run_price_status' : 'apply_price_status',
            'dry_run' => $dryRun,
            'created' => 0,
            'unchanged' => $unchanged,
            'conflicts' => $plan['blocked_messages'],
            'skipped' => [],
            'rows' => $rows,
            'counts_by_action' => $plan['counts_by_action'],
            'price_status' => [
                'plan_hash' => $plan['plan_hash'],
                'processing_contract_version' => $plan['processing_contract_version'],
                'plan' => $plan,
            ],
            'security' => [
                'package_integrity' => 'pass',
                'package_sha256' => $pre['sha'],
                'operational_database' => $pre['database'],
                'content_apply_receipt' => 'pass',
                'content_apply_receipt_id' => $pre['receipt']['receipt_id'],
                'backup_proof' => 'pass',
                'backup_proof_run_id' => $pre['proof']['backup_run_id'],
                'backup_proof_dump_sha256' => $pre['proof']['dump_sha256'],
                'protection_snapshots' => 'pass',
                'protected_table_count' => count($tableCounts),
                'protected_table_counts' => $tableCounts,
                'price_list_pins' => 'pass',
                'price_list_binding_count' => $pins['binding_count'],
                'price_list_pin_count' => $pins['list_count'],
                'whitelist' => 'pass',
                'result' => $blocked ? 'STOP' : 'PASS',
            ],
        ];
    }
}
