<?php

namespace Tests\Feature\DynamicField;

use App\Enums\Role;
use App\Models\ConfigurationSnapshot;
use App\Models\User;
use App\Services\Calculation\CalculationWriter;
use App\Services\DispoOrder\DispoOrderWriter;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesSavedCalculation;
use Tests\Concerns\CreatesSpotClassicCatalog;
use Tests\Support\MysqlTestDatabaseGuard;
use Tests\TestCase;

/**
 * MySQL-Nachweis: Positionslöschung mit historischer Dispo-Origin rollt nicht mehr zurück.
 */
class ConfigurationSnapshotHistoricalOriginRetentionMysqlTest extends TestCase
{
    use CreatesSavedCalculation;
    use CreatesSpotClassicCatalog;
    use DatabaseMigrations;

    public function test_mysql_removing_calc_position_with_dispo_origin_succeeds_atomically(): void
    {
        $this->requireMysql('DF33A2B-ORIGIN-RETENTION');

        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $calculation = $this->createSavedCalculation($catalog, [
            ['inventory_id' => $catalog['hamburg']->id],
            ['inventory_id' => $catalog['rock']->id],
        ], $user);

        $positions = $calculation->positions()->orderBy('id')->get();
        $keptId = (int) $positions[0]->id;
        $removedId = (int) $positions[1]->id;
        $historicalCalcEffectiveId = (int) $positions[1]->effective_configuration_snapshot_id;

        $order = app(DispoOrderWriter::class)
            ->createFromCalculation($calculation, [$removedId], $user)
            ->order;
        $dispoEffectiveId = (int) $order->positions()->firstOrFail()->effective_configuration_snapshot_id;

        $writer = app(CalculationWriter::class);
        $payload = $writer->payloadFromCalculation(
            $calculation->fresh([
                'positions.planRows',
                'positions.timeRanges',
                'positions.discounts',
                'orderDiscounts',
                'configurationSnapshot',
                'fieldValues',
            ]),
        );
        $payload['lock_version'] = $calculation->lock_version;
        $payload['positions'] = array_values(array_filter(
            $payload['positions'],
            fn (array $row): bool => (int) ($row['id'] ?? 0) === $keptId,
        ));

        $updated = $writer->update($calculation->fresh(), $payload, $user);

        $this->assertSame(1, $updated->positions()->count());
        $this->assertDatabaseMissing('calculation_positions', ['id' => $removedId]);
        $this->assertDatabaseHas('configuration_snapshots', ['id' => $historicalCalcEffectiveId]);
        $this->assertDatabaseHas('configuration_snapshots', [
            'id' => $dispoEffectiveId,
            'source_configuration_snapshot_id' => $historicalCalcEffectiveId,
        ]);
        ConfigurationSnapshot::query()->findOrFail($dispoEffectiveId)->assertReadable();
    }

    private function requireMysql(string $label): void
    {
        MysqlTestDatabaseGuard::assertSafeBeforeDestructiveTraits();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped($label.' erfordert MySQL (GitHub-Job mysql).');
        }
    }
}
