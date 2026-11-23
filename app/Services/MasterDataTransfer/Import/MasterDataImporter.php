<?php

namespace App\Services\MasterDataTransfer\Import;

use App\Enums\CalculationKind;
use App\Enums\CalculationMethodMode;
use App\Enums\ComponentCalculationStrategy;
use App\Enums\InventoryType;
use App\Enums\PriceListStatus;
use App\Enums\ProductionType;
use App\Enums\SpotComponentProfile;
use App\Models\AdvertisingCategory;
use App\Models\AdvertisingCategoryCalculationMethod;
use App\Models\AdvertisingMedium;
use App\Models\AdvertisingMediumCalculationMethod;
use App\Models\CalculationMethod;
use App\Models\Inventory;
use App\Models\InventoryMediumRule;
use App\Models\Organization;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\ProductionPriceList;
use App\Models\User;
use App\Services\MasterDataTransfer\MasterDataTransferContract;
use App\Services\MasterDataTransfer\MasterDataTransferException;
use App\Services\MasterDataTransfer\Support\MasterDataPackage;
use App\Services\MasterDataTransfer\Support\PriceListFingerprint;
use App\Services\MasterDataTransfer\Support\SpotScopeGuard;
use Illuminate\Support\Facades\DB;

/**
 * EPIC-03-B: fail-closed Import (schreibfreier Dry-Run / Apply) ohne ID-Übernahme und ohne Auto-Activate.
 */
final class MasterDataImporter
{
    public function __construct(
        private readonly MasterDataPackage $packages,
        private readonly SpotScopeGuard $spotScope,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function import(string $packagePath, bool $dryRun, string $confirmDatabase): array
    {
        $current = (string) config('database.connections.'.config('database.default').'.database');
        if ($confirmDatabase === '' || $confirmDatabase !== $current) {
            throw new MasterDataTransferException(sprintf(
                'Abbruch: --confirm-database muss exakt die aktive DB sein (aktiv=%s, confirm=%s).',
                $current,
                $confirmDatabase === '' ? '(leer)' : $confirmDatabase,
            ));
        }

        if (in_array($confirmDatabase, ['dispo_mat_core', 'dispo'], true) && ! $dryRun) {
            throw new MasterDataTransferException(
                'Apply gegen geschützte Datenbank ist nicht erlaubt: '.$confirmDatabase,
            );
        }

        $package = $this->packages->load($packagePath);
        if ($package['schema_version'] !== MasterDataTransferContract::SCHEMA_VERSION) {
            throw new MasterDataTransferException(sprintf(
                'Import für schema_version=%s ist in diesem Slice nicht freigegeben (erwartet %s).',
                $package['schema_version'],
                MasterDataTransferContract::SCHEMA_VERSION,
            ));
        }

        $usersBefore = $this->snapshotUsers();
        $this->spotScope->assertPackage($package['data'], $package['manifest']);

        $report = new MasterDataImportReport;
        $ops = $this->plan($package['data'], $report);

        if ($report->hasConflicts()) {
            throw new MasterDataTransferException('Import fail-closed wegen Konflikten.', $report->conflictMessages());
        }

        if ($this->snapshotUsers() !== $usersBefore) {
            throw new MasterDataTransferException('Benutzerbestand wurde verändert — Abbruch.');
        }

        if ($dryRun) {
            return $report->toArray(true);
        }

        return DB::transaction(function () use ($ops, $report, $usersBefore): array {
            $this->apply($ops);
            if ($this->snapshotUsers() !== $usersBefore) {
                throw new MasterDataTransferException('Benutzerbestand wurde verändert — Abbruch.');
            }

            return $report->toArray(false);
        });
    }

    /**
     * @return list<array{id: int, email: string, role: string, is_active: bool}>
     */
    private function snapshotUsers(): array
    {
        $rows = [];
        foreach (User::query()->orderBy('id')->get(['id', 'email', 'role', 'is_active']) as $u) {
            $rows[] = [
                'id' => (int) $u->id,
                'email' => (string) $u->email,
                'role' => (string) $u->role->value,
                'is_active' => (bool) $u->is_active,
            ];
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<callable(): void>
     */
    private function plan(array $data, MasterDataImportReport $report): array
    {
        $ops = [];
        $ops = array_merge($ops, $this->planOrganization($data['data/organization.json'], $report));
        $this->planCalculationMethods($data['data/calculation_methods.json'], $report);
        $ops = array_merge($ops, $this->planCategories($data['data/advertising_categories.json'], $report));
        $ops = array_merge($ops, $this->planInventories($data['data/inventories.json'], $report));
        $ops = array_merge($ops, $this->planMedia($data['data/advertising_media.json'], $report));
        $ops = array_merge($ops, $this->planCategoryMethods($data['data/advertising_category_calculation_methods.json'], $report));
        $ops = array_merge($ops, $this->planMediumMethods($data['data/advertising_medium_calculation_methods.json'], $report));
        $ops = array_merge($ops, $this->planRules($data['data/inventory_medium_rules.json'], $report));
        $ops = array_merge($ops, $this->planPriceLists($data['data/price_lists.json'], $report));
        $ops = array_merge($ops, $this->planProductionPriceLists($data['data/production_price_lists.json'], $report));

        return $ops;
    }

    /**
     * @param  list<callable(): void>  $ops
     */
    private function apply(array $ops): void
    {
        foreach ($ops as $op) {
            $op();
        }
    }

    /**
     * @param  array<string, mixed>  $row
     * @return list<callable(): void>
     */
    private function planOrganization(array $row, MasterDataImportReport $report): array
    {
        $name = trim((string) ($row['name'] ?? ''));
        if ($name === '') {
            $report->conflict('organization.name fehlt.');

            return [];
        }

        $existing = Organization::query()->orderBy('id')->first();
        if ($existing === null) {
            $report->add('organization', $name, 'create');

            return [function () use ($name): void {
                Organization::query()->create(['name' => $name]);
            }];
        }

        if ($existing->name !== $name) {
            $report->conflict(sprintf(
                'Organisation abweichend (vorhanden=%s, paket=%s) — keine stille Umbenennung.',
                $existing->name,
                $name,
            ));

            return [];
        }

        $report->add('organization', $name, 'unchanged');

        return [];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function planCalculationMethods(array $rows, MasterDataImportReport $report): void
    {
        foreach ($rows as $row) {
            $key = (string) ($row['key'] ?? '');
            if ($key === '') {
                $report->conflict('calculation_method.key fehlt.');

                continue;
            }
            $existing = CalculationMethod::query()->where('key', $key)->first();
            if ($existing === null) {
                $report->conflict('Berechnungsmethode fehlt in Ziel-DB: '.$key);

                continue;
            }
            $expected = [
                'name' => (string) $row['name'],
                'is_active' => (bool) $row['is_active'],
                'sort' => (int) $row['sort'],
            ];
            $actual = [
                'name' => $existing->name,
                'is_active' => (bool) $existing->is_active,
                'sort' => (int) $existing->sort,
            ];
            if ($expected !== $actual) {
                $report->conflict('calculation_method abweichend (kein Überschreiben): '.$key);

                continue;
            }
            $report->add('calculation_method', $key, 'unchanged');
        }
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<callable(): void>
     */
    private function planCategories(array $rows, MasterDataImportReport $report): array
    {
        $ops = [];
        foreach ($rows as $row) {
            $key = (string) ($row['key'] ?? '');
            if ($key === '') {
                $report->conflict('category.key fehlt.');

                continue;
            }
            if (! MasterDataTransferContract::isAllowedCategoryKey($key)) {
                $report->conflict('Kategorie außerhalb Spot-Scope: '.$key);

                continue;
            }
            $existing = AdvertisingCategory::query()->where('key', $key)->first();
            $defaultMethodKey = $row['default_calculation_method_key'] ?? null;
            $defaultMethodId = null;
            if (is_string($defaultMethodKey) && $defaultMethodKey !== '') {
                $defaultMethodId = CalculationMethod::query()->where('key', $defaultMethodKey)->value('id');
                if ($defaultMethodId === null) {
                    $report->conflict('default_calculation_method_key unbekannt: '.$defaultMethodKey);

                    continue;
                }
            }

            $payload = [
                'name' => (string) $row['name'],
                'is_active' => (bool) $row['is_active'],
                'sort' => (int) $row['sort'],
                'default_calculation_method_id' => $defaultMethodId,
            ];

            if ($existing === null) {
                $report->add('advertising_category', $key, 'create');
                $ops[] = function () use ($key, $payload): void {
                    $category = new AdvertisingCategory;
                    $category->forceFill(['key' => $key, ...$payload, 'lock_version' => 1])->save();
                };

                continue;
            }

            $actual = [
                'name' => $existing->name,
                'is_active' => (bool) $existing->is_active,
                'sort' => (int) $existing->sort,
                'default_calculation_method_id' => $existing->default_calculation_method_id,
            ];
            if ($actual !== $payload) {
                if ($existing->name !== $payload['name']) {
                    $report->conflict('advertising_category Namenskonflikt: '.$key);

                    continue;
                }
                $report->add('advertising_category', $key, 'update', 'nicht-destruktives Angleichen von Metadaten');
                $ops[] = function () use ($existing, $payload): void {
                    $existing->forceFill($payload)->save();
                };

                continue;
            }
            $report->add('advertising_category', $key, 'unchanged');
        }

        return $ops;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<callable(): void>
     */
    private function planInventories(array $rows, MasterDataImportReport $report): array
    {
        $ops = [];
        $organizationExists = Organization::query()->orderBy('id')->exists();
        $willCreateOrganization = ! $organizationExists;

        foreach ($rows as $row) {
            $code = (string) ($row['code'] ?? '');
            if ($code === '') {
                $report->conflict('inventory.code fehlt.');

                continue;
            }
            $type = InventoryType::tryFrom((string) ($row['type'] ?? ''));
            if ($type === null) {
                $report->conflict('inventory.type ungültig: '.$code);

                continue;
            }
            $payload = [
                'name' => (string) $row['name'],
                'type' => $type,
                'is_active' => (bool) $row['is_active'],
                'sort' => (int) $row['sort'],
            ];
            $existing = Inventory::query()->where('code', $code)->first();
            if ($existing === null) {
                if (! $organizationExists && ! $willCreateOrganization) {
                    $report->conflict('Organisation fehlt vor Inventar-Import.');

                    continue;
                }
                $report->add('inventory', $code, 'create');
                $ops[] = function () use ($code, $payload): void {
                    $organization = Organization::query()->orderBy('id')->first();
                    if ($organization === null) {
                        throw new MasterDataTransferException('Organisation fehlt vor Inventar-Import.');
                    }
                    $inventory = new Inventory;
                    $inventory->forceFill([
                        'organization_id' => $organization->id,
                        'code' => $code,
                        ...$payload,
                        'lock_version' => 1,
                    ])->save();
                };

                continue;
            }
            if ($existing->type !== $type || $existing->name !== $payload['name']) {
                $report->conflict('inventory Konflikt (name/type): '.$code);

                continue;
            }
            if ((bool) $existing->is_active !== $payload['is_active'] || (int) $existing->sort !== $payload['sort']) {
                $report->add('inventory', $code, 'update', 'is_active/sort');
                $ops[] = function () use ($existing, $payload): void {
                    $existing->forceFill([
                        'is_active' => $payload['is_active'],
                        'sort' => $payload['sort'],
                    ])->save();
                };

                continue;
            }
            $report->add('inventory', $code, 'unchanged');
        }

        return $ops;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<callable(): void>
     */
    private function planMedia(array $rows, MasterDataImportReport $report): array
    {
        $ops = [];
        foreach ($rows as $row) {
            $code = (string) ($row['code'] ?? '');
            $categoryKey = (string) ($row['category_key'] ?? '');
            if ($code === '' || $categoryKey === '') {
                $report->conflict('advertising_medium code/category_key fehlt.');

                continue;
            }
            if (MasterDataTransferContract::isExcludedMediumCode($code)) {
                $report->conflict('Ausgeschlossenes Werbemittel im Paket: '.$code);

                continue;
            }
            if (! MasterDataTransferContract::isAllowedCategoryKey($categoryKey)) {
                $report->conflict('Werbemittel-Kategorie außerhalb Spot-Scope: '.$code);

                continue;
            }
            $category = AdvertisingCategory::query()->where('key', $categoryKey)->first();
            $kind = ($row['kind'] ?? null) === null || $row['kind'] === ''
                ? null
                : CalculationKind::tryFrom((string) $row['kind']);
            if (($row['kind'] ?? null) !== null && $row['kind'] !== '' && $kind === null) {
                $report->conflict('kind ungültig: '.$code);

                continue;
            }
            $mode = CalculationMethodMode::tryFrom((string) ($row['calculation_method_mode'] ?? 'inherit'));
            if ($mode === null) {
                $report->conflict('calculation_method_mode ungültig: '.$code);

                continue;
            }
            $profile = ($row['component_profile'] ?? null) === null || $row['component_profile'] === ''
                ? null
                : SpotComponentProfile::tryFrom((string) $row['component_profile']);
            if (($row['component_profile'] ?? null) !== null && $row['component_profile'] !== '' && $profile === null) {
                $report->conflict('component_profile ungültig: '.$code);

                continue;
            }
            $defaultMethodKey = $row['default_calculation_method_key'] ?? null;
            $defaultMethodId = null;
            if (is_string($defaultMethodKey) && $defaultMethodKey !== '') {
                $defaultMethodId = CalculationMethod::query()->where('key', $defaultMethodKey)->value('id');
                if ($defaultMethodId === null) {
                    $report->conflict('default_calculation_method_key unbekannt: '.$defaultMethodKey);

                    continue;
                }
            }

            $existing = AdvertisingMedium::query()->where('code', $code)->first();
            if ($existing === null) {
                $report->add('advertising_medium', $code, 'create');
                $ops[] = function () use ($code, $categoryKey, $kind, $profile, $mode, $defaultMethodId, $row): void {
                    $category = AdvertisingCategory::query()->where('key', $categoryKey)->first();
                    if ($category === null) {
                        throw new MasterDataTransferException('Kategorie für Medium fehlt: '.$categoryKey);
                    }
                    $medium = new AdvertisingMedium;
                    $medium->forceFill([
                        'code' => $code,
                        'category_id' => $category->id,
                        'name' => (string) $row['name'],
                        'kind' => $kind,
                        'component_profile' => $profile,
                        'calculation_method_mode' => $mode,
                        'default_calculation_method_id' => $defaultMethodId,
                        'default_length_seconds' => (int) $row['default_length_seconds'],
                        'is_discountable' => (bool) $row['is_discountable'],
                        'is_ae_eligible' => (bool) $row['is_ae_eligible'],
                        'is_active' => (bool) $row['is_active'],
                        'sort' => (int) $row['sort'],
                        'lock_version' => 1,
                    ])->save();
                };

                continue;
            }

            if ($category === null) {
                $report->conflict('Kategorie für Medium fehlt: '.$categoryKey);

                continue;
            }

            $payload = [
                'category_id' => $category->id,
                'name' => (string) $row['name'],
                'kind' => $kind,
                'component_profile' => $profile,
                'calculation_method_mode' => $mode,
                'default_calculation_method_id' => $defaultMethodId,
                'default_length_seconds' => (int) $row['default_length_seconds'],
                'is_discountable' => (bool) $row['is_discountable'],
                'is_ae_eligible' => (bool) $row['is_ae_eligible'],
                'is_active' => (bool) $row['is_active'],
                'sort' => (int) $row['sort'],
            ];

            $same = $existing->name === $payload['name']
                && $existing->category_id === $payload['category_id']
                && $existing->kind === $payload['kind']
                && $existing->component_profile === $payload['component_profile']
                && $existing->calculation_method_mode === $payload['calculation_method_mode']
                && $existing->default_calculation_method_id === $payload['default_calculation_method_id']
                && (int) $existing->default_length_seconds === $payload['default_length_seconds']
                && (bool) $existing->is_discountable === $payload['is_discountable']
                && (bool) $existing->is_ae_eligible === $payload['is_ae_eligible']
                && (bool) $existing->is_active === $payload['is_active']
                && (int) $existing->sort === $payload['sort'];

            if (! $same) {
                $report->conflict('advertising_medium Konflikt: '.$code);

                continue;
            }
            $report->add('advertising_medium', $code, 'unchanged');
        }

        return $ops;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<callable(): void>
     */
    private function planCategoryMethods(array $rows, MasterDataImportReport $report): array
    {
        $ops = [];
        foreach ($rows as $row) {
            $engine = (string) ($row['engine_profile_key'] ?? '');
            if (MasterDataTransferContract::isExcludedEngineProfile($engine)) {
                $report->conflict('OA-Kategorie-Methode im Paket: '.$engine);

                continue;
            }
            $categoryKey = (string) ($row['category_key'] ?? '');
            $methodKey = (string) ($row['calculation_method_key'] ?? '');
            if ($categoryKey !== '' && ! MasterDataTransferContract::isAllowedCategoryKey($categoryKey)) {
                $report->conflict('Kategorie-Methode außerhalb Spot-Scope: '.$categoryKey);

                continue;
            }
            $category = AdvertisingCategory::query()->where('key', $categoryKey)->first();
            $method = CalculationMethod::query()->where('key', $methodKey)->first();
            if ($method === null) {
                $report->conflict(sprintf('category method Referenz fehlt: %s/%s', $categoryKey, $methodKey));

                continue;
            }
            $key = $categoryKey.'|'.$methodKey.'|'.$engine;
            $payload = [
                'engine_profile_key' => $engine === '' ? null : $engine,
                'is_active' => (bool) $row['is_active'],
                'sort' => (int) $row['sort'],
            ];

            if ($category === null) {
                $report->add('category_method', $key, 'create');
                $ops[] = function () use ($categoryKey, $method, $payload): void {
                    $category = AdvertisingCategory::query()->where('key', $categoryKey)->first();
                    if ($category === null) {
                        throw new MasterDataTransferException('category method Kategorie fehlt: '.$categoryKey);
                    }
                    $model = new AdvertisingCategoryCalculationMethod;
                    $model->forceFill([
                        'advertising_category_id' => $category->id,
                        'calculation_method_id' => $method->id,
                        ...$payload,
                        'lock_version' => 1,
                    ])->save();
                };

                continue;
            }

            $existing = AdvertisingCategoryCalculationMethod::query()
                ->where('advertising_category_id', $category->id)
                ->where('calculation_method_id', $method->id)
                ->where('engine_profile_key', $payload['engine_profile_key'])
                ->first();

            if ($existing === null) {
                $report->add('category_method', $key, 'create');
                $ops[] = function () use ($category, $method, $payload): void {
                    $model = new AdvertisingCategoryCalculationMethod;
                    $model->forceFill([
                        'advertising_category_id' => $category->id,
                        'calculation_method_id' => $method->id,
                        ...$payload,
                        'lock_version' => 1,
                    ])->save();
                };

                continue;
            }

            if ((string) $existing->engine_profile_key !== (string) $payload['engine_profile_key']
                || (bool) $existing->is_active !== $payload['is_active']
                || (int) $existing->sort !== $payload['sort']) {
                $report->conflict('category_method Konflikt: '.$key);

                continue;
            }
            $report->add('category_method', $key, 'unchanged');
        }

        return $ops;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<callable(): void>
     */
    private function planMediumMethods(array $rows, MasterDataImportReport $report): array
    {
        $ops = [];
        foreach ($rows as $row) {
            $engine = (string) ($row['engine_profile_key'] ?? '');
            if (MasterDataTransferContract::isExcludedEngineProfile($engine)) {
                $report->conflict('OA-Engine-Zuordnung im Paket: '.$engine);

                continue;
            }
            $mediumCode = (string) ($row['advertising_medium_code'] ?? '');
            $methodKey = (string) ($row['calculation_method_key'] ?? '');
            if (MasterDataTransferContract::isExcludedMediumCode($mediumCode)) {
                $report->conflict('Medium-Methodenzuordnung für ausgeschlossenes Medium: '.$mediumCode);

                continue;
            }
            $method = CalculationMethod::query()->where('key', $methodKey)->first();
            if ($method === null) {
                $report->conflict(sprintf('medium method Referenz fehlt: %s/%s', $mediumCode, $methodKey));

                continue;
            }
            $medium = AdvertisingMedium::query()->where('code', $mediumCode)->first();
            $key = $mediumCode.'|'.$methodKey.'|'.$engine;
            $payload = [
                'engine_profile_key' => $engine,
                'is_active' => (bool) $row['is_active'],
                'sort' => (int) $row['sort'],
            ];

            if ($medium === null) {
                $report->add('medium_method', $key, 'create');
                $ops[] = function () use ($mediumCode, $method, $payload): void {
                    $medium = AdvertisingMedium::query()->where('code', $mediumCode)->first();
                    if ($medium === null) {
                        throw new MasterDataTransferException('medium method Medium fehlt: '.$mediumCode);
                    }
                    $model = new AdvertisingMediumCalculationMethod;
                    $model->forceFill([
                        'advertising_medium_id' => $medium->id,
                        'calculation_method_id' => $method->id,
                        ...$payload,
                        'lock_version' => 1,
                    ])->save();
                };

                continue;
            }

            $existing = AdvertisingMediumCalculationMethod::query()
                ->where('advertising_medium_id', $medium->id)
                ->where('calculation_method_id', $method->id)
                ->where('engine_profile_key', $engine)
                ->first();
            if ($existing === null) {
                $report->add('medium_method', $key, 'create');
                $ops[] = function () use ($medium, $method, $payload): void {
                    $model = new AdvertisingMediumCalculationMethod;
                    $model->forceFill([
                        'advertising_medium_id' => $medium->id,
                        'calculation_method_id' => $method->id,
                        ...$payload,
                        'lock_version' => 1,
                    ])->save();
                };

                continue;
            }
            if ((bool) $existing->is_active !== $payload['is_active'] || (int) $existing->sort !== $payload['sort']) {
                $report->conflict('medium_method Konflikt: '.$key);

                continue;
            }
            $report->add('medium_method', $key, 'unchanged');
        }

        return $ops;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<callable(): void>
     */
    private function planRules(array $rows, MasterDataImportReport $report): array
    {
        $ops = [];
        foreach ($rows as $row) {
            $inventoryCode = (string) ($row['inventory_code'] ?? '');
            $mediumCode = (string) ($row['advertising_medium_code'] ?? '');
            if (MasterDataTransferContract::isExcludedMediumCode($mediumCode)) {
                $report->conflict('Regel mit ausgeschlossenem Medium: '.$mediumCode);

                continue;
            }
            $strategy = ComponentCalculationStrategy::tryFrom((string) ($row['component_calculation_strategy'] ?? ''));
            if ($strategy === null) {
                $report->conflict('component_calculation_strategy ungültig: '.$inventoryCode.'/'.$mediumCode);

                continue;
            }
            $key = $inventoryCode.'|'.$mediumCode;
            $payload = [
                'is_active' => (bool) $row['is_active'],
                'booking_code' => $row['booking_code'] ?? null,
                'planning_responsibility_key' => $row['planning_responsibility_key'] ?? null,
                'hint_text' => $row['hint_text'] ?? null,
                'sort' => (int) ($row['sort'] ?? 0),
                'default_length_seconds' => $row['default_length_seconds'] === null ? null : (int) $row['default_length_seconds'],
                'surcharge_percent' => $row['surcharge_percent'] === null ? null : (string) $row['surcharge_percent'],
                'is_discountable' => (bool) $row['is_discountable'],
                'is_ae_eligible' => (bool) $row['is_ae_eligible'],
                'component_calculation_strategy' => $strategy,
                'swf_allonge_confirmed_at' => $row['swf_allonge_confirmed_at'] ?? null,
            ];

            $inventory = Inventory::query()->where('code', $inventoryCode)->first();
            $medium = AdvertisingMedium::query()->where('code', $mediumCode)->first();

            if ($inventory === null || $medium === null) {
                $report->add('inventory_medium_rule', $key, 'create');
                $ops[] = function () use ($inventoryCode, $mediumCode, $payload): void {
                    $inventory = Inventory::query()->where('code', $inventoryCode)->first();
                    $medium = AdvertisingMedium::query()->where('code', $mediumCode)->first();
                    if ($inventory === null || $medium === null) {
                        throw new MasterDataTransferException(sprintf('rule Referenz fehlt: %s/%s', $inventoryCode, $mediumCode));
                    }
                    $rule = new InventoryMediumRule;
                    $rule->forceFill([
                        'inventory_id' => $inventory->id,
                        'advertising_medium_id' => $medium->id,
                        ...$payload,
                        'lock_version' => 1,
                    ])->save();
                };

                continue;
            }

            $existing = InventoryMediumRule::query()
                ->where('inventory_id', $inventory->id)
                ->where('advertising_medium_id', $medium->id)
                ->first();
            if ($existing === null) {
                $report->add('inventory_medium_rule', $key, 'create');
                $ops[] = function () use ($inventory, $medium, $payload): void {
                    $rule = new InventoryMediumRule;
                    $rule->forceFill([
                        'inventory_id' => $inventory->id,
                        'advertising_medium_id' => $medium->id,
                        ...$payload,
                        'lock_version' => 1,
                    ])->save();
                };

                continue;
            }

            $existingSurcharge = $existing->surcharge_percent === null ? null : (string) $existing->surcharge_percent;
            $payloadSurcharge = $payload['surcharge_percent'];
            $same = (bool) $existing->is_active === $payload['is_active']
                && (string) ($existing->booking_code ?? '') === (string) ($payload['booking_code'] ?? '')
                && (string) ($existing->planning_responsibility_key ?? '') === (string) ($payload['planning_responsibility_key'] ?? '')
                && (string) ($existing->hint_text ?? '') === (string) ($payload['hint_text'] ?? '')
                && (int) $existing->sort === $payload['sort']
                && $existing->default_length_seconds === $payload['default_length_seconds']
                && $existingSurcharge === $payloadSurcharge
                && (bool) $existing->is_discountable === $payload['is_discountable']
                && (bool) $existing->is_ae_eligible === $payload['is_ae_eligible']
                && $existing->component_calculation_strategy === $payload['component_calculation_strategy'];

            if (! $same) {
                $report->conflict('inventory_medium_rule Konflikt: '.$key);

                continue;
            }
            $report->add('inventory_medium_rule', $key, 'unchanged');
        }

        return $ops;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<callable(): void>
     */
    private function planPriceLists(array $rows, MasterDataImportReport $report): array
    {
        $ops = [];
        foreach ($rows as $row) {
            $inventoryCode = (string) ($row['inventory_code'] ?? '');
            $year = (int) ($row['year'] ?? 0);
            $revision = (int) ($row['revision_number'] ?? 0);
            $version = (string) ($row['version'] ?? '');
            if (($row['import_status'] ?? null) !== 'draft') {
                $report->conflict('price_list import_status muss draft sein: '.$inventoryCode.'/'.$year);

                continue;
            }
            $items = $row['items'] ?? null;
            if (! is_array($items) || $items === []) {
                $report->conflict('price_list items fehlen: '.$inventoryCode.'/'.$year);

                continue;
            }

            /** @var list<array{hour: int, day_group: string, second_price: string}> $incoming */
            $incoming = collect($items)->map(fn ($i) => [
                'hour' => (int) $i['hour'],
                'day_group' => (string) $i['day_group'],
                'second_price' => (string) $i['second_price'],
            ])->values()->all();
            $fingerprint = PriceListFingerprint::forSpotItems($incoming);
            $key = sprintf('%s|%d|r%d', $inventoryCode, $year, $revision);

            $inventory = Inventory::query()->where('code', $inventoryCode)->first();
            if ($inventory !== null) {
                $match = $this->findMatchingSpotPriceList($inventory->id, $year, $fingerprint);
                if ($match !== null) {
                    // Aktive/archivierte/Draft mit gleichem Inhalt → unverändert, keine neue Draft-Revision.
                    $report->add(
                        'price_list',
                        sprintf('%s|%d|r%d', $inventoryCode, $year, $match->revision_number),
                        'unchanged',
                        'fingerprint-match status='.$match->status->value,
                    );

                    continue;
                }

                $sameRevision = PriceList::query()
                    ->where('inventory_id', $inventory->id)
                    ->where('year', $year)
                    ->where('revision_number', $revision)
                    ->first();

                if ($sameRevision !== null && $sameRevision->status === PriceListStatus::Draft) {
                    $report->conflict('price_list draft Inhalt abweichend: '.$key);

                    continue;
                }

                if ($sameRevision !== null) {
                    // Quellrevision existiert als active/archived mit anderem Inhalt → neue Draft-Revision.
                    $revision = (int) PriceList::query()
                        ->where('inventory_id', $inventory->id)
                        ->where('year', $year)
                        ->max('revision_number') + 1;
                    $version = (string) $revision;
                    $key = sprintf('%s|%d|r%d', $inventoryCode, $year, $revision);
                    $report->add(
                        'price_list',
                        $key,
                        'create',
                        'status=draft; neue Revision weil Quellinhalt abweicht',
                    );
                } else {
                    $report->add('price_list', $key, 'create', 'status=draft; keine Aktivierung');
                }
            } else {
                $report->add('price_list', $key, 'create', 'status=draft; keine Aktivierung');
            }

            $name = (string) $row['name'];
            $validFrom = $row['valid_from'] ?? null;
            $ops[] = function () use ($inventoryCode, $year, $revision, $version, $name, $validFrom, $incoming, $fingerprint): void {
                $inventory = Inventory::query()->where('code', $inventoryCode)->first();
                if ($inventory === null) {
                    throw new MasterDataTransferException('price_list Inventar fehlt: '.$inventoryCode);
                }
                // Apply-Zeit erneut prüfen (Race / paralleler Import).
                $match = $this->findMatchingSpotPriceList($inventory->id, $year, $fingerprint);
                if ($match !== null) {
                    return;
                }
                $list = new PriceList;
                $list->forceFill([
                    'inventory_id' => $inventory->id,
                    'name' => $name,
                    'year' => $year,
                    'version' => $version,
                    'revision_number' => $revision,
                    'status' => PriceListStatus::Draft,
                    'valid_from' => $validFrom,
                    'lock_version' => 1,
                    'published_at' => null,
                    'archived_at' => null,
                ])->save();
                foreach ($incoming as $item) {
                    PriceListItem::query()->create([
                        'price_list_id' => $list->id,
                        'hour' => $item['hour'],
                        'day_group' => $item['day_group'],
                        'second_price' => $item['second_price'],
                    ]);
                }
            };
        }

        return $ops;
    }

    private function findMatchingSpotPriceList(int $inventoryId, int $year, string $fingerprint): ?PriceList
    {
        $lists = PriceList::query()
            ->with(['items' => fn ($q) => $q->orderBy('day_group')->orderBy('hour')])
            ->where('inventory_id', $inventoryId)
            ->where('year', $year)
            ->orderBy('revision_number')
            ->get();

        foreach ($lists as $list) {
            /** @var list<array{hour: int, day_group: string, second_price: string}> $items */
            $items = $list->items->map(fn (PriceListItem $i) => [
                'hour' => (int) $i->hour,
                'day_group' => $i->day_group->value,
                'second_price' => (string) $i->second_price,
            ])->values()->all();
            if (PriceListFingerprint::forSpotItems($items) === $fingerprint) {
                return $list;
            }
        }

        return null;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<callable(): void>
     */
    private function planProductionPriceLists(array $rows, MasterDataImportReport $report): array
    {
        $ops = [];
        foreach ($rows as $row) {
            $inventoryCode = (string) ($row['inventory_code'] ?? '');
            $year = (int) ($row['year'] ?? 0);
            $revision = (int) ($row['revision_number'] ?? 0);
            $type = ProductionType::tryFrom((string) ($row['production_type'] ?? ''));
            if ($type === null) {
                $report->conflict('production_price_list production_type ungültig: '.$inventoryCode);

                continue;
            }
            if (($row['import_status'] ?? null) !== 'draft') {
                $report->conflict('production_price_list import_status muss draft sein.');

                continue;
            }

            $payloadUnit = (string) $row['unit_price'];
            $isDiscountable = (bool) $row['is_discountable'];
            $isAeEligible = (bool) $row['is_ae_eligible'];
            $fingerprint = PriceListFingerprint::forProduction($payloadUnit, $isDiscountable, $isAeEligible);
            $version = (string) $row['version'];
            $key = sprintf('%s|%s|%d|r%d', $inventoryCode, $type->value, $year, $revision);
            $name = (string) $row['name'];

            $inventory = Inventory::query()->where('code', $inventoryCode)->first();
            if ($inventory !== null) {
                $match = $this->findMatchingProductionPriceList($inventory->id, $type, $year, $fingerprint);
                if ($match !== null) {
                    $report->add(
                        'production_price_list',
                        sprintf('%s|%s|%d|r%d', $inventoryCode, $type->value, $year, $match->revision_number),
                        'unchanged',
                        'fingerprint-match status='.$match->status->value,
                    );

                    continue;
                }

                $sameRevision = ProductionPriceList::query()
                    ->where('inventory_id', $inventory->id)
                    ->where('production_type', $type)
                    ->where('year', $year)
                    ->where('revision_number', $revision)
                    ->first();

                if ($sameRevision !== null && $sameRevision->status === PriceListStatus::Draft) {
                    $report->conflict('production_price_list draft abweichend: '.$key);

                    continue;
                }

                if ($sameRevision !== null) {
                    $revision = (int) ProductionPriceList::query()
                        ->where('inventory_id', $inventory->id)
                        ->where('production_type', $type)
                        ->where('year', $year)
                        ->max('revision_number') + 1;
                    $version = (string) $revision;
                    $key = sprintf('%s|%s|%d|r%d', $inventoryCode, $type->value, $year, $revision);
                    $report->add('production_price_list', $key, 'create', 'status=draft; neue Revision');
                } else {
                    $report->add('production_price_list', $key, 'create', 'status=draft');
                }
            } else {
                $report->add('production_price_list', $key, 'create', 'status=draft');
            }

            $ops[] = function () use (
                $inventoryCode,
                $type,
                $year,
                $revision,
                $version,
                $name,
                $payloadUnit,
                $isDiscountable,
                $isAeEligible,
                $fingerprint,
            ): void {
                $inventory = Inventory::query()->where('code', $inventoryCode)->first();
                if ($inventory === null) {
                    throw new MasterDataTransferException('production_price_list Inventar fehlt: '.$inventoryCode);
                }
                $match = $this->findMatchingProductionPriceList($inventory->id, $type, $year, $fingerprint);
                if ($match !== null) {
                    return;
                }
                $list = new ProductionPriceList;
                $list->forceFill([
                    'inventory_id' => $inventory->id,
                    'production_type' => $type,
                    'year' => $year,
                    'name' => $name,
                    'version' => $version,
                    'revision_number' => $revision,
                    'status' => PriceListStatus::Draft,
                    'unit_price' => $payloadUnit,
                    'is_discountable' => $isDiscountable,
                    'is_ae_eligible' => $isAeEligible,
                    'lock_version' => 1,
                    'published_at' => null,
                    'archived_at' => null,
                ])->save();
            };
        }

        return $ops;
    }

    private function findMatchingProductionPriceList(
        int $inventoryId,
        ProductionType $type,
        int $year,
        string $fingerprint,
    ): ?ProductionPriceList {
        $lists = ProductionPriceList::query()
            ->where('inventory_id', $inventoryId)
            ->where('production_type', $type)
            ->where('year', $year)
            ->orderBy('revision_number')
            ->get();

        foreach ($lists as $list) {
            $fp = PriceListFingerprint::forProduction(
                (string) $list->unit_price,
                (bool) $list->is_discountable,
                (bool) $list->is_ae_eligible,
            );
            if ($fp === $fingerprint) {
                return $list;
            }
        }

        return null;
    }
}
