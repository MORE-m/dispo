<?php

namespace App\Services\OnlineAudio\Admin;

use App\Models\OaTkpPriceList;
use App\Models\OaTkpPriceListItem;
use App\Services\OnlineAudio\Import\OaTkpImportDecimal;
use App\Services\OnlineAudio\Import\OaTkpPriceListImporter;
use App\Services\OnlineAudio\Import\OaTkpStammdatenImportException;
use App\Support\OnlineAudio\OaTkpActiveMediumInventoryPairs;
use App\Support\OnlineAudio\OaTkpCoreMedia;

/**
 * PO-CALC-TKP-1D4c-4c: fachliche Matrix-Validierung für Admin-Prüf-Endpoint.
 *
 * SoT der erforderlichen Paare = aktive InventoryMediumRules (wie ActivationService).
 * Aktivierung selbst prüft erneut ausschließlich im ActivationService.
 */
final class OaTkpPriceListMatrixValidator
{
    public function __construct(
        private readonly OaTkpPriceListImporter $importer,
    ) {}

    /**
     * @param  array<string, mixed>  $payload  Importer-kompatibles Payload (year/revision/items…)
     * @return array{
     *     valid: bool,
     *     errors: list<array{line: int|null, field: string|null, message: string}>,
     *     items_count: int,
     *     required_pairs: int,
     *     missing_pairs: list<string>,
     *     unexpected_pairs: list<string>,
     *     complete: bool
     * }
     */
    public function validatePayload(array $payload, bool $requireComplete = false): array
    {
        $errors = [];
        $itemsRaw = is_array($payload['items'] ?? null) ? $payload['items'] : [];
        $required = OaTkpActiveMediumInventoryPairs::pairKeySet();
        $seen = [];
        $unexpected = [];

        try {
            $this->importer->import([
                ...$payload,
                'status' => 'draft',
            ], true);
        } catch (OaTkpStammdatenImportException $e) {
            foreach ($e->errors as $message) {
                $errors[] = $this->parseErrorMessage($message);
            }
        }

        foreach ($itemsRaw as $index => $item) {
            if (! is_array($item)) {
                continue;
            }
            $line = $index + 1;
            $medium = trim((string) ($item['medium_code'] ?? ''));
            $inventory = trim((string) ($item['inventory_code'] ?? ''));
            if ($medium === '' || $inventory === '') {
                continue;
            }
            $pair = $medium.'|'.$inventory;
            $seen[$pair] = true;
            if ($required !== [] && ! isset($required[$pair])) {
                if ($medium === 'native_ad' || ! OaTkpCoreMedia::contains($medium)) {
                    continue;
                }
                $unexpected[] = $pair;
                $errors[] = [
                    'line' => $line,
                    'field' => 'medium_code',
                    'message' => "Item {$line}: Medium×Inventar „{$pair}“ ist keine aktive OA-Zuordnung.",
                ];
            }
        }

        $missing = [];
        foreach (array_keys($required) as $pair) {
            if (! isset($seen[$pair])) {
                $missing[] = $pair;
            }
        }

        $complete = $required !== [] && $missing === [] && $unexpected === [];
        if ($requireComplete && $required === []) {
            $errors[] = [
                'line' => null,
                'field' => 'items',
                'message' => 'Keine aktiven OA-Medium×Inventar-Zuordnungen vorhanden; Aktivierung nicht zulässig.',
            ];
        }
        if ($requireComplete && ! $complete) {
            foreach ($missing as $pair) {
                $errors[] = [
                    'line' => null,
                    'field' => 'items',
                    'message' => "Preismatrix unvollständig: fehlendes Paar „{$pair}“.",
                ];
            }
        }

        $unique = [];
        foreach ($errors as $error) {
            $unique[$error['message']] = $error;
        }

        return [
            'valid' => $unique === [] && (! $requireComplete || $complete),
            'errors' => array_values($unique),
            'items_count' => count($itemsRaw),
            'required_pairs' => count($required),
            'missing_pairs' => array_values($missing),
            'unexpected_pairs' => array_values(array_unique($unexpected)),
            'complete' => $complete,
        ];
    }

    /**
     * @return array{
     *     valid: bool,
     *     errors: list<array{line: int|null, field: string|null, message: string}>,
     *     items_count: int,
     *     required_pairs: int,
     *     missing_pairs: list<string>,
     *     unexpected_pairs: list<string>,
     *     complete: bool
     * }
     */
    public function validateStoredList(OaTkpPriceList $list, bool $requireComplete = false): array
    {
        $list->loadMissing(['items.advertisingMedium:id,code', 'items.inventory:id,code']);

        $items = [];
        foreach ($list->items as $item) {
            /** @var OaTkpPriceListItem $item */
            $items[] = [
                'medium_code' => (string) ($item->advertisingMedium?->code ?? ''),
                'inventory_code' => (string) ($item->inventory?->code ?? ''),
                'list_tkp' => (string) $item->list_tkp,
                'minimum_tkp' => (string) $item->minimum_tkp,
            ];
        }

        return $this->validatePayload([
            'year' => (int) $list->year,
            'revision_number' => (int) $list->revision_number,
            'name' => (string) $list->name,
            'version' => (string) $list->version,
            'status' => 'draft',
            'items' => $items,
        ], $requireComplete);
    }

    /**
     * @return array{line: int|null, field: string|null, message: string}
     */
    private function parseErrorMessage(string $message): array
    {
        $line = null;
        if (preg_match('/^Item (\d+):/', $message, $m) === 1) {
            $line = (int) $m[1];
        }

        $field = null;
        foreach (['list_tkp', 'minimum_tkp', 'medium_code', 'inventory_code', 'year', 'revision_number', 'status', 'items'] as $candidate) {
            if (str_contains($message, $candidate)) {
                $field = $candidate;
                break;
            }
        }

        return [
            'line' => $line,
            'field' => $field,
            'message' => $message,
        ];
    }

    /**
     * Schnellcheck eines einzelnen Item-Paares (für UI-Zeilenfeedback).
     */
    public function assertDecimalPair(string $listTkp, string $minimumTkp, int $line): void
    {
        $errors = [];
        $list = OaTkpImportDecimal::normalizePositive($listTkp);
        $min = OaTkpImportDecimal::normalizePositive($minimumTkp);
        if ($list === null) {
            $errors[] = "Item {$line}: list_tkp ungültig (muss > 0, max. 4 NK, ≤ ".OaTkpImportDecimal::MAX.' sein).';
        }
        if ($min === null) {
            $errors[] = "Item {$line}: minimum_tkp ungültig (muss > 0, max. 4 NK, ≤ ".OaTkpImportDecimal::MAX.' sein).';
        }
        if ($list !== null && $min !== null && OaTkpImportDecimal::compare($min, $list) > 0) {
            $errors[] = "Item {$line}: minimum_tkp darf list_tkp nicht überschreiten.";
        }
        if ($errors !== []) {
            throw new OaTkpStammdatenImportException('TKP-Preiszeile ungültig.', $errors);
        }
    }
}
