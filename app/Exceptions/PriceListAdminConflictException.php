<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * BL-P4-01a: Optimistic-Lock / Preview-Fingerprint-Konflikt (HTTP 409).
 */
final class PriceListAdminConflictException extends ConflictHttpException
{
    public function __construct(string $message = 'Die Preisliste wurde parallel geändert. Bitte neu laden.')
    {
        parent::__construct($message);
    }
}
