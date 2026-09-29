<?php

namespace App\Services\DispoOrder;

use App\Models\DispoOrder;
use App\Models\DispoOrderUpload;

/**
 * Ergebnis von {@see DispoOrderUploadService::archive()}.
 */
final class DispoOrderUploadArchiveResult
{
    public function __construct(
        public readonly DispoOrderUpload $upload,
        public readonly DispoOrder $order,
        public readonly bool $approvalInvalidated,
    ) {}
}
