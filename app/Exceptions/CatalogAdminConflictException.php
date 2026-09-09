<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * ADV-001b: Optimistic-Lock / Impact-Fingerprint-Konflikt (HTTP 409).
 */
final class CatalogAdminConflictException extends ConflictHttpException
{
    public function __construct(string $message = 'Katalog-Vorschau ist veraltet')
    {
        parent::__construct($message);
    }
}
