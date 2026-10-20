<?php

namespace App\Services\InventoryMediumRule\Catalog;

use App\Enums\CalculationKind;
use App\Enums\CalculationMethodMode;
use App\Enums\InventoryType;
use App\Enums\SpotComponentProfile;
use App\Models\AdvertisingCategory;
use App\Models\AdvertisingCategoryCalculationMethod;
use App\Models\AdvertisingMedium;
use App\Models\AdvertisingMediumCalculationMethod;
use App\Models\CalculationMethod;
use App\Models\Inventory;
use App\Support\Advertising\CanonicalAdvertisingCategories;
use App\Support\Calculation\EngineProfileRegistry;
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
            // Kategorien zuerst: bei fehlender/inaktiver Oberkategorie keine Teilanlage.
            $categoryIds = $this->resolveRequiredActiveCategoryIds();

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

            foreach (InitialCatalogDefinitions::media() as $definition) {
                $categoryId = $categoryIds[$definition['category_key']];
                $result = $this->ensureMedium($definition, $categoryId);
                if ($result === 'created') {
                    $mediaCreated++;
                } else {
                    $mediaUnchanged++;
                }
            }

            $this->ensureSwfTrailerCategoryMethod($categoryIds);
            $this->ensureOnlineAudioTkpCategoryMethod($categoryIds);
            $this->ensureAllongeMediumOverride();
            $this->ensurePromoMediumOverride();
            $this->ensureContestMediumOverride();
        });

        return [
            'inventories_created' => $inventoriesCreated,
            'inventories_unchanged' => $inventoriesUnchanged,
            'media_created' => $mediaCreated,
            'media_unchanged' => $mediaUnchanged,
        ];
    }

    /**
     * PO-CALC-TKP-1A: Kategorie online_audio → tkp / oa_tkp (planned; kein Release).
     * Idempotent; abweichende Verträge fail-closed.
     *
     * @param  array<string, int>  $categoryIds
     */
    private function ensureOnlineAudioTkpCategoryMethod(array $categoryIds): void
    {
        $categoryId = $categoryIds[CanonicalAdvertisingCategories::ONLINE_AUDIO] ?? null;
        if ($categoryId === null) {
            return;
        }

        $tkp = CalculationMethod::query()->where('key', 'tkp')->first();
        if ($tkp === null) {
            throw new RuntimeException('Berechnungsmethode „tkp“ fehlt für den Initialkatalog-Bootstrap.');
        }

        $existing = AdvertisingCategoryCalculationMethod::query()
            ->where('advertising_category_id', $categoryId)
            ->where('calculation_method_id', (int) $tkp->id)
            ->get();

        if ($existing->count() > 1) {
            throw new RuntimeException('PO-CALC-TKP-1A: mehrdeutige online_audio/tkp-Zuordnung.');
        }

        if ($existing->count() === 1) {
            $row = $existing->first();
            if ((string) $row->engine_profile_key !== EngineProfileRegistry::PROFILE_OA_TKP) {
                throw new RuntimeException(
                    'PO-CALC-TKP-1A: online_audio/tkp hat abweichendes engine_profile_key (erwartet oa_tkp).',
                );
            }

            return;
        }

        $this->ensureCategoryMethodAssignment(
            $categoryId,
            (int) $tkp->id,
            EngineProfileRegistry::PROFILE_OA_TKP,
            10,
        );
    }

    /**
     * BL-P5-01a / PO-SWF-CALENDAR-1: Kategorie special_advertising_formats → average + calendar
     * (Profil swf_trailer), Standardmethode average. Idempotent; abweichende Verträge nicht überschreiben.
     *
     * @param  array<string, int>  $categoryIds
     */
    private function ensureSwfTrailerCategoryMethod(array $categoryIds): void
    {
        $categoryId = $categoryIds[CanonicalAdvertisingCategories::SPECIAL_ADVERTISING_FORMATS] ?? null;
        if ($categoryId === null) {
            return;
        }

        $average = CalculationMethod::query()->where('key', 'average')->first();
        $calendar = CalculationMethod::query()->where('key', 'calendar')->first();
        if ($average === null || $calendar === null) {
            throw new RuntimeException('Berechnungsmethoden „average“/„calendar“ fehlen für den Initialkatalog-Bootstrap.');
        }

        $this->ensureCategoryMethodAssignment(
            $categoryId,
            (int) $average->id,
            EngineProfileRegistry::PROFILE_SWF_TRAILER,
            10,
        );
        $this->ensureCategoryMethodAssignment(
            $categoryId,
            (int) $calendar->id,
            EngineProfileRegistry::PROFILE_SWF_TRAILER,
            20,
        );

        AdvertisingCategory::query()
            ->whereKey($categoryId)
            ->whereNull('default_calculation_method_id')
            ->update(['default_calculation_method_id' => $average->id]);
    }

    private function ensureCategoryMethodAssignment(
        int $categoryId,
        int $calculationMethodId,
        string $engineProfileKey,
        int $sort,
    ): void {
        $exists = AdvertisingCategoryCalculationMethod::query()
            ->where('advertising_category_id', $categoryId)
            ->where('calculation_method_id', $calculationMethodId)
            ->where('engine_profile_key', $engineProfileKey)
            ->exists();
        if ($exists) {
            return;
        }

        $assignment = new AdvertisingCategoryCalculationMethod;
        $assignment->advertising_category_id = $categoryId;
        $assignment->calculation_method_id = $calculationMethodId;
        $assignment->engine_profile_key = $engineProfileKey;
        $assignment->is_active = true;
        $assignment->sort = $sort;
        $assignment->lock_version = 1;
        $assignment->save();
    }

    /**
     * PO-ALLONGE-1 / PO-SWF-CALENDAR-1: Medium allonge → Override Average+Calendar / Profil swf_allonge.
     */
    private function ensureAllongeMediumOverride(): void
    {
        $this->ensureSwfLengthMediumAverageAndCalendarOverride(
            mediumCode: 'allonge',
            expectedKind: CalculationKind::SwfAllonge,
            engineProfile: EngineProfileRegistry::PROFILE_SWF_ALLONGE,
            label: 'Allonge',
        );
    }

    /**
     * PO-SWF-PROMO-1 / PO-SWF-CALENDAR-1: Medium promo_moderation → Override Average+Calendar / Profil swf_promo.
     */
    private function ensurePromoMediumOverride(): void
    {
        $this->ensureSwfLengthMediumAverageAndCalendarOverride(
            mediumCode: 'promo_moderation',
            expectedKind: CalculationKind::SwfPromo,
            engineProfile: EngineProfileRegistry::PROFILE_SWF_PROMO,
            label: 'Promo',
        );
    }

    /**
     * PO-SWF-CONTEST-1 / PO-SWF-CALENDAR-1: Medium contest_payoff → Override Average+Calendar / Profil swf_contest.
     */
    private function ensureContestMediumOverride(): void
    {
        $this->ensureSwfLengthMediumAverageAndCalendarOverride(
            mediumCode: 'contest_payoff',
            expectedKind: CalculationKind::SwfContest,
            engineProfile: EngineProfileRegistry::PROFILE_SWF_CONTEST,
            label: 'Contest',
        );
    }

    /**
     * Installiert oder ergänzt Override mit Average (Default) + Calendar am angegebenen SWF-Profil.
     * Idempotent. Bekannter Vorzustand (nur Average) wird um Calendar ergänzt; sonst fail-closed.
     */
    private function ensureSwfLengthMediumAverageAndCalendarOverride(
        string $mediumCode,
        CalculationKind $expectedKind,
        string $engineProfile,
        string $label,
    ): void {
        $medium = AdvertisingMedium::query()->where('code', $mediumCode)->lockForUpdate()->first();
        if ($medium === null) {
            return;
        }

        if ($medium->kind !== $expectedKind) {
            throw new RuntimeException(
                "{$label}-Medium hat nicht die Berechnungsart {$expectedKind->value}. Bootstrap überschreibt das nicht.",
            );
        }

        $average = CalculationMethod::query()->where('key', 'average')->first();
        $calendar = CalculationMethod::query()->where('key', 'calendar')->first();
        if ($average === null || $calendar === null) {
            throw new RuntimeException("Berechnungsmethoden „average“/„calendar“ fehlen für den {$label}-Override.");
        }

        $assignments = AdvertisingMediumCalculationMethod::query()
            ->where('advertising_medium_id', $medium->id)
            ->get();

        $hasAverage = $assignments->contains(
            fn (AdvertisingMediumCalculationMethod $row): bool => (int) $row->calculation_method_id === (int) $average->id
                && $row->engine_profile_key === $engineProfile
                && (bool) $row->is_active,
        );
        $hasCalendar = $assignments->contains(
            fn (AdvertisingMediumCalculationMethod $row): bool => (int) $row->calculation_method_id === (int) $calendar->id
                && $row->engine_profile_key === $engineProfile
                && (bool) $row->is_active,
        );

        $matches = $medium->calculation_method_mode === CalculationMethodMode::Override
            && (int) $medium->default_calculation_method_id === (int) $average->id
            && $hasAverage
            && $hasCalendar
            && $assignments->count() === 2;

        if ($matches) {
            return;
        }

        $canInstallFresh = $medium->calculation_method_mode === CalculationMethodMode::Inherit
            && $medium->default_calculation_method_id === null
            && $assignments->isEmpty();

        $canUpgradeAverageOnly = $medium->calculation_method_mode === CalculationMethodMode::Override
            && (int) $medium->default_calculation_method_id === (int) $average->id
            && $hasAverage
            && ! $hasCalendar
            && $assignments->count() === 1;

        if (! $canInstallFresh && ! $canUpgradeAverageOnly) {
            throw new RuntimeException(
                "{$label}-Methodenvertrag weicht ab und wird nicht überschrieben.",
            );
        }

        if ($canInstallFresh) {
            $medium->calculation_method_mode = CalculationMethodMode::Override;
            $medium->default_calculation_method_id = $average->id;
            $medium->save();

            $averageAssignment = new AdvertisingMediumCalculationMethod;
            $averageAssignment->advertising_medium_id = $medium->id;
            $averageAssignment->calculation_method_id = $average->id;
            $averageAssignment->engine_profile_key = $engineProfile;
            $averageAssignment->is_active = true;
            $averageAssignment->sort = 10;
            $averageAssignment->lock_version = 1;
            $averageAssignment->save();
        }

        $calendarAssignment = new AdvertisingMediumCalculationMethod;
        $calendarAssignment->advertising_medium_id = $medium->id;
        $calendarAssignment->calculation_method_id = $calendar->id;
        $calendarAssignment->engine_profile_key = $engineProfile;
        $calendarAssignment->is_active = true;
        $calendarAssignment->sort = 20;
        $calendarAssignment->lock_version = 1;
        $calendarAssignment->save();
    }

    /**
     * Liefert ID-Map aller im Katalog benötigten Oberkategorien.
     * Fail-closed: jede Key muss existieren und is_active=true sein.
     *
     * @return array<string, int>
     */
    private function resolveRequiredActiveCategoryIds(): array
    {
        $requiredKeys = array_values(array_unique(array_map(
            static fn (array $definition): string => $definition['category_key'],
            InitialCatalogDefinitions::media(),
        )));

        $categories = AdvertisingCategory::query()
            ->whereIn('key', $requiredKeys)
            ->lockForUpdate()
            ->get(['id', 'key', 'name', 'is_active']);

        $byKey = [];
        foreach ($categories as $category) {
            $byKey[$category->key] = $category;
        }

        $map = [];
        foreach ($requiredKeys as $key) {
            $category = $byKey[$key] ?? null;
            if ($category === null) {
                throw new RuntimeException(
                    "Benötigte Oberkategorie fehlt für den Initialkatalog-Bootstrap: „{$key}“.",
                );
            }
            if (! $category->is_active) {
                throw new RuntimeException(
                    "Benötigte Oberkategorie ist deaktiviert und kann für den Initialkatalog-Bootstrap nicht verwendet werden: „{$key}“ ({$category->name}).",
                );
            }
            $map[$key] = (int) $category->id;
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
        if (in_array($definition['code'], ['allonge', 'promo_moderation', 'contest_payoff'], true)) {
            $averageId = CalculationMethod::query()->where('key', 'average')->value('id');
            if ($existing->calculation_method_mode !== CalculationMethodMode::Override) {
                $mismatches[] = 'calculation_method_mode Ist='.$existing->calculation_method_mode->value.' Soll=override';
            }
            if ((int) $existing->default_calculation_method_id !== (int) $averageId) {
                $mismatches[] = 'default_calculation_method_id Ist='.(string) $existing->default_calculation_method_id.' Soll=average';
            }
        } else {
            if ($existing->calculation_method_mode !== CalculationMethodMode::Inherit) {
                $mismatches[] = 'calculation_method_mode Ist='.$existing->calculation_method_mode->value.' Soll=inherit';
            }
            if ($existing->default_calculation_method_id !== null) {
                $mismatches[] = 'default_calculation_method_id Ist='.$existing->default_calculation_method_id.' Soll=null';
            }
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
        if (in_array($definition['code'], ['allonge', 'promo_moderation', 'contest_payoff'], true)) {
            $expectedProfile = match ($definition['code']) {
                'allonge' => EngineProfileRegistry::PROFILE_SWF_ALLONGE,
                'promo_moderation' => EngineProfileRegistry::PROFILE_SWF_PROMO,
                default => EngineProfileRegistry::PROFILE_SWF_CONTEST,
            };
            $keys = AdvertisingMediumCalculationMethod::query()
                ->where('advertising_medium_id', $existing->id)
                ->where('engine_profile_key', $expectedProfile)
                ->where('is_active', true)
                ->with('calculationMethod')
                ->get()
                ->pluck('calculationMethod.key')
                ->sort()
                ->values()
                ->all();
            // PO-SWF-CALENDAR-1: Average + Calendar am jeweiligen SWF-Profil.
            if ($methodCount !== 2 || $keys !== ['average', 'calendar']) {
                $mismatches[] = match ($definition['code']) {
                    'allonge' => 'Allonge-Override fehlt oder weicht ab',
                    'promo_moderation' => 'Promo-Override fehlt oder weicht ab',
                    default => 'Contest-Override fehlt oder weicht ab',
                };
            }
        } elseif ($methodCount !== 0) {
            $mismatches[] = "Methoden-Zuordnungen vorhanden ({$methodCount})";
        }

        if ($mismatches !== []) {
            throw new RuntimeException(
                'Werbemittel-Konflikt ohne Überschreiben ('.$definition['code'].'): '.implode('; ', $mismatches),
            );
        }
    }
}
