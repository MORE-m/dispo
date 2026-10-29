<?php

namespace App\Services\OnlineAudio\Admin;

use App\Enums\PriceListStatus;
use App\Exceptions\OaTkpAdminConflictException;
use App\Models\OaTkpPriceList;
use App\Models\OaTkpPriceListItem;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\OnlineAudio\Import\OaTkpPriceListActivationService;
use App\Services\OnlineAudio\Import\OaTkpPriceListDraftImportGuard;
use App\Services\OnlineAudio\Import\OaTkpPriceListYearLock;
use App\Services\OnlineAudio\Import\OaTkpStammdatenImportException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * PO-CALC-TKP-1D4c-4c: Admin-Lifecycle für OA-TKP-Preislisten.
 *
 * Schreiben nur Draft über DraftImportGuard; Aktivierung ausschließlich über
 * OaTkpPriceListActivationService (+ YearLock). Kein Hard-Delete.
 */
final class OaTkpPriceListAdminWriter
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly OaTkpPriceListDraftImportGuard $draftGuard,
        private readonly OaTkpPriceListActivationService $activation,
        private readonly OaTkpPriceListMatrixValidator $matrix,
        private readonly OaTkpPriceListYearLock $yearLock,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function createDraft(array $payload, User $actor): OaTkpPriceList
    {
        $year = $this->assertYear($payload['year'] ?? null);
        $name = $this->assertName((string) ($payload['name'] ?? ''));
        $items = $this->normalizeItems($payload['items'] ?? null);
        $revision = array_key_exists('revision_number', $payload)
            ? $this->assertRevision($payload['revision_number'])
            : null;

        $this->yearLock->acquire($year);

        try {
            return DB::transaction(function () use ($year, $name, $items, $revision, $actor, $payload): OaTkpPriceList {
                OaTkpPriceList::query()->where('year', $year)->orderBy('id')->lockForUpdate()->get();

                $nextRevision = $revision ?? $this->nextRevision($year);
                if ($revision !== null) {
                    $exists = OaTkpPriceList::query()
                        ->where('year', $year)
                        ->where('revision_number', $revision)
                        ->exists();
                    if ($exists) {
                        throw ValidationException::withMessages([
                            'revision_number' => "Jahr {$year} Revision {$revision} existiert bereits.",
                        ]);
                    }
                }

                $importPayload = [
                    'year' => $year,
                    'revision_number' => $nextRevision,
                    'name' => $name,
                    'version' => (string) ($payload['version'] ?? "{$year}.{$nextRevision}"),
                    'status' => PriceListStatus::Draft->value,
                    'valid_from' => $payload['valid_from'] ?? null,
                    'items' => $items,
                ];

                $report = $this->matrix->validatePayload($importPayload, requireComplete: false);
                if ($report['errors'] !== []) {
                    throw new OaTkpStammdatenImportException(
                        'TKP-Preislisten-Entwurf ungültig.',
                        array_map(static fn (array $e): string => $e['message'], $report['errors']),
                    );
                }

                $result = $this->draftGuard->importDraftOnly($importPayload, false);
                $list = OaTkpPriceList::query()->findOrFail((int) $result['price_list_id']);

                $this->audit->record(
                    $list,
                    'oa_tkp_price_list.created',
                    $actor,
                    null,
                    $this->auditPayload($list),
                );

                return $list->fresh(['items.advertisingMedium', 'items.inventory']) ?? $list;
            });
        } finally {
            $this->yearLock->release();
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function updateDraft(OaTkpPriceList $priceList, array $payload, User $actor): OaTkpPriceList
    {
        $this->assertClientLockPresent($payload);
        $year = (int) $priceList->year;
        $this->yearLock->acquire($year);

        try {
            return DB::transaction(function () use ($priceList, $payload, $actor): OaTkpPriceList {
                OaTkpPriceList::query()->where('year', (int) $priceList->year)->orderBy('id')->lockForUpdate()->get();

                /** @var OaTkpPriceList $locked */
                $locked = OaTkpPriceList::query()->whereKey($priceList->id)->firstOrFail();
                $this->assertLock($locked, (int) $payload['lock_version']);
                $this->assertDraft($locked);
                $this->rejectImmutableRebind($payload, $locked);

                $name = $this->assertName((string) ($payload['name'] ?? $locked->name));
                $items = array_key_exists('items', $payload)
                    ? $this->normalizeItems($payload['items'])
                    : $this->itemsFromList($locked);
                $version = (string) ($payload['version'] ?? $locked->version);

                $before = $this->auditPayload($locked);
                $importPayload = [
                    'year' => (int) $locked->year,
                    'revision_number' => (int) $locked->revision_number,
                    'name' => $name,
                    'version' => $version,
                    'status' => PriceListStatus::Draft->value,
                    'valid_from' => array_key_exists('valid_from', $payload)
                        ? $payload['valid_from']
                        : ($locked->valid_from?->toDateString()),
                    'items' => $items,
                ];

                $report = $this->matrix->validatePayload($importPayload, requireComplete: false);
                if ($report['errors'] !== []) {
                    throw new OaTkpStammdatenImportException(
                        'TKP-Preislisten-Entwurf ungültig.',
                        array_map(static fn (array $e): string => $e['message'], $report['errors']),
                    );
                }

                $this->draftGuard->importDraftOnly($importPayload, false);
                $fresh = OaTkpPriceList::query()
                    ->with(['items.advertisingMedium', 'items.inventory'])
                    ->findOrFail($locked->id);

                if ($this->auditPayload($fresh) == $before) {
                    return $fresh;
                }

                $this->audit->record(
                    $fresh,
                    'oa_tkp_price_list.updated',
                    $actor,
                    $before,
                    $this->auditPayload($fresh),
                );

                return $fresh;
            });
        } finally {
            $this->yearLock->release();
        }
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
    public function validate(OaTkpPriceList $priceList, bool $requireComplete = true): array
    {
        return $this->matrix->validateStoredList($priceList, $requireComplete);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{
     *     dry_run: bool,
     *     year: int,
     *     revision_number: int,
     *     price_list_id: int|null,
     *     activated: bool,
     *     already_active: bool,
     *     archived_previous_ids: array<int, int>,
     *     items_count: int,
     *     price_list: OaTkpPriceList
     * }
     */
    public function activate(OaTkpPriceList $priceList, array $payload, User $actor, bool $dryRun = false): array
    {
        $this->assertClientLockPresent($payload);

        /** @var OaTkpPriceList $fresh */
        $fresh = OaTkpPriceList::query()->whereKey($priceList->id)->firstOrFail();
        $this->assertLock($fresh, (int) $payload['lock_version']);

        if ($fresh->status === PriceListStatus::Archived) {
            throw new OaTkpStammdatenImportException(
                'Aktivierung ungültig.',
                ['Archivierte Revisionen können nicht aktiviert werden.'],
            );
        }

        // Vollständigkeit/Zulässigkeit ausschließlich im ActivationService
        // (identisch zu CLI); kein zweiter Aktivierungspfad.
        $before = $this->auditPayload($fresh);
        $result = $this->activation->activate(
            (int) $fresh->year,
            (int) $fresh->revision_number,
            archivePrevious: true,
            dryRun: $dryRun,
        );

        $activated = OaTkpPriceList::query()
            ->with(['items.advertisingMedium', 'items.inventory'])
            ->findOrFail($fresh->id);

        if (! $dryRun && ($result['activated'] || $result['already_active'])) {
            $this->audit->record(
                $activated,
                'oa_tkp_price_list.activated',
                $actor,
                $before,
                array_merge($this->auditPayload($activated), [
                    'archived_previous_ids' => $result['archived_previous_ids'],
                    'already_active' => $result['already_active'],
                    'activated' => $result['activated'],
                ]),
            );
        }

        return [
            ...$result,
            'price_list' => $activated,
        ];
    }

    /**
     * Kontrolliertes Archivieren einer aktiven Revision (ohne Nachfolger-Activate).
     *
     * @param  array<string, mixed>  $payload
     */
    public function archive(OaTkpPriceList $priceList, array $payload, User $actor): OaTkpPriceList
    {
        $this->assertClientLockPresent($payload);
        $year = (int) $priceList->year;
        $this->yearLock->acquire($year);

        try {
            return DB::transaction(function () use ($priceList, $payload, $actor, $year): OaTkpPriceList {
                OaTkpPriceList::query()->where('year', $year)->orderBy('id')->lockForUpdate()->get();

                /** @var OaTkpPriceList $locked */
                $locked = OaTkpPriceList::query()->whereKey($priceList->id)->firstOrFail();
                $this->assertLock($locked, (int) $payload['lock_version']);

                if ($locked->status === PriceListStatus::Archived) {
                    throw ValidationException::withMessages([
                        'oa_tkp_price_list' => 'Die Revision ist bereits archiviert.',
                    ]);
                }
                if ($locked->status === PriceListStatus::Draft) {
                    throw ValidationException::withMessages([
                        'oa_tkp_price_list' => 'Entwürfe werden nicht archiviert; bitte löschen/überschreiben ist unzulässig – neuen Entwurf anlegen oder aktivieren.',
                    ]);
                }

                $before = $this->auditPayload($locked);
                $locked->status = PriceListStatus::Archived;
                if ($locked->archived_at === null) {
                    $locked->archived_at = Carbon::now();
                }
                $locked->lock_version = ((int) $locked->lock_version) + 1;
                $locked->save();

                $fresh = $locked->fresh(['items.advertisingMedium', 'items.inventory']) ?? $locked;
                $this->audit->record(
                    $fresh,
                    'oa_tkp_price_list.archived',
                    $actor,
                    $before,
                    $this->auditPayload($fresh),
                );

                return $fresh;
            });
        } finally {
            $this->yearLock->release();
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function auditPayload(OaTkpPriceList $list): array
    {
        $list->loadMissing(['items.advertisingMedium:id,code', 'items.inventory:id,code']);

        return [
            'id' => (int) $list->id,
            'year' => (int) $list->year,
            'revision_number' => (int) $list->revision_number,
            'name' => (string) $list->name,
            'version' => (string) $list->version,
            'status' => $list->status->value,
            'lock_version' => (int) $list->lock_version,
            'valid_from' => $list->valid_from?->toDateString(),
            'published_at' => $list->published_at?->toIso8601String(),
            'archived_at' => $list->archived_at?->toIso8601String(),
            'items' => $list->items->map(static function (OaTkpPriceListItem $item): array {
                return [
                    'medium_code' => (string) $item->advertisingMedium->code,
                    'inventory_code' => (string) $item->inventory->code,
                    'list_tkp' => (string) $item->list_tkp,
                    'minimum_tkp' => (string) $item->minimum_tkp,
                ];
            })->values()->all(),
        ];
    }

    private function nextRevision(int $year): int
    {
        $max = (int) OaTkpPriceList::query()->where('year', $year)->max('revision_number');

        return max(1, $max + 1);
    }

    private function assertYear(mixed $year): int
    {
        if (! is_numeric($year)) {
            throw ValidationException::withMessages(['year' => 'year ist erforderlich.']);
        }
        $value = (int) $year;
        if ($value < 2000 || $value > 2100) {
            throw ValidationException::withMessages(['year' => 'year ungültig.']);
        }

        return $value;
    }

    private function assertRevision(mixed $revision): int
    {
        if (! is_numeric($revision) || (int) $revision < 1) {
            throw ValidationException::withMessages(['revision_number' => 'revision_number muss ≥ 1 sein.']);
        }

        return (int) $revision;
    }

    private function assertName(string $name): string
    {
        $trimmed = trim($name);
        if ($trimmed === '') {
            throw ValidationException::withMessages(['name' => 'name ist erforderlich.']);
        }
        if (mb_strlen($trimmed) > 255) {
            throw ValidationException::withMessages(['name' => 'name darf höchstens 255 Zeichen haben.']);
        }

        return $trimmed;
    }

    /**
     * @return list<array{medium_code: string, inventory_code: string, list_tkp: string, minimum_tkp: string}>
     */
    private function normalizeItems(mixed $items): array
    {
        if (! is_array($items) || $items === []) {
            throw ValidationException::withMessages(['items' => 'items fehlen.']);
        }

        $normalized = [];
        foreach ($items as $index => $item) {
            if (! is_array($item)) {
                throw ValidationException::withMessages([
                    "items.{$index}" => 'Item ungültig.',
                ]);
            }
            $normalized[] = [
                'medium_code' => trim((string) ($item['medium_code'] ?? '')),
                'inventory_code' => trim((string) ($item['inventory_code'] ?? '')),
                'list_tkp' => (string) ($item['list_tkp'] ?? ''),
                'minimum_tkp' => (string) ($item['minimum_tkp'] ?? ''),
            ];
        }

        return $normalized;
    }

    /**
     * @return list<array{medium_code: string, inventory_code: string, list_tkp: string, minimum_tkp: string}>
     */
    private function itemsFromList(OaTkpPriceList $list): array
    {
        $list->loadMissing(['items.advertisingMedium:id,code', 'items.inventory:id,code']);
        $items = [];
        foreach ($list->items as $item) {
            $items[] = [
                'medium_code' => (string) $item->advertisingMedium->code,
                'inventory_code' => (string) $item->inventory->code,
                'list_tkp' => (string) $item->list_tkp,
                'minimum_tkp' => (string) $item->minimum_tkp,
            ];
        }

        return $items;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function assertClientLockPresent(array $payload): void
    {
        if (! array_key_exists('lock_version', $payload) || ! is_numeric($payload['lock_version'])) {
            throw ValidationException::withMessages([
                'lock_version' => 'lock_version ist erforderlich.',
            ]);
        }
    }

    private function assertLock(OaTkpPriceList $list, int $clientLock): void
    {
        if ((int) $list->lock_version !== $clientLock) {
            throw new OaTkpAdminConflictException;
        }
    }

    private function assertDraft(OaTkpPriceList $list): void
    {
        if ($list->status !== PriceListStatus::Draft) {
            throw new OaTkpStammdatenImportException(
                'Nur Entwürfe sind bearbeitbar.',
                [
                    "Jahr {$list->year} Revision {$list->revision_number} ist „{$list->status->value}“ und unveränderlich.",
                ],
            );
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function rejectImmutableRebind(array $payload, OaTkpPriceList $list): void
    {
        foreach (['year', 'revision_number', 'status'] as $field) {
            if (! array_key_exists($field, $payload)) {
                continue;
            }
            $incoming = is_scalar($payload[$field]) ? (string) $payload[$field] : null;
            $current = match ($field) {
                'year' => (string) $list->year,
                'revision_number' => (string) $list->revision_number,
                'status' => $list->status->value,
            };
            if ($incoming !== null && $incoming !== $current) {
                throw ValidationException::withMessages([
                    $field => "{$field} ist nach dem Anlegen unveränderlich.",
                ]);
            }
        }
    }
}
