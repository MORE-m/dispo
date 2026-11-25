<?php

namespace App\Services\MasterDataTransfer\Import;

/**
 * @phpstan-type RowReport array{entity: string, key: string, action: string, detail?: string}
 */
final class MasterDataImportReport
{
    /** @var list<RowReport> */
    private array $rows = [];

    /** @var list<string> */
    private array $conflicts = [];

    /** @var list<string> */
    private array $skipped = [];

    private string $packageIntegrity = 'pass';

    private string $protectionSnapshots = 'pass';

    private string $priceListPins = 'pass';

    private string $whitelist = 'pass';

    private string $referentialIntegrity = 'pass';

    private ?string $packageSha256 = null;

    private ?string $operationalDatabase = null;

    /** @var array<string, int> */
    private array $protectedTableCounts = [];

    private int $protectedTableCount = 0;

    private int $priceListBindingCount = 0;

    private int $priceListPinCount = 0;

    public function add(string $entity, string $key, string $action, ?string $detail = null): void
    {
        $row = ['entity' => $entity, 'key' => $key, 'action' => $action];
        if ($detail !== null) {
            $row['detail'] = $detail;
        }
        $this->rows[] = $row;
    }

    public function conflict(string $message): void
    {
        $this->conflicts[] = $message;
    }

    public function skip(string $message): void
    {
        $this->skipped[] = $message;
    }

    public function hasConflicts(): bool
    {
        return $this->conflicts !== [];
    }

    /**
     * @return list<string>
     */
    public function conflictMessages(): array
    {
        return $this->conflicts;
    }

    public function setPackageIntegrity(string $status): void
    {
        $this->packageIntegrity = $status;
    }

    public function setProtectionSnapshots(string $status): void
    {
        $this->protectionSnapshots = $status;
    }

    public function setPriceListPins(string $status): void
    {
        $this->priceListPins = $status;
    }

    public function setWhitelist(string $status): void
    {
        $this->whitelist = $status;
    }

    public function setReferentialIntegrity(string $status): void
    {
        $this->referentialIntegrity = $status;
    }

    public function setPackageSha256(string $sha256): void
    {
        $this->packageSha256 = strtolower($sha256);
    }

    public function setOperationalDatabase(string $database): void
    {
        $this->operationalDatabase = $database;
    }

    /**
     * @param  array<string, array{count: int, digest: string}>  $tables
     */
    public function setProtectedTableStats(array $tables): void
    {
        $counts = [];
        foreach ($tables as $name => $meta) {
            $counts[$name] = (int) $meta['count'];
        }
        ksort($counts);
        $this->protectedTableCounts = $counts;
        $this->protectedTableCount = count($counts);
    }

    public function setPriceListPinStats(int $bindingCount, int $listCount): void
    {
        $this->priceListBindingCount = $bindingCount;
        $this->priceListPinCount = $listCount;
    }

    /**
     * @return array{
     *     dry_run: bool,
     *     created: int,
     *     unchanged: int,
     *     conflicts: list<string>,
     *     skipped: list<string>,
     *     rows: list<RowReport>,
     *     counts_by_action: array<string, int>,
     *     security: array{
     *         package_integrity: string,
     *         package_sha256: ?string,
     *         operational_database: ?string,
     *         protection_snapshots: string,
     *         protected_table_count: int,
     *         protected_table_counts: array<string, int>,
     *         price_list_pins: string,
     *         price_list_binding_count: int,
     *         price_list_pin_count: int,
     *         whitelist: string,
     *         referential_integrity: string,
     *         result: string
     *     }
     * }
     */
    public function toArray(bool $dryRun): array
    {
        $counts = [];
        foreach ($this->rows as $row) {
            $action = $row['action'];
            $counts[$action] = ($counts[$action] ?? 0) + 1;
        }

        $stopped = $this->hasConflicts()
            || $this->packageIntegrity === 'stop'
            || $this->protectionSnapshots === 'stop'
            || $this->priceListPins === 'stop'
            || $this->whitelist === 'stop'
            || $this->referentialIntegrity === 'stop';

        return [
            'dry_run' => $dryRun,
            'created' => $counts['create'] ?? 0,
            'unchanged' => $counts['unchanged'] ?? 0,
            'conflicts' => $this->conflicts,
            'skipped' => $this->skipped,
            'rows' => $this->rows,
            'counts_by_action' => $counts,
            'security' => [
                'package_integrity' => $this->packageIntegrity,
                'package_sha256' => $this->packageSha256,
                'operational_database' => $this->operationalDatabase,
                'protection_snapshots' => $this->protectionSnapshots,
                'protected_table_count' => $this->protectedTableCount,
                'protected_table_counts' => $this->protectedTableCounts,
                'price_list_pins' => $this->priceListPins,
                'price_list_binding_count' => $this->priceListBindingCount,
                'price_list_pin_count' => $this->priceListPinCount,
                'whitelist' => $this->whitelist,
                'referential_integrity' => $this->referentialIntegrity,
                'result' => $stopped ? 'STOP' : 'PASS',
            ],
        ];
    }
}
