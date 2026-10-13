<?php

namespace App\Exceptions\Crm;

use RuntimeException;

/**
 * Signal: unter Sperre wurden zusätzliche Account-IDs benötigt.
 * Aufrufer starten die Transaktion kontrolliert mit der vereinigten Menge neu.
 */
final class CrmAccountLockSetExpandedException extends RuntimeException
{
    /**
     * @param  list<int>  $accountIds
     */
    public function __construct(
        private readonly array $accountIds,
    ) {
        parent::__construct('Account-Sperrmenge muss erweitert werden.');
    }

    /**
     * @return list<int>
     */
    public function accountIds(): array
    {
        return $this->accountIds;
    }
}
