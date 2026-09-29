<?php

namespace App\Services\InventoryMediumRule\Admin;

use App\Enums\ComponentCalculationStrategy;
use App\Exceptions\CatalogAdminConflictException;
use App\Models\AdvertisingMedium;
use App\Models\Inventory;
use App\Models\InventoryMediumRule;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\InventoryMediumRule\InventoryMediumRuleOperativeContract;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * BL-P2-02a / MAT-CORE-1: Admin-Lifecycle für Inventar-/Werbemittel-Kombinationen.
 * Kein Hard Delete, keine Memberships, keine Produktivmatrix-Seeds.
 */
final class InventoryMediumRuleAdminWriter
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function create(array $payload, User $actor): InventoryMediumRule
    {
        return DB::transaction(function () use ($payload, $actor): InventoryMediumRule {
            $inventory = $this->assertActiveInventory((int) $payload['inventory_id']);
            $medium = $this->assertActiveMedium((int) $payload['advertising_medium_id']);
            $isActive = array_key_exists('is_active', $payload) ? (bool) $payload['is_active'] : true;

            $fields = $this->normalizeWritableFields($payload, requireOperativeWhenActive: $isActive);

            try {
                $rule = new InventoryMediumRule;
                $rule->inventory()->associate($inventory);
                $rule->advertisingMedium()->associate($medium);
                $rule->is_active = $isActive;
                $this->applyWritableFields($rule, $fields);
                $rule->lock_version = 1;
                $rule->save();
            } catch (UniqueConstraintViolationException) {
                throw $this->duplicateCombination();
            } catch (QueryException $exception) {
                if ($this->isUniqueViolation($exception)) {
                    throw $this->duplicateCombination();
                }
                throw $exception;
            }

            $this->audit->record(
                $rule,
                'inventory_medium_rule.created',
                $actor,
                null,
                $this->auditPayload($rule),
            );

            return $rule->fresh(['inventory', 'advertisingMedium.category']) ?? $rule;
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function update(InventoryMediumRule $rule, array $payload, User $actor): InventoryMediumRule
    {
        return DB::transaction(function () use ($rule, $payload, $actor): InventoryMediumRule {
            /** @var InventoryMediumRule $locked */
            $locked = InventoryMediumRule::query()->whereKey($rule->id)->lockForUpdate()->firstOrFail();
            $this->assertLock($locked, (int) $payload['lock_version']);

            if (array_key_exists('inventory_id', $payload)
                && (int) $payload['inventory_id'] !== (int) $locked->inventory_id
            ) {
                throw ValidationException::withMessages([
                    'inventory_id' => 'Inventar und Werbemittel der Kombination sind nach dem Anlegen unveränderlich.',
                ]);
            }
            if (array_key_exists('advertising_medium_id', $payload)
                && (int) $payload['advertising_medium_id'] !== (int) $locked->advertising_medium_id
            ) {
                throw ValidationException::withMessages([
                    'advertising_medium_id' => 'Inventar und Werbemittel der Kombination sind nach dem Anlegen unveränderlich.',
                ]);
            }
            if (array_key_exists('is_active', $payload)
                && (bool) $payload['is_active'] !== (bool) $locked->is_active
            ) {
                throw ValidationException::withMessages([
                    'is_active' => 'Der Aktivstatus wird nur über Aktivieren oder Deaktivieren geändert.',
                ]);
            }

            $fields = $this->normalizeWritableFields(
                $payload,
                requireOperativeWhenActive: (bool) $locked->is_active,
            );

            $before = $this->auditPayload($locked);
            $this->applyWritableFields($locked, $fields);

            if ($this->auditPayload($locked) === $before) {
                return $locked->loadMissing(['inventory', 'advertisingMedium.category']);
            }

            $locked->lock_version = (int) $locked->lock_version + 1;
            $locked->save();

            $this->audit->record(
                $locked,
                'inventory_medium_rule.updated',
                $actor,
                $before,
                $this->auditPayload($locked),
            );

            return $locked->fresh(['inventory', 'advertisingMedium.category']) ?? $locked;
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function deactivate(InventoryMediumRule $rule, array $payload, User $actor): InventoryMediumRule
    {
        return DB::transaction(function () use ($rule, $payload, $actor): InventoryMediumRule {
            /** @var InventoryMediumRule $locked */
            $locked = InventoryMediumRule::query()->whereKey($rule->id)->lockForUpdate()->firstOrFail();
            $this->assertLock($locked, (int) $payload['lock_version']);

            if (! $locked->is_active) {
                return $locked->loadMissing(['inventory', 'advertisingMedium.category']);
            }

            $before = $this->auditPayload($locked);
            $locked->is_active = false;
            $locked->lock_version = (int) $locked->lock_version + 1;
            $locked->save();

            $this->audit->record(
                $locked,
                'inventory_medium_rule.deactivated',
                $actor,
                $before,
                $this->auditPayload($locked),
            );

            return $locked->fresh(['inventory', 'advertisingMedium.category']) ?? $locked;
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function reactivate(InventoryMediumRule $rule, array $payload, User $actor): InventoryMediumRule
    {
        return DB::transaction(function () use ($rule, $payload, $actor): InventoryMediumRule {
            /** @var InventoryMediumRule $locked */
            $locked = InventoryMediumRule::query()->whereKey($rule->id)->lockForUpdate()->firstOrFail();
            $this->assertLock($locked, (int) $payload['lock_version']);

            if ($locked->is_active) {
                return $locked->loadMissing(['inventory', 'advertisingMedium.category']);
            }

            $this->assertInventoryAndMediumStillActive($locked);
            InventoryMediumRuleOperativeContract::assertCompleteForActiveUse($locked, 'rule');
            // Reaktivierung trotz must_not_plan erlaubt (Admin-Stammdatum); Runtime blockiert Planung.

            $before = $this->auditPayload($locked);
            $locked->is_active = true;
            $locked->lock_version = (int) $locked->lock_version + 1;
            $locked->save();

            $this->audit->record(
                $locked,
                'inventory_medium_rule.reactivated',
                $actor,
                $before,
                $this->auditPayload($locked),
            );

            return $locked->fresh(['inventory', 'advertisingMedium.category']) ?? $locked;
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{
     *     booking_code: string|null,
     *     planning_responsibility_key: string|null,
     *     hint_text: string|null,
     *     sort: int,
     *     default_length_seconds: int|null,
     *     surcharge_percent: string,
     *     is_discountable: bool,
     *     is_ae_eligible: bool,
     *     component_calculation_strategy: ComponentCalculationStrategy
     * }
     */
    private function normalizeWritableFields(array $payload, bool $requireOperativeWhenActive): array
    {
        $booking = InventoryMediumRuleOperativeContract::normalizeBookingCode($payload['booking_code'] ?? null);
        $planning = InventoryMediumRuleOperativeContract::normalizePlanningKey(
            $payload['planning_responsibility_key'] ?? null,
        );
        $hint = InventoryMediumRuleOperativeContract::normalizeHint($payload['hint_text'] ?? null);

        if ($planning !== null && ! InventoryMediumRuleOperativeContract::isKnownPlanningKey($planning)) {
            throw ValidationException::withMessages([
                'planning_responsibility_key' => 'Unbekannter Wert für „Einplanung durch“.',
            ]);
        }

        if ($booking !== null && mb_strlen($booking) > 32) {
            throw ValidationException::withMessages([
                'booking_code' => 'Buchungskennzeichen darf höchstens 32 Zeichen haben.',
            ]);
        }

        if ($hint !== null && mb_strlen($hint) > 5000) {
            throw ValidationException::withMessages([
                'hint_text' => 'Hinweistext ist zu lang (max. 5000 Zeichen).',
            ]);
        }

        if ($requireOperativeWhenActive && ($booking === null || $planning === null)) {
            $messages = [];
            if ($booking === null) {
                $messages['booking_code'] = 'Aktive Kombinationen benötigen ein Buchungskennzeichen.';
            }
            if ($planning === null) {
                $messages['planning_responsibility_key'] = 'Aktive Kombinationen benötigen „Einplanung durch“.';
            }
            throw ValidationException::withMessages($messages);
        }

        $sort = isset($payload['sort']) ? (int) $payload['sort'] : 0;
        if ($sort < 0 || $sort > 999999) {
            throw ValidationException::withMessages([
                'sort' => 'Sortierung muss zwischen 0 und 999999 liegen.',
            ]);
        }

        $defaultLength = array_key_exists('default_length_seconds', $payload)
            && $payload['default_length_seconds'] !== null
            && $payload['default_length_seconds'] !== ''
            ? (int) $payload['default_length_seconds']
            : null;
        if ($defaultLength !== null && ($defaultLength < 1 || $defaultLength > 3600)) {
            throw ValidationException::withMessages([
                'default_length_seconds' => 'Standardlänge muss zwischen 1 und 3600 Sekunden liegen.',
            ]);
        }

        $surcharge = array_key_exists('surcharge_percent', $payload)
            ? (string) $payload['surcharge_percent']
            : '0';
        if (! is_numeric($surcharge) || (float) $surcharge < 0 || (float) $surcharge > 999.9999) {
            throw ValidationException::withMessages([
                'surcharge_percent' => 'Aufschlag ist ungültig.',
            ]);
        }

        $strategy = ComponentCalculationStrategy::tryFrom(
            (string) ($payload['component_calculation_strategy'] ?? ComponentCalculationStrategy::SharedTotalLength->value),
        );
        if ($strategy === null) {
            throw ValidationException::withMessages([
                'component_calculation_strategy' => 'Ungültige Komponentenstrategie.',
            ]);
        }

        return [
            'booking_code' => $booking,
            'planning_responsibility_key' => $planning,
            'hint_text' => $hint,
            'sort' => $sort,
            'default_length_seconds' => $defaultLength,
            'surcharge_percent' => number_format((float) $surcharge, 4, '.', ''),
            'is_discountable' => array_key_exists('is_discountable', $payload)
                ? (bool) $payload['is_discountable']
                : true,
            'is_ae_eligible' => array_key_exists('is_ae_eligible', $payload)
                ? (bool) $payload['is_ae_eligible']
                : true,
            'component_calculation_strategy' => $strategy,
        ];
    }

    /**
     * @param  array{
     *     booking_code: string|null,
     *     planning_responsibility_key: string|null,
     *     hint_text: string|null,
     *     sort: int,
     *     default_length_seconds: int|null,
     *     surcharge_percent: string,
     *     is_discountable: bool,
     *     is_ae_eligible: bool,
     *     component_calculation_strategy: ComponentCalculationStrategy
     * }  $fields
     */
    private function applyWritableFields(InventoryMediumRule $rule, array $fields): void
    {
        $rule->booking_code = $fields['booking_code'];
        $rule->planning_responsibility_key = $fields['planning_responsibility_key'];
        $rule->hint_text = $fields['hint_text'];
        $rule->sort = $fields['sort'];
        $rule->default_length_seconds = $fields['default_length_seconds'];
        $rule->surcharge_percent = $fields['surcharge_percent'];
        $rule->is_discountable = $fields['is_discountable'];
        $rule->is_ae_eligible = $fields['is_ae_eligible'];
        $rule->component_calculation_strategy = $fields['component_calculation_strategy'];
    }

    private function assertActiveInventory(int $id): Inventory
    {
        $inventory = Inventory::query()->whereKey($id)->where('is_active', true)->first();
        if ($inventory === null) {
            throw ValidationException::withMessages([
                'inventory_id' => 'Inventar ist unbekannt oder inaktiv.',
            ]);
        }

        return $inventory;
    }

    private function assertActiveMedium(int $id): AdvertisingMedium
    {
        $medium = AdvertisingMedium::query()->whereKey($id)->where('is_active', true)->first();
        if ($medium === null) {
            throw ValidationException::withMessages([
                'advertising_medium_id' => 'Werbemittel ist unbekannt oder inaktiv.',
            ]);
        }

        return $medium;
    }

    private function assertInventoryAndMediumStillActive(InventoryMediumRule $rule): void
    {
        $inventory = Inventory::query()->whereKey($rule->inventory_id)->where('is_active', true)->first();
        if ($inventory === null) {
            throw ValidationException::withMessages([
                'rule' => 'Das Inventar der Kombination ist inaktiv. Reaktivierung nicht möglich.',
            ]);
        }
        $medium = AdvertisingMedium::query()->whereKey($rule->advertising_medium_id)->where('is_active', true)->first();
        if ($medium === null) {
            throw ValidationException::withMessages([
                'rule' => 'Das Werbemittel der Kombination ist inaktiv. Reaktivierung nicht möglich.',
            ]);
        }
    }

    private function assertLock(InventoryMediumRule $rule, int $expected): void
    {
        if ((int) $rule->lock_version !== $expected) {
            throw new CatalogAdminConflictException(
                'Die Kombination wurde parallel geändert. Bitte neu laden und erneut speichern.',
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function auditPayload(InventoryMediumRule $rule): array
    {
        return [
            'inventory_id' => (int) $rule->inventory_id,
            'advertising_medium_id' => (int) $rule->advertising_medium_id,
            'is_active' => (bool) $rule->is_active,
            'booking_code' => $rule->booking_code,
            'planning_responsibility_key' => $rule->planning_responsibility_key,
            'hint_text' => $rule->hint_text,
            'sort' => (int) $rule->sort,
            'default_length_seconds' => $rule->default_length_seconds,
            'surcharge_percent' => (string) $rule->surcharge_percent,
            'is_discountable' => (bool) $rule->is_discountable,
            'is_ae_eligible' => (bool) $rule->is_ae_eligible,
            'component_calculation_strategy' => $rule->component_calculation_strategy->value,
            'lock_version' => (int) $rule->lock_version,
        ];
    }

    private function duplicateCombination(): ValidationException
    {
        return ValidationException::withMessages([
            'advertising_medium_id' => 'Für dieses Inventar und Werbemittel existiert bereits eine Kombination.',
        ]);
    }

    private function isUniqueViolation(QueryException $exception): bool
    {
        $message = $exception->getMessage();

        return str_contains($message, 'inventory_medium_unique')
            || str_contains($message, 'UNIQUE constraint failed');
    }
}
