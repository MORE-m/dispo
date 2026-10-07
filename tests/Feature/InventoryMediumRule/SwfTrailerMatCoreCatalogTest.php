<?php

namespace Tests\Feature\InventoryMediumRule;

use App\Enums\CalculationKind;
use App\Enums\Role;
use App\Models\AdvertisingCategory;
use App\Models\AdvertisingCategoryCalculationMethod;
use App\Models\AdvertisingMedium;
use App\Models\InventoryMediumRule;
use App\Models\User;
use App\Services\InventoryMediumRule\Catalog\InitialCatalogBootstrapper;
use App\Services\InventoryMediumRule\Import\CombinationMatrixImporter;
use App\Support\Advertising\AdvertisingMediumLiveBookability;
use App\Support\Advertising\CanonicalAdvertisingCategories;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTrailerAverageCatalog;
use Tests\TestCase;

/**
 * BL-P5-01a / PO-BLP501A-1 (A1): Initialkatalog + Matrix-Import für Trailer.
 *
 * Matrix-zulässig: Radio Hamburg, ROCK ANTENNE Hamburg, 80er 90er OLDIE ANTENNE Hamburg, CARAVAN.fm.
 * Länge/Aufschlag werden NICHT erfunden (NULL = nicht konfiguriert); Spot-Regeln bleiben 30 s / 0 %.
 */
class SwfTrailerMatCoreCatalogTest extends TestCase
{
    use CreatesTrailerAverageCatalog;
    use RefreshDatabase;

    public function test_bootstrap_marks_only_trailer_as_swf_trailer_and_assigns_category_average(): void
    {
        app(InitialCatalogBootstrapper::class)->bootstrap();

        $withKind = AdvertisingMedium::query()
            ->whereHas('category', fn ($q) => $q->where('key', CanonicalAdvertisingCategories::SPECIAL_ADVERTISING_FORMATS))
            ->whereNotNull('kind')
            ->pluck('code')
            ->all();
        $this->assertSame(['trailer_station_voice'], $withKind);

        $swf = AdvertisingCategory::query()
            ->where('key', CanonicalAdvertisingCategories::SPECIAL_ADVERTISING_FORMATS)
            ->firstOrFail();
        $assignments = AdvertisingCategoryCalculationMethod::query()
            ->where('advertising_category_id', $swf->id)
            ->with('calculationMethod')
            ->get();
        $this->assertCount(1, $assignments);
        $this->assertSame('average', $assignments[0]->calculationMethod?->key);
        $this->assertSame('swf_trailer', $assignments[0]->engine_profile_key);

        // Idempotent.
        app(InitialCatalogBootstrapper::class)->bootstrap();
        $this->assertSame(
            1,
            AdvertisingCategoryCalculationMethod::query()->where('advertising_category_id', $swf->id)->count(),
        );

        $bookability = app(AdvertisingMediumLiveBookability::class);
        $trailer = AdvertisingMedium::query()->where('code', 'trailer_station_voice')->firstOrFail();
        $this->assertSame(CalculationKind::SwfTrailer, $trailer->kind);
        $this->assertTrue($bookability->evaluate($trailer)->isBookableForNewPositions);

        foreach (['preseller', 'abbinder', 'allonge', 'promo_moderation', 'opener', 'stinger', 'bumper'] as $code) {
            $other = AdvertisingMedium::query()->where('code', $code)->firstOrFail();
            $this->assertNull($other->kind, $code);
            $this->assertFalse($bookability->evaluate($other)->isBookableForNewPositions, $code);
        }
    }

    public function test_matrix_import_leaves_trailer_length_and_surcharge_unconfigured(): void
    {
        app(InitialCatalogBootstrapper::class)->bootstrap();
        app(CombinationMatrixImporter::class)->import();

        $trailer = AdvertisingMedium::query()->where('code', 'trailer_station_voice')->firstOrFail();
        $rules = InventoryMediumRule::query()
            ->where('advertising_medium_id', $trailer->id)
            ->with('inventory')
            ->get();

        $this->assertEqualsCanonicalizing(
            ['Radio Hamburg', 'ROCK ANTENNE Hamburg', '80er 90er OLDIE ANTENNE Hamburg', 'CARAVAN.fm'],
            $rules->pluck('inventory.name')->all(),
        );
        foreach ($rules as $rule) {
            $this->assertNull($rule->default_length_seconds, $rule->inventory->name);
            $this->assertNull($rule->surcharge_percent, $rule->inventory->name);
        }

        $spotRule = InventoryMediumRule::query()
            ->whereHas('inventory', fn ($q) => $q->where('name', 'Radio Hamburg'))
            ->whereHas('advertisingMedium', fn ($q) => $q->where('code', 'spot_classic'))
            ->firstOrFail();
        $this->assertSame(30, $spotRule->default_length_seconds);
        $this->assertSame('0.0000', $spotRule->surcharge_percent);
    }

    public function test_live_bookability_requires_profile_matching_kind(): void
    {
        $catalog = $this->createTrailerAverageCatalog();
        $bookability = app(AdvertisingMediumLiveBookability::class);

        // Trailer-Kind mit Spot-Profil in der Kategorie → nicht buchbar.
        AdvertisingCategoryCalculationMethod::query()
            ->where('advertising_category_id', $catalog['trailer']->category_id)
            ->update(['engine_profile_key' => 'spot_classic']);

        $result = $bookability->evaluate($catalog['trailer']->fresh());
        $this->assertFalse($result->isBookableForNewPositions);
        $this->assertSame(
            'Die Berechnungsmethode passt nicht zur Berechnungsart des Werbemittels.',
            $result->unbookableReason,
        );

        // Spot-Kind mit swf_trailer-Profil → nicht buchbar.
        AdvertisingCategoryCalculationMethod::query()
            ->where('advertising_category_id', $catalog['spot']->category_id)
            ->update(['engine_profile_key' => 'swf_trailer']);
        $this->assertFalse($bookability->evaluate($catalog['spot']->fresh())->isBookableForNewPositions);
    }

    public function test_admin_can_clear_trailer_surcharge_to_null_and_keep_explicit_zero(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $catalog = $this->createTrailerAverageCatalog();
        $rule = $catalog['ruleA'];

        $this->actingAs($admin)->put(route('administration.combinations.update', $rule), [
            'lock_version' => $rule->lock_version,
            'booking_code' => $rule->booking_code,
            'planning_responsibility_key' => $rule->planning_responsibility_key,
            'default_length_seconds' => 20,
            'surcharge_percent' => '',
        ])->assertRedirect();
        $rule->refresh();
        $this->assertNull($rule->surcharge_percent);
        $this->assertSame(20, $rule->default_length_seconds);

        $this->actingAs($admin)->put(route('administration.combinations.update', $rule), [
            'lock_version' => $rule->lock_version,
            'booking_code' => $rule->booking_code,
            'planning_responsibility_key' => $rule->planning_responsibility_key,
            'default_length_seconds' => 20,
            'surcharge_percent' => '0',
        ])->assertRedirect();
        $rule->refresh();
        $this->assertSame('0.0000', $rule->surcharge_percent);

        // Fehlendes Feld bei Trailer = nicht konfiguriert (nie still 0).
        $this->actingAs($admin)->put(route('administration.combinations.update', $rule), [
            'lock_version' => $rule->lock_version,
            'booking_code' => $rule->booking_code,
            'planning_responsibility_key' => $rule->planning_responsibility_key,
            'default_length_seconds' => 20,
        ])->assertRedirect();
        $this->assertNull($rule->fresh()->surcharge_percent);

        // Spot-Regel: fehlendes Feld bleibt wie bisher 0.
        $spotRule = InventoryMediumRule::query()
            ->where('advertising_medium_id', $catalog['spot']->id)
            ->where('inventory_id', $catalog['a']->id)
            ->firstOrFail();
        $this->actingAs($admin)->put(route('administration.combinations.update', $spotRule), [
            'lock_version' => $spotRule->lock_version,
            'booking_code' => $spotRule->booking_code,
            'planning_responsibility_key' => $spotRule->planning_responsibility_key,
            'default_length_seconds' => 30,
        ])->assertRedirect();
        $this->assertSame('0.0000', $spotRule->fresh()->surcharge_percent);
    }
}
