<?php

namespace Tests\Feature\InventoryMediumRule;

use App\Enums\CalculationKind;
use App\Enums\CalculationMethodMode;
use App\Enums\InventoryType;
use App\Enums\SpotComponentProfile;
use App\Models\AdvertisingCategory;
use App\Models\AdvertisingMedium;
use App\Models\AdvertisingMediumCalculationMethod;
use App\Models\Inventory;
use App\Models\Organization;
use App\Services\InventoryMediumRule\Catalog\InitialCatalogBootstrapper;
use App\Support\Advertising\CanonicalAdvertisingCategories;
use App\Support\InventoryMediumRule\Catalog\InitialCatalogDefinitions;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\InitialCatalogMatCoreSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionClass;
use RuntimeException;
use Tests\TestCase;

/**
 * PO-MAT-CORE-CATALOG-1 / BL-P2-02c: Initialkatalog 14 Inventare / 42 Werbemittel.
 */
class InitialCatalogMatCoreBootstrapTest extends TestCase
{
    use RefreshDatabase;

    public function test_bootstrap_creates_exact_catalog(): void
    {
        $result = app(InitialCatalogBootstrapper::class)->bootstrap();

        $this->assertSame(14, $result['inventories_created']);
        $this->assertSame(0, $result['inventories_unchanged']);
        $this->assertSame(42, $result['media_created']);
        $this->assertSame(0, $result['media_unchanged']);

        $this->assertSame(14, Inventory::query()->count());
        $this->assertSame(42, AdvertisingMedium::query()->count());
        $this->assertSame(1, Organization::query()->count());
        $this->assertSame('more Marketing', Organization::query()->value('name'));

        foreach (InitialCatalogDefinitions::inventories() as $definition) {
            $inventory = Inventory::query()->where('code', $definition['code'])->firstOrFail();
            $this->assertSame($definition['name'], $inventory->name);
            $this->assertSame($definition['type'], $inventory->type);
            $this->assertTrue($inventory->is_active);
        }

        $spotsId = (int) AdvertisingCategory::query()
            ->where('key', CanonicalAdvertisingCategories::SPOTS)
            ->value('id');

        foreach (InitialCatalogDefinitions::media() as $definition) {
            $medium = AdvertisingMedium::query()->where('code', $definition['code'])->firstOrFail();
            $this->assertSame($definition['name'], $medium->name);
            $categoryKey = AdvertisingCategory::query()->whereKey($medium->category_id)->value('key');
            $this->assertSame($definition['category_key'], $categoryKey);
            $this->assertSame($definition['kind']?->value, $medium->kind?->value);
            $this->assertSame(CalculationMethodMode::Inherit, $medium->calculation_method_mode);
            $this->assertNull($medium->default_calculation_method_id);
            $this->assertSame(30, $medium->default_length_seconds);
            $this->assertSame($definition['is_discountable'], $medium->is_discountable);
            $this->assertSame($definition['is_ae_eligible'], $medium->is_ae_eligible);
            $this->assertSame($definition['component_profile']?->value, $medium->component_profile?->value);
            $this->assertTrue($medium->is_active);
            $this->assertSame(
                0,
                AdvertisingMediumCalculationMethod::query()
                    ->where('advertising_medium_id', $medium->id)
                    ->count(),
            );
        }

        $this->assertSame(
            CalculationKind::SpotClassic,
            AdvertisingMedium::query()->where('code', 'spot_classic')->firstOrFail()->kind,
        );
        $this->assertSame(
            SpotComponentProfile::Tandem,
            AdvertisingMedium::query()->where('code', 'spot_tandem')->firstOrFail()->component_profile,
        );
        $this->assertSame(
            CalculationKind::SpotClassic,
            AdvertisingMedium::query()->where('code', 'spot_tandem')->firstOrFail()->kind,
        );
        $this->assertSame(
            SpotComponentProfile::Tridem,
            AdvertisingMedium::query()->where('code', 'spot_tridem')->firstOrFail()->component_profile,
        );
        $this->assertSame(
            CalculationKind::SpotClassic,
            AdvertisingMedium::query()->where('code', 'spot_tridem')->firstOrFail()->kind,
        );

        foreach (['online_facebook', 'online_instagram', 'online_instagram_influencer', 'online_tiktok'] as $code) {
            $medium = AdvertisingMedium::query()->where('code', $code)->firstOrFail();
            $this->assertFalse($medium->is_discountable);
            $this->assertFalse($medium->is_ae_eligible);
        }

        $this->assertSame(
            $spotsId,
            (int) AdvertisingMedium::query()->where('code', 'spot_classic')->value('category_id'),
        );
        $this->assertSame(
            'Sondersendung (4x90Sek)',
            AdvertisingMedium::query()->where('code', 'special_broadcast')->value('name'),
        );
        $this->assertSame(
            'Mid-Roll Spotify / Deezer / Youtube Musikumfeld',
            AdvertisingMedium::query()->where('code', 'midroll_music_environment')->value('name'),
        );

        $kombiCount = Inventory::query()->where('type', InventoryType::Kombi)->count();
        $senderCount = Inventory::query()->where('type', InventoryType::Sender)->count();
        $this->assertSame(8, $kombiCount);
        $this->assertSame(6, $senderCount);
    }

    public function test_bootstrap_is_idempotent(): void
    {
        $bootstrapper = app(InitialCatalogBootstrapper::class);
        $first = $bootstrapper->bootstrap();
        $second = $bootstrapper->bootstrap();

        $this->assertSame(14, $first['inventories_created']);
        $this->assertSame(42, $first['media_created']);
        $this->assertSame(0, $second['inventories_created']);
        $this->assertSame(14, $second['inventories_unchanged']);
        $this->assertSame(0, $second['media_created']);
        $this->assertSame(42, $second['media_unchanged']);
        $this->assertSame(14, Inventory::query()->count());
        $this->assertSame(42, AdvertisingMedium::query()->count());
    }

    public function test_bootstrap_fails_closed_on_code_name_conflict_without_overwrite(): void
    {
        app(InitialCatalogBootstrapper::class)->bootstrap();
        $medium = AdvertisingMedium::query()->where('code', 'spot_classic')->firstOrFail();
        $originalName = $medium->name;
        $medium->name = 'Anderer Name';
        $medium->save();

        try {
            app(InitialCatalogBootstrapper::class)->bootstrap();
            $this->fail('Erwartete RuntimeException bei Identitätskonflikt.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Werbemittel-Konflikt', $exception->getMessage());
        }

        $medium->refresh();
        $this->assertSame('Anderer Name', $medium->name);
        $this->assertNotSame($originalName, $medium->name);
        $this->assertSame(42, AdvertisingMedium::query()->count());
    }

    public function test_seeder_is_not_wired_into_database_seeder(): void
    {
        $source = file_get_contents((new ReflectionClass(DatabaseSeeder::class))->getFileName() ?: '');
        $this->assertIsString($source);
        $this->assertStringNotContainsString('InitialCatalogMatCoreSeeder', $source);
        $this->assertStringNotContainsString('CombinationMatrixMatCoreSeeder', $source);

        $this->assertTrue(class_exists(InitialCatalogMatCoreSeeder::class));
        $this->seed(InitialCatalogMatCoreSeeder::class);
        $this->assertSame(14, Inventory::query()->count());
        $this->assertSame(42, AdvertisingMedium::query()->count());
    }

    public function test_catalog_names_match_matrix_import_expectations(): void
    {
        app(InitialCatalogBootstrapper::class)->bootstrap();

        $mediaNames = AdvertisingMedium::query()->orderBy('name')->pluck('name')->all();
        $this->assertContains('Sondersendung (4x90Sek)', $mediaNames);
        $this->assertContains('Mid-Roll Spotify / Deezer / Youtube Musikumfeld', $mediaNames);
        $this->assertNotContains('Sondersendung (4x90 Sek.)', $mediaNames);
        $this->assertNotContains('Mid-Roll Spotify / Deezer / YouTube Musikumfeld', $mediaNames);
        $this->assertNotContains('Pre-/In-Stream', $mediaNames);

        $inventoryNames = Inventory::query()->orderBy('name')->pluck('name')->all();
        $this->assertContains('MORE Hamburg-Kombi+', $inventoryNames);
        $this->assertNotContains('Hamburg-Kombi+', $inventoryNames);
    }
}
