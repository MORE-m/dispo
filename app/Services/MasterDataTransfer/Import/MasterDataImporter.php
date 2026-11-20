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
use Illuminate\Support\Facades\DB;

/**
 * EPIC-03-B: fail-closed Import (Dry-Run / Apply) ohne ID-Übernahme und ohne Auto-Activate.
 */
final class MasterDataImporter
{
    public function __construct(
        private readonly MasterDataPackage $packages,
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

        $usersBefore = User::query()->orderBy('id')->get(['id', 'email', 'role', 'is_active'])->map(
            fn (User $u) => [
                'id' => $u->id,
                'email' => $u->email,
                'role' => $u->role->value,
                'is_active' => (bool) $u->is_active,
            ],
        )->all();

        $package = $this->packages->load($packagePath);
        $report = new MasterDataImportReport;

        $run = function () use ($package, $report, $dryRun, $usersBefore): array {
            $data = $package['data'];
            $this->importOrganization($data['data/organization.json'], $report, $dryRun);
            $this->importCalculationMethods($data['data/calculation_methods.json'], $report, $dryRun);
            $this->importCategories($data['data/advertising_categories.json'], $report, $dryRun);
            $this->importInventories($data['data/inventories.json'], $report, $dryRun);
            $this->importMedia($data['data/advertising_media.json'], $report, $dryRun);
            $this->importCategoryMethods($data['data/advertising_category_calculation_methods.json'], $report, $dryRun);
            $this->importMediumMethods($data['data/advertising_medium_calculation_methods.json'], $report, $dryRun);
            $this->importRules($data['data/inventory_medium_rules.json'], $report, $dryRun);
            $this->importPriceLists($data['data/price_lists.json'], $report, $dryRun);
            $this->importProductionPriceLists($data['data/production_price_lists.json'], $report, $dryRun);

            if ($report->hasConflicts()) {
                throw new MasterDataTransferException('Import fail-closed wegen Konflikten.', $report->conflicts);
            }

            $usersAfter = User::query()->orderBy('id')->get(['id', 'email', 'role', 'is_active'])->map(
                fn (User $u) => [
                    'id' => $u->id,
                    'email' => $u->email,
                    'role' => $u->role->value,
                    'is_active' => (bool) $u->is_active,
                ],
            )->all();

            if ($usersBefore !== $usersAfter) {
                throw new MasterDataTransferException('Benutzerbestand wurde verändert — Abbruch.');
            }

            return $report->toArray($dryRun);
        };

        if ($dryRun) {
            $result = $report->toArray(true);
            try {
                DB::transaction(function () use ($run, &$result): void {
                    $result = $run();
                    throw new MasterDataTransferDryRunRollback;
                });
            } catch (MasterDataTransferDryRunRollback) {
                return $result;
            }

            return $result;
        }

        return DB::transaction($run);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function importOrganization(array $row, MasterDataImportReport $report, bool $dryRun): void
    {
        $name = trim((string) ($row['name'] ?? ''));
        if ($name === '') {
            $report->conflict('organization.name fehlt.');

            return;
        }

        $existing = Organization::query()->orderBy('id')->first();
        if ($existing === null) {
            $report->add('organization', $name, 'create');
            if (true) { // apply writes; dry-run transaction rolls back
                Organization::query()->create(['name' => $name]);
            }

            return;
        }

        if ($existing->name !== $name) {
            $report->conflict(sprintf(
                'Organisation abweichend (vorhanden=%s, paket=%s) — keine stille Umbenennung.',
                $existing->name,
                $name,
            ));

            return;
        }

        $report->add('organization', $name, 'unchanged');
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function importCalculationMethods(array $rows, MasterDataImportReport $report, bool $dryRun): void
    {
        foreach ($rows as $row) {
            $key = (string) ($row['key'] ?? '');
            if ($key === '') {
                $report->conflict('calculation_method.key fehlt.');

                continue;
            }
            $existing = CalculationMethod::query()->where('key', $key)->first();
            if ($existing === null) {
                // Methoden kommen aus Migrationen; fehlende Keys sind ein Stop.
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
     */
    private function importCategories(array $rows, MasterDataImportReport $report, bool $dryRun): void
    {
        foreach ($rows as $row) {
            $key = (string) ($row['key'] ?? '');
            if ($key === '') {
                $report->conflict('category.key fehlt.');

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
                if (true) { // apply writes; dry-run transaction rolls back
                    $category = new AdvertisingCategory;
                    $category->forceFill(['key' => $key, ...$payload, 'lock_version' => 1])->save();
                }

                continue;
            }

            $actual = [
                'name' => $existing->name,
                'is_active' => (bool) $existing->is_active,
                'sort' => (int) $existing->sort,
                'default_calculation_method_id' => $existing->default_calculation_method_id,
            ];
            if ($actual !== $payload) {
                // Migration liefert Kategorien bereits; abweichende Defaults (z. B. SWF) dürfen gesetzt werden,
                // wenn nur default_calculation_method_id/name/sort/is_active soft-updatable und key gleich.
                // Fail-closed bei key-Kollision mit anderem fachlichen Kern: hier nur conflict wenn name/key mismatch hard.
                if ($existing->name !== $payload['name']) {
                    $report->conflict('advertising_category Namenskonflikt: '.$key);

                    continue;
                }
                $report->add('advertising_category', $key, 'update', 'nicht-destruktives Angleichen von Metadaten');
                if (true) { // apply writes; dry-run transaction rolls back
                    $existing->forceFill($payload)->save();
                }

                continue;
            }
            $report->add('advertising_category', $key, 'unchanged');
        }
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function importInventories(array $rows, MasterDataImportReport $report, bool $dryRun): void
    {
        $organization = Organization::query()->orderBy('id')->first();
        if ($organization === null && ! $dryRun) {
            $report->conflict('Organisation fehlt vor Inventar-Import.');

            return;
        }

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
                $report->add('inventory', $code, 'create');
                if (true) { // apply writes; dry-run transaction rolls back
                    $inventory = new Inventory;
                    $inventory->forceFill([
                        'organization_id' => $organization->id,
                        'code' => $code,
                        ...$payload,
                        'lock_version' => 1,
                    ])->save();
                }

                continue;
            }
            if ($existing->type !== $type || $existing->name !== $payload['name']) {
                $report->conflict('inventory Konflikt (name/type): '.$code);

                continue;
            }
            if ((bool) $existing->is_active !== $payload['is_active'] || (int) $existing->sort !== $payload['sort']) {
                $report->add('inventory', $code, 'update', 'is_active/sort');
                if (true) { // apply writes; dry-run transaction rolls back
                    $existing->forceFill([
                        'is_active' => $payload['is_active'],
                        'sort' => $payload['sort'],
                    ])->save();
                }

                continue;
            }
            $report->add('inventory', $code, 'unchanged');
        }
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function importMedia(array $rows, MasterDataImportReport $report, bool $dryRun): void
    {
        foreach ($rows as $row) {
            $code = (string) ($row['code'] ?? '');
            $categoryKey = (string) ($row['category_key'] ?? '');
            if ($code === '' || $categoryKey === '') {
                $report->conflict('advertising_medium code/category_key fehlt.');

                continue;
            }
            $category = AdvertisingCategory::query()->where('key', $categoryKey)->first();
            if ($category === null) {
                $report->conflict('Kategorie für Medium fehlt: '.$categoryKey);

                continue;
            }
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

            $existing = AdvertisingMedium::query()->where('code', $code)->first();
            if ($existing === null) {
                $report->add('advertising_medium', $code, 'create');
                if (true) { // apply writes; dry-run transaction rolls back
                    $medium = new AdvertisingMedium;
                    $medium->forceFill(['code' => $code, ...$payload, 'lock_version' => 1])->save();
                }

                continue;
            }

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
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function importCategoryMethods(array $rows, MasterDataImportReport $report, bool $dryRun): void
    {
        foreach ($rows as $row) {
            $engine = (string) ($row['engine_profile_key'] ?? '');
            if (in_array($engine, MasterDataTransferContract::EXCLUDED_ENGINE_PROFILE_KEYS, true)) {
                $report->skip('OA category method übersprungen: '.$engine);

                continue;
            }
            $categoryKey = (string) ($row['category_key'] ?? '');
            $methodKey = (string) ($row['calculation_method_key'] ?? '');
            $category = AdvertisingCategory::query()->where('key', $categoryKey)->first();
            $method = CalculationMethod::query()->where('key', $methodKey)->first();
            if ($category === null || $method === null) {
                $report->conflict(sprintf('category method Referenz fehlt: %s/%s', $categoryKey, $methodKey));

                continue;
            }
            $key = $categoryKey.'|'.$methodKey.'|'.$engine;
            $existing = AdvertisingCategoryCalculationMethod::query()
                ->where('advertising_category_id', $category->id)
                ->where('calculation_method_id', $method->id)
                ->where('engine_profile_key', $engine === '' ? null : $engine)
                ->first();

            // engine_profile_key may be stored as string; also try exact
            if ($existing === null && $engine !== '') {
                $existing = AdvertisingCategoryCalculationMethod::query()
                    ->where('advertising_category_id', $category->id)
                    ->where('calculation_method_id', $method->id)
                    ->where('engine_profile_key', $engine)
                    ->first();
            }

            $payload = [
                'engine_profile_key' => $engine === '' ? null : $engine,
                'is_active' => (bool) $row['is_active'],
                'sort' => (int) $row['sort'],
            ];

            if ($existing === null) {
                $report->add('category_method', $key, 'create');
                if (true) { // apply writes; dry-run transaction rolls back
                    $model = new AdvertisingCategoryCalculationMethod;
                    $model->forceFill([
                        'advertising_category_id' => $category->id,
                        'calculation_method_id' => $method->id,
                        ...$payload,
                        'lock_version' => 1,
                    ])->save();
                }

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
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function importMediumMethods(array $rows, MasterDataImportReport $report, bool $dryRun): void
    {
        foreach ($rows as $row) {
            $engine = (string) ($row['engine_profile_key'] ?? '');
            if (in_array($engine, MasterDataTransferContract::EXCLUDED_ENGINE_PROFILE_KEYS, true)) {
                $report->skip('OA medium method übersprungen: '.$engine);

                continue;
            }
            $mediumCode = (string) ($row['advertising_medium_code'] ?? '');
            $methodKey = (string) ($row['calculation_method_key'] ?? '');
            $medium = AdvertisingMedium::query()->where('code', $mediumCode)->first();
            $method = CalculationMethod::query()->where('key', $methodKey)->first();
            if ($medium === null || $method === null) {
                $report->conflict(sprintf('medium method Referenz fehlt: %s/%s', $mediumCode, $methodKey));

                continue;
            }
            $key = $mediumCode.'|'.$methodKey.'|'.$engine;
            $existing = AdvertisingMediumCalculationMethod::query()
                ->where('advertising_medium_id', $medium->id)
                ->where('calculation_method_id', $method->id)
                ->where('engine_profile_key', $engine)
                ->first();
            $payload = [
                'engine_profile_key' => $engine,
                'is_active' => (bool) $row['is_active'],
                'sort' => (int) $row['sort'],
            ];
            if ($existing === null) {
                $report->add('medium_method', $key, 'create');
                if (true) { // apply writes; dry-run transaction rolls back
                    $model = new AdvertisingMediumCalculationMethod;
                    $model->forceFill([
                        'advertising_medium_id' => $medium->id,
                        'calculation_method_id' => $method->id,
                        ...$payload,
                        'lock_version' => 1,
                    ])->save();
                }

                continue;
            }
            if ((bool) $existing->is_active !== $payload['is_active'] || (int) $existing->sort !== $payload['sort']) {
                $report->conflict('medium_method Konflikt: '.$key);

                continue;
            }
            $report->add('medium_method', $key, 'unchanged');
        }
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function importRules(array $rows, MasterDataImportReport $report, bool $dryRun): void
    {
        foreach ($rows as $row) {
            $inventoryCode = (string) ($row['inventory_code'] ?? '');
            $mediumCode = (string) ($row['advertising_medium_code'] ?? '');
            $inventory = Inventory::query()->where('code', $inventoryCode)->first();
            $medium = AdvertisingMedium::query()->where('code', $mediumCode)->first();
            if ($inventory === null || $medium === null) {
                $report->conflict(sprintf('rule Referenz fehlt: %s/%s', $inventoryCode, $mediumCode));

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
            $existing = InventoryMediumRule::query()
                ->where('inventory_id', $inventory->id)
                ->where('advertising_medium_id', $medium->id)
                ->first();
            if ($existing === null) {
                $report->add('inventory_medium_rule', $key, 'create');
                if (true) { // apply writes; dry-run transaction rolls back
                    $rule = new InventoryMediumRule;
                    $rule->forceFill([
                        'inventory_id' => $inventory->id,
                        'advertising_medium_id' => $medium->id,
                        ...$payload,
                        'lock_version' => 1,
                    ])->save();
                }

                continue;
            }

            $same = (bool) $existing->is_active === $payload['is_active']
                && (string) $existing->booking_code === (string) $payload['booking_code']
                && (string) $existing->planning_responsibility_key === (string) $payload['planning_responsibility_key']
                && (string) $existing->hint_text === (string) $payload['hint_text']
                && (int) $existing->sort === $payload['sort']
                && $existing->default_length_seconds === $payload['default_length_seconds']
                && (string) $existing->surcharge_percent === (string) ($payload['surcharge_percent'] ?? '')
                && (bool) $existing->is_discountable === $payload['is_discountable']
                && (bool) $existing->is_ae_eligible === $payload['is_ae_eligible']
                && $existing->component_calculation_strategy === $payload['component_calculation_strategy'];

            // surcharge null vs "0.0000" — compare normalized
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
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function importPriceLists(array $rows, MasterDataImportReport $report, bool $dryRun): void
    {
        foreach ($rows as $row) {
            $inventoryCode = (string) ($row['inventory_code'] ?? '');
            $year = (int) ($row['year'] ?? 0);
            $revision = (int) ($row['revision_number'] ?? 0);
            $version = (string) ($row['version'] ?? '');
            $inventory = Inventory::query()->where('code', $inventoryCode)->first();
            if ($inventory === null) {
                $report->conflict('price_list Inventar fehlt: '.$inventoryCode);

                continue;
            }
            if (($row['import_status'] ?? null) !== 'draft') {
                $report->conflict('price_list import_status muss draft sein: '.$inventoryCode.'/'.$year);

                continue;
            }
            $key = sprintf('%s|%d|r%d', $inventoryCode, $year, $revision);
            $items = $row['items'] ?? null;
            if (! is_array($items) || $items === []) {
                $report->conflict('price_list items fehlen: '.$key);

                continue;
            }

            $existing = PriceList::query()
                ->where('inventory_id', $inventory->id)
                ->where('year', $year)
                ->where('revision_number', $revision)
                ->first();

            $incoming = collect($items)->map(fn ($i) => [
                'hour' => (int) $i['hour'],
                'day_group' => (string) $i['day_group'],
                'second_price' => (string) $i['second_price'],
            ])->sortBy(fn ($i) => $i['day_group'].'|'.$i['hour'])->values()->all();

            if ($existing !== null && $existing->status === PriceListStatus::Draft) {
                $existingSorted = $existing->items()->orderBy('day_group')->orderBy('hour')->get()
                    ->map(fn (PriceListItem $i) => [
                        'hour' => (int) $i->hour,
                        'day_group' => $i->day_group instanceof \BackedEnum
                            ? $i->day_group->value
                            : (string) $i->day_group,
                        'second_price' => (string) $i->second_price,
                    ])->sortBy(fn ($i) => $i['day_group'].'|'.$i['hour'])->values()->all();
                if ($existingSorted === $incoming && (string) $existing->version === $version) {
                    $report->add('price_list', $key, 'unchanged');

                    continue;
                }
                $report->conflict('price_list draft Inhalt abweichend: '.$key);

                continue;
            }

            if ($existing !== null) {
                // Aktive/archivierte Revision bleibt unberührt; neue Draft-Revision.
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
                    'status=draft; neue Revision weil Quellrevision bereits active/archived',
                );
            } else {
                $report->add('price_list', $key, 'create', 'status=draft; keine Aktivierung');
            }

            if (true) { // apply writes; dry-run transaction rolls back
                $list = new PriceList;
                $list->forceFill([
                    'inventory_id' => $inventory->id,
                    'name' => (string) $row['name'],
                    'year' => $year,
                    'version' => $version,
                    'revision_number' => $revision,
                    'status' => PriceListStatus::Draft,
                    'valid_from' => $row['valid_from'] ?? null,
                    'lock_version' => 1,
                    'published_at' => null,
                    'archived_at' => null,
                ])->save();
                foreach ($items as $item) {
                    PriceListItem::query()->create([
                        'price_list_id' => $list->id,
                        'hour' => (int) $item['hour'],
                        'day_group' => (string) $item['day_group'],
                        'second_price' => (string) $item['second_price'],
                    ]);
                }
            }
        }
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function importProductionPriceLists(array $rows, MasterDataImportReport $report, bool $dryRun): void
    {
        foreach ($rows as $row) {
            $inventoryCode = (string) ($row['inventory_code'] ?? '');
            $year = (int) ($row['year'] ?? 0);
            $revision = (int) ($row['revision_number'] ?? 0);
            $type = ProductionType::tryFrom((string) ($row['production_type'] ?? ''));
            $inventory = Inventory::query()->where('code', $inventoryCode)->first();
            if ($inventory === null || $type === null) {
                $report->conflict('production_price_list Referenz ungültig: '.$inventoryCode);

                continue;
            }
            if (($row['import_status'] ?? null) !== 'draft') {
                $report->conflict('production_price_list import_status muss draft sein.');

                continue;
            }
            $key = sprintf('%s|%s|%d|r%d', $inventoryCode, $type->value, $year, $revision);
            $existing = ProductionPriceList::query()
                ->where('inventory_id', $inventory->id)
                ->where('production_type', $type)
                ->where('year', $year)
                ->where('revision_number', $revision)
                ->first();

            $payloadUnit = (string) $row['unit_price'];
            $version = (string) $row['version'];
            if ($existing !== null && $existing->status === PriceListStatus::Draft) {
                if ((string) $existing->unit_price === $payloadUnit
                    && (bool) $existing->is_discountable === (bool) $row['is_discountable']
                    && (bool) $existing->is_ae_eligible === (bool) $row['is_ae_eligible']) {
                    $report->add('production_price_list', $key, 'unchanged');

                    continue;
                }
                $report->conflict('production_price_list draft abweichend: '.$key);

                continue;
            }

            if ($existing !== null) {
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

            if (true) { // apply writes; dry-run transaction rolls back
                $list = new ProductionPriceList;
                $list->forceFill([
                    'inventory_id' => $inventory->id,
                    'production_type' => $type,
                    'year' => $year,
                    'name' => (string) $row['name'],
                    'version' => $version,
                    'revision_number' => $revision,
                    'status' => PriceListStatus::Draft,
                    'unit_price' => $payloadUnit,
                    'is_discountable' => (bool) $row['is_discountable'],
                    'is_ae_eligible' => (bool) $row['is_ae_eligible'],
                    'lock_version' => 1,
                    'published_at' => null,
                    'archived_at' => null,
                ])->save();
            }
        }
    }
}
