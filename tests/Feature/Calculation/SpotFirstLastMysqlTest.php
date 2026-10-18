<?php

namespace Tests\Feature\Calculation;

use App\Enums\Role;
use App\Models\Calculation;
use App\Models\User;
use App\Services\DispoOrder\DispoOrderWriter;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesSpotFirstLastCatalog;
use Tests\Support\MysqlTestDatabaseGuard;
use Tests\TestCase;

/**
 * PO-SPOT-SURCHARGE-1: MySQL-Persistenz für kind, surcharge, Bookability, Snapshot.
 */
class SpotFirstLastMysqlTest extends TestCase
{
    use CreatesSpotFirstLastCatalog;
    use DatabaseMigrations;

    public function test_mysql_first_preview_store_reload_and_dispo_snapshot(): void
    {
        $this->requireMysql('PO-SPOT-SURCHARGE-1');

        $catalog = $this->createSpotFirstLastCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $payload = $this->withLiveSchemaFingerprint([
            'planning_mode' => 'manual',
            'order_discount_percent' => '0',
            'positions' => [[
                'inventory_id' => $catalog['hamburg']->id,
                'advertising_medium_id' => $catalog['first']->id,
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
        $this->assertSame('390.00', $preview->json('totals.media_gross'));

        $this->actingAs($user)->post(route('calculations.store'), $payload)->assertRedirect();
        $calculation = Calculation::query()->firstOrFail()->load('positions');
        $this->assertSame('spot_classic', $calculation->positions[0]->engine_profile_key);
        $this->assertEqualsWithDelta(30.0, (float) $calculation->positions[0]->surcharge_percent, 0.00001);

        $result = app(DispoOrderWriter::class)->createFromCalculation(
            $calculation,
            [$calculation->positions[0]->id],
            $user,
        );
        $dispo = $result->order->positions()->firstOrFail();
        $this->assertEqualsWithDelta(30.0, (float) $dispo->surcharge_percent, 0.00001);
        $this->assertSame('390.00', (string) $dispo->media_gross);

        $catalog['ruleFirstHamburg']->update(['surcharge_percent' => '0']);
        $this->assertEqualsWithDelta(30.0, (float) $dispo->fresh()->surcharge_percent, 0.00001);

        $blocked = $this->actingAs($user)->postJson(route('calculations.preview'), $payload);
        $blocked->assertStatus(422);
    }

    public function test_mysql_zero_surcharge_not_bookable_for_first(): void
    {
        $this->requireMysql('PO-SPOT-SURCHARGE-1 zero');

        $catalog = $this->createSpotFirstLastCatalog();
        $catalog['ruleFirstHamburg']->update(['surcharge_percent' => '0']);
        $user = User::factory()->role(Role::Sales)->create();
        $payload = $this->withLiveSchemaFingerprint([
            'planning_mode' => 'manual',
            'order_discount_percent' => '0',
            'positions' => [[
                'inventory_id' => $catalog['hamburg']->id,
                'advertising_medium_id' => $catalog['first']->id,
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
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped("{$label}: nur unter MySQL (aktuell ".DB::getDriverName().').');
        }

        MysqlTestDatabaseGuard::assertMysqlDatabaseIsDispoTest(DB::connection()->getDatabaseName());
    }
}
