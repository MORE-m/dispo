<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * DF-3.3a1: Optimistic-Lock / Fingerprint-Konflikt bei Assignments (HTTP 409).
 */
final class FieldSetAssignmentConflictException extends ConflictHttpException
{
    public function __construct(string $message = 'Preview-Fingerprint veraltet')
    {
        parent::__construct($message);
    }
}
