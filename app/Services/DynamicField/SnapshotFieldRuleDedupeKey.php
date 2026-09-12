<?php

namespace App\Services\DynamicField;

use App\Support\DynamicField\FieldRuleContract;

/**
 * Kanonischer Dedupe-Key für eingefrorene Feldregeln (Freeze und Integrity).
 */
final class SnapshotFieldRuleDedupeKey
{
    /**
     * @param  array<string, mixed>  $condition
     * @param  array<string, mixed>  $action
     */
    public static function from(array $condition, array $action): string
    {
        return FieldRuleContract::dedupePayload($condition, $action);
    }
}
