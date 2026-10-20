<?php

namespace App\Services\OnlineAudio\Import;

use App\Enums\OaTargetingCategory;
use App\Models\OaTargetingDefinition;
use Illuminate\Support\Facades\DB;

/**
 * PO-CALC-TKP-1A: Import-Grundlage für Targeting-Stammdaten (absolute TKP-Aufschläge).
 */
final class OaTargetingDefinitionImporter
{
    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array{created: int, updated: int, dry_run: bool}
     */
    public function import(array $rows, bool $dryRun = false): array
    {
        $errors = [];
        $seen = [];

        foreach ($rows as $index => $row) {
            $line = $index + 1;
            $code = trim((string) ($row['code'] ?? ''));
            $name = trim((string) ($row['name'] ?? ''));
            $category = trim((string) ($row['category'] ?? ''));
            $revision = (int) ($row['revision_number'] ?? 1);
            $surcharge = OaTkpImportDecimal::normalizeNonNegative($row['tkp_surcharge'] ?? null);

            if ($code === '') {
                $errors[] = "Zeile {$line}: code fehlt.";
            }

            $identity = $code.'#'.$revision;
            if ($code !== '' && isset($seen[$identity])) {
                $errors[] = "Zeile {$line}: doppelte code/revision „{$identity}“.";
            } elseif ($code !== '') {
                $seen[$identity] = true;
            }

            if ($name === '') {
                $errors[] = "Zeile {$line}: name fehlt.";
            }

            if (OaTargetingCategory::tryFrom($category) === null) {
                $errors[] = "Zeile {$line}: category ungültig (erwartet technisch|dmp).";
            }

            if ($revision < 1) {
                $errors[] = "Zeile {$line}: revision_number muss ≥ 1 sein.";
            }

            if ($surcharge === null) {
                $errors[] = "Zeile {$line}: tkp_surcharge ungültig.";
            }
        }

        if ($errors !== []) {
            throw new OaTkpStammdatenImportException('Targeting-Import ungültig.', $errors);
        }

        if ($dryRun) {
            return ['created' => 0, 'updated' => 0, 'dry_run' => true];
        }

        $created = 0;
        $updated = 0;

        DB::transaction(function () use ($rows, &$created, &$updated): void {
            foreach ($rows as $row) {
                $code = trim((string) $row['code']);
                $revision = (int) ($row['revision_number'] ?? 1);
                $payload = [
                    'name' => trim((string) $row['name']),
                    'category' => OaTargetingCategory::from(trim((string) $row['category'])),
                    'tkp_surcharge' => OaTkpImportDecimal::normalizeNonNegative($row['tkp_surcharge']),
                    'is_active' => (bool) ($row['is_active'] ?? true),
                    'valid_from' => $row['valid_from'] ?? null,
                    'valid_to' => $row['valid_to'] ?? null,
                ];

                $existing = OaTargetingDefinition::query()
                    ->where('code', $code)
                    ->where('revision_number', $revision)
                    ->first();

                if ($existing === null) {
                    OaTargetingDefinition::query()->create([
                        ...$payload,
                        'code' => $code,
                        'revision_number' => $revision,
                    ]);
                    $created++;
                } else {
                    $existing->fill($payload)->save();
                    $updated++;
                }
            }
        });

        return ['created' => $created, 'updated' => $updated, 'dry_run' => false];
    }
}
