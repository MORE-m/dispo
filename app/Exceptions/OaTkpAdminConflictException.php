<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * PO-CALC-TKP-1D4c-4c: Optimistic-Lock / Paralleländerung (HTTP 409).
 */
final class OaTkpAdminConflictException extends ConflictHttpException
{
    public function __construct(string $message = 'Die OA-Preisliste wurde parallel geändert. Bitte neu laden.')
    {
        parent::__construct($message);
    }
}
