<?php

namespace Tests\Feature\Calculation;

use App\Enums\CalculationKind;
use App\Enums\Role;
use App\Models\AdvertisingMedium;
use App\Models\AdvertisingMediumCalculationMethod;
use App\Models\Calculation;
use App\Models\InventoryMediumRule;
use App\Models\User;
use App\Services\DispoOrder\DispoOrderWriter;
use App\Services\InventoryMediumRule\Catalog\InitialCatalogBootstrapper;
use App\Services\InventoryMediumRule\Import\CombinationMatrixImporter;
use App\Support\Advertising\AdvertisingMediumLiveBookability;
use App\Support\Calculation\EngineProfileRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesPromoAverageCatalog;
use Tests\Support\MysqlTestDatabaseGuard;
use Tests\TestCase;

/**
 * PO-SWF-PROMO-1: MySQL – Migration/Kind/4 Regeln/Bookability/Snapshot.
 *
 * RefreshDatabase (nicht DatabaseMigrations): Slice-Migration down() ist fail-closed
 * bei bestehenden Promo-Positionen; Rollback je Test wäre damit blockiert.
 */
class SwfPromoMysqlTest extends TestCase
{
    use CreatesPromoAverageCatalog;
    use RefreshDatabase;

    public function test_mysql_catalog_four_rules_20_50_and_bookability(): void
    {
        $this->requireMysql('PO-SWF-PROMO-1 catalog');

        app(InitialCatalogBootstrapper::class)->bootstrap();
        app(CombinationMatrixImporter::class)->import();

        $promo = AdvertisingMedium::query()->where('code', 'promo_moderation')->firstOrFail();
        $this->assertSame(CalculationKind::SwfPromo, $promo->kind);
        $this->assertSame(
            EngineProfileRegistry::PROFILE_SWF_PROMO,
            AdvertisingMediumCalculationMethod::query()
                ->where('advertising_medium_id', $promo->id)
                ->value('engine_profile_key'),
        );

        $rules = InventoryMediumRule::query()
            ->where('advertising_medium_id', $promo->id)
            ->with('inventory')
            ->get();
        $this->assertCount(4, $rules);
        $this->assertEqualsCanonicalizing(
            ['Radio Hamburg', 'ROCK ANTENNE Hamburg', '80er 90er OLDIE ANTENNE Hamburg', 'CARAVAN.fm'],
            $rules->pluck('inventory.name')->all(),
        );
        foreach ($rules as $rule) {
            $this->assertSame(20, $rule->default_length_seconds);
            $this->assertSame('50.0000', $rule->surcharge_percent);
        }

        $bookability = app(AdvertisingMediumLiveBookability::class);
        $this->assertTrue($bookability->evaluate($promo)->isBookableForNewPositions);
    }

    public function test_mysql_preview_store_reload_dispo_snapshot(): void
    {
        $this->requireMysql('PO-SWF-PROMO-1 snapshot');

        $catalog = $this->createPromoAverageCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $payload = $this->promoPayload([
            $this->promoPosition($catalog['a'], $catalog['promo'], 10),
        ]);

        $preview = $this->actingAs($user)->postJson(route('calculations.preview'), $payload);
        $preview->assertOk();
        $this->assertSame('600.00', $preview->json('totals.media_gross'));

        $this->actingAs($user)->post(route('calculations.store'), $payload)->assertRedirect();
        $calculation = Calculation::query()->firstOrFail()->load('positions');
        $this->assertSame('swf_promo', $calculation->positions[0]->engine_profile_key);
        $this->assertSame(20, $calculation->positions[0]->length_seconds);
        $this->assertEqualsWithDelta(50.0, (float) $calculation->positions[0]->surcharge_percent, 0.00001);

        $result = app(DispoOrderWriter::class)->createFromCalculation(
            $calculation,
            [$calculation->positions[0]->id],
            $user,
        );
        $dispo = $result->order->positions()->firstOrFail();
        $this->assertSame('600.00', (string) $dispo->media_gross);

        $catalog['ruleA']->update(['surcharge_percent' => '55', 'default_length_seconds' => 25]);
        $this->assertEqualsWithDelta(50.0, (float) $dispo->fresh()->surcharge_percent, 0.00001);
        $this->assertSame(20, $dispo->fresh()->length_seconds);
    }

    private function requireMysql(string $label): void
    {
        MysqlTestDatabaseGuard::assertSafeBeforeDestructiveTraits();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped($label.' erfordert MySQL (GitHub-Job mysql).');
        }
    }
}
