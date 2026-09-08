<?php

namespace App\Services\DynamicField;

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
        return hash('sha256', json_encode([
            'condition' => $condition,
            'action' => $action,
        ], JSON_THROW_ON_ERROR));
    }
}
