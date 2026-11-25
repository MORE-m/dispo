<?php

namespace Tests\Feature\InventoryMediumRule;

use App\Enums\CalculationKind;
use App\Enums\CalculationMethodMode;
use App\Enums\PriceListStatus;
use App\Enums\ProductionType;
use App\Models\AdvertisingCategoryCalculationMethod;
use App\Models\AdvertisingMedium;
use App\Models\AdvertisingMediumCalculationMethod;
use App\Models\CalculationMethod;
use App\Models\Inventory;
use App\Models\InventoryMediumRule;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\ProductionPriceList;
use App\Services\InventoryMediumRule\Catalog\InitialCatalogBootstrapper;
use App\Services\InventoryMediumRule\Catalog\SwfLengthMediumOverrideEnsurer;
use App\Services\InventoryMediumRule\Catalog\SwfMediumMethodOverrideRepairer;
use App\Services\InventoryMediumRule\Import\CombinationMatrixImporter;
use App\Services\MasterDataTransfer\Export\MasterDataExporter;
use App\Support\Advertising\AdvertisingMediumLiveBookability;
use App\Support\Calculation\EngineProfileRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * PO-REFOCUS-6: Gezielte Reparatur Allonge + Promo Methoden-Overrides.
 */
class SwfMediumMethodOverrideRepairTest extends TestCase
{
    use RefreshDatabase;

    private string $tmp = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = storage_path('app/testing/swf-repair-'.uniqid('', true));
        File::ensureDirectoryExists($this->tmp);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tmp);
        parent::tearDown();
    }

    #[Test]
    public function repair_installs_allonge_and_promo_overrides_from_inherit(): void
    {
        $this->seedBrokenSwfOverrides();

        $report = app(SwfMediumMethodOverrideRepairer::class)->repair(dryRun: false);

        $this->assertSame('PASS', $report['status']);
        $this->assertEqualsCanonicalizing(['allonge', 'promo_moderation'], $report['changed']);
        $this->assertSame([], $report['unchanged']);

        $this->assertOverrideState('allonge', EngineProfileRegistry::PROFILE_SWF_ALLONGE);
        $this->assertOverrideState('promo_moderation', EngineProfileRegistry::PROFILE_SWF_PROMO);
    }

    #[Test]
    public function second_apply_is_idempotent(): void
    {
        $this->seedBrokenSwfOverrides();
        app(SwfMediumMethodOverrideRepairer::class)->repair(dryRun: false);

        $report = app(SwfMediumMethodOverrideRepairer::class)->repair(dryRun: false);

        $this->assertSame('PASS', $report['status']);
        $this->assertEqualsCanonicalizing(['allonge', 'promo_moderation'], $report['unchanged']);
        $this->assertSame([], $report['changed']);
    }

    #[Test]
    public function unknown_prestate_stops_without_partial_writes(): void
    {
        $this->seedBrokenSwfOverrides();

        $promo = AdvertisingMedium::query()->where('code', 'promo_moderation')->firstOrFail();
        $promo->calculation_method_mode = CalculationMethodMode::Override;
        $promo->default_calculation_method_id = CalculationMethod::query()->where('key', 'calendar')->value('id');
        $promo->save();

        $allongeBefore = $this->mediumFingerprint('allonge');
        $promoBefore = $this->mediumFingerprint('promo_moderation');
        $mmBefore = AdvertisingMediumCalculationMethod::query()->count();

        $report = app(SwfMediumMethodOverrideRepairer::class)->repair(dryRun: false);

        $this->assertSame('STOP', $report['status']);
        $this->assertNotEmpty($report['conflicts']);
        $this->assertSame($allongeBefore, $this->mediumFingerprint('allonge'));
        $this->assertSame($promoBefore, $this->mediumFingerprint('promo_moderation'));
        $this->assertSame($mmBefore, AdvertisingMediumCalculationMethod::query()->count());
    }

    #[Test]
    public function trailer_contest_category_rules_prices_and_methods_stay_untouched(): void
    {
        $this->seedBrokenSwfOverrides();
        app(CombinationMatrixImporter::class)->import();

        $trailerBefore = $this->mediumFingerprint('trailer_station_voice');
        $contestBefore = $this->mediumFingerprint('contest_payoff');
        $categoryBefore = AdvertisingCategoryCalculationMethod::query()
            ->orderBy('id')
            ->get(['advertising_category_id', 'calculation_method_id', 'engine_profile_key', 'is_active', 'sort'])
            ->toArray();
        $rulesBefore = InventoryMediumRule::query()
            ->orderBy('id')
            ->get(['id', 'advertising_medium_id', 'default_length_seconds', 'surcharge_percent', 'swf_allonge_confirmed_at'])
            ->map(fn (InventoryMediumRule $r): array => [
                'id' => $r->id,
                'advertising_medium_id' => $r->advertising_medium_id,
                'default_length_seconds' => $r->default_length_seconds,
                'surcharge_percent' => $r->surcharge_percent,
                'swf_allonge_confirmed_at' => $r->swf_allonge_confirmed_at?->toIso8601String(),
            ])
            ->all();
        $priceCount = PriceList::query()->count();
        $averageActive = (bool) CalculationMethod::query()->where('key', 'average')->value('is_active');
        $calendarActive = (bool) CalculationMethod::query()->where('key', 'calendar')->value('is_active');

        app(SwfMediumMethodOverrideRepairer::class)->repair(dryRun: false);

        $this->assertSame($trailerBefore, $this->mediumFingerprint('trailer_station_voice'));
        $this->assertSame($contestBefore, $this->mediumFingerprint('contest_payoff'));
        $this->assertSame(
            $categoryBefore,
            AdvertisingCategoryCalculationMethod::query()
                ->orderBy('id')
                ->get(['advertising_category_id', 'calculation_method_id', 'engine_profile_key', 'is_active', 'sort'])
                ->toArray(),
        );
        $this->assertSame(
            $rulesBefore,
            InventoryMediumRule::query()
                ->orderBy('id')
                ->get(['id', 'advertising_medium_id', 'default_length_seconds', 'surcharge_percent', 'swf_allonge_confirmed_at'])
                ->map(fn (InventoryMediumRule $r): array => [
                    'id' => $r->id,
                    'advertising_medium_id' => $r->advertising_medium_id,
                    'default_length_seconds' => $r->default_length_seconds,
                    'surcharge_percent' => $r->surcharge_percent,
                    'swf_allonge_confirmed_at' => $r->swf_allonge_confirmed_at?->toIso8601String(),
                ])
                ->all(),
        );
        $this->assertSame($priceCount, PriceList::query()->count());
        $this->assertTrue($averageActive);
        $this->assertTrue($calendarActive);
        $this->assertTrue((bool) CalculationMethod::query()->where('key', 'average')->value('is_active'));
        $this->assertTrue((bool) CalculationMethod::query()->where('key', 'calendar')->value('is_active'));
    }

    #[Test]
    public function live_bookability_uses_correct_profiles_after_repair(): void
    {
        $this->seedBrokenSwfOverrides();
        app(SwfMediumMethodOverrideRepairer::class)->repair(dryRun: false);

        $bookability = app(AdvertisingMediumLiveBookability::class);
        $allonge = AdvertisingMedium::query()->where('code', 'allonge')->firstOrFail();
        $promo = AdvertisingMedium::query()->where('code', 'promo_moderation')->firstOrFail();

        $allongeEval = $bookability->evaluate($allonge);
        $promoEval = $bookability->evaluate($promo);

        $this->assertTrue($allongeEval->isBookableForNewPositions);
        $this->assertSame('average', $allongeEval->calculationMethodKey);
        $this->assertSame(EngineProfileRegistry::PROFILE_SWF_ALLONGE, $allongeEval->engineProfileKey);

        $this->assertTrue($promoEval->isBookableForNewPositions);
        $this->assertSame('average', $promoEval->calculationMethodKey);
        $this->assertSame(EngineProfileRegistry::PROFILE_SWF_PROMO, $promoEval->engineProfileKey);
    }

    #[Test]
    public function dry_run_reports_changes_without_writes(): void
    {
        $this->seedBrokenSwfOverrides();
        $beforeAllonge = $this->mediumFingerprint('allonge');
        $beforePromo = $this->mediumFingerprint('promo_moderation');
        $mmBefore = AdvertisingMediumCalculationMethod::query()->count();

        $db = (string) config('database.connections.'.config('database.default').'.database');
        $exit = Artisan::call('dispo:repair-swf-method-overrides', [
            '--dry-run' => true,
            '--confirm-database' => $db,
        ]);

        $this->assertSame(0, $exit);
        $this->assertSame($beforeAllonge, $this->mediumFingerprint('allonge'));
        $this->assertSame($beforePromo, $this->mediumFingerprint('promo_moderation'));
        $this->assertSame($mmBefore, AdvertisingMediumCalculationMethod::query()->count());
        $this->assertStringContainsString('PASS', Artisan::output());
    }

    #[Test]
    public function wrong_confirm_database_stops(): void
    {
        $this->seedBrokenSwfOverrides();
        $before = AdvertisingMediumCalculationMethod::query()->count();

        $exit = Artisan::call('dispo:repair-swf-method-overrides', [
            '--dry-run' => true,
            '--confirm-database' => 'definitely-not-the-active-db',
        ]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('STOP', Artisan::output());
        $this->assertSame($before, AdvertisingMediumCalculationMethod::query()->count());
    }

    #[Test]
    public function schema_100_export_includes_repaired_medium_overrides(): void
    {
        $this->seedBrokenSwfOverrides();
        // Contest absichtlich im defekten Inherit-Zustand belassen – darf nicht mitrepariert werden.
        $contest = AdvertisingMedium::query()->where('code', 'contest_payoff')->firstOrFail();
        AdvertisingMediumCalculationMethod::query()->where('advertising_medium_id', $contest->id)->delete();
        $contest->calculation_method_mode = CalculationMethodMode::Inherit;
        $contest->default_calculation_method_id = null;
        $contest->save();
        $contestBefore = $this->mediumFingerprint('contest_payoff');

        app(CombinationMatrixImporter::class)->import();
        $this->seedSpotPricesForCodes(['inv_radio_hamburg']);
        app(SwfMediumMethodOverrideRepairer::class)->repair(dryRun: false);

        $this->assertSame($contestBefore, $this->mediumFingerprint('contest_payoff'));

        $exportPath = $this->tmp.'/export';
        $result = app(MasterDataExporter::class)->export($exportPath, ['inv_radio_hamburg'], 'refocus6');
        $this->assertSame('1.0.0', $result['manifest']['schema_version']);

        $media = json_decode((string) file_get_contents($exportPath.'/data/advertising_media.json'), true, 512, JSON_THROW_ON_ERROR);
        $methods = json_decode((string) file_get_contents($exportPath.'/data/advertising_medium_calculation_methods.json'), true, 512, JSON_THROW_ON_ERROR);

        $byCode = [];
        foreach ($media as $row) {
            $byCode[$row['code']] = $row;
        }

        $this->assertSame('override', $byCode['allonge']['calculation_method_mode']);
        $this->assertSame('average', $byCode['allonge']['default_calculation_method_key']);
        $this->assertSame('override', $byCode['promo_moderation']['calculation_method_mode']);
        $this->assertSame('average', $byCode['promo_moderation']['default_calculation_method_key']);

        $allongeMethods = array_values(array_filter(
            $methods,
            fn (array $row): bool => ($row['advertising_medium_code'] ?? null) === 'allonge',
        ));
        $promoMethods = array_values(array_filter(
            $methods,
            fn (array $row): bool => ($row['advertising_medium_code'] ?? null) === 'promo_moderation',
        ));
        $contestMethods = array_values(array_filter(
            $methods,
            fn (array $row): bool => ($row['advertising_medium_code'] ?? null) === 'contest_payoff',
        ));

        $this->assertCount(2, $allongeMethods);
        $this->assertEqualsCanonicalizing(
            ['average', 'calendar'],
            array_column($allongeMethods, 'calculation_method_key'),
        );
        $this->assertSame(
            [EngineProfileRegistry::PROFILE_SWF_ALLONGE, EngineProfileRegistry::PROFILE_SWF_ALLONGE],
            array_column($allongeMethods, 'engine_profile_key'),
        );

        $this->assertCount(2, $promoMethods);
        $this->assertEqualsCanonicalizing(
            ['average', 'calendar'],
            array_column($promoMethods, 'calculation_method_key'),
        );
        $this->assertSame(
            [EngineProfileRegistry::PROFILE_SWF_PROMO, EngineProfileRegistry::PROFILE_SWF_PROMO],
            array_column($promoMethods, 'engine_profile_key'),
        );

        $this->assertSame('inherit', $byCode['contest_payoff']['calculation_method_mode']);
        $this->assertSame([], $contestMethods);
    }

    #[Test]
    public function bootstrap_still_installs_overrides_via_shared_ensurer(): void
    {
        app(InitialCatalogBootstrapper::class)->bootstrap();

        $this->assertOverrideState('allonge', EngineProfileRegistry::PROFILE_SWF_ALLONGE);
        $this->assertOverrideState('promo_moderation', EngineProfileRegistry::PROFILE_SWF_PROMO);
        $this->assertSame(
            SwfLengthMediumOverrideEnsurer::OUTCOME_UNCHANGED,
            app(SwfLengthMediumOverrideEnsurer::class)->ensure(
                mediumCode: 'allonge',
                expectedKind: CalculationKind::SwfAllonge,
                engineProfile: EngineProfileRegistry::PROFILE_SWF_ALLONGE,
                label: 'Allonge',
            )['outcome'],
        );
    }

    private function seedBrokenSwfOverrides(): void
    {
        app(InitialCatalogBootstrapper::class)->bootstrap();

        foreach (['allonge', 'promo_moderation'] as $code) {
            $medium = AdvertisingMedium::query()->where('code', $code)->firstOrFail();
            AdvertisingMediumCalculationMethod::query()
                ->where('advertising_medium_id', $medium->id)
                ->delete();
            $medium->calculation_method_mode = CalculationMethodMode::Inherit;
            $medium->default_calculation_method_id = null;
            $medium->save();
        }
    }

    /**
     * @param  list<string>  $codes
     */
    private function seedSpotPricesForCodes(array $codes): void
    {
        foreach ($codes as $code) {
            $inventory = Inventory::query()->where('code', $code)->firstOrFail();
            foreach ([2026, 2027] as $year) {
                $list = PriceList::factory()->create([
                    'inventory_id' => $inventory->id,
                    'name' => "Import {$year} {$code}",
                    'year' => $year,
                    'revision_number' => 1,
                    'version' => '1',
                    'status' => PriceListStatus::Active,
                ]);
                PriceListItem::factory()->create([
                    'price_list_id' => $list->id,
                    'hour' => 8,
                    'day_group' => 'mo_fr',
                    'second_price' => '1.2500',
                ]);
            }
            ProductionPriceList::factory()->create([
                'inventory_id' => $inventory->id,
                'production_type' => ProductionType::SpotProduction,
                'year' => 2026,
                'revision_number' => 1,
                'version' => '1',
                'status' => PriceListStatus::Active,
                'unit_price' => '600.00',
            ]);
        }
    }

    private function assertOverrideState(string $code, string $profile): void
    {
        $medium = AdvertisingMedium::query()->where('code', $code)->firstOrFail();
        $this->assertSame(CalculationMethodMode::Override, $medium->calculation_method_mode);
        $averageId = (int) CalculationMethod::query()->where('key', 'average')->value('id');
        $this->assertSame($averageId, (int) $medium->default_calculation_method_id);

        $assignments = AdvertisingMediumCalculationMethod::query()
            ->where('advertising_medium_id', $medium->id)
            ->with('calculationMethod')
            ->orderBy('sort')
            ->get();
        $this->assertCount(2, $assignments);
        $this->assertSame(['average', 'calendar'], $assignments->pluck('calculationMethod.key')->all());
        $this->assertSame([$profile, $profile], $assignments->pluck('engine_profile_key')->all());
        $this->assertTrue($assignments->every(fn (AdvertisingMediumCalculationMethod $row): bool => (bool) $row->is_active));
        $this->assertSame([10, 20], $assignments->pluck('sort')->all());
    }

    /**
     * @return array{id: int, mode: string, default: int|null, methods: list<array<string, mixed>>}
     */
    private function mediumFingerprint(string $code): array
    {
        $medium = AdvertisingMedium::query()->where('code', $code)->firstOrFail();
        $methods = AdvertisingMediumCalculationMethod::query()
            ->where('advertising_medium_id', $medium->id)
            ->orderBy('sort')
            ->get(['calculation_method_id', 'engine_profile_key', 'is_active', 'sort'])
            ->toArray();

        return [
            'id' => (int) $medium->id,
            'mode' => $medium->calculation_method_mode->value,
            'default' => $medium->default_calculation_method_id === null
                ? null
                : (int) $medium->default_calculation_method_id,
            'methods' => $methods,
        ];
    }
}
