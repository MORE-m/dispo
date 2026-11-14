<?php

namespace Tests\Feature\Calculation\FreePosition;

use App\Enums\CalculationKind;
use App\Enums\CalculationMethodMode;
use App\Models\AdvertisingMedium;
use App\Models\AdvertisingMediumCalculationMethod;
use App\Models\CalculationMethod;
use App\Models\Inventory;
use App\Models\InventoryMediumRule;
use App\Services\InventoryMediumRule\Catalog\InitialCatalogBootstrapper;
use App\Support\Calculation\EngineProfileRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * NATIVE-5: Datenmigration setzt native_ad auf free_position (Override + Assignment),
 * legt die Podcast-Rule an und lässt die vier historischen Native-Ad-Rules unberührt.
 *
 * Pre-State simuliert den mat_core-Stand vor NATIVE-5 (Bootstrap setzt den Vertrag bereits,
 * daher wird er hier bewusst zurückgesetzt).
 */
class FreePositionNative5ReleaseMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'migrations/2026_11_14_180000_po_oa_native_5_release.php';

    /** @var array<string, string> Inventar-Code => Buchungskennzeichen der historischen Rules */
    private const HISTORICAL = [
        'inv_radio_hamburg' => 'Online Audio (UA)',
        'inv_rock_antenne_hamburg' => 'Online Audio (UA)',
        'inv_80er_90er_oldie_antenne_hamburg' => 'Online Audio (UA)',
        'inv_caravan_fm' => 'UC',
    ];

    #[Test]
    public function up_installs_free_position_podcast_and_keeps_historical_rules_unchanged(): void
    {
        $native = $this->preNative5State();
        $snapshots = $this->createHistoricalRulesAndSnapshot($native);

        $this->assertNull($native->fresh()->getAttributes()['kind']);
        $this->assertSame(
            0,
            AdvertisingMediumCalculationMethod::query()->where('advertising_medium_id', $native->id)->count(),
        );
        $this->assertFalse($this->podcastRuleQuery($native)->exists());
        $this->assertFalse($this->moreOaRuleQuery($native)->exists());
        $this->assertSame(4, $this->activeNativeRuleCount($native));

        $migration = require database_path(self::MIGRATION);
        $migration->up();

        $this->assertNativeIsFreePosition($native->id);

        $podcast = $this->podcastRuleQuery($native)->get();
        $this->assertCount(1, $podcast);
        $rule = $podcast->firstOrFail();
        $this->assertTrue($rule->is_active);
        $this->assertSame('redaktion', $rule->planning_responsibility_key);
        $this->assertSame('Online Audio (UA)', $rule->booking_code);
        $this->assertSame(30, (int) $rule->default_length_seconds);

        $this->assertHistoricalRulesUnchanged($snapshots);
        $this->assertFalse($this->moreOaRuleQuery($native)->exists());
        $this->assertSame(5, $this->activeNativeRuleCount($native));
    }

    #[Test]
    public function down_removes_release_initial_state_but_keeps_historical_rules_and_reup_is_idempotent(): void
    {
        $native = $this->preNative5State();
        $snapshots = $this->createHistoricalRulesAndSnapshot($native);

        $migration = require database_path(self::MIGRATION);
        $migration->up();
        $this->assertNativeIsFreePosition($native->id);

        $migration->down();

        $rolled = AdvertisingMedium::query()->findOrFail($native->id);
        $this->assertNull($rolled->getAttributes()['kind']);
        $this->assertSame(CalculationMethodMode::Inherit, $rolled->calculation_method_mode);
        $this->assertNull($rolled->default_calculation_method_id);
        $this->assertSame(
            0,
            AdvertisingMediumCalculationMethod::query()->where('advertising_medium_id', $native->id)->count(),
        );
        $this->assertFalse($this->podcastRuleQuery($native)->exists());
        // Vier historische Rules bleiben, byte-/wertgleich.
        $this->assertHistoricalRulesUnchanged($snapshots);
        $this->assertSame(4, $this->activeNativeRuleCount($native));

        // Re-Up (idempotent, auch mehrfach).
        $migration->up();
        $migration->up();
        $this->assertNativeIsFreePosition($native->id);
        $this->assertSame(1, $this->podcastRuleQuery($native)->count());
        $this->assertSame(
            1,
            AdvertisingMediumCalculationMethod::query()->where('advertising_medium_id', $native->id)->count(),
        );
        $this->assertHistoricalRulesUnchanged($snapshots);
        $this->assertSame(5, $this->activeNativeRuleCount($native));
    }

    #[Test]
    public function down_keeps_podcast_rule_and_native_config_after_later_admin_changes(): void
    {
        $native = $this->preNative5State();
        $snapshots = $this->createHistoricalRulesAndSnapshot($native);

        $migration = require database_path(self::MIGRATION);
        $migration->up();

        // Spätere Admin-Änderungen: Podcast-Rule angepasst, Assignment-Sort geändert.
        $this->podcastRuleQuery($native)->firstOrFail()->update(['booking_code' => 'UX']);
        AdvertisingMediumCalculationMethod::query()
            ->where('advertising_medium_id', $native->id)
            ->update(['sort' => 20]);

        $migration->down();

        $this->assertSame(1, $this->podcastRuleQuery($native)->count());
        $this->assertSame('UX', $this->podcastRuleQuery($native)->firstOrFail()->booking_code);
        $this->assertSame(CalculationKind::FreePosition, $native->fresh()->kind);
        $this->assertSame(
            1,
            AdvertisingMediumCalculationMethod::query()->where('advertising_medium_id', $native->id)->count(),
        );
        $this->assertHistoricalRulesUnchanged($snapshots);
    }

    #[Test]
    public function up_fails_closed_when_more_kombi_online_audio_rule_exists(): void
    {
        $native = $this->preNative5State();
        $this->createHistoricalRulesAndSnapshot($native);
        $moreOa = Inventory::query()->where('code', 'inv_more_kombi_online_audio')->firstOrFail();
        InventoryMediumRule::factory()->create([
            'inventory_id' => $moreOa->id,
            'advertising_medium_id' => $native->id,
        ]);

        $migration = require database_path(self::MIGRATION);
        try {
            $migration->up();
            $this->fail('Erwartet: RuntimeException für MORE-OA × native_ad.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('inv_more_kombi_online_audio', $e->getMessage());
        }

        // Transaktion zurückgerollt: kein halber Release.
        $this->assertNull($native->fresh()->getAttributes()['kind']);
        $this->assertFalse($this->podcastRuleQuery($native)->exists());
    }

    /**
     * Bootstrap, dann native_ad und Podcast-Rule auf den Stand vor NATIVE-5 zurücksetzen.
     */
    private function preNative5State(): AdvertisingMedium
    {
        app(InitialCatalogBootstrapper::class)->bootstrap();

        $native = AdvertisingMedium::query()->where('code', 'native_ad')->firstOrFail();
        // Bootstrap hat den Release-Vertrag schon gesetzt (Sanity), jetzt zurücksetzen.
        $this->assertSame(CalculationKind::FreePosition, $native->kind);

        AdvertisingMediumCalculationMethod::query()
            ->where('advertising_medium_id', $native->id)
            ->delete();
        DB::table('advertising_media')->where('id', $native->id)->update([
            'kind' => null,
            'calculation_method_mode' => 'inherit',
            'default_calculation_method_id' => null,
        ]);

        $this->podcastRuleQuery($native)->delete();

        return $native->fresh();
    }

    /**
     * @return array<string, array<string, mixed>> Inventar-Code => Rohzeile
     */
    private function createHistoricalRulesAndSnapshot(AdvertisingMedium $native): array
    {
        $snapshots = [];
        foreach (self::HISTORICAL as $code => $booking) {
            $inventory = Inventory::query()->where('code', $code)->firstOrFail();
            $rule = InventoryMediumRule::factory()->create([
                'inventory_id' => $inventory->id,
                'advertising_medium_id' => $native->id,
                'is_active' => true,
                'booking_code' => $booking,
                'planning_responsibility_key' => 'redaktion',
            ]);
            $snapshots[$code] = $this->rawRule((int) $rule->id);
        }

        return $snapshots;
    }

    /**
     * @param  array<string, array<string, mixed>>  $snapshots
     */
    private function assertHistoricalRulesUnchanged(array $snapshots): void
    {
        $this->assertCount(4, $snapshots);
        foreach ($snapshots as $code => $before) {
            $this->assertSame(
                $before,
                $this->rawRule((int) $before['id']),
                "Historische Rule {$code} wurde verändert.",
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function rawRule(int $id): array
    {
        $row = DB::table('inventory_medium_rules')->where('id', $id)->first();
        $this->assertNotNull($row, "Rule {$id} fehlt.");

        return (array) $row;
    }

    private function assertNativeIsFreePosition(int $nativeId): void
    {
        $native = AdvertisingMedium::query()->findOrFail($nativeId);
        $method = CalculationMethod::query()->where('key', 'free_position')->firstOrFail();

        $this->assertSame(CalculationKind::FreePosition, $native->kind);
        $this->assertSame(CalculationMethodMode::Override, $native->calculation_method_mode);
        $this->assertSame((int) $method->id, (int) $native->default_calculation_method_id);
        $this->assertTrue((bool) $native->is_discountable);
        $this->assertTrue((bool) $native->is_ae_eligible);

        $assignments = AdvertisingMediumCalculationMethod::query()
            ->where('advertising_medium_id', $nativeId)
            ->get();
        $this->assertCount(1, $assignments);
        $assignment = $assignments->firstOrFail();
        $this->assertSame((int) $method->id, (int) $assignment->calculation_method_id);
        $this->assertSame(EngineProfileRegistry::PROFILE_FREE_POSITION, $assignment->engine_profile_key);
        $this->assertTrue((bool) $assignment->is_active);
        $this->assertSame(10, (int) $assignment->sort);
    }

    private function podcastRuleQuery(AdvertisingMedium $native): Builder
    {
        $podcast = Inventory::query()->where('code', 'inv_more_kombi_podcast')->firstOrFail();

        return InventoryMediumRule::query()
            ->where('inventory_id', $podcast->id)
            ->where('advertising_medium_id', $native->id);
    }

    private function moreOaRuleQuery(AdvertisingMedium $native): Builder
    {
        $moreOa = Inventory::query()->where('code', 'inv_more_kombi_online_audio')->firstOrFail();

        return InventoryMediumRule::query()
            ->where('inventory_id', $moreOa->id)
            ->where('advertising_medium_id', $native->id);
    }

    private function activeNativeRuleCount(AdvertisingMedium $native): int
    {
        return InventoryMediumRule::query()
            ->where('advertising_medium_id', $native->id)
            ->where('is_active', true)
            ->count();
    }
}
