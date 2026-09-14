<?php

namespace App\Support\PriceList\Import;

use App\Models\Inventory;
use Illuminate\Support\Collection;

/**
 * Inventarauflösung: Code (primär), kanonischer Name, dokumentierte Aliase.
 * Kein Fuzzy-Matching.
 */
final class InventoryAliasResolver
{
    /**
     * Dokumentierte Referenzaliase (initialdaten.md).
     *
     * @var array<string, string> alias_normalized => canonical inventory name
     */
    private const NAME_ALIASES = [
        'radio ffn' => 'ffn Hamburg Plus',
        'bollerwagen' => 'RADIO BOLLERWAGEN DAB+ Hamburg',
    ];

    /**
     * @param  Collection<int, Inventory>  $inventories
     */
    public function __construct(
        private readonly Collection $inventories,
    ) {}

    public static function fromDatabase(): self
    {
        return new self(Inventory::query()->orderBy('id')->get(['id', 'name', 'code', 'type']));
    }

    /**
     * @return array{inventory: Inventory}|array{error: string}
     */
    public function resolve(string $raw): array
    {
        $key = self::normalizeKey($raw);
        if ($key === '') {
            return ['error' => 'Inventarbezug fehlt.'];
        }

        foreach ($this->inventories as $inventory) {
            if (self::normalizeKey((string) $inventory->code) === $key) {
                return ['inventory' => $inventory];
            }
        }

        $aliasTarget = self::NAME_ALIASES[$key] ?? null;
        $nameKey = $aliasTarget !== null ? self::normalizeKey($aliasTarget) : $key;

        foreach ($this->inventories as $inventory) {
            if (self::normalizeKey((string) $inventory->name) === $nameKey) {
                return ['inventory' => $inventory];
            }
        }

        return ['error' => 'Unbekanntes Inventar: '.$raw];
    }

    public static function normalizeKey(string $value): string
    {
        $collapsed = preg_replace('/\s+/u', ' ', trim($value)) ?? '';

        return mb_strtolower($collapsed);
    }
}
