<?php

namespace App\Services\ProductionPrice\Admin;

use App\Enums\PriceListStatus;
use App\Enums\ProductionType;
use App\Exceptions\PriceListAdminConflictException;
use App\Models\Inventory;
use App\Models\ProductionPriceList;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * BL-P5-02a: Admin-Lifecycle für inventarspezifische Produktionspreise. Kein Hard Delete.
 *
 * Geldbeträge werden ausschließlich als Dezimal-Strings mit zwei Nachkommastellen geführt.
 */
final class ProductionPriceListAdminWriter
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function createDraft(array $payload, User $actor): ProductionPriceList
    {
        return DB::transaction(function () use ($payload, $actor): ProductionPriceList {
            $inventory = $this->assertInventory((int) ($payload['inventory_id'] ?? 0));
            $type = $this->assertProductionType($payload['production_type'] ?? ProductionType::SpotProduction->value);
            $year = $this->assertYear($payload['year'] ?? null);
            $name = $this->assertName((string) ($payload['name'] ?? ''));
            $unitPrice = $this->assertUnitPrice($payload['unit_price'] ?? null);

            $this->lockScope((int) $inventory->id, $type, $year);
            $list = $this->insertDraftRow(
                (int) $inventory->id,
                $type,
                $year,
                $name,
                $unitPrice,
                $this->bool($payload['is_discountable'] ?? false),
                $this->bool($payload['is_ae_eligible'] ?? false),
            );

            $this->audit->record($list, 'production_price_list.created', $actor, null, $this->auditPayload($list));

            return $list->fresh(['inventory']) ?? $list;
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function updateDraft(ProductionPriceList $list, array $payload, User $actor): ProductionPriceList
    {
        return DB::transaction(function () use ($list, $payload, $actor): ProductionPriceList {
            $this->assertClientLockPresent($payload);
            $this->lockScope((int) $list->inventory_id, $list->production_type, (int) $list->year);

            /** @var ProductionPriceList $locked */
            $locked = ProductionPriceList::query()->whereKey($list->id)->firstOrFail();
            $this->assertLock($locked, (int) $payload['lock_version']);
            $this->assertDraft($locked);
            $this->rejectImmutableRebind($payload, $locked);

            $name = $this->assertName((string) ($payload['name'] ?? $locked->name));
            $unitPrice = $this->assertUnitPrice($payload['unit_price'] ?? $locked->unit_price);
            $discountable = $this->bool($payload['is_discountable'] ?? $locked->is_discountable);
            $aeEligible = $this->bool($payload['is_ae_eligible'] ?? $locked->is_ae_eligible);

            if ($name === $locked->name
                && $unitPrice === $this->money($locked->unit_price)
                && $discountable === (bool) $locked->is_discountable
                && $aeEligible === (bool) $locked->is_ae_eligible
            ) {
                return $locked;
            }

            $before = $this->auditPayload($locked);
            $locked->name = $name;
            $locked->unit_price = $unitPrice;
            $locked->is_discountable = $discountable;
            $locked->is_ae_eligible = $aeEligible;
            $locked->lock_version = $locked->lock_version + 1;
            $locked->save();

            $fresh = $locked->fresh(['inventory']) ?? $locked;
            $this->audit->record($fresh, 'production_price_list.updated', $actor, $before, $this->auditPayload($fresh));

            return $fresh;
        });
    }

    /**
     * Aktiviert einen Entwurf und archiviert eine bisher aktive Liste desselben Inventars,
     * derselben Produktionsart und desselben Jahres.
     *
     * @param  array<string, mixed>  $payload
     */
    public function activate(ProductionPriceList $list, array $payload, User $actor): ProductionPriceList
    {
        return DB::transaction(function () use ($list, $payload, $actor): ProductionPriceList {
            $this->assertClientLockPresent($payload);
            $inventoryId = (int) $list->inventory_id;
            $type = $list->production_type;
            $year = (int) $list->year;
            $this->lockScope($inventoryId, $type, $year);

            /** @var ProductionPriceList $locked */
            $locked = ProductionPriceList::query()->whereKey($list->id)->firstOrFail();
            $this->assertLock($locked, (int) $payload['lock_version']);
            $this->assertDraft($locked);
            $this->assertActivatable($locked);

            $predecessors = ProductionPriceList::query()
                ->where('inventory_id', $inventoryId)
                ->where('production_type', $type->value)
                ->where('year', $year)
                ->where('status', PriceListStatus::Active->value)
                ->where('id', '!=', $locked->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $archivedPayloads = [];
            foreach ($predecessors as $predecessor) {
                $archivedBefore = $this->auditPayload($predecessor);
                $predecessor->status = PriceListStatus::Archived;
                $predecessor->archived_at = now();
                $predecessor->lock_version = $predecessor->lock_version + 1;
                $predecessor->save();
                $archivedPayloads[] = [
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

            $fresh = $locked->fresh(['inventory']) ?? $locked;
            $this->audit->record(
                $fresh,
                'production_price_list.activated',
                $actor,
                $before,
                array_merge($this->auditPayload($fresh), ['archived_predecessors' => $archivedPayloads]),
            );

            return $fresh;
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function archive(ProductionPriceList $list, array $payload, User $actor): ProductionPriceList
    {
        return DB::transaction(function () use ($list, $payload, $actor): ProductionPriceList {
            $this->assertClientLockPresent($payload);
            $this->lockScope((int) $list->inventory_id, $list->production_type, (int) $list->year);

            /** @var ProductionPriceList $locked */
            $locked = ProductionPriceList::query()->whereKey($list->id)->firstOrFail();
            $this->assertLock($locked, (int) $payload['lock_version']);

            if ($locked->status === PriceListStatus::Archived) {
                throw ValidationException::withMessages([
                    'production_price_list' => 'Die Produktionspreisliste ist bereits archiviert.',
                ]);
            }

            $before = $this->auditPayload($locked);
            $locked->status = PriceListStatus::Archived;
            $locked->archived_at = now();
            $locked->lock_version = $locked->lock_version + 1;
            $locked->save();

            $fresh = $locked->fresh(['inventory']) ?? $locked;
            $this->audit->record($fresh, 'production_price_list.archived', $actor, $before, $this->auditPayload($fresh));

            return $fresh;
        });
    }

    /**
     * Legt aus einer beliebigen Liste einen neuen Entwurf an. Jahr und Name sind optional überschreibbar.
     *
     * @param  array<string, mixed>  $payload
     */
    public function copyAsDraft(ProductionPriceList $source, array $payload, User $actor): ProductionPriceList
    {
        return DB::transaction(function () use ($source, $payload, $actor): ProductionPriceList {
            $sourceYear = (int) $source->year;
            $year = array_key_exists('year', $payload) && $payload['year'] !== null
                ? $this->assertYear($payload['year'])
                : $sourceYear;
            $inventoryId = (int) $source->inventory_id;
            $type = $source->production_type;
            $this->lockScope($inventoryId, $type, $sourceYear);
            if ($year !== $sourceYear) {
                $this->lockScope($inventoryId, $type, $year);
            }

            /** @var ProductionPriceList $locked */
            $locked = ProductionPriceList::query()->whereKey($source->id)->firstOrFail();
            $name = $this->assertName((string) ($payload['name'] ?? $locked->name));

            $copy = $this->insertDraftRow(
                $inventoryId,
                $type,
                $year,
                $name,
                $this->money($locked->unit_price),
                (bool) $locked->is_discountable,
                (bool) $locked->is_ae_eligible,
            );

            $this->audit->record(
                $copy,
                'production_price_list.copied',
                $actor,
                null,
                array_merge($this->auditPayload($copy), [
                    'source_id' => (int) $locked->id,
                    'source_version' => $locked->version,
                    'source_year' => (int) $locked->year,
                ]),
            );

            return $copy->fresh(['inventory']) ?? $copy;
        });
    }

    private function lockScope(int $inventoryId, ProductionType $type, int $year): void
    {
        Inventory::query()->whereKey($inventoryId)->lockForUpdate()->firstOrFail();
        ProductionPriceList::query()
            ->where('inventory_id', $inventoryId)
            ->where('production_type', $type->value)
            ->where('year', $year)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    private function insertDraftRow(
        int $inventoryId,
        ProductionType $type,
        int $year,
        string $name,
        string $unitPrice,
        bool $discountable,
        bool $aeEligible,
    ): ProductionPriceList {
        $attempts = 0;
        while ($attempts < 16) {
            $attempts++;
            $revision = $this->nextRevisionNumber($inventoryId, $type, $year);
            try {
                $list = new ProductionPriceList;
                $list->inventory()->associate($inventoryId);
                $list->production_type = $type;
                $list->year = $year;
                $list->name = $name;
                $list->revision_number = $revision;
                $list->version = (string) $revision;
                $list->status = PriceListStatus::Draft;
                $list->lock_version = 1;
                $list->unit_price = $unitPrice;
                $list->is_discountable = $discountable;
                $list->is_ae_eligible = $aeEligible;
                $list->save();

                return $list;
            } catch (UniqueConstraintViolationException) {
                continue;
            } catch (QueryException $exception) {
                if ($this->isUniqueViolation($exception)) {
                    continue;
                }
                throw $exception;
            }
        }

        throw ValidationException::withMessages([
            'version' => 'Die Versionskennung ist in diesem Inventar und Jahr bereits vergeben.',
        ]);
    }

    private function nextRevisionNumber(int $inventoryId, ProductionType $type, int $year): int
    {
        $max = ProductionPriceList::query()
            ->where('inventory_id', $inventoryId)
            ->where('production_type', $type->value)
            ->where('year', $year)
            ->max('revision_number');

        return ((int) $max) + 1;
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

    private function assertProductionType(mixed $value): ProductionType
    {
        $type = $value instanceof ProductionType
            ? $value
            : (is_string($value) ? ProductionType::tryFrom($value) : null);

        if ($type === null || ! $type->isSupportedInSlice()) {
            throw ValidationException::withMessages([
                'production_type' => 'Diese Produktionsart wird noch nicht unterstützt. Erlaubt ist nur Spotproduktion.',
            ]);
        }

        return $type;
    }

    private function assertYear(mixed $value): int
    {
        if (! is_int($value) && ! (is_string($value) && preg_match('/^-?\d+$/', trim($value)) === 1)) {
            throw ValidationException::withMessages([
                'year' => 'Das Kalenderjahr ist erforderlich.',
            ]);
        }

        $year = (int) $value;
        if ($year < 2000 || $year > 2100) {
            throw ValidationException::withMessages([
                'year' => 'Das Kalenderjahr muss zwischen 2000 und 2100 liegen.',
            ]);
        }

        return $year;
    }

    private function assertName(string $name): string
    {
        $trimmed = trim($name);
        if ($trimmed === '') {
            throw ValidationException::withMessages(['name' => 'Der Name ist erforderlich.']);
        }
        if (mb_strlen($trimmed) > 255) {
            throw ValidationException::withMessages(['name' => 'Der Name darf maximal 255 Zeichen lang sein.']);
        }

        return $trimmed;
    }

    /**
     * Normalisiert einen Betrag auf einen Dezimal-String mit zwei Nachkommastellen (z. B. "1250.00").
     */
    private function assertUnitPrice(mixed $value): string
    {
        if (is_float($value)) {
            $value = rtrim(rtrim(sprintf('%.6F', $value), '0'), '.');
        }
        if (is_int($value)) {
            $value = (string) $value;
        }
        if (! is_string($value) || trim($value) === '') {
            throw ValidationException::withMessages([
                'unit_price' => 'Der Produktionspreis ist erforderlich.',
            ]);
        }

        $normalized = str_replace(',', '.', trim($value));
        if (preg_match('/^-\d+(\.\d+)?$/', $normalized) === 1) {
            throw ValidationException::withMessages([
                'unit_price' => 'Der Produktionspreis darf nicht negativ sein.',
            ]);
        }
        if (preg_match('/^\d{1,12}(\.\d{1,2})?$/', $normalized) !== 1) {
            throw ValidationException::withMessages([
                'unit_price' => 'Der Produktionspreis muss ein Betrag mit höchstens zwei Nachkommastellen sein.',
            ]);
        }

        return $this->money($normalized);
    }

    private function money(mixed $value): string
    {
        $string = (string) $value;
        [$int, $frac] = array_pad(explode('.', $string, 2), 2, '');
        $frac = substr(str_pad($frac, 2, '0'), 0, 2);

        return ($int === '' ? '0' : $int).'.'.$frac;
    }

    private function bool(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;
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

    private function assertLock(ProductionPriceList $list, int $expected): void
    {
        if ((int) $list->lock_version !== $expected) {
            throw new PriceListAdminConflictException(
                'Die Produktionspreisliste wurde parallel geändert. Bitte neu laden und erneut prüfen.',
            );
        }
    }

    private function assertDraft(ProductionPriceList $list): void
    {
        if ($list->status !== PriceListStatus::Draft) {
            throw ValidationException::withMessages([
                'production_price_list' => 'Veröffentlichte und archivierte Produktionspreise sind unveränderlich. Bitte eine neue Version durch Kopieren anlegen.',
            ]);
        }
    }

    private function assertActivatable(ProductionPriceList $list): void
    {
        $this->assertProductionType($list->production_type);
        $this->assertUnitPrice($list->unit_price);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function rejectImmutableRebind(array $payload, ProductionPriceList $list): void
    {
        if (array_key_exists('inventory_id', $payload) && (int) $payload['inventory_id'] !== (int) $list->inventory_id) {
            throw ValidationException::withMessages([
                'inventory_id' => 'Die Inventarzuordnung einer angelegten Version ist unveränderlich.',
            ]);
        }
        if (array_key_exists('year', $payload) && (int) $payload['year'] !== (int) $list->year) {
            throw ValidationException::withMessages([
                'year' => 'Das Kalenderjahr einer angelegten Version ist unveränderlich. Bitte eine Kopie anlegen.',
            ]);
        }
        if (array_key_exists('production_type', $payload) && (string) $payload['production_type'] !== $list->production_type->value) {
            throw ValidationException::withMessages([
                'production_type' => 'Die Produktionsart einer angelegten Version ist unveränderlich.',
            ]);
        }
        if (array_key_exists('version', $payload) && (string) $payload['version'] !== (string) $list->version) {
            throw ValidationException::withMessages([
                'version' => 'Die Versionskennung ist unveränderlich.',
            ]);
        }
        if (array_key_exists('status', $payload) && (string) $payload['status'] !== $list->status->value) {
            throw ValidationException::withMessages([
                'status' => 'Der Status wird nur über Aktivieren oder Archivieren geändert.',
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function auditPayload(ProductionPriceList $list): array
    {
        return [
            'id' => (int) $list->id,
            'inventory_id' => (int) $list->inventory_id,
            'production_type' => $list->production_type->value,
            'year' => (int) $list->year,
            'name' => $list->name,
            'version' => $list->version,
            'revision_number' => (int) $list->revision_number,
            'status' => $list->status->value,
            'lock_version' => (int) $list->lock_version,
            'unit_price' => $this->money($list->unit_price),
            'is_discountable' => (bool) $list->is_discountable,
            'is_ae_eligible' => (bool) $list->is_ae_eligible,
        ];
    }

    private function isUniqueViolation(QueryException $exception): bool
    {
        $code = (string) $exception->getCode();
        $message = $exception->getMessage();

        return in_array($code, ['23000', '19', '1062'], true)
            || str_contains($message, 'prod_price_lists_identity_unique')
            || str_contains($message, 'UNIQUE constraint failed');
    }
}
