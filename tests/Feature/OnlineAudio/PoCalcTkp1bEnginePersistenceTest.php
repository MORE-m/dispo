<?php

namespace Tests\Feature\OnlineAudio;

use App\Enums\OaTargetingCategory;
use App\Enums\PriceListStatus;
use App\Enums\Role;
use App\Models\AdvertisingMedium;
use App\Models\Inventory;
use App\Models\OaPlatform;
use App\Models\OaTargetingDefinition;
use App\Models\OaTkpPriceList;
use App\Models\OaTkpPriceListItem;
use App\Models\User;
use App\Services\Calculation\OaTkp\OaTkpCalculationPersister;
use App\Services\Calculation\OaTkp\OaTkpPlatformAllocationInput;
use App\Services\Calculation\OaTkp\OaTkpPositionInput;
use App\Services\Calculation\OaTkp\OaTkpPriceResolver;
use App\Services\Calculation\OaTkp\OaTkpTargetingSelectionInput;
use App\Services\OnlineAudio\Import\OaTkpImportDecimal;
use App\Support\Advertising\AdvertisingMediumLiveBookability;
use App\Support\Calculation\EngineProfileRegistry;
use App\Support\OnlineAudio\OaTkpCoreMedia;
use Database\Seeders\InitialCatalogMatCoreSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * PO-CALC-TKP-1B: Persistenz, Snapshot-Isolation, Price Resolver, Release-Schutz.
 */
class PoCalcTkp1bEnginePersistenceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function registry_stays_planned_while_v1_is_implemented(): void
    {
        $this->assertSame(
            'planned',
            EngineProfileRegistry::pairStatus(EngineProfileRegistry::PROFILE_OA_TKP, 'tkp')->value,
        );
        $this->assertNull(
            EngineProfileRegistry::currentReleasedVersion(EngineProfileRegistry::PROFILE_OA_TKP, 'tkp'),
        );
        $this->assertSame(
            'implemented',
            EngineProfileRegistry::statusForVersion(EngineProfileRegistry::PROFILE_OA_TKP, 'tkp', 'v1')->value,
        );
    }

    #[Test]
    public function core_media_remain_assigned_native_has_no_effective_tkp(): void
    {
        $this->seed(InitialCatalogMatCoreSeeder::class);

        foreach (OaTkpCoreMedia::codes() as $code) {
            $medium = AdvertisingMedium::query()->where('code', $code)->firstOrFail();
            $this->assertSame('override', $medium->calculation_method_mode->value, $code);
        }

        $native = AdvertisingMedium::query()->where('code', 'native_ad')->firstOrFail();
        $bookability = app(AdvertisingMediumLiveBookability::class)->evaluate($native, 'tkp');
        $this->assertFalse($bookability->isBookableForNewPositions);

        $pre = AdvertisingMedium::query()->where('code', 'pre_stream')->firstOrFail();
        $preBook = app(AdvertisingMediumLiveBookability::class)->evaluate($pre, 'tkp');
        $this->assertFalse($preBook->isBookableForNewPositions);
    }

    #[Test]
    public function price_resolver_fail_closed_on_missing_and_ambiguous_active(): void
    {
        $this->seed(InitialCatalogMatCoreSeeder::class);
        $medium = AdvertisingMedium::query()->where('code', 'pre_stream')->firstOrFail();
        $inventory = Inventory::query()->where('code', 'inv_more_kombi_online_audio')->firstOrFail();
        $resolver = app(OaTkpPriceResolver::class);

        try {
            $resolver->resolveActive(2026, (int) $medium->id, (int) $inventory->id, 'pre_stream');
            $this->fail('Expected missing active revision');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Keine aktive', collect($e->errors())->flatten()->implode(' '));
        }

        $this->createActivePriceList(2026, 1, (int) $medium->id, (int) $inventory->id, '12.0000', '8.0000');
        $this->createActivePriceList(2026, 2, (int) $medium->id, (int) $inventory->id, '13.0000', '9.0000');

        try {
            $resolver->resolveActive(2026, (int) $medium->id, (int) $inventory->id, 'pre_stream');
            $this->fail('Expected ambiguous active revision');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Mehrdeutige', collect($e->errors())->flatten()->implode(' '));
        }
    }

    #[Test]
    public function save_reload_and_snapshot_isolation_for_price_and_targeting(): void
    {
        $this->seed(InitialCatalogMatCoreSeeder::class);
        $user = User::factory()->role(Role::Sales)->create();
        $medium = AdvertisingMedium::query()->where('code', 'pre_stream')->firstOrFail();
        $inventory = Inventory::query()->where('code', 'inv_more_kombi_online_audio')->firstOrFail();

        $list = $this->createActivePriceList(
            2026,
            1,
            (int) $medium->id,
            (int) $inventory->id,
            '12.0000',
            '8.0000',
        );

        $targeting = OaTargetingDefinition::query()->create([
            'code' => 'fixture_tgt',
            'name' => 'Fixture Targeting',
            'category' => OaTargetingCategory::Technisch,
            'tkp_surcharge' => '2.0000',
            'revision_number' => 1,
            'is_active' => true,
        ]);

        $platform = OaPlatform::query()->create([
            'code' => 'fixture_plat_a',
            'name' => 'Fixture Platform A',
            'is_active' => true,
            'sort' => 1,
            'is_adserver_relevant' => false,
        ]);

        $input = new OaTkpPositionInput(
            inventoryId: (int) $inventory->id,
            inventoryCode: (string) $inventory->code,
            inventoryName: (string) $inventory->name,
            advertisingMediumId: (int) $medium->id,
            advertisingMediumCode: (string) $medium->code,
            advertisingMediumName: (string) $medium->name,
            impressions: 100_000,
            basisTkp: '10.0000',
            listTkp: '12.0000',
            minimumTkp: '8.0000',
            oaTkpPriceListId: (int) $list->id,
            oaTkpPriceListVersion: (string) $list->version,
            oaTkpPriceListYear: (int) $list->year,
            oaTkpPriceListRevision: (int) $list->revision_number,
            platformAllocations: [
                new OaTkpPlatformAllocationInput(
                    platformCode: (string) $platform->code,
                    platformName: (string) $platform->name,
                    impressions: 100_000,
                    oaPlatformId: (int) $platform->id,
                ),
            ],
            targetings: [
                new OaTkpTargetingSelectionInput(
                    code: (string) $targeting->code,
                    name: (string) $targeting->name,
                    category: $targeting->category->value,
                    tkpSurcharge: (string) $targeting->tkp_surcharge,
                    revisionNumber: (int) $targeting->revision_number,
                    oaTargetingDefinitionId: (int) $targeting->id,
                ),
            ],
            isAeEligible: false,
        );

        $persister = app(OaTkpCalculationPersister::class);
        $preview = $persister->preview($input);
        $this->assertSame('12.0000', $preview->finalTkp);
        $this->assertSame('1200.00', $preview->mediaGross);

        $calculation = $persister->create($user, $input, aeEnabled: false);
        $reloaded = $persister->reload($calculation);
        $position = $reloaded->positions->firstOrFail();

        $this->assertSame('oa_tkp', $position->engine_profile_key);
        $this->assertSame('tkp', $position->calculation_method_key);
        $this->assertSame('v1', $position->algorithm_version);
        $this->assertSame(100_000, (int) $position->impressions);
        $this->assertSame(0, OaTkpImportDecimal::compare((string) $position->basis_tkp, '10.0000'));
        $this->assertSame(0, OaTkpImportDecimal::compare((string) $position->final_tkp, '12.0000'));
        $this->assertSame(0, OaTkpImportDecimal::compare((string) $position->targeting_surcharge_total, '2.0000'));
        $this->assertSame('1200.00', (string) $position->media_gross);
        $this->assertFalse((bool) $position->below_minimum_tkp);
        $this->assertCount(1, $position->oaPlatformAllocations);
        $this->assertCount(1, $position->oaTargetingSelections);
        $this->assertSame(
            0,
            OaTkpImportDecimal::compare(
                (string) $position->oaTargetingSelections->first()->tkp_surcharge_snapshot,
                '2.0000',
            ),
        );

        // Stammdaten ändern → alte Calc unverändert.
        $targeting->tkp_surcharge = '3.0000';
        $targeting->save();
        OaTkpPriceListItem::query()->where('oa_tkp_price_list_id', $list->id)->update([
            'list_tkp' => '20.0000',
            'minimum_tkp' => '15.0000',
        ]);

        $afterMutation = $persister->reload($calculation);
        $frozen = $afterMutation->positions->firstOrFail();
        $this->assertSame(0, OaTkpImportDecimal::compare((string) $frozen->list_tkp_snapshot, '12.0000'));
        $this->assertSame(0, OaTkpImportDecimal::compare((string) $frozen->minimum_tkp_snapshot, '8.0000'));
        $this->assertSame(0, OaTkpImportDecimal::compare((string) $frozen->final_tkp, '12.0000'));
        $this->assertSame(
            0,
            OaTkpImportDecimal::compare(
                (string) $frozen->oaTargetingSelections->first()->tkp_surcharge_snapshot,
                '2.0000',
            ),
        );

        // Neue Preview nutzt neue Targeting-Werte, wenn Input aktualisiert wird.
        $newInput = new OaTkpPositionInput(
            inventoryId: (int) $inventory->id,
            inventoryCode: (string) $inventory->code,
            inventoryName: (string) $inventory->name,
            advertisingMediumId: (int) $medium->id,
            advertisingMediumCode: (string) $medium->code,
            advertisingMediumName: (string) $medium->name,
            impressions: 100_000,
            basisTkp: '10.0000',
            listTkp: '20.0000',
            minimumTkp: '15.0000',
            oaTkpPriceListId: (int) $list->id,
            oaTkpPriceListVersion: (string) $list->version,
            oaTkpPriceListYear: (int) $list->year,
            oaTkpPriceListRevision: (int) $list->revision_number,
            targetings: [
                new OaTkpTargetingSelectionInput(
                    code: (string) $targeting->code,
                    name: (string) $targeting->name,
                    category: $targeting->category->value,
                    tkpSurcharge: (string) $targeting->fresh()->tkp_surcharge,
                    revisionNumber: (int) $targeting->revision_number,
                    oaTargetingDefinitionId: (int) $targeting->id,
                ),
            ],
            isAeEligible: false,
        );
        $newPreview = $persister->preview($newInput);
        $this->assertSame('13.0000', $newPreview->finalTkp);
        $this->assertSame('1300.00', $newPreview->mediaGross);
    }

    #[Test]
    public function http_preview_with_tkp_remains_fail_closed(): void
    {
        $this->seed(InitialCatalogMatCoreSeeder::class);
        $user = User::factory()->role(Role::Sales)->create();
        $medium = AdvertisingMedium::query()->where('code', 'pre_stream')->firstOrFail();
        $inventory = Inventory::query()->where('code', 'inv_more_kombi_online_audio')->firstOrFail();

        $response = $this->actingAs($user)->postJson(route('calculations.preview'), [
            'planning_mode' => 'manual',
            'order_discount_percent' => '0',
            'positions' => [[
                'inventory_id' => $inventory->id,
                'advertising_medium_id' => $medium->id,
                'calculation_method_key' => 'tkp',
                'length_seconds' => 30,
                'total_spot_count' => 1,
                'position_discount_percent' => '0',
                'ae_percent' => '0',
                'plan_rows' => [['hour' => 8, 'day_group' => 'mo_fr']],
            ]],
        ]);

        $this->assertContains($response->status(), [422, 403]);
        $this->assertNotSame(500, $response->status());
    }

    private function createActivePriceList(
        int $year,
        int $revision,
        int $mediumId,
        int $inventoryId,
        string $listTkp,
        string $minimumTkp,
    ): OaTkpPriceList {
        $list = OaTkpPriceList::query()->create([
            'year' => $year,
            'name' => "Fixture {$year}.{$revision}",
            'version' => "{$year}.{$revision}",
            'revision_number' => $revision,
            'lock_version' => 1,
            'status' => PriceListStatus::Active,
            'published_at' => now(),
        ]);

        OaTkpPriceListItem::query()->create([
            'oa_tkp_price_list_id' => $list->id,
            'advertising_medium_id' => $mediumId,
            'inventory_id' => $inventoryId,
            'list_tkp' => $listTkp,
            'minimum_tkp' => $minimumTkp,
        ]);

        return $list;
    }
}
