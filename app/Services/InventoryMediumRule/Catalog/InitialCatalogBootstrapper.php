<?php

namespace App\Services\InventoryMediumRule\Catalog;

use App\Enums\CalculationKind;
use App\Enums\CalculationMethodMode;
use App\Enums\InventoryType;
use App\Enums\SpotComponentProfile;
use App\Models\AdvertisingCategory;
use App\Models\AdvertisingMedium;
use App\Models\AdvertisingMediumCalculationMethod;
use App\Models\Inventory;
use App\Support\Inventory\InventoryCodeValidator;
use App\Support\InventoryMediumRule\Catalog\InitialCatalogDefinitions;
use App\Support\Organization\SingletonOrganizationResolver;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * PO-MAT-CORE-CATALOG-1 / BL-P2-02c: idempotenter Initialkatalog-Bootstrap.
 * Vorhandene Stammdaten werden nicht überschrieben; Identitätskonflikte fail-closed.
 */
final class InitialCatalogBootstrapper
{
    public function __construct(
        private readonly SingletonOrganizationResolver $organizations,
    ) {}

    /**
     * @return array{inventories_created: int, inventories_unchanged: int, media_created: int, media_unchanged: int}
     */
    public function bootstrap(): array
    {
        $inventoriesCreated = 0;
        $inventoriesUnchanged = 0;
        $mediaCreated = 0;
        $mediaUnchanged = 0;

        DB::transaction(function () use (
            &$inventoriesCreated,
            &$inventoriesUnchanged,
            &$mediaCreated,
            &$mediaUnchanged,
        ): void {
            $organization = $this->organizations->resolve();

            foreach (InitialCatalogDefinitions::inventories() as $index => $definition) {
                $code = InventoryCodeValidator::assertValid($definition['code']);
                $result = $this->ensureInventory(
                    organizationId: (int) $organization->id,
                    name: $definition['name'],
                    code: $code,
                    type: $definition['type'],
                    sort: $index + 1,
                );
                if ($result === 'created') {
                    $inventoriesCreated++;
                } else {
                    $inventoriesUnchanged++;
                }
            }

            $categoryIds = $this->resolveCategoryIds();

            foreach (InitialCatalogDefinitions::media() as $definition) {
                $categoryId = $categoryIds[$definition['category_key']]
                    ?? throw new RuntimeException(
                        "Oberkategorie fehlt für Katalog-Bootstrap: {$definition['category_key']}",
                    );
                $result = $this->ensureMedium($definition, $categoryId);
                if ($result === 'created') {
                    $mediaCreated++;
                } else {
                    $mediaUnchanged++;
                }
            }
        });

        return [
            'inventories_created' => $inventoriesCreated,
            'inventories_unchanged' => $inventoriesUnchanged,
            'media_created' => $mediaCreated,
            'media_unchanged' => $mediaUnchanged,
        ];
    }

    /**
     * @return array<string, int>
     */
    private function resolveCategoryIds(): array
    {
        $map = [];
        foreach (AdvertisingCategory::query()->get(['id', 'key']) as $category) {
            $map[$category->key] = (int) $category->id;
        }

        return $map;
    }

    private function ensureInventory(
        int $organizationId,
        string $name,
        string $code,
        InventoryType $type,
        int $sort,
    ): string {
        $byCode = Inventory::query()
            ->where('organization_id', $organizationId)
            ->where('code', $code)
            ->lockForUpdate()
            ->first();
        $byName = Inventory::query()
            ->where('organization_id', $organizationId)
            ->where('name', $name)
            ->lockForUpdate()
            ->first();

        if ($byCode !== null && $byName !== null && (int) $byCode->id !== (int) $byName->id) {
            throw new RuntimeException(
                "Inventar-Identitätskonflikt: Code „{$code}“ und Name „{$name}“ verweisen auf unterschiedliche Datensätze.",
            );
        }

        $existing = $byCode ?? $byName;
        if ($existing !== null) {
            $this->assertInventoryMatches($existing, $name, $code, $type);

            return 'unchanged';
        }

        $inventory = new Inventory;
        $inventory->organization_id = $organizationId;
        $inventory->name = $name;
        $inventory->code = $code;
        $inventory->type = $type;
        $inventory->is_active = true;
        $inventory->sort = $sort;
        $inventory->lock_version = 1;
        $inventory->save();

        return 'created';
    }

    private function assertInventoryMatches(
        Inventory $existing,
        string $name,
        string $code,
        InventoryType $type,
    ): void {
        $mismatches = [];
        if ($existing->name !== $name) {
            $mismatches[] = "name Ist={$existing->name} Soll={$name}";
        }
        if ($existing->code !== $code) {
            $mismatches[] = "code Ist={$existing->code} Soll={$code}";
        }
        if ($existing->type !== $type) {
            $mismatches[] = 'type Ist='.$existing->type->value.' Soll='.$type->value;
        }
        if ($mismatches !== []) {
            throw new RuntimeException(
                'Inventar-Konflikt ohne Überschreiben ('.$existing->code.'): '.implode('; ', $mismatches),
            );
        }
    }

    /**
     * @param  array{
     *     name: string,
     *     code: string,
     *     category_key: string,
     *     kind: CalculationKind|null,
     *     component_profile: SpotComponentProfile|null,
     *     is_discountable: bool,
     *     is_ae_eligible: bool,
     *     default_length_seconds: int,
     *     sort: int
     * }  $definition
     */
    private function ensureMedium(array $definition, int $categoryId): string
    {
        $byCode = AdvertisingMedium::query()->where('code', $definition['code'])->lockForUpdate()->first();
        $byName = AdvertisingMedium::query()->where('name', $definition['name'])->lockForUpdate()->first();

        if ($byCode !== null && $byName !== null && (int) $byCode->id !== (int) $byName->id) {
            throw new RuntimeException(
                "Werbemittel-Identitätskonflikt: Code „{$definition['code']}“ und Name „{$definition['name']}“ verweisen auf unterschiedliche Datensätze.",
            );
        }

        $existing = $byCode ?? $byName;
        if ($existing !== null) {
            $this->assertMediumMatches($existing, $definition, $categoryId);

            return 'unchanged';
        }

        $medium = new AdvertisingMedium;
        $medium->category_id = $categoryId;
        $medium->code = $definition['code'];
        $medium->name = $definition['name'];
        $medium->kind = $definition['kind'];
        $medium->calculation_method_mode = CalculationMethodMode::Inherit;
        $medium->default_calculation_method_id = null;
        $medium->default_length_seconds = $definition['default_length_seconds'];
        $medium->is_discountable = $definition['is_discountable'];
        $medium->is_ae_eligible = $definition['is_ae_eligible'];
        $medium->component_profile = $definition['component_profile'];
        $medium->is_active = true;
        $medium->sort = $definition['sort'];
        $medium->lock_version = 1;
        $medium->save();

        $methodCount = AdvertisingMediumCalculationMethod::query()
            ->where('advertising_medium_id', $medium->id)
            ->count();
        if ($methodCount !== 0) {
            throw new RuntimeException(
                "Unerwartete Methoden-Zuordnungen nach Anlegen von {$definition['code']}.",
            );
        }

        return 'created';
    }

    /**
     * @param  array{
     *     name: string,
     *     code: string,
     *     category_key: string,
     *     kind: CalculationKind|null,
     *     component_profile: SpotComponentProfile|null,
     *     is_discountable: bool,
     *     is_ae_eligible: bool,
     *     default_length_seconds: int,
     *     sort: int
     * }  $definition
     */
    private function assertMediumMatches(
        AdvertisingMedium $existing,
        array $definition,
        int $categoryId,
    ): void {
        $mismatches = [];
        if ($existing->name !== $definition['name']) {
            $mismatches[] = "name Ist={$existing->name} Soll={$definition['name']}";
        }
        if ($existing->code !== $definition['code']) {
            $mismatches[] = "code Ist={$existing->code} Soll={$definition['code']}";
        }
        if ((int) $existing->category_id !== $categoryId) {
            $mismatches[] = "category_id Ist={$existing->category_id} Soll={$categoryId}";
        }
        $existingKind = $existing->kind?->value;
        $expectedKind = $definition['kind']?->value;
        if ($existingKind !== $expectedKind) {
            $mismatches[] = 'kind Ist='.($existingKind ?? 'null').' Soll='.($expectedKind ?? 'null');
        }
        if ($existing->calculation_method_mode !== CalculationMethodMode::Inherit) {
            $mismatches[] = 'calculation_method_mode Ist='.$existing->calculation_method_mode->value.' Soll=inherit';
        }
        if ($existing->default_calculation_method_id !== null) {
            $mismatches[] = 'default_calculation_method_id Ist='.$existing->default_calculation_method_id.' Soll=null';
        }
        if ((int) $existing->default_length_seconds !== $definition['default_length_seconds']) {
            $mismatches[] = "default_length_seconds Ist={$existing->default_length_seconds} Soll={$definition['default_length_seconds']}";
        }
        if ((bool) $existing->is_discountable !== $definition['is_discountable']) {
            $mismatches[] = 'is_discountable weicht ab';
        }
        if ((bool) $existing->is_ae_eligible !== $definition['is_ae_eligible']) {
            $mismatches[] = 'is_ae_eligible weicht ab';
        }
        $existingProfile = $existing->component_profile?->value;
        $expectedProfile = $definition['component_profile']?->value;
        if ($existingProfile !== $expectedProfile) {
            $mismatches[] = 'component_profile Ist='.($existingProfile ?? 'null').' Soll='.($expectedProfile ?? 'null');
        }
        if (! $existing->is_active) {
            $mismatches[] = 'is_active Ist=false Soll=true';
        }

        $methodCount = AdvertisingMediumCalculationMethod::query()
            ->where('advertising_medium_id', $existing->id)
            ->count();
        if ($methodCount !== 0) {
            $mismatches[] = "Methoden-Zuordnungen vorhanden ({$methodCount})";
        }

        if ($mismatches !== []) {
            throw new RuntimeException(
                'Werbemittel-Konflikt ohne Überschreiben ('.$definition['code'].'): '.implode('; ', $mismatches),
            );
        }
    }
}
