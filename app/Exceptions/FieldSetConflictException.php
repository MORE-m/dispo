<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class FieldSetConflictException extends ConflictHttpException
{
    public function __construct(string $message = 'Das Feldset wurde parallel geändert. Bitte die Seite neu laden.')
    {
        parent::__construct($message);
    }
}
