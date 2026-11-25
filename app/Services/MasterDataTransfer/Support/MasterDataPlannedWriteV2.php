<?php

namespace App\Services\MasterDataTransfer\Support;

use App\Services\MasterDataTransfer\MasterDataTransferException;
use Closure;

/**
 * Geplante Schreiboperation mit deklarierter Zieltabelle (kein ungeprüftes Closure).
 * Whitelist-Prüfung erfolgt zentral über MasterDataWriteWhitelistGuardV2 vor dem Apply.
 */
final class MasterDataPlannedWriteV2
{
    public readonly string $operation;

    /** @var list<string> */
    public readonly array $tables;

    /**
     * @param  string  $operation  nur create|update erlaubt
     * @param  list<string>|string  $tables  eine oder mehrere Zieltabelle(n)
     */
    public function __construct(
        string $operation,
        array|string $tables,
        private readonly Closure $execute,
    ) {
        if (! in_array($operation, ['create', 'update'], true)) {
            throw new MasterDataTransferException(
                'Whitelist-STOP: unerlaubter Operationstyp im Plan: '.$operation,
            );
        }
        $this->operation = $operation;

        $normalized = array_values(array_unique(is_string($tables) ? [$tables] : $tables));
        sort($normalized);
        if ($normalized === []) {
            throw new MasterDataTransferException('Whitelist-STOP: geplante Write ohne Zieltabelle.');
        }

        $this->tables = $normalized;
    }

    public function run(): void
    {
        ($this->execute)();
    }
}
