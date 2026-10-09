<?php

namespace App\Exceptions;

use App\Models\CrmImport;
use RuntimeException;

final class CrmImportConflictException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?CrmImport $import = null,
    ) {
        parent::__construct($message);
    }
}
