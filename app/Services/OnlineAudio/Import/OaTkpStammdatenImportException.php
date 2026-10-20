<?php

namespace App\Services\OnlineAudio\Import;

use RuntimeException;

/**
 * PO-CALC-TKP-1A: aggregierte Validierungsfehler beim OA/TKP-Stammdatenimport.
 */
final class OaTkpStammdatenImportException extends RuntimeException
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
