<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * BL-P4-01c / PO-PRI-YEAR-1: Active-Drift oder Expected-Mismatch bei Live-Preisbindung (HTTP 409).
 */
final class PriceListSelectionConflictException extends ConflictHttpException
{
    public function __construct(string $message = 'Die aktive Preisliste hat sich geändert. Bitte neu laden und bewusst speichern.')
    {
        parent::__construct($message);
    }
}
