<?php

namespace App\Services\PriceList\Admin;

use App\Enums\DayGroup;
use App\Enums\PriceListStatus;
use App\Exceptions\PriceListAdminConflictException;
use App\Models\Inventory;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\PriceList\PriceListItemContract;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * BL-P4-01a: Admin-Lifecycle für Preislisten. Kein Hard Delete, keine Excel-Importe.
 */
final class PriceListAdminWriter
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly PriceListImpactPreviewService $impact,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function createDraft(array $payload, User $actor): PriceList
    {
        return DB::transaction(function () use ($payload, $actor): PriceList {
            $inventory = $this->assertInventory((int) ($payload['inventory_id'] ?? 0));
            $year = $this->assertYear($payload['year'] ?? null);
            $name = $this->assertName((string) ($payload['name'] ?? ''));
            $items = PriceListItemContract::normalizeBaseItems(
                is_array($payload['items'] ?? null) ? $payload['items'] : [],
                forActivation: false,
            );

            $this->lockInventoryYear($inventory->id, $year);
            $revision = $this->nextRevisionNumber($inventory->id, $year);

            try {
                $list = new PriceList;
                $list->inventory()->associate($inventory);
                $list->name = $name;
                $list->year = $year;
                $list->revision_number = $revision;
                $list->version = (string) $revision;
                $list->status = PriceListStatus::Draft;
                $list->valid_from = null;
                $list->lock_version = 1;
                $list->save();
            } catch (UniqueConstraintViolationException) {
                throw $this->duplicateIdentity();
            } catch (QueryException $exception) {
                if ($this->isUniqueViolation($exception)) {
                    throw $this->duplicateIdentity();
                }
                throw $exception;
            }

            $this->replaceItems($list, $items);

            $this->audit->record(
                $list,
                'price_list.created',
                $actor,
                null,
                $this->auditPayload($list->fresh(['items']) ?? $list),
            );

            return $list->fresh(['items', 'inventory']) ?? $list;
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function copyAsDraft(PriceList $source, array $payload, User $actor): PriceList
    {
        return DB::transaction(function () use ($source, $payload, $actor): PriceList {
            /** @var PriceList $lockedSource */
            $lockedSource = PriceList::query()->whereKey($source->id)->lockForUpdate()->firstOrFail();
            $lockedSource->load(['items', 'inventory']);

            $year = array_key_exists('year', $payload)
                ? $this->assertYear($payload['year'])
                : (int) $lockedSource->year;
            $name = $this->assertName((string) ($payload['name'] ?? $lockedSource->name));
            $inventoryId = (int) $lockedSource->inventory_id;

            $this->lockInventoryYear($inventoryId, $year);
            $revision = $this->nextRevisionNumber($inventoryId, $year);

            $copy = new PriceList;
            $copy->inventory()->associate($inventoryId);
            $copy->name = $name;
            $copy->year = $year;
            $copy->revision_number = $revision;
            $copy->version = (string) $revision;
            $copy->status = PriceListStatus::Draft;
            $copy->valid_from = null;
            $copy->lock_version = 1;
            $copy->save();

            $copied = [];
            foreach ($lockedSource->items as $item) {
                if ($item->day_group->isDerived()) {
                    continue;
                }
                $copied[] = [
                    'hour' => (int) $item->hour,
                    'day_group' => $item->day_group,
                    'second_price' => (string) $item->second_price,
                ];
            }
            $this->replaceItems($copy, $copied);

            $this->audit->record(
                $copy,
                'price_list.copied',
                $actor,
                null,
                array_merge($this->auditPayload($copy->fresh(['items']) ?? $copy), [
                    'source_id' => (int) $lockedSource->id,
                    'source_version' => $lockedSource->version,
                    'source_year' => (int) $lockedSource->year,
                ]),
            );

            return $copy->fresh(['items', 'inventory']) ?? $copy;
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function updateDraft(PriceList $priceList, array $payload, User $actor): PriceList
    {
        return DB::transaction(function () use ($priceList, $payload, $actor): PriceList {
            $this->assertClientLockPresent($payload);
            /** @var PriceList $locked */
            $locked = PriceList::query()->whereKey($priceList->id)->lockForUpdate()->firstOrFail();
            $this->assertLock($locked, (int) $payload['lock_version']);
            $this->assertDraft($locked);
            $this->rejectImmutableRebind($payload, $locked);

            $name = $this->assertName((string) ($payload['name'] ?? $locked->name));
            $items = PriceListItemContract::normalizeBaseItems(
                is_array($payload['items'] ?? null) ? $payload['items'] : [],
                forActivation: false,
            );

            $locked->load('items');
            if ($name === $locked->name && $this->itemsEqual($locked, $items)) {
                return $locked;
            }

            $before = $this->auditPayload($locked);
            $locked->name = $name;
            $locked->lock_version = $locked->lock_version + 1;
            $locked->save();
            $this->replaceItems($locked, $items);

            $fresh = $locked->fresh(['items', 'inventory']) ?? $locked;
            $this->audit->record(
                $fresh,
                'price_list.updated',
                $actor,
                $before,
                $this->auditPayload($fresh),
            );

            return $fresh;
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function activate(PriceList $priceList, array $payload, User $actor): PriceList
    {
        return DB::transaction(function () use ($priceList, $payload, $actor): PriceList {
            $this->assertClientLockPresent($payload);
            if (! array_key_exists('fingerprint', $payload) || ! is_string($payload['fingerprint']) || $payload['fingerprint'] === '') {
                throw ValidationException::withMessages([
                    'fingerprint' => 'Die Auswirkungsvorschau muss bestätigt werden.',
                ]);
            }

            $inventoryId = (int) $priceList->inventory_id;
            $year = (int) $priceList->year;
            $this->lockInventoryYear($inventoryId, $year);

            /** @var PriceList $locked */
            $locked = PriceList::query()->whereKey($priceList->id)->firstOrFail();
            $this->assertLock($locked, (int) $payload['lock_version']);
            $this->assertDraft($locked);
            $locked->load('items');
            $rawItems = [];
            foreach ($locked->items as $item) {
                $rawItems[] = [
                    'hour' => $item->hour,
                    'day_group' => $item->day_group->value,
                    'second_price' => (string) $item->second_price,
                ];
            }
            PriceListItemContract::normalizeBaseItems($rawItems, forActivation: true);

            $preview = $this->impact->previewActivate($locked);
            $this->impact->assertFingerprint($preview, (string) $payload['fingerprint']);

            if (! $preview['can_proceed']) {
                /** @var list<array{message?: string}> $reasons */
                $reasons = is_array($preview['blocking_reasons'] ?? null) ? $preview['blocking_reasons'] : [];
                throw ValidationException::withMessages([
                    'price_list' => $reasons[0]['message'] ?? 'Die Veröffentlichung ist blockiert.',
                ]);
            }

            $predecessor = PriceList::query()
                ->where('inventory_id', $inventoryId)
                ->where('year', $year)
                ->where('status', PriceListStatus::Active)
                ->where('id', '!=', $locked->id)
                ->lockForUpdate()
                ->first();

            $archivedPayload = null;
            if ($predecessor !== null) {
                $archivedBefore = $this->auditPayload($predecessor);
                $predecessor->status = PriceListStatus::Archived;
                $predecessor->archived_at = now();
                $predecessor->lock_version = $predecessor->lock_version + 1;
                $predecessor->save();
                $archivedPayload = [
                    'before' => $archivedBefore,
                    'after' => $this->auditPayload($predecessor->fresh() ?? $predecessor),
                ];
            }

            $before = $this->auditPayload($locked);
            $locked->status = PriceListStatus::Active;
            $locked->published_at = now();
            $locked->archived_at = null;
            $locked->lock_version = $locked->lock_version + 1;
            $locked->save();

            $fresh = $locked->fresh(['items', 'inventory']) ?? $locked;
            $this->audit->record(
                $fresh,
                'price_list.activated',
                $actor,
                $before,
                array_merge($this->auditPayload($fresh), [
                    'archived_predecessor' => $archivedPayload,
                    'fingerprint' => $preview['fingerprint'],
                ]),
            );

            return $fresh;
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function archive(PriceList $priceList, array $payload, User $actor): PriceList
    {
        return DB::transaction(function () use ($priceList, $payload, $actor): PriceList {
            $this->assertClientLockPresent($payload);
            if (! array_key_exists('fingerprint', $payload) || ! is_string($payload['fingerprint']) || $payload['fingerprint'] === '') {
                throw ValidationException::withMessages([
                    'fingerprint' => 'Die Auswirkungsvorschau muss bestätigt werden.',
                ]);
            }

            $this->lockInventoryYear((int) $priceList->inventory_id, (int) $priceList->year);

            /** @var PriceList $locked */
            $locked = PriceList::query()->whereKey($priceList->id)->firstOrFail();
            $this->assertLock($locked, (int) $payload['lock_version']);

            if ($locked->status === PriceListStatus::Archived) {
                throw ValidationException::withMessages([
                    'price_list' => 'Die Preisliste ist bereits archiviert.',
                ]);
            }

            $preview = $this->impact->previewArchive($locked);
            $this->impact->assertFingerprint($preview, (string) $payload['fingerprint']);

            $before = $this->auditPayload($locked);
            $locked->status = PriceListStatus::Archived;
            $locked->archived_at = now();
            $locked->lock_version = $locked->lock_version + 1;
            $locked->save();

            $fresh = $locked->fresh(['items', 'inventory']) ?? $locked;
            $this->audit->record(
                $fresh,
                'price_list.archived',
                $actor,
                $before,
                array_merge($this->auditPayload($fresh), [
                    'fingerprint' => $preview['fingerprint'],
                ]),
            );

            return $fresh;
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array{hour: int, day_group: DayGroup, second_price: string}>
     */
    public function inspectDraftItems(array $payload): array
    {
        return PriceListItemContract::normalizeBaseItems(
            is_array($payload['items'] ?? null) ? $payload['items'] : [],
            forActivation: false,
        );
    }

    private function lockInventoryYear(int $inventoryId, int $year): void
    {
        Inventory::query()->whereKey($inventoryId)->lockForUpdate()->firstOrFail();
        PriceList::query()
            ->where('inventory_id', $inventoryId)
            ->where('year', $year)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    private function nextRevisionNumber(int $inventoryId, int $year): int
    {
        $max = PriceList::query()
            ->where('inventory_id', $inventoryId)
            ->where('year', $year)
            ->max('revision_number');

        return ((int) $max) + 1;
    }

    /**
     * @param  list<array{hour: int, day_group: DayGroup, second_price: string}>  $items
     */
    private function replaceItems(PriceList $list, array $items): void
    {
        PriceListItem::query()->where('price_list_id', $list->id)->delete();

        foreach ($items as $item) {
            $row = new PriceListItem;
            $row->priceList()->associate($list);
            $row->hour = $item['hour'];
            $row->day_group = $item['day_group'];
            $row->second_price = $item['second_price'];
            $row->save();
        }
    }

    /**
     * @param  list<array{hour: int, day_group: DayGroup, second_price: string}>  $items
     */
    private function itemsEqual(PriceList $list, array $items): bool
    {
        $current = $list->items
            ->map(fn (PriceListItem $item): string => $item->hour.'|'.$item->day_group->value.'|'.$item->second_price)
            ->sort()
            ->values()
            ->all();

        $next = collect($items)
            ->map(fn (array $item): string => $item['hour'].'|'.$item['day_group']->value.'|'.$item['second_price'])
            ->sort()
            ->values()
            ->all();

        return $current === $next;
    }

    private function assertInventory(int $inventoryId): Inventory
    {
        $inventory = Inventory::query()->find($inventoryId);
        if ($inventory === null) {
            throw ValidationException::withMessages([
                'inventory_id' => 'Das Inventar wurde nicht gefunden.',
            ]);
        }

        return $inventory;
    }

    private function assertYear(mixed $value): int
    {
        if (! is_int($value) && ! (is_string($value) && preg_match('/^-?\d+$/', trim($value)) === 1)) {
            throw ValidationException::withMessages([
                'year' => 'Das Kalenderjahr ist erforderlich.',
            ]);
        }

        $year = (int) $value;
        if ($year < 1990 || $year > 2100) {
            throw ValidationException::withMessages([
                'year' => 'Das Kalenderjahr muss zwischen 1990 und 2100 liegen.',
            ]);
        }

        return $year;
    }

    private function assertName(string $name): string
    {
        $trimmed = trim($name);
        if ($trimmed === '') {
            throw ValidationException::withMessages([
                'name' => 'Der Name ist erforderlich.',
            ]);
        }

        if (mb_strlen($trimmed) > 255) {
            throw ValidationException::withMessages([
                'name' => 'Der Name darf maximal 255 Zeichen lang sein.',
            ]);
        }

        return $trimmed;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function assertClientLockPresent(array $payload): void
    {
        if (! array_key_exists('lock_version', $payload)) {
            throw ValidationException::withMessages([
                'lock_version' => 'Die Sperrversion muss vom Formular übermittelt werden.',
            ]);
        }
    }

    private function assertLock(PriceList $list, int $expected): void
    {
        if ((int) $list->lock_version !== $expected) {
            throw new PriceListAdminConflictException(
                'Die Preisliste wurde parallel geändert. Bitte neu laden und erneut prüfen.',
            );
        }
    }

    private function assertDraft(PriceList $list): void
    {
        if ($list->status !== PriceListStatus::Draft) {
            throw ValidationException::withMessages([
                'price_list' => 'Veröffentlichte und archivierte Preisstände sind fachlich unveränderlich. Bitte eine neue Version durch Kopieren anlegen.',
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function rejectImmutableRebind(array $payload, PriceList $list): void
    {
        if (array_key_exists('inventory_id', $payload)
            && (int) $payload['inventory_id'] !== (int) $list->inventory_id
        ) {
            throw ValidationException::withMessages([
                'inventory_id' => 'Die Inventarzuordnung einer angelegten Version ist unveränderlich.',
            ]);
        }

        if (array_key_exists('year', $payload) && (int) $payload['year'] !== (int) $list->year) {
            throw ValidationException::withMessages([
                'year' => 'Das Kalenderjahr einer angelegten Version ist unveränderlich. Für ein anderes Jahr bitte einen neuen Entwurf oder eine Kopie anlegen.',
            ]);
        }

        if (array_key_exists('version', $payload) && (string) $payload['version'] !== (string) $list->version) {
            throw ValidationException::withMessages([
                'version' => 'Die Versionskennung ist unveränderlich.',
            ]);
        }

        if (array_key_exists('status', $payload)
            && (string) $payload['status'] !== $list->status->value
        ) {
            throw ValidationException::withMessages([
                'status' => 'Der Status wird nur über Veröffentlichen oder Archivieren geändert.',
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function auditPayload(PriceList $list): array
    {
        $list->loadMissing('items');

        return [
            'id' => (int) $list->id,
            'inventory_id' => (int) $list->inventory_id,
            'name' => $list->name,
            'year' => (int) $list->year,
            'version' => $list->version,
            'revision_number' => (int) $list->revision_number,
            'status' => $list->status->value,
            'lock_version' => (int) $list->lock_version,
            'item_count' => $list->items->count(),
            'items' => $list->items
                ->sortBy(fn (PriceListItem $item): string => sprintf('%02d|%s', $item->hour, $item->day_group->value))
                ->map(fn (PriceListItem $item): array => [
                    'hour' => (int) $item->hour,
                    'day_group' => $item->day_group->value,
                    'second_price' => (string) $item->second_price,
                ])
                ->values()
                ->all(),
        ];
    }

    private function duplicateIdentity(): ValidationException
    {
        return ValidationException::withMessages([
            'version' => 'Die Versionskennung ist in diesem Inventar und Jahr bereits vergeben.',
        ]);
    }

    private function isUniqueViolation(QueryException $exception): bool
    {
        $code = (string) $exception->getCode();
        $message = $exception->getMessage();

        return in_array($code, ['23000', '19', '1062'], true)
            || str_contains($message, 'price_lists_inventory_year')
            || str_contains($message, 'price_lists_one_active')
            || str_contains($message, 'UNIQUE constraint failed');
    }
}
