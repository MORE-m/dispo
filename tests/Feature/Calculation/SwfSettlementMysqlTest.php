<?php

namespace Tests\Feature\Calculation;

use App\Enums\CalculationKind;
use App\Enums\Role;
use App\Models\Calculation;
use App\Models\User;
use App\Services\DispoOrder\DispoOrderWriter;
use App\Support\PriceList\PriceListCalendar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesPromoAverageCatalog;
use Tests\Support\MysqlTestDatabaseGuard;
use Tests\TestCase;

/**
 * PO-SWF-SETTLEMENT-1: MySQL – Settlement-Spalten, Decimal, Calendar-Planner, Dispo-Snapshot.
 */
class SwfSettlementMysqlTest extends TestCase
{
    use CreatesPromoAverageCatalog;
    use RefreshDatabase;

    public function test_mysql_promo_calendar_fixed_settlement_persist_and_dispo(): void
    {
        $this->requireMysql('PO-SWF-SETTLEMENT-1');

        $catalog = $this->createPromoAverageCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $year = PriceListCalendar::currentYear();
        $date = sprintf('%04d-09-14', $year);
        $payload = $this->promoPayload([[
            'inventory_id' => $catalog['a']->id,
            'advertising_medium_id' => $catalog['promo']->id,
            'spot_method' => 'calendar',
            'calculation_method_key' => 'calendar',
            'length_seconds' => 20,
            'position_discount_percent' => '0',
            'ae_percent' => '0',
            'pricing_settlement_mode' => 'fixed_price',
            'fixed_price_nn' => '500.00',
            'planner_entries' => [
                ['date' => $date, 'hour' => 8, 'spot_count' => 10],
                ['date' => $date, 'hour' => 14, 'spot_count' => 5],
            ],
        ]]);

        $this->actingAs($user)->post(route('calculations.store'), $payload)->assertRedirect();
        $calculation = Calculation::query()->firstOrFail()->load('positions.plannerEntries');
        $position = $calculation->positions[0];

        $this->assertSame(CalculationKind::SwfPromo, $position->kind);
        $this->assertSame('calendar', $position->calculation_method_key);
        $this->assertSame('fixed_price', $position->pricing_settlement_mode->value);
        $this->assertSame('500.00', (string) $position->fixed_price_nn);
        $this->assertSame('900.00', (string) $calculation->media_gross);
        $this->assertSame('500.00', (string) $calculation->nn_invest);
        $this->assertCount(2, $position->plannerEntries);

        $row = DB::table('calculation_positions')->where('id', $position->id)->first();
        $this->assertSame('fixed_price', $row->pricing_settlement_mode);
        $this->assertSame('500.00', (string) $row->fixed_price_nn);

        $dispo = app(DispoOrderWriter::class)
            ->createFromCalculation($calculation, [$position->id], $user)
            ->order
            ->positions()
            ->firstOrFail();

        $this->assertSame('fixed_price', $dispo->pricing_settlement_mode->value);
        $this->assertSame('500.00', (string) $dispo->fixed_price_nn);
        $this->assertSame('900.00', (string) $dispo->media_gross);
        $this->assertSame('500.00', (string) $dispo->nn_invest);
        $this->assertIsArray($dispo->planner_entries_snapshot);
        $this->assertCount(2, $dispo->planner_entries_snapshot);
    }

    private function requireMysql(string $label): void
    {
        MysqlTestDatabaseGuard::assertSafeBeforeDestructiveTraits();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped($label.' erfordert MySQL (GitHub-Job mysql).');
        }
    }
}
