<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class FieldDefinitionConflictException extends ConflictHttpException
{
    public function __construct(string $message = 'Die Felddefinition wurde parallel geändert. Bitte die Seite neu laden.')
    {
        parent::__construct($message);
    }
}
