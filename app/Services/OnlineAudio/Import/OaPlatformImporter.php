<?php

namespace App\Services\OnlineAudio\Import;

use App\Models\OaPlatform;
use Illuminate\Support\Facades\DB;

/**
 * PO-CALC-TKP-1A: Import-Grundlage für OA-Plattform-Stammdaten (keine produktiven Seeds).
 */
final class OaPlatformImporter
{
    /**
     * @param  list<array{
     *     code: string,
     *     name: string,
     *     is_active?: bool,
     *     sort?: int,
     *     is_adserver_relevant?: bool
     * }>  $rows
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

            if ($code === '') {
                $errors[] = "Zeile {$line}: code fehlt.";
            } elseif (isset($seen[$code])) {
                $errors[] = "Zeile {$line}: doppelter code „{$code}“.";
            } else {
                $seen[$code] = true;
            }

            if ($name === '') {
                $errors[] = "Zeile {$line}: name fehlt.";
            }
        }

        if ($errors !== []) {
            throw new OaTkpStammdatenImportException('Plattform-Import ungültig.', $errors);
        }

        if ($dryRun) {
            return ['created' => 0, 'updated' => 0, 'dry_run' => true];
        }

        $created = 0;
        $updated = 0;

        DB::transaction(function () use ($rows, &$created, &$updated): void {
            foreach ($rows as $row) {
                $code = trim((string) $row['code']);
                $payload = [
                    'name' => trim((string) $row['name']),
                    'is_active' => (bool) ($row['is_active'] ?? true),
                    'sort' => (int) ($row['sort'] ?? 0),
                    'is_adserver_relevant' => (bool) ($row['is_adserver_relevant'] ?? false),
                ];

                $existing = OaPlatform::query()->where('code', $code)->first();
                if ($existing === null) {
                    OaPlatform::query()->create([...$payload, 'code' => $code]);
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
