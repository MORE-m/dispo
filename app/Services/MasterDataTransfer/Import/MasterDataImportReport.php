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
     * @return array{
     *     dry_run: bool,
     *     created: int,
     *     unchanged: int,
     *     conflicts: list<string>,
     *     skipped: list<string>,
     *     rows: list<RowReport>,
     *     counts_by_action: array<string, int>
     * }
     */
    public function toArray(bool $dryRun): array
    {
        $counts = [];
        foreach ($this->rows as $row) {
            $action = $row['action'];
            $counts[$action] = ($counts[$action] ?? 0) + 1;
        }

        return [
            'dry_run' => $dryRun,
            'created' => $counts['create'] ?? 0,
            'unchanged' => $counts['unchanged'] ?? 0,
            'conflicts' => $this->conflicts,
            'skipped' => $this->skipped,
            'rows' => $this->rows,
            'counts_by_action' => $counts,
        ];
    }
}
