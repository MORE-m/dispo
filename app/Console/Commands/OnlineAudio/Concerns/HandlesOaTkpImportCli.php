<?php

namespace App\Console\Commands\OnlineAudio\Concerns;

use App\Services\OnlineAudio\Import\OaTkpStammdatenImportException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * @mixin Command
 */
trait HandlesOaTkpImportCli
{
    /**
     * @param  list<string>  $errors
     */
    protected function writeImportErrors(string $headline, array $errors): void
    {
        $this->error($headline);
        foreach ($errors as $error) {
            $this->error(' - '.$error);
        }
    }

    /**
     * @param  array<string, mixed>  $context
     */
    protected function logImportOutcome(string $event, array $context): void
    {
        Log::info($event, $context);
    }

    protected function failureFromException(OaTkpStammdatenImportException $exception): int
    {
        $this->writeImportErrors($exception->getMessage(), $exception->errors);

        return self::FAILURE;
    }
}
