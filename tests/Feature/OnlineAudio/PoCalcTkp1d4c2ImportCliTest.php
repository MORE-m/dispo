<?php

namespace Tests\Feature\OnlineAudio;

use App\Enums\EngineCapabilityStatus;
use App\Enums\OaTargetingCategory;
use App\Enums\PriceListStatus;
use App\Enums\Role;
use App\Models\AdvertisingMedium;
use App\Models\Calculation;
use App\Models\Inventory;
use App\Models\OaPlatform;
use App\Models\OaTargetingDefinition;
use App\Models\OaTkpPriceList;
use App\Models\User;
use App\Services\Calculation\CalculationWriter;
use App\Services\OnlineAudio\Import\OaTkpImportDecimal;
use App\Services\OnlineAudio\Import\OaTkpPriceListYearLock;
use App\Services\OnlineAudio\Import\OaTkpStammdatenImportException;
use App\Support\Calculation\EngineProfileRegistry;
use App\Support\Calculation\OaTkpWizardCapability;
use Database\Seeders\InitialCatalogMatCoreSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * PO-CALC-TKP-1D4c-2: Artisan Import-/Activate-CLI (isolierte Testdaten).
 */
class PoCalcTkp1d4c2ImportCliTest extends TestCase
{
    use RefreshDatabase;

    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir().'/oa-d4c2-'.uniqid('', true);
        File::ensureDirectoryExists($this->tmpDir);
    }

    protected function tearDown(): void
    {
        OaTkpWizardCapability::clearHttpMutationFakeForTests();
        File::deleteDirectory($this->tmpDir);
        parent::tearDown();
    }

    #[Test]
    public function platforms_import_valid_and_invalid_and_dry_run(): void
    {
        $path = $this->writeJson('platforms.json', [[
            'code' => 'cli_plat_a',
            'name' => 'CLI Platform A',
            'sort' => 3,
        ]]);

        $this->assertSame(0, Artisan::call('oa:import-platforms', ['path' => $path, '--dry-run' => true]));
        $this->assertSame(0, OaPlatform::query()->count());

        $this->assertSame(0, Artisan::call('oa:import-platforms', ['path' => $path]));
        $this->assertSame(1, OaPlatform::query()->count());
        $this->assertSame('CLI Platform A', OaPlatform::query()->firstOrFail()->name);

        $bad = $this->writeJson('platforms-bad.json', [
            ['code' => 'x', 'name' => 'One'],
            ['code' => 'x', 'name' => 'Dup'],
        ]);
        $this->assertSame(1, Artisan::call('oa:import-platforms', ['path' => $bad]));
        $this->assertSame(1, OaPlatform::query()->count());
    }

    #[Test]
    public function targetings_import_valid_optional(): void
    {
        $path = $this->writeJson('targetings.json', [[
            'code' => 'cli_tgt',
            'name' => 'CLI Targeting',
            'category' => OaTargetingCategory::Dmp->value,
            'tkp_surcharge' => '1.5000',
        ]]);

        $this->assertSame(0, Artisan::call('oa:import-targetings', ['path' => $path, '--dry-run' => true]));
        $this->assertSame(0, OaTargetingDefinition::query()->count());

        $this->assertSame(0, Artisan::call('oa:import-targetings', ['path' => $path]));
        $this->assertSame(1, OaTargetingDefinition::query()->count());
        $imported = OaTargetingDefinition::query()->firstOrFail();
        $this->assertSame(0, OaTkpImportDecimal::compare((string) $imported->tkp_surcharge, '1.5000'));
        $this->assertSame(0, OaTkpImportDecimal::compare((string) $imported->minimum_tkp_surcharge, '0.0000'));
    }

    #[Test]
    public function price_list_draft_import_and_guards(): void
    {
        $this->seed(InitialCatalogMatCoreSeeder::class);
        $payload = $this->priceListPayload(2026, 1, PriceListStatus::Draft->value);
        $path = $this->writeJson('price-draft.json', $payload);

        $this->assertSame(0, Artisan::call('oa:import-price-list', ['path' => $path, '--dry-run' => true]));
        $this->assertSame(0, OaTkpPriceList::query()->count());

        $this->assertSame(0, Artisan::call('oa:import-price-list', ['path' => $path]));
        $list = OaTkpPriceList::query()->firstOrFail();
        $this->assertSame(PriceListStatus::Draft, $list->status);
        $this->assertSame(1, $list->items()->count());

        $activePath = $this->writeJson('price-active.json', $this->priceListPayload(2026, 2, PriceListStatus::Active->value));
        $this->assertSame(1, Artisan::call('oa:import-price-list', ['path' => $activePath]));
        $this->assertSame(1, OaTkpPriceList::query()->count());

        $archivedPath = $this->writeJson('price-archived.json', $this->priceListPayload(2026, 3, PriceListStatus::Archived->value));
        $this->assertSame(1, Artisan::call('oa:import-price-list', ['path' => $archivedPath]));

        $list->update([
            'status' => PriceListStatus::Active,
            'published_at' => now(),
        ]);
        $overwrite = $this->writeJson('price-overwrite.json', $this->priceListPayload(2026, 1, PriceListStatus::Draft->value, '99.0000', '90.0000'));
        $this->assertSame(1, Artisan::call('oa:import-price-list', ['path' => $overwrite]));
        $this->assertSame(0, OaTkpImportDecimal::compare(
            (string) $list->fresh()->items()->firstOrFail()->list_tkp,
            '12.0000',
        ));
    }

    #[Test]
    public function price_list_rejects_duplicates_native_ad_and_unknown_inventory(): void
    {
        $this->seed(InitialCatalogMatCoreSeeder::class);

        $dup = $this->priceListPayload(2026, 1, PriceListStatus::Draft->value);
        $dup['items'][] = $dup['items'][0];
        $this->assertSame(1, Artisan::call('oa:import-price-list', [
            'path' => $this->writeJson('dup.json', $dup),
        ]));
        $this->assertSame(0, OaTkpPriceList::query()->count());

        $native = $this->priceListPayload(2026, 1, PriceListStatus::Draft->value);
        $native['items'][0]['medium_code'] = 'native_ad';
        $this->assertSame(1, Artisan::call('oa:import-price-list', [
            'path' => $this->writeJson('native.json', $native),
        ]));

        $missing = $this->priceListPayload(2026, 1, PriceListStatus::Draft->value);
        $missing['items'][0]['inventory_code'] = 'inv_does_not_exist_d4c2';
        $this->assertSame(1, Artisan::call('oa:import-price-list', [
            'path' => $this->writeJson('missing-inv.json', $missing),
        ]));
    }

    #[Test]
    public function activate_without_and_with_archive_previous(): void
    {
        $this->seed(InitialCatalogMatCoreSeeder::class);
        $this->assertSame(0, Artisan::call('oa:import-price-list', [
            'path' => $this->writeJson('r1.json', $this->priceListPayload(2026, 1, PriceListStatus::Draft->value)),
        ]));
        $this->assertSame(0, Artisan::call('oa:activate-price-list', [
            '--year' => 2026,
            '--revision' => 1,
        ]));
        $first = OaTkpPriceList::query()->where('revision_number', 1)->firstOrFail();
        $this->assertSame(PriceListStatus::Active, $first->status);

        $this->assertSame(0, Artisan::call('oa:import-price-list', [
            'path' => $this->writeJson('r2.json', $this->priceListPayload(2026, 2, PriceListStatus::Draft->value, '13.0000', '9.0000')),
        ]));

        $this->assertSame(1, Artisan::call('oa:activate-price-list', [
            '--year' => 2026,
            '--revision' => 2,
        ]));
        $this->assertSame(PriceListStatus::Active, $first->fresh()->status);
        $this->assertSame(PriceListStatus::Draft, OaTkpPriceList::query()->where('revision_number', 2)->firstOrFail()->status);

        $this->assertSame(0, Artisan::call('oa:activate-price-list', [
            '--year' => 2026,
            '--revision' => 2,
            '--archive-previous' => true,
        ]));
        $this->assertSame(PriceListStatus::Archived, $first->fresh()->status);
        $this->assertSame(PriceListStatus::Active, OaTkpPriceList::query()->where('revision_number', 2)->firstOrFail()->status);
        $this->assertSame(1, OaTkpPriceList::query()->where('year', 2026)->where('status', PriceListStatus::Active)->count());
    }

    #[Test]
    public function activate_dry_run_and_idempotent_and_rollback_on_empty_items(): void
    {
        $this->seed(InitialCatalogMatCoreSeeder::class);
        $this->assertSame(0, Artisan::call('oa:import-price-list', [
            'path' => $this->writeJson('r1.json', $this->priceListPayload(2026, 1, PriceListStatus::Draft->value)),
        ]));

        $this->assertSame(0, Artisan::call('oa:activate-price-list', [
            '--year' => 2026,
            '--revision' => 1,
            '--dry-run' => true,
        ]));
        $this->assertSame(PriceListStatus::Draft, OaTkpPriceList::query()->firstOrFail()->status);

        $firstActivate = Artisan::call('oa:activate-price-list', [
            '--year' => 2026,
            '--revision' => 1,
        ]);
        $this->assertSame(0, $firstActivate);
        $secondActivate = Artisan::call('oa:activate-price-list', [
            '--year' => 2026,
            '--revision' => 1,
        ]);
        $this->assertSame(0, $secondActivate);
        $this->assertSame(1, OaTkpPriceList::query()->where('status', PriceListStatus::Active)->count());

        $empty = OaTkpPriceList::query()->create([
            'year' => 2026,
            'name' => 'Empty',
            'version' => '2026.9',
            'revision_number' => 9,
            'lock_version' => 1,
            'status' => PriceListStatus::Draft,
        ]);
        $beforeActiveId = (int) OaTkpPriceList::query()->where('status', PriceListStatus::Active)->value('id');
        $this->assertSame(1, Artisan::call('oa:activate-price-list', [
            '--year' => 2026,
            '--revision' => 9,
            '--archive-previous' => true,
        ]));
        $this->assertSame(PriceListStatus::Draft, $empty->fresh()->status);
        $this->assertSame($beforeActiveId, (int) OaTkpPriceList::query()->where('status', PriceListStatus::Active)->value('id'));
    }

    #[Test]
    public function different_years_are_independent_and_pins_survive_activation(): void
    {
        $this->seed(InitialCatalogMatCoreSeeder::class);
        $this->assertSame(0, Artisan::call('oa:import-price-list', [
            'path' => $this->writeJson('y2026.json', $this->priceListPayload(2026, 1, PriceListStatus::Draft->value)),
        ]));
        $this->assertSame(0, Artisan::call('oa:activate-price-list', [
            '--year' => 2026,
            '--revision' => 1,
        ]));

        $this->assertSame(0, Artisan::call('oa:import-price-list', [
            'path' => $this->writeJson('y2027.json', $this->priceListPayload(2027, 1, PriceListStatus::Draft->value, '14.0000', '10.0000')),
        ]));
        $this->assertSame(0, Artisan::call('oa:activate-price-list', [
            '--year' => 2027,
            '--revision' => 1,
        ]));
        $this->assertSame(1, OaTkpPriceList::query()->where('year', 2026)->where('status', PriceListStatus::Active)->count());
        $this->assertSame(1, OaTkpPriceList::query()->where('year', 2027)->where('status', PriceListStatus::Active)->count());

        OaPlatform::query()->create([
            'code' => 'cli_pin_plat',
            'name' => 'Pin Platform',
            'is_active' => true,
            'sort' => 1,
            'is_adserver_relevant' => false,
        ]);
        $list2026 = OaTkpPriceList::query()->where('year', 2026)->where('status', PriceListStatus::Active)->firstOrFail();
        OaTkpWizardCapability::fakeHttpMutationAllowedForTests(true);
        $user = User::factory()->role(Role::Sales)->create();
        $medium = AdvertisingMedium::query()->where('code', 'pre_stream')->firstOrFail();
        $inventory = Inventory::query()->where('code', 'inv_more_kombi_online_audio')->firstOrFail();
        $platform = OaPlatform::query()->where('code', 'cli_pin_plat')->firstOrFail();

        $calc = app(CalculationWriter::class)->create($this->withLiveSchemaFingerprint([
            'planning_mode' => 'manual',
            'order_discount_percent' => '0',
            'ae_enabled' => false,
            'positions' => [[
                'client_key' => (string) str()->uuid(),
                'inventory_id' => $inventory->id,
                'advertising_medium_id' => $medium->id,
                'calculation_method_key' => 'tkp',
                'price_year' => 2026,
                'expected_oa_tkp_price_list_id' => $list2026->id,
                'impressions' => 100_000,
                'basis_tkp' => '10.0000',
                'oa_platform_allocations' => [
                    ['oa_platform_id' => $platform->id, 'impressions' => 100_000],
                ],
                'oa_targeting_selections' => [],
                'position_discount_percent' => '0',
                'ae_percent' => '15',
            ]],
        ]), $user);

        $position = $calc->positions()->firstOrFail();
        $pinnedListId = (int) $position->oa_tkp_price_list_id;
        $pinnedListTkp = (string) $position->list_tkp_snapshot;
        $pinnedNn = (string) $calc->nn_invest;

        $this->assertSame(0, Artisan::call('oa:import-price-list', [
            'path' => $this->writeJson('y2026-r2.json', $this->priceListPayload(2026, 2, PriceListStatus::Draft->value, '20.0000', '15.0000')),
        ]));
        $this->assertSame(0, Artisan::call('oa:activate-price-list', [
            '--year' => 2026,
            '--revision' => 2,
            '--archive-previous' => true,
        ]));

        $fresh = Calculation::query()->findOrFail($calc->id);
        $freshPos = $fresh->positions()->firstOrFail();
        $this->assertSame($pinnedListId, (int) $freshPos->oa_tkp_price_list_id);
        $this->assertSame(0, OaTkpImportDecimal::compare((string) $freshPos->list_tkp_snapshot, $pinnedListTkp));
        $this->assertSame($pinnedNn, (string) $fresh->nn_invest);
        $this->assertNotSame(
            (int) OaTkpPriceList::query()->where('year', 2026)->where('status', PriceListStatus::Active)->value('id'),
            $pinnedListId,
        );
    }

    #[Test]
    public function year_lock_fails_closed_for_unsupported_driver(): void
    {
        $previous = (string) config('database.default');
        config([
            'database.default' => 'oa_tkp_unsupported',
            'database.connections.oa_tkp_unsupported' => [
                'driver' => 'sqlsrv',
                'host' => '127.0.0.1',
                'database' => 'unused',
                'username' => 'unused',
                'password' => '',
            ],
        ]);
        DB::purge('oa_tkp_unsupported');
        DB::setDefaultConnection('oa_tkp_unsupported');

        try {
            $this->expectException(OaTkpStammdatenImportException::class);
            (new OaTkpPriceListYearLock)->acquire(2026, 1);
        } finally {
            DB::setDefaultConnection($previous);
            DB::purge('oa_tkp_unsupported');
        }
    }

    #[Test]
    public function release_protection_unchanged(): void
    {
        $this->assertSame(EngineCapabilityStatus::Planned, EngineProfileRegistry::pairStatus('oa_tkp', 'tkp'));
        $this->assertNull(EngineProfileRegistry::currentReleasedVersion('oa_tkp', 'tkp'));
        $this->assertFalse(OaTkpWizardCapability::allowsHttpMutation());
    }

    /**
     * @param  array<mixed>  $data
     */
    private function writeJson(string $name, array $data): string
    {
        $path = $this->tmpDir.'/'.$name;
        File::put($path, json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

        return $path;
    }

    /**
     * @return array<string, mixed>
     */
    private function priceListPayload(
        int $year,
        int $revision,
        string $status,
        string $listTkp = '12.0000',
        string $minimumTkp = '8.0000',
    ): array {
        return [
            'year' => $year,
            'revision_number' => $revision,
            'status' => $status,
            'name' => "CLI {$year}.{$revision}",
            'version' => "{$year}.{$revision}",
            'items' => [[
                'medium_code' => 'pre_stream',
                'inventory_code' => 'inv_more_kombi_online_audio',
                'list_tkp' => $listTkp,
                'minimum_tkp' => $minimumTkp,
            ]],
        ];
    }
}
