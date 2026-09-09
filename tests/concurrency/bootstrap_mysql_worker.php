<?php

declare(strict_types=1);

/**
 * Gemeinsamer Bootstrap-Schutz für MySQL-Parallelworker unter tests/concurrency/.
 */

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Tests\Support\MysqlTestDatabaseGuard;

require __DIR__.'/../../vendor/autoload.php';

try {
    /** @var Application $app */
    $app = require __DIR__.'/../../bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();

    MysqlTestDatabaseGuard::assertSafeBeforeMysqlWorkerMutation();
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage().PHP_EOL);
    exit(1);
}

return $app;
