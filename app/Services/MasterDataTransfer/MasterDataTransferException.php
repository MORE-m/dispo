<?php

namespace App\Services\MasterDataTransfer;

use RuntimeException;

final class MasterDataTransferException extends RuntimeException
{
    /**
     * @param  list<string>  $errors
     */
    public function __construct(
        string $message,
        public readonly array $errors = [],
    ) {
        parent::__construct($message);
    }
}
