<?php

namespace Tests\Feature\OnlineAudio;

use App\Enums\EngineCapabilityStatus;
use App\Models\AdvertisingMedium;
use App\Models\CalculationPositionOaPlatformAllocation;
use App\Models\OaPlatform;
use App\Models\OaPlatformMediumRule;
use App\Support\Calculation\EngineProfileRegistry;
use App\Support\OnlineAudio\OaPlatformMediumRules;
use App\Support\OnlineAudio\OaTkpCoreMedia;
use Database\Seeders\InitialCatalogMatCoreSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * PO-CALC-TKP-1D4c-4j-1: Schema + Helper für Platform×Medium-Rules (ohne Writer-Enforce).
 */
class PoCalcTkp1d4c4j1PlatformMediumRulesTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function migration_creates_table_with_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('oa_platform_medium_rules'));
        foreach (['id', 'oa_platform_id', 'advertising_medium_id', 'is_active', 'created_at', 'updated_at'] as $column) {
            $this->assertTrue(Schema::hasColumn('oa_platform_medium_rules', $column), $column);
        }
    }

    #[Test]
    public function foreign_keys_reject_unknown_platform_and_medium(): void
    {
        $this->seed(InitialCatalogMatCoreSeeder::class);
        $mediumId = (int) AdvertisingMedium::query()->where('code', 'pre_stream')->value('id');

        $this->expectException(QueryException::class);
        OaPlatformMediumRule::query()->create([
            'oa_platform_id' => 9_999_999,
            'advertising_medium_id' => $mediumId,
            'is_active' => true,
        ]);
    }

    #[Test]
    public function unique_constraint_prevents_duplicate_pairs(): void
    {
        $ctx = $this->seedPlatformAndCoreMedia();
        OaPlatformMediumRule::query()->create([
            'oa_platform_id' => $ctx['platform']->id,
            'advertising_medium_id' => $ctx['pre_stream']->id,
            'is_active' => true,
        ]);

        $this->expectException(QueryException::class);
        OaPlatformMediumRule::query()->create([
            'oa_platform_id' => $ctx['platform']->id,
            'advertising_medium_id' => $ctx['pre_stream']->id,
            'is_active' => false,
        ]);
    }

    #[Test]
    public function all_five_core_media_are_allowed_including_pre_stream_and_midroll(): void
    {
        $ctx = $this->seedPlatformAndCoreMedia();

        foreach (OaTkpCoreMedia::codes() as $code) {
            $this->assertTrue(OaPlatformMediumRules::isAdvertisingMediumCodeAllowed($code), $code);
            $medium = AdvertisingMedium::query()->where('code', $code)->firstOrFail();
            $asserted = OaPlatformMediumRules::assertAdvertisingMediumAllowed($medium);
            $this->assertSame($medium->id, $asserted->id);

            OaPlatformMediumRule::query()->create([
                'oa_platform_id' => $ctx['platform']->id,
                'advertising_medium_id' => $medium->id,
                'is_active' => true,
            ]);
            $this->assertTrue(OaPlatformMediumRules::isActivelyAssigned(
                (int) $ctx['platform']->id,
                (int) $medium->id,
            ), $code);
        }

        $this->assertCount(5, OaPlatformMediumRules::activeMediumIdsForPlatform((int) $ctx['platform']->id));
        $this->assertSame(
            [(int) $ctx['platform']->id],
            OaPlatformMediumRules::activePlatformIdsForMedium((int) $ctx['pre_stream']->id),
        );
        $this->assertSame(
            [(int) $ctx['platform']->id],
            OaPlatformMediumRules::activePlatformIdsForMedium((int) $ctx['midroll']->id),
        );
    }

    #[Test]
    public function native_ad_and_non_core_media_are_rejected(): void
    {
        $this->seed(InitialCatalogMatCoreSeeder::class);
        $native = AdvertisingMedium::query()->where('code', 'native_ad')->first();
        if ($native !== null) {
            $this->assertFalse(OaPlatformMediumRules::isAdvertisingMediumCodeAllowed('native_ad'));
            try {
                OaPlatformMediumRules::assertAdvertisingMediumAllowed($native);
                $this->fail('native_ad should be rejected');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('advertising_medium_id', $e->errors());
            }
        }

        $other = AdvertisingMedium::query()
            ->whereNotIn('code', [...OaTkpCoreMedia::codes(), 'native_ad'])
            ->first();
        if ($other !== null) {
            $this->assertFalse(OaPlatformMediumRules::isAdvertisingMediumCodeAllowed((string) $other->code));
            $this->expectException(ValidationException::class);
            OaPlatformMediumRules::assertAdvertisingMediumAllowed($other);
        } else {
            $this->assertTrue(true);
        }
    }

    #[Test]
    public function inactive_rule_and_inactive_platform_are_excluded_from_active_lookups(): void
    {
        $ctx = $this->seedPlatformAndCoreMedia();
        $rule = OaPlatformMediumRule::query()->create([
            'oa_platform_id' => $ctx['platform']->id,
            'advertising_medium_id' => $ctx['pre_stream']->id,
            'is_active' => false,
        ]);

        $this->assertSame([], OaPlatformMediumRules::activePlatformIdsForMedium((int) $ctx['pre_stream']->id));
        $this->assertFalse(OaPlatformMediumRules::isActivelyAssigned(
            (int) $ctx['platform']->id,
            (int) $ctx['pre_stream']->id,
        ));
        $this->assertCount(1, OaPlatformMediumRules::rulesForMedium((int) $ctx['pre_stream']->id));
        $this->assertCount(0, OaPlatformMediumRules::rulesForMedium((int) $ctx['pre_stream']->id, onlyActive: true));

        $rule->update(['is_active' => true]);
        $ctx['platform']->update(['is_active' => false]);

        $this->assertSame([], OaPlatformMediumRules::activePlatformIdsForMedium((int) $ctx['pre_stream']->id));
        $this->assertSame([], OaPlatformMediumRules::activeMediumIdsForPlatform((int) $ctx['platform']->id));
        $this->assertFalse(OaPlatformMediumRules::isActivelyAssigned(
            (int) $ctx['platform']->id,
            (int) $ctx['pre_stream']->id,
        ));
    }

    #[Test]
    public function empty_rule_set_yields_no_active_assignments(): void
    {
        $ctx = $this->seedPlatformAndCoreMedia();

        $this->assertSame([], OaPlatformMediumRules::activePlatformIdsForMedium((int) $ctx['pre_stream']->id));
        $this->assertSame([], OaPlatformMediumRules::activeMediumIdsForPlatform((int) $ctx['platform']->id));
        $this->assertFalse(OaPlatformMediumRules::isActivelyAssigned(
            (int) $ctx['platform']->id,
            (int) $ctx['pre_stream']->id,
        ));
        $this->assertCount(0, OaPlatformMediumRules::rulesForPlatform((int) $ctx['platform']->id));
    }

    #[Test]
    public function deactivating_rule_does_not_mutate_allocation_snapshots_or_registry(): void
    {
        $ctx = $this->seedPlatformAndCoreMedia();
        $allocBefore = CalculationPositionOaPlatformAllocation::query()->count();

        $rule = OaPlatformMediumRule::query()->create([
            'oa_platform_id' => $ctx['platform']->id,
            'advertising_medium_id' => $ctx['pre_stream']->id,
            'is_active' => true,
        ]);
        $rule->update(['is_active' => false]);

        $this->assertSame($allocBefore, CalculationPositionOaPlatformAllocation::query()->count());
        $this->assertSame(
            EngineCapabilityStatus::Planned,
            EngineProfileRegistry::pairStatus('oa_tkp', 'tkp'),
        );
    }

    #[Test]
    public function eloquent_relations_and_admin_platform_routes_remain_available(): void
    {
        $ctx = $this->seedPlatformAndCoreMedia();
        $rule = OaPlatformMediumRule::query()->create([
            'oa_platform_id' => $ctx['platform']->id,
            'advertising_medium_id' => $ctx['midroll']->id,
            'is_active' => true,
        ]);

        $this->assertTrue($ctx['platform']->mediumRules()->whereKey($rule->id)->exists());
        $this->assertTrue($ctx['midroll']->oaPlatformMediumRules()->whereKey($rule->id)->exists());
        $this->assertSame($ctx['platform']->id, $rule->oaPlatform()->firstOrFail()->id);
        $this->assertSame($ctx['midroll']->id, $rule->advertisingMedium()->firstOrFail()->id);

        $this->assertTrue(
            collect(Route::getRoutes())->contains(
                static fn ($route): bool => $route->getName() === 'administration.oa-tkp.platforms.index',
            ),
        );
    }

    #[Test]
    public function mysql_schema_has_unique_and_foreign_keys_when_on_mysql(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('MySQL-only schema introspection');
        }

        $this->assertTrue(Schema::hasTable('oa_platform_medium_rules'));

        $indexes = collect(DB::select('SHOW INDEX FROM oa_platform_medium_rules'))
            ->pluck('Key_name')
            ->unique()
            ->all();
        $this->assertContains('oa_platform_medium_rules_unique', $indexes);

        $fks = collect(DB::select(
            'SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_TYPE = ?',
            ['oa_platform_medium_rules', 'FOREIGN KEY'],
        ))->pluck('CONSTRAINT_NAME')->all();
        $this->assertNotEmpty($fks);
    }

    /**
     * @return array{
     *     platform: OaPlatform,
     *     pre_stream: AdvertisingMedium,
     *     midroll: AdvertisingMedium
     * }
     */
    private function seedPlatformAndCoreMedia(): array
    {
        $this->seed(InitialCatalogMatCoreSeeder::class);

        $platform = OaPlatform::query()->create([
            'code' => 'plat_d4c4j1',
            'name' => 'D4c4j1 Platform',
            'is_active' => true,
            'sort' => 1,
            'is_adserver_relevant' => false,
        ]);

        $preStream = AdvertisingMedium::query()->where('code', 'pre_stream')->firstOrFail();
        $midroll = AdvertisingMedium::query()->where('code', 'midroll_music_environment')->firstOrFail();

        foreach (OaTkpCoreMedia::codes() as $code) {
            $this->assertNotNull(
                AdvertisingMedium::query()->where('code', $code)->first(),
                "missing core medium {$code}",
            );
        }

        return [
            'platform' => $platform,
            'pre_stream' => $preStream,
            'midroll' => $midroll,
        ];
    }
}
