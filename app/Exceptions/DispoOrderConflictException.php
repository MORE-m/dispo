<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class DispoOrderConflictException extends ConflictHttpException
{
    public function __construct(string $message = 'Der Dispoauftrag wurde parallel geändert. Bitte die Seite neu laden.')
    {
        parent::__construct($message);
    }
}
