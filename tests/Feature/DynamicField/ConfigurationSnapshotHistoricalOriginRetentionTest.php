<?php

namespace Tests\Feature\DynamicField;

use App\Enums\DayGroup;
use App\Enums\PriceListStatus;
use App\Enums\Role;
use App\Models\AdvertisingMedium;
use App\Models\CalculationPosition;
use App\Models\ConfigurationSnapshot;
use App\Models\DispoOrderPosition;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\User;
use App\Services\Calculation\CalculationWriter;
use App\Services\DispoOrder\DispoOrderWriter;
use App\Services\DynamicField\ConfigurationSnapshotFreezeService;
use App\Support\PriceList\PriceListCalendar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesSavedCalculation;
use Tests\Concerns\CreatesSpotClassicCatalog;
use Tests\TestCase;

/**
 * DF-3.3a2β Hotfix: historische Dispo-Origin behält Calc-Effektiv nach
 * Positions-Löschung / Effektiv-Ersetzung (kein Cleanup-Blocker).
 */
class ConfigurationSnapshotHistoricalOriginRetentionTest extends TestCase
{
    use CreatesSavedCalculation;
    use CreatesSpotClassicCatalog;
    use RefreshDatabase;

    public function test_removing_calc_position_keeps_historical_calc_effective_for_dispo_origin(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $calculation = $this->createSavedCalculation($catalog, [
            ['inventory_id' => $catalog['hamburg']->id],
            ['inventory_id' => $catalog['rock']->id],
        ], $user);

        $positions = $calculation->positions()->orderBy('id')->get();
        $this->assertCount(2, $positions);
        $kept = $positions[0];
        $removed = $positions[1];
        $historicalCalcEffectiveId = (int) $removed->effective_configuration_snapshot_id;

        $order = app(DispoOrderWriter::class)
            ->createFromCalculation($calculation, [$removed->id], $user)
            ->order;
        $dispoPosition = $order->positions()->firstOrFail();
        $dispoEffectiveId = (int) $dispoPosition->effective_configuration_snapshot_id;
        $dispoEffective = ConfigurationSnapshot::query()->findOrFail($dispoEffectiveId);
        $this->assertSame($historicalCalcEffectiveId, (int) $dispoEffective->source_configuration_snapshot_id);

        $frozenContext = [
            'medium_id' => (int) $dispoEffective->context_advertising_medium_id,
            'medium_name' => (string) $dispoEffective->context_advertising_medium_name,
            'category_id' => (int) $dispoEffective->context_advertising_category_id,
        ];
        $frozenDefCount = $dispoEffective->fieldDefinitions()->count();

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
        $this->assertCount(2, $payload['positions']);

        // Bewusst nur die zweite Position entfernen (echte Writer-Orphan-Route).
        $payload['positions'] = array_values(array_filter(
            $payload['positions'],
            fn (array $row): bool => (int) ($row['id'] ?? 0) === (int) $kept->id,
        ));
        $this->assertCount(1, $payload['positions']);

        $updated = $writer->update($calculation->fresh(), $payload, $user);

        $this->assertSame(1, $updated->positions()->count());
        $this->assertSame((int) $kept->id, (int) $updated->positions()->firstOrFail()->id);
        $this->assertNull(CalculationPosition::query()->find($removed->id));

        $this->assertTrue(
            ConfigurationSnapshot::query()->whereKey($historicalCalcEffectiveId)->exists(),
            'Historischer Calc-Effektiv muss für Dispo-Origin erhalten bleiben',
        );
        $this->assertFalse(
            CalculationPosition::query()
                ->where('effective_configuration_snapshot_id', $historicalCalcEffectiveId)
                ->exists(),
            'Kein direkter Calc-Owner mehr',
        );

        $dispoEffective = ConfigurationSnapshot::query()->findOrFail($dispoEffectiveId);
        $this->assertSame($historicalCalcEffectiveId, (int) $dispoEffective->source_configuration_snapshot_id);
        $this->assertSame($frozenContext['medium_id'], (int) $dispoEffective->context_advertising_medium_id);
        $this->assertSame($frozenContext['medium_name'], (string) $dispoEffective->context_advertising_medium_name);
        $this->assertSame($frozenContext['category_id'], (int) $dispoEffective->context_advertising_category_id);
        $this->assertSame($frozenDefCount, $dispoEffective->fieldDefinitions()->count());

        $dispoEffective->assertReadable();
        $this->assertTrue($order->fresh()->positions()->whereKey($dispoPosition->id)->exists());
    }

    public function test_noop_save_keeps_both_positions_when_dispo_origin_exists(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $calculation = $this->createSavedCalculation($catalog, [
            ['inventory_id' => $catalog['hamburg']->id],
            ['inventory_id' => $catalog['rock']->id],
        ], $user);
        $positionIds = $calculation->positions()->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $this->assertCount(2, $positionIds);

        app(DispoOrderWriter::class)
            ->createFromCalculation($calculation, [$positionIds[0]], $user);

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
        $this->assertCount(2, $payload['positions']);

        $updated = $writer->update($calculation->fresh(), $payload, $user);
        $freshIds = $updated->positions()->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->all();

        $this->assertSame($positionIds, $freshIds, 'No-op-Save darf keine Position aus dem Payload verlieren');
    }

    public function test_replace_calc_effective_retains_old_snapshot_for_dispo_origin(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $mediumB = AdvertisingMedium::factory()->create([
            'code' => 'df33a2b_origin_replace',
            'is_active' => true,
            'default_length_seconds' => 30,
            'category_id' => $catalog['medium']->category_id,
        ]);
        $catalog['hamburg']->mediumRules()->create([
            'advertising_medium_id' => $mediumB->id,
        ]);

        $calculation = $this->createSavedCalculation($catalog, [
            ['inventory_id' => $catalog['hamburg']->id],
        ], $user);
        $position = $calculation->positions()->firstOrFail();
        $oldEffectiveId = (int) $position->effective_configuration_snapshot_id;

        $order = app(DispoOrderWriter::class)
            ->createFromCalculation($calculation, [$position->id], $user)
            ->order;
        $dispoEffectiveId = (int) $order->positions()->firstOrFail()->effective_configuration_snapshot_id;
        $this->assertSame(
            $oldEffectiveId,
            (int) ConfigurationSnapshot::query()->findOrFail($dispoEffectiveId)->source_configuration_snapshot_id,
        );

        $base = $calculation->configurationSnapshot()->firstOrFail();
        $fingerprint = (string) app(ConfigurationSnapshotFreezeService::class)
            ->resolvePositionSchemaFromBase($base, (int) $mediumB->id)['schema_fingerprint'];

        $next = app(ConfigurationSnapshotFreezeService::class)->replacePositionEffective(
            $position->fresh(),
            $base,
            (int) $mediumB->id,
            $fingerprint,
        );

        $position->refresh();
        $this->assertSame((int) $next->id, (int) $position->effective_configuration_snapshot_id);
        $this->assertNotSame($oldEffectiveId, (int) $position->effective_configuration_snapshot_id);
        $this->assertTrue(ConfigurationSnapshot::query()->whereKey($oldEffectiveId)->exists());

        $dispoEffective = ConfigurationSnapshot::query()->findOrFail($dispoEffectiveId);
        $this->assertSame($oldEffectiveId, (int) $dispoEffective->source_configuration_snapshot_id);
        $dispoEffective->assertReadable();
        $this->assertSame(
            1,
            DispoOrderPosition::query()->where('effective_configuration_snapshot_id', $dispoEffectiveId)->count(),
        );
    }

    public function test_newer_active_price_list_noop_save_keeps_pins_and_positions_with_dispo(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $calculation = $this->createSavedCalculation($catalog, [
            ['inventory_id' => $catalog['hamburg']->id],
            ['inventory_id' => $catalog['rock']->id],
        ], $user);

        $positions = $calculation->positions()->orderBy('id')->get();
        $pinnedByPosition = $positions->mapWithKeys(
            fn ($position) => [(int) $position->id => [
                'price_list_id' => (int) $position->price_list_id,
                'price_list_version' => (string) $position->price_list_version,
            ]],
        )->all();

        app(DispoOrderWriter::class)
            ->createFromCalculation($calculation, [(int) $positions[0]->id], $user);

        $hamburgPinnedId = (int) $positions[0]->price_list_id;
        /** @var PriceList $previous */
        $previous = PriceList::query()->whereKey($hamburgPinnedId)->firstOrFail();
        $previous->update(['status' => PriceListStatus::Archived]);
        $newer = PriceList::factory()->create([
            'inventory_id' => $catalog['hamburg']->id,
            'year' => PriceListCalendar::currentYear(),
            'status' => PriceListStatus::Active,
            'version' => 'origin-retention-newer',
            'revision_number' => ((int) $previous->revision_number) + 1,
            'valid_from' => $previous->valid_from,
        ]);
        foreach (range(0, 23) as $hour) {
            foreach ([DayGroup::MoFr, DayGroup::Sa, DayGroup::So] as $group) {
                PriceListItem::factory()->create([
                    'price_list_id' => $newer->id,
                    'hour' => $hour,
                    'day_group' => $group,
                    'second_price' => '9.0000',
                ]);
            }
        }

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
        $this->assertCount(2, $payload['positions']);

        $updated = $writer->update($calculation->fresh(), $payload, $user);
        $fresh = $updated->positions()->orderBy('id')->get();
        $this->assertCount(2, $fresh);

        foreach ($fresh as $position) {
            $expected = $pinnedByPosition[(int) $position->id];
            $this->assertSame($expected['price_list_id'], (int) $position->price_list_id);
            $this->assertSame($expected['price_list_version'], (string) $position->price_list_version);
        }
        $this->assertNotSame($newer->id, (int) $fresh[0]->price_list_id);
    }

    public function test_legitimate_dispo_origin_retains_without_deleting_snapshot_graph(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $calculation = $this->createSavedCalculation($catalog, [
            ['inventory_id' => $catalog['hamburg']->id],
        ], $user);
        $position = $calculation->positions()->firstOrFail();
        $calcEffectiveId = (int) $position->effective_configuration_snapshot_id;

        $order = app(DispoOrderWriter::class)
            ->createFromCalculation($calculation, [$position->id], $user)
            ->order;
        $dispoEffectiveId = (int) $order->positions()->firstOrFail()->effective_configuration_snapshot_id;

        $position->forceFill(['effective_configuration_snapshot_id' => null])->save();
        $sourceIdsBefore = DB::table('configuration_snapshot_sources')
            ->where('configuration_snapshot_id', $calcEffectiveId)
            ->count();
        $defIdsBefore = DB::table('snapshot_field_definitions')
            ->where('configuration_snapshot_id', $calcEffectiveId)
            ->count();

        $deleted = app(ConfigurationSnapshotFreezeService::class)
            ->deleteEffectiveIfUnreferenced(
                ConfigurationSnapshot::query()->findOrFail($calcEffectiveId),
            );

        $this->assertFalse($deleted);
        $this->assertTrue(ConfigurationSnapshot::query()->whereKey($calcEffectiveId)->exists());
        $this->assertSame(
            $sourceIdsBefore,
            DB::table('configuration_snapshot_sources')
                ->where('configuration_snapshot_id', $calcEffectiveId)
                ->count(),
        );
        $this->assertSame(
            $defIdsBefore,
            DB::table('snapshot_field_definitions')
                ->where('configuration_snapshot_id', $calcEffectiveId)
                ->count(),
        );
        $this->assertTrue(ConfigurationSnapshot::query()->whereKey($dispoEffectiveId)->exists());
        ConfigurationSnapshot::query()->findOrFail($dispoEffectiveId)->assertReadable();
    }

    public function test_truly_unreferenced_effective_is_still_deleted(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $calculation = $this->createSavedCalculation($catalog, [
            ['inventory_id' => $catalog['hamburg']->id],
        ]);
        $position = $calculation->positions()->firstOrFail();
        $effectiveId = (int) $position->effective_configuration_snapshot_id;

        $position->fieldValues()->delete();
        $position->forceFill(['effective_configuration_snapshot_id' => null])->save();

        $deleted = app(ConfigurationSnapshotFreezeService::class)
            ->deleteEffectiveIfUnreferenced(
                ConfigurationSnapshot::query()->findOrFail($effectiveId),
            );

        $this->assertTrue($deleted);
        $this->assertNull(ConfigurationSnapshot::query()->find($effectiveId));
        $this->assertSame(
            0,
            DB::table('snapshot_field_definitions')
                ->where('configuration_snapshot_id', $effectiveId)
                ->count(),
        );
        $this->assertSame(
            0,
            DB::table('configuration_snapshot_sources')
                ->where('configuration_snapshot_id', $effectiveId)
                ->count(),
        );
    }
}
