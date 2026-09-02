<?php

namespace App\Services\DispoOrder;

use App\Models\DispoOrder;

final class DispoOrderWriterResult
{
    /**
     * @param  list<int>  $positionIds
     */
    public function __construct(
        public readonly DispoOrder $order,
        public readonly array $positionIds,
    ) {}
}
