<?php

namespace App\Services\OnlineAudio\Import;

use App\Enums\OaTargetingCategory;
use App\Models\OaTargetingDefinition;
use Illuminate\Support\Facades\DB;

/**
 * PO-CALC-TKP-1A / 1D4c-4a: Import für Targeting-Stammdaten.
 *
 * `tkp_surcharge` = Listen-Aufschlag; `minimum_tkp_surcharge` = Mindest-Aufschlag (Default 0).
 * Engine/Under-Min nutzen den Mindestaufschlag erst ab D4c-4b.
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
        /** @var list<array{line: int, list: string, minimum: string}> $normalizedSurcharges */
        $normalizedSurcharges = [];

        foreach ($rows as $index => $row) {
            $line = $index + 1;
            $code = trim((string) ($row['code'] ?? ''));
            $name = trim((string) ($row['name'] ?? ''));
            $category = trim((string) ($row['category'] ?? ''));
            $revision = (int) ($row['revision_number'] ?? 1);
            $listSurcharge = OaTkpImportDecimal::normalizeNonNegative($row['tkp_surcharge'] ?? null);
            $minimumRaw = $row['minimum_tkp_surcharge'] ?? null;
            $minimumMissing = $minimumRaw === null || $minimumRaw === '';
            $minimumSurcharge = $minimumMissing
                ? '0.0000'
                : OaTkpImportDecimal::normalizeNonNegative($minimumRaw);

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

            if ($listSurcharge === null) {
                $errors[] = "Zeile {$line}: tkp_surcharge ungültig.";
            }

            if ($minimumSurcharge === null) {
                $errors[] = "Zeile {$line}: minimum_tkp_surcharge ungültig.";
            }

            if ($listSurcharge !== null && $minimumSurcharge !== null
                && OaTkpImportDecimal::compare($minimumSurcharge, $listSurcharge) > 0) {
                $errors[] = "Zeile {$line}: minimum_tkp_surcharge darf tkp_surcharge nicht überschreiten.";
            }

            if ($listSurcharge !== null && $minimumSurcharge !== null) {
                $normalizedSurcharges[$index] = [
                    'line' => $line,
                    'list' => $listSurcharge,
                    'minimum' => $minimumSurcharge,
                ];
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

        DB::transaction(function () use ($rows, $normalizedSurcharges, &$created, &$updated): void {
            foreach ($rows as $index => $row) {
                $code = trim((string) $row['code']);
                $revision = (int) ($row['revision_number'] ?? 1);
                $surcharges = $normalizedSurcharges[$index];
                $payload = [
                    'name' => trim((string) $row['name']),
                    'category' => OaTargetingCategory::from(trim((string) $row['category'])),
                    'tkp_surcharge' => $surcharges['list'],
                    'minimum_tkp_surcharge' => $surcharges['minimum'],
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
