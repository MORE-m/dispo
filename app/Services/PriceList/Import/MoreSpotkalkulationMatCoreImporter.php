<?php

namespace App\Services\PriceList\Import;

use App\Models\Inventory;
use App\Models\User;
use App\Services\PriceList\Admin\PriceListAdminWriter;
use App\Services\PriceList\Admin\PriceListImpactPreviewService;
use App\Support\PriceList\Import\InventoryAliasResolver;
use App\Support\PriceList\Import\MoreSpotkalkulationWorkbookParser;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * PRI-OPS-1: MORE Spotkalkulation → Draft-Preislisten (optional Aktivierung).
 * Keine Auto-Aktivierung ohne explizites Flag; keine Mutation anderer DBs.
 */
final class MoreSpotkalkulationMatCoreImporter
{
    public function __construct(
        private readonly MoreSpotkalkulationWorkbookParser $parser,
        private readonly PriceListAdminWriter $writer,
        private readonly PriceListImpactPreviewService $impact,
    ) {}

    public static function defaultWorkbookPath(): string
    {
        return database_path('data/Spotkalkulation_2026.xlsx');
    }

    /**
     * @return array{
     *     year: int,
     *     parsed_rows: int,
     *     skipped_average_rows: int,
     *     skipped_empty_cells: int,
     *     created_drafts: list<array{inventory_code: string, price_list_id: int, item_count: int}>,
     *     activated: list<array{inventory_code: string, price_list_id: int}>,
     *     warnings: list<string>
     * }
     */
    public function import(
        User $actor,
        int $year = 2026,
        bool $activate = false,
        ?string $workbookPath = null,
    ): array {
        $path = $workbookPath ?? self::defaultWorkbookPath();
        if (! is_file($path)) {
            throw new RuntimeException('MORE-Spotkalkulation-Workbook nicht gefunden: '.$path);
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $parsed = $this->parser->parse($path, $extension === '' ? 'xlsx' : $extension);

        $errorIssues = array_values(array_filter(
            $parsed['issues'],
            static fn (array $issue): bool => $issue['severity'] === 'error',
        ));
        if ($errorIssues !== []) {
            $first = $errorIssues[0];
            throw ValidationException::withMessages([
                'workbook' => ($first['sheet'] !== null && $first['sheet'] !== '' ? $first['sheet'].': ' : '').$first['message'],
            ]);
        }

        $metaYear = $parsed['meta']['year'];
        if ($metaYear !== null && $metaYear !== $year) {
            throw ValidationException::withMessages([
                'year' => 'Workbook-Jahr ('.$metaYear.') weicht vom Importjahr ('.$year.') ab.',
            ]);
        }

        $resolver = InventoryAliasResolver::fromDatabase();
        /** @var array<int, array{inventory: Inventory, items: list<array{hour: int, day_group: string, second_price: string}>}> $buckets */
        $buckets = [];
        $warnings = [];

        foreach ($parsed['issues'] as $issue) {
            if ($issue['severity'] === 'warning') {
                $location = '';
                if ($issue['sheet'] !== null && $issue['sheet'] !== '') {
                    $location = $issue['sheet'];
                    if ($issue['row'] !== null) {
                        $location .= ' Z'.$issue['row'];
                    }
                    $location .= ': ';
                }
                $warnings[] = trim($location.$issue['message']);
            }
        }

        foreach ($parsed['rows'] as $raw) {
            $resolved = $resolver->resolve((string) $raw['inventory_raw']);
            if (isset($resolved['error'])) {
                throw ValidationException::withMessages([
                    'inventory' => $resolved['error'].' ('.$raw['inventory_raw'].')',
                ]);
            }
            /** @var Inventory $inventory */
            $inventory = $resolved['inventory'];
            $buckets[$inventory->id]['inventory'] = $inventory;
            $buckets[$inventory->id]['items'][] = [
                'hour' => (int) $raw['hour_raw'],
                'day_group' => (string) $raw['day_group_raw'],
                'second_price' => $raw['second_price_raw'],
            ];
        }

        if ($buckets === []) {
            throw ValidationException::withMessages([
                'workbook' => 'Keine importierbaren Einzelstundenpreise gefunden.',
            ]);
        }

        $created = [];
        $activated = [];

        DB::transaction(function () use ($buckets, $actor, $year, $activate, &$created, &$activated): void {
            foreach ($buckets as $bucket) {
                $inventory = $bucket['inventory'];
                $draft = $this->writer->createDraft([
                    'inventory_id' => (int) $inventory->id,
                    'year' => $year,
                    'name' => $inventory->name.' '.$year,
                    'items' => $bucket['items'],
                ], $actor);

                $created[] = [
                    'inventory_code' => (string) $inventory->code,
                    'price_list_id' => (int) $draft->id,
                    'item_count' => $draft->items()->count(),
                ];

                if (! $activate) {
                    continue;
                }

                $preview = $this->impact->previewActivate($draft);
                if (! $preview['can_proceed']) {
                    throw ValidationException::withMessages([
                        'activate' => $inventory->code.': Aktivierung blockiert.',
                    ]);
                }

                $activatedList = $this->writer->activate($draft, [
                    'lock_version' => (int) $draft->lock_version,
                    'fingerprint' => (string) $preview['fingerprint'],
                ], $actor);

                $activated[] = [
                    'inventory_code' => (string) $inventory->code,
                    'price_list_id' => (int) $activatedList->id,
                ];
            }
        });

        return [
            'year' => $year,
            'parsed_rows' => count($parsed['rows']),
            'skipped_average_rows' => $parsed['meta']['skipped_average_rows'],
            'skipped_empty_cells' => $parsed['meta']['skipped_empty_cells'],
            'created_drafts' => $created,
            'activated' => $activated,
            'warnings' => $warnings,
        ];
    }
}
