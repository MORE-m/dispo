<?php

namespace App\Services\MasterDataTransfer\Import;

use RuntimeException;

/**
 * Internes Signal: Dry-Run-Transaktion absichtlich zurückrollen.
 */
final class MasterDataTransferDryRunRollback extends RuntimeException {}
