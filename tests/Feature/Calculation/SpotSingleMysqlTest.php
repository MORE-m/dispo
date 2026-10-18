<?php

namespace Tests\Feature\Calculation;

use App\Enums\Role;
use App\Models\Calculation;
use App\Models\User;
use App\Services\DispoOrder\DispoOrderWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesSpotSingleCatalog;
use Tests\Support\MysqlTestDatabaseGuard;
use Tests\TestCase;

/**
 * PO-SPOT-SINGLE-1: MySQL-Persistenz für kind, surcharge, Bookability, Snapshot, MAT-003.
 *
 * RefreshDatabase (nicht DatabaseMigrations): Slice-Migration down() ist fail-closed
 * bei bestehenden Single-Positionen; Rollback je Test wäre damit blockiert.
 */
class SpotSingleMysqlTest extends TestCase
{
    use CreatesSpotSingleCatalog;
    use RefreshDatabase;

    public function test_mysql_single_preview_store_reload_and_dispo_snapshot(): void
    {
        $this->requireMysql('PO-SPOT-SINGLE-1');

        $catalog = $this->createSpotSingleCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $payload = $this->withLiveSchemaFingerprint([
            'planning_mode' => 'manual',
            'order_discount_percent' => '0',
            'positions' => [[
                'inventory_id' => $catalog['hamburg']->id,
                'advertising_medium_id' => $catalog['single']->id,
                'spot_method' => 'average',
                'length_seconds' => 30,
                'position_discount_percent' => '0',
                'ae_percent' => '0',
                'time_ranges' => [[
                    'start_hour' => 8,
                    'end_hour_exclusive' => 9,
                    'day_group' => 'mo_fr',
                    'spot_count' => 10,
                    'sort' => 0,
                ]],
            ]],
        ]);

        $preview = $this->actingAs($user)->postJson(route('calculations.preview'), $payload);
        $preview->assertOk();
        $this->assertSame('450.00', $preview->json('totals.media_gross'));

        $this->actingAs($user)->post(route('calculations.store'), $payload)->assertRedirect();
        $calculation = Calculation::query()->firstOrFail()->load('positions');
        $this->assertSame('spot_classic', $calculation->positions[0]->engine_profile_key);
        $this->assertEqualsWithDelta(50.0, (float) $calculation->positions[0]->surcharge_percent, 0.00001);

        $result = app(DispoOrderWriter::class)->createFromCalculation(
            $calculation,
            [$calculation->positions[0]->id],
            $user,
        );
        $dispo = $result->order->positions()->firstOrFail();
        $this->assertEqualsWithDelta(50.0, (float) $dispo->surcharge_percent, 0.00001);
        $this->assertSame('450.00', (string) $dispo->media_gross);

        $catalog['ruleSingleHamburg']->update(['surcharge_percent' => '0']);
        $this->assertEqualsWithDelta(50.0, (float) $dispo->fresh()->surcharge_percent, 0.00001);

        $blocked = $this->actingAs($user)->postJson(route('calculations.preview'), $payload);
        $blocked->assertStatus(422);
    }

    public function test_mysql_mat003_kombi_plus_not_bookable(): void
    {
        $this->requireMysql('PO-SPOT-SINGLE-1 MAT-003');

        $catalog = $this->createSpotSingleCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $payload = $this->withLiveSchemaFingerprint([
            'planning_mode' => 'manual',
            'order_discount_percent' => '0',
            'positions' => [[
                'inventory_id' => $catalog['moreKombiPlus']->id,
                'advertising_medium_id' => $catalog['single']->id,
                'spot_method' => 'average',
                'length_seconds' => 30,
                'position_discount_percent' => '0',
                'ae_percent' => '0',
                'time_ranges' => [[
                    'start_hour' => 8,
                    'end_hour_exclusive' => 9,
                    'day_group' => 'mo_fr',
                    'spot_count' => 10,
                    'sort' => 0,
                ]],
            ]],
        ]);

        $this->actingAs($user)->postJson(route('calculations.preview'), $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['positions']);
    }

    public function test_mysql_zero_surcharge_not_bookable_for_single(): void
    {
        $this->requireMysql('PO-SPOT-SINGLE-1 zero');

        $catalog = $this->createSpotSingleCatalog();
        $catalog['ruleSingleHamburg']->update(['surcharge_percent' => '0']);
        $user = User::factory()->role(Role::Sales)->create();
        $payload = $this->withLiveSchemaFingerprint([
            'planning_mode' => 'manual',
            'order_discount_percent' => '0',
            'positions' => [[
                'inventory_id' => $catalog['hamburg']->id,
                'advertising_medium_id' => $catalog['single']->id,
                'spot_method' => 'average',
                'length_seconds' => 30,
                'position_discount_percent' => '0',
                'ae_percent' => '0',
                'time_ranges' => [[
                    'start_hour' => 8,
                    'end_hour_exclusive' => 9,
                    'day_group' => 'mo_fr',
                    'spot_count' => 10,
                    'sort' => 0,
                ]],
            ]],
        ]);

        $this->actingAs($user)->postJson(route('calculations.preview'), $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['positions']);
    }

    private function requireMysql(string $label): void
    {
        MysqlTestDatabaseGuard::assertSafeBeforeDestructiveTraits();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped($label.' erfordert MySQL (GitHub-Job mysql).');
        }
    }
}
