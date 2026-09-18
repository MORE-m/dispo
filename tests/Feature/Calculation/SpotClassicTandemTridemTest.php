<?php

namespace Tests\Feature\Calculation;

use App\Enums\ComponentCalculationStrategy;
use App\Enums\PriceListStatus;
use App\Enums\Role;
use App\Models\AdvertisingMedium;
use App\Models\InventoryMediumRule;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\User;
use App\Services\Calculation\CalculationWriter;
use App\Services\DispoOrder\DispoOrderSnapshotMapper;
use App\Services\DispoOrder\DispoOrderWriter;
use App\Services\DynamicField\ConfigurationSnapshotFreezeService;
use App\Support\PriceList\PriceListCalendar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesSpotClassicCatalog;
use Tests\TestCase;

/**
 * BL-P4-02e: Tandem/Tridem Feature-Abdeckung.
 */
class SpotClassicTandemTridemTest extends TestCase
{
    use CreatesSpotClassicCatalog;
    use RefreshDatabase;

    /** @var array{tandem: AdvertisingMedium, tridem: AdvertisingMedium} */
    private array $profileMedia = [];

    /** @var array{organization: mixed, medium: AdvertisingMedium, hamburg: mixed, rock: mixed} */
    private array $catalog = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->catalog = $this->createSpotClassicCatalog();
        $this->profileMedia['tandem'] = $this->attachProfileMedium(
            $this->catalog,
            AdvertisingMedium::factory()->tandem()->create(['code' => 'tandem_'.uniqid()]),
        );
        $this->profileMedia['tridem'] = $this->attachProfileMedium(
            $this->catalog,
            AdvertisingMedium::factory()->tridem()->create(['code' => 'tridem_'.uniqid()]),
        );
        $this->setSecondPrice($this->catalog, '2.0000');
    }

    public function test_tandem_average_preview_matches_store_and_reload(): void
    {
        $user = User::factory()->role(Role::Sales)->create();
        $writer = app(CalculationWriter::class);
        $payload = $this->tandemAveragePayload();

        $preview = $writer->preview($payload, $user)->toArray();
        $calc = $writer->create($payload, $user);
        $position = $calc->positions->first();

        $this->assertSame('600.00', $preview['media_gross']);
        $this->assertSame('600.00', (string) $calc->media_gross);
        $this->assertSame('tandem', $position->component_profile?->value);
        $this->assertSame('shared_total_length', $position->component_calculation_strategy);
        $this->assertCount(2, $position->components);

        $roundtrip = $writer->payloadFromCalculation($calc);
        $reloadPreview = $writer->preview($roundtrip, $user)->toArray();
        $this->assertSame('600.00', $reloadPreview['media_gross']);
    }

    public function test_tridem_calendar_preview_matches_store(): void
    {
        $user = User::factory()->role(Role::Sales)->create();
        $writer = app(CalculationWriter::class);
        $year = PriceListCalendar::currentYear();
        $payload = $this->tridemCalendarPayload([
            ['date' => "{$year}-09-14", 'hour' => 8, 'spot_count' => 10],
        ]);

        $preview = $writer->preview($payload, $user)->toArray();
        $calc = $writer->create($payload, $user);

        $this->assertSame('760.00', $preview['media_gross']);
        $this->assertSame('760.00', (string) $calc->media_gross);
        $this->assertSame('tridem', $calc->positions->first()->component_profile?->value);
        $this->assertCount(3, $calc->positions->first()->components);
    }

    public function test_individual_strategy_rejected_for_tandem_and_tridem(): void
    {
        $user = User::factory()->role(Role::Sales)->create();
        $writer = app(CalculationWriter::class);

        foreach (['tandem', 'tridem'] as $profile) {
            $payload = $profile === 'tandem'
                ? $this->tandemAveragePayload()
                : $this->tridemAveragePayload();
            $payload['positions'][0]['component_calculation_strategy'] = 'individual';

            try {
                $writer->create($payload, $user);
                $this->fail("Expected ValidationException for {$profile}");
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('positions.0.component_calculation_strategy', $exception->errors());
            }
        }
    }

    public function test_allonge_rejected_on_forced_profiles(): void
    {
        $user = User::factory()->role(Role::Sales)->create();
        $payload = $this->tandemAveragePayload();
        $payload['positions'][0]['components'] = [
            ['role' => 'main_spot', 'label' => 'Hauptspot', 'length_seconds' => 20, 'sort' => 1],
            ['role' => 'allonge', 'label' => 'Allonge', 'length_seconds' => 10, 'sort' => 2],
        ];

        try {
            app(CalculationWriter::class)->preview($payload, $user);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('positions.0.components.1.role', $exception->errors());
        }
    }

    public function test_medium_switch_tandem_to_classic_clears_invalid_components_on_save(): void
    {
        $user = User::factory()->role(Role::Sales)->create();
        $writer = app(CalculationWriter::class);
        $calc = $writer->create($this->tandemAveragePayload(), $user);
        $calc->load('configurationSnapshot');
        $base = $calc->configurationSnapshot;

        $payload = $writer->payloadFromCalculation($calc);
        $payload['lock_version'] = $calc->lock_version;
        $classicMediumId = (int) $this->catalog['medium']->id;
        $payload['positions'][0]['advertising_medium_id'] = $classicMediumId;
        $payload['positions'][0]['schema_fingerprint'] = app(ConfigurationSnapshotFreezeService::class)
            ->resolvePositionSchemaFromBase($base, $classicMediumId)['schema_fingerprint'];
        $payload['positions'][0]['components'] = [];
        $payload['positions'][0]['component_calculation_strategy'] = null;
        $payload['positions'][0]['length_seconds'] = 30;

        $updated = $writer->update($calc, $payload, $user);
        $position = $updated->positions->first();
        $this->assertNull($position->component_profile);
        $this->assertCount(0, $position->components);
        $this->assertSame(30, $position->length_seconds);
    }

    public function test_medium_switch_tandem_to_classic_with_allonge(): void
    {
        $user = User::factory()->role(Role::Sales)->create();
        $this->setRuleStrategy($this->catalog, ComponentCalculationStrategy::SharedTotalLength);
        $writer = app(CalculationWriter::class);
        $calc = $writer->create($this->tandemAveragePayload(), $user);
        $calc->load('configurationSnapshot');
        $base = $calc->configurationSnapshot;

        $payload = $writer->payloadFromCalculation($calc);
        $payload['lock_version'] = $calc->lock_version;
        $classicMediumId = (int) $this->catalog['medium']->id;
        $payload['positions'][0]['advertising_medium_id'] = $classicMediumId;
        $payload['positions'][0]['schema_fingerprint'] = app(ConfigurationSnapshotFreezeService::class)
            ->resolvePositionSchemaFromBase($base, $classicMediumId)['schema_fingerprint'];
        $payload['positions'][0]['component_calculation_strategy'] = 'shared_total_length';
        $payload['positions'][0]['components'] = [
            ['role' => 'main_spot', 'label' => 'Hauptspot', 'length_seconds' => 20, 'sort' => 0],
            ['role' => 'allonge', 'label' => 'Allonge', 'length_seconds' => 10, 'sort' => 1],
        ];
        $payload['positions'][0]['length_seconds'] = 30;

        $updated = $writer->update($calc, $payload, $user);
        $this->assertNull($updated->positions->first()->component_profile);
        $this->assertCount(2, $updated->positions->first()->components);
        $this->assertSame('600.00', (string) $updated->positions->first()->media_gross);
    }

    public function test_fixed_price_with_ae_on_tandem(): void
    {
        $user = User::factory()->role(Role::Sales)->create();
        $payload = $this->tandemAveragePayload();
        $payload['ae_enabled'] = true;
        $payload['positions'][0]['pricing_settlement_mode'] = 'fixed_price';
        $payload['positions'][0]['fixed_price_nn'] = '10000.00';

        $writer = app(CalculationWriter::class);
        $preview = $writer->preview($payload, $user)->toArray();
        $calc = $writer->create($payload, $user);

        $this->assertSame('600.00', $preview['media_gross']);
        $this->assertSame('10000.00', $preview['nn_invest']);
        $this->assertSame('1764.71', $preview['ae_total']);
        $this->assertSame('10000.00', (string) $calc->positions->first()->fixed_price_nn);
        $this->assertSame('1764.71', (string) $calc->positions->first()->ae_amount);
    }

    public function test_method_change_average_to_calendar_retains_pin_for_tandem(): void
    {
        $user = User::factory()->role(Role::Sales)->create();
        $writer = app(CalculationWriter::class);
        $calc = $writer->create($this->tandemAveragePayload(), $user);
        $pin = $calc->positions->first()->price_list_id;
        $year = PriceListCalendar::currentYear();

        $payload = $writer->payloadFromCalculation($calc);
        $payload['lock_version'] = $calc->lock_version;
        $payload['positions'][0]['spot_method'] = 'calendar';
        $payload['positions'][0]['calculation_method_key'] = 'calendar';
        $payload['positions'][0]['time_ranges'] = [];
        $payload['positions'][0]['plan_rows'] = [];
        $payload['positions'][0]['planner_entries'] = [
            ['date' => "{$year}-09-14", 'hour' => 8, 'spot_count' => 10],
        ];

        $updated = $writer->update($calc, $payload, $user);
        $position = $updated->positions->first();
        $this->assertSame($pin, $position->price_list_id);
        $this->assertSame('calendar', $position->calculation_method_key);
        $this->assertSame('tandem', $position->component_profile?->value);
        $this->assertSame('600.00', (string) $position->media_gross);
    }

    public function test_stale_lock_version_rejected_on_tandem_update(): void
    {
        $user = User::factory()->role(Role::Sales)->create();
        $writer = app(CalculationWriter::class);
        $calc = $writer->create($this->tandemAveragePayload(), $user);

        $payload = $writer->payloadFromCalculation($calc->fresh([
            'positions.plannerEntries',
            'positions.planRows',
            'positions.timeRanges',
            'positions.discounts',
            'orderDiscounts',
            'configurationSnapshot',
            'fieldValues',
        ]));
        $payload['lock_version'] = 99;

        try {
            $writer->update($calc->fresh(), $payload, $user);
            $this->fail('Erwartete ValidationException bei veraltetem lock_version.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                ['Die Kalkulation wurde parallel geändert. Bitte neu laden.'],
                $exception->errors()['lock_version'] ?? null,
            );
        }
    }

    public function test_dispo_snapshot_and_show_include_profile_and_derived_airings(): void
    {
        $user = User::factory()->role(Role::Sales)->create();
        $writer = app(CalculationWriter::class);
        $calc = $writer->create($this->tandemAveragePayload(), $user);
        $position = $calc->positions->first();

        $snapshot = app(DispoOrderSnapshotMapper::class)->positionFromCalculationPosition($position, 0);
        $this->assertSame('tandem', $snapshot['component_profile']);
        $this->assertSame(20, $snapshot['derived_component_airings']);
        $this->assertCount(2, $snapshot['components_snapshot']);

        $order = app(DispoOrderWriter::class)
            ->createFromCalculation($calc, [$position->id], $user)
            ->order;

        $this->actingAs($user)
            ->get(route('dispo-orders.show', $order))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('dispo-orders/show')
                ->where('order.positions.0.component_profile', 'tandem')
                ->where('order.positions.0.derived_component_airings', 20)
                ->where('order.positions.0.components.0.role', 'main_spot')
                ->where('order.positions.0.components.1.role', 'reminder'));
    }

    public function test_legacy_medium_still_allows_individual_allonge(): void
    {
        $user = User::factory()->role(Role::Sales)->create();
        $this->setRuleStrategy($this->catalog, ComponentCalculationStrategy::Individual);
        $writer = app(CalculationWriter::class);
        $payload = $this->legacyAllongeAveragePayload();

        $preview = $writer->preview($payload, $user)->toArray();
        $calc = $writer->create($payload, $user);

        $this->assertSame('640.00', $preview['media_gross']);
        $this->assertNull($calc->positions->first()->component_profile);
        $this->assertSame('individual', $calc->positions->first()->component_calculation_strategy);
    }

    public function test_mixed_multi_position_tandem_and_legacy_allonge(): void
    {
        $user = User::factory()->role(Role::Sales)->create();
        $this->setRuleStrategy($this->catalog, ComponentCalculationStrategy::Individual);
        $writer = app(CalculationWriter::class);

        $legacy = $this->legacyAllongeAveragePayload()['positions'][0];
        $legacy['client_key'] = 'legacy-allonge';

        $payload = $this->tandemAveragePayload();
        $payload['positions'][0]['client_key'] = 'tandem-pos';
        $payload['positions'][] = $legacy;

        $preview = $writer->preview($payload, $user)->toArray();
        $calc = $writer->create($payload, $user);

        $this->assertSame('1240.00', $preview['media_gross']);
        $this->assertCount(2, $calc->positions);
        $tandem = $calc->positions->firstWhere('component_profile', 'tandem');
        $legacyPos = $calc->positions->first(fn ($p) => $p->component_profile === null && $p->components->isNotEmpty());
        $this->assertNotNull($tandem);
        $this->assertNotNull($legacyPos);
        $this->assertSame('600.00', (string) $tandem->media_gross);
        $this->assertSame('individual', $legacyPos->component_calculation_strategy);
    }

    /**
     * @param  array{hamburg: mixed}  $catalog
     */
    private function attachProfileMedium(array $catalog, AdvertisingMedium $medium): AdvertisingMedium
    {
        InventoryMediumRule::factory()->create([
            'inventory_id' => $catalog['hamburg']->id,
            'advertising_medium_id' => $medium->id,
            'component_calculation_strategy' => ComponentCalculationStrategy::SharedTotalLength,
        ]);

        return $medium;
    }

    /**
     * @param  array{hamburg: mixed, medium: mixed}  $catalog
     */
    private function setRuleStrategy(array $catalog, ComponentCalculationStrategy $strategy): void
    {
        InventoryMediumRule::query()
            ->where('inventory_id', $catalog['hamburg']->id)
            ->where('advertising_medium_id', $catalog['medium']->id)
            ->update(['component_calculation_strategy' => $strategy->value]);
    }

    /**
     * @param  array{hamburg: mixed}  $catalog
     */
    private function setSecondPrice(array $catalog, string $price): void
    {
        $list = PriceList::query()
            ->where('inventory_id', $catalog['hamburg']->id)
            ->where('status', PriceListStatus::Active)
            ->firstOrFail();
        PriceListItem::query()->where('price_list_id', $list->id)->update(['second_price' => $price]);
    }

    /**
     * @return array<string, mixed>
     */
    private function tandemAveragePayload(): array
    {
        return $this->profileAveragePayload($this->profileMedia['tandem'], $this->tandemComponents(), 30);
    }

    /**
     * @return array<string, mixed>
     */
    private function tridemAveragePayload(): array
    {
        return $this->profileAveragePayload($this->profileMedia['tridem'], $this->tridemComponents(), 40);
    }

    /**
     * @param  list<array{date: string, hour: int, spot_count: int}>  $entries
     * @return array<string, mixed>
     */
    private function tridemCalendarPayload(array $entries): array
    {
        $payload = $this->profileAveragePayload($this->profileMedia['tridem'], $this->tridemComponents(), 40);
        $payload['positions'][0]['spot_method'] = 'calendar';
        $payload['positions'][0]['calculation_method_key'] = 'calendar';
        $payload['positions'][0]['time_ranges'] = [];
        $payload['positions'][0]['plan_rows'] = [];
        $payload['positions'][0]['planner_entries'] = $entries;
        $payload['positions'][0]['total_spot_count'] = array_sum(array_column($entries, 'spot_count'));

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function legacyAllongeAveragePayload(): array
    {
        return $this->profileAveragePayload(
            $this->catalog['medium'],
            [
                ['role' => 'main_spot', 'label' => 'Hauptspot', 'length_seconds' => 20, 'sort' => 0],
                ['role' => 'allonge', 'label' => 'Allonge', 'length_seconds' => 10, 'sort' => 1],
            ],
            30,
            'individual',
        );
    }

    /**
     * @param  list<array{role: string, label: string, length_seconds: int, sort: int}>  $components
     * @return array<string, mixed>
     */
    private function profileAveragePayload(
        AdvertisingMedium $medium,
        array $components,
        int $lengthSeconds,
        ?string $strategy = 'shared_total_length',
    ): array {
        $position = [
            'inventory_id' => $this->catalog['hamburg']->id,
            'advertising_medium_id' => $medium->id,
            'schema_fingerprint' => app(ConfigurationSnapshotFreezeService::class)
                ->resolveLivePositionSchema((int) $medium->id)['schema_fingerprint'],
            'spot_method' => 'average',
            'length_seconds' => $lengthSeconds,
            'total_spot_count' => 10,
            'position_discount_percent' => '0',
            'ae_percent' => '0',
            'plan_rows' => [['hour' => 8, 'day_group' => 'mo_fr']],
            'time_ranges' => [[
                'start_hour' => 8,
                'end_hour_exclusive' => 9,
                'day_group' => 'mo_fr',
                'spot_count' => 10,
            ]],
            'components' => $components,
        ];

        if ($strategy !== null) {
            $position['component_calculation_strategy'] = $strategy;
        }

        return [
            'planning_mode' => 'manual',
            'customer_name' => 'Tandem Tridem GmbH',
            'schema_fingerprint' => app(ConfigurationSnapshotFreezeService::class)
                ->resolveLiveSchemaForCalculationV3()['schema_fingerprint'],
            'positions' => [$position],
        ];
    }

    /**
     * @return list<array{role: string, label: string, length_seconds: int, sort: int}>
     */
    private function tandemComponents(): array
    {
        return [
            ['role' => 'main_spot', 'label' => 'Hauptspot', 'length_seconds' => 20, 'sort' => 1],
            ['role' => 'reminder', 'label' => 'Reminder', 'length_seconds' => 10, 'sort' => 2],
        ];
    }

    /**
     * @return list<array{role: string, label: string, length_seconds: int, sort: int}>
     */
    private function tridemComponents(): array
    {
        return [
            ['role' => 'main_spot', 'label' => 'Hauptspot', 'length_seconds' => 20, 'sort' => 1],
            ['role' => 'reminder', 'label' => 'Reminder', 'length_seconds' => 10, 'sort' => 2],
            ['role' => 'reminder', 'label' => 'Reminder', 'length_seconds' => 10, 'sort' => 3],
        ];
    }
}
