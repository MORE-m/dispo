<?php

namespace Tests\Feature\StandardOffer;

use App\Enums\ComponentCalculationStrategy;
use App\Enums\PriceListStatus;
use App\Enums\Role;
use App\Enums\StandardOfferVersionStatus;
use App\Models\AdvertisingMedium;
use App\Models\Calculation;
use App\Models\CalculationPosition;
use App\Models\Inventory;
use App\Models\InventoryMediumRule;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\StandardOffer;
use App\Models\User;
use App\Services\Calculation\CalculationWriter;
use App\Services\StandardOffer\FrozenCalculationPersistenceContract;
use App\Services\StandardOffer\StandardOfferMaterializer;
use App\Services\StandardOffer\StandardOfferWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreatesSpotClassicCatalog;
use Tests\TestCase;

/**
 * BL-P4-03f / PO-BLP403F-1 / STD-001–STD-009 / AUTH-006/007 / VER-004 / SPT-012.
 *
 * Tandem/Tridem in Spot-Classic-Average-Standardangeboten (Calc-Logik 02e).
 */
class StandardOfferBlP403fTest extends TestCase
{
    use CreatesSpotClassicCatalog;
    use RefreshDatabase;

    public function test_four_combinations_tandem_tridem_normal_fixed_price(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $tandem = $this->attachProfileMedium($catalog, AdvertisingMedium::factory()->tandem()->create());
        $tridem = $this->attachProfileMedium($catalog, AdvertisingMedium::factory()->tridem()->create());
        $pm = User::factory()->role(Role::ProductManagement)->create();
        $sales = User::factory()->role(Role::Sales)->create();
        $this->setSecondPrice($catalog, '2.0000');

        $cases = [
            ['medium' => $tandem, 'profile' => 'tandem', 'components' => $this->tandemComponents(), 'length' => 30, 'mode' => 'normal', 'nn' => null],
            ['medium' => $tandem, 'profile' => 'tandem', 'components' => $this->tandemComponents(), 'length' => 30, 'mode' => 'fixed_price', 'nn' => '888.50'],
            ['medium' => $tridem, 'profile' => 'tridem', 'components' => $this->tridemComponents(), 'length' => 40, 'mode' => 'normal', 'nn' => null],
            ['medium' => $tridem, 'profile' => 'tridem', 'components' => $this->tridemComponents(), 'length' => 40, 'mode' => 'fixed_price', 'nn' => '1200.00'],
        ];

        foreach ($cases as $index => $case) {
            $payload = $this->profilePayload(
                $catalog,
                $case['medium'],
                $case['profile'],
                $case['components'],
                $case['length'],
                $case['mode'],
                $case['nn'],
            );
            $offer = $this->writer()->create("03f case {$index}", $payload, $pm);
            $draft = $offer->draftVersion;
            $this->assertNotNull($draft);
            $this->assertSame($case['profile'], $draft->draft_payload['positions'][0]['component_profile'] ?? null);
            $this->assertSame('shared_total_length', $draft->draft_payload['positions'][0]['component_calculation_strategy'] ?? null);
            $this->assertSame($case['mode'], $draft->draft_payload['positions'][0]['pricing_settlement_mode'] ?? null);
            if ($case['nn'] !== null) {
                $this->assertSame($case['nn'], $draft->draft_payload['positions'][0]['fixed_price_nn'] ?? null);
            }

            $published = $this->writer()->publish($draft, (int) $draft->lock_version, $pm);
            $this->assertSame(StandardOfferVersionStatus::Published, $published->status);
            $this->assertSame(FrozenCalculationPersistenceContract::CURRENT_WRITE_VERSION, (int) ($published->frozen_materialization['materialization_version'] ?? 0));
            $frozen = $published->frozen_materialization['positions'][0];
            $this->assertSame($case['profile'], $frozen['component_profile'] ?? null);
            $this->assertSame('shared_total_length', $frozen['component_calculation_strategy'] ?? null);
            $this->assertCount(count($case['components']), $frozen['components'] ?? []);
            $this->assertSame($case['mode'], $frozen['pricing_settlement_mode'] ?? null);
            if ($case['nn'] !== null) {
                $this->assertSame($case['nn'], (string) ($frozen['fixed_price_nn'] ?? ''));
                $this->assertSame($case['nn'], (string) ($frozen['nn_invest'] ?? ''));
            }

            $adopted = $this->writer()->adopt($published, "Kunde {$index}", null, null, $sales);
            $position = $adopted->fresh(['positions.components'])->positions->first();
            $this->assertSame($case['profile'], $position->component_profile?->value);
            $this->assertSame('shared_total_length', $position->component_calculation_strategy);
            $this->assertCount(count($case['components']), $position->components);
            $this->assertSame($case['mode'], $position->pricing_settlement_mode?->value ?? $position->pricing_settlement_mode);
            if ($case['nn'] !== null) {
                $this->assertSame($case['nn'], (string) $position->fixed_price_nn);
            }
            $this->assertSame($published->id, $adopted->origin_standard_offer_version_id);
        }
    }

    public function test_mixed_template_tandem_and_classic_allonge(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $tandem = $this->attachProfileMedium($catalog, AdvertisingMedium::factory()->tandem()->create());
        $pm = User::factory()->role(Role::ProductManagement)->create();
        $sales = User::factory()->role(Role::Sales)->create();
        $this->setRuleStrategy($catalog, ComponentCalculationStrategy::SharedTotalLength);
        $this->setSecondPrice($catalog, '2.0000');

        $payload = $this->withLiveSchemaFingerprint([
            'planning_mode' => 'manual',
            'order_discount_percent' => '0',
            'ae_enabled' => false,
            'order_discounts' => [],
            'dynamic_field_values' => ['campaign_period' => null],
            'positions' => [
                $this->positionRow($catalog, $tandem, 'tandem', $this->tandemComponents(), 30, 'normal', null, 't1'),
                [
                    'client_key' => 'a1',
                    'inventory_id' => $catalog['hamburg']->id,
                    'advertising_medium_id' => $catalog['medium']->id,
                    'spot_method' => 'average',
                    'length_seconds' => 30,
                    'total_spot_count' => 10,
                    'position_discount_percent' => '0',
                    'ae_percent' => '0',
                    'pricing_settlement_mode' => 'normal',
                    'component_profile' => null,
                    'component_calculation_strategy' => ComponentCalculationStrategy::SharedTotalLength->value,
                    'components' => [
                        ['role' => 'main_spot', 'label' => 'Hauptspot', 'length_seconds' => 20, 'sort' => 0],
                        ['role' => 'allonge', 'label' => 'Allonge', 'length_seconds' => 10, 'sort' => 1],
                    ],
                    'plan_rows' => [['hour' => 8, 'day_group' => 'mo_fr']],
                    'time_ranges' => [[
                        'start_hour' => 8,
                        'end_hour_exclusive' => 9,
                        'day_group' => 'mo_fr',
                        'spot_count' => 10,
                    ]],
                    'position_discounts' => [],
                    'dynamic_field_values' => ['period_open' => true],
                ],
            ],
        ]);

        $offer = $this->writer()->create('03f Mix', $payload, $pm);
        $draft = $offer->draftVersion;
        $this->assertNotNull($draft);
        $published = $this->writer()->publish($draft, (int) $draft->lock_version, $pm);
        $adopted = $this->writer()->adopt($published, 'Mix Kunde', null, null, $sales)->fresh(['positions.components']);
        $sorted = $adopted->positions->sortBy('sort')->values();
        $this->assertCount(2, $sorted);
        $this->assertSame('tandem', $sorted[0]->component_profile?->value);
        $this->assertNull($sorted[1]->component_profile);
        $this->assertCount(2, $sorted[1]->components);
    }

    public function test_from_calculation_preserves_tandem_without_customer_leak(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $tandem = $this->attachProfileMedium($catalog, AdvertisingMedium::factory()->tandem()->create());
        $sales = User::factory()->role(Role::Sales)->create();
        $this->setSecondPrice($catalog, '2.0000');

        $calcPayload = $this->withLiveSchemaFingerprint([
            'planning_mode' => 'manual',
            'customer_name' => 'Geheimkunde Tandem',
            'agency_name' => 'Agentur Geheim',
            'campaign' => 'Kundenkampagne',
            'briefing' => 'Briefing geheim',
            'order_discount_percent' => '0',
            'ae_enabled' => false,
            'order_discounts' => [],
            'dynamic_field_values' => [],
            'positions' => [
                $this->positionRow($catalog, $tandem, 'tandem', $this->tandemComponents(), 30, 'fixed_price', '500.00', 'p1'),
            ],
        ]);
        $calculation = app(CalculationWriter::class)->create($calcPayload, $sales);

        $this->actingAs($sales)
            ->post(route('standard-offers.from-calculation', $calculation))
            ->assertRedirect(route('calculations.edit', $calculation));

        $offer = StandardOffer::query()->firstOrFail();
        $draft = $offer->draftVersion;
        $this->assertNotNull($draft);
        $this->assertArrayNotHasKey('customer_name', $draft->draft_payload);
        $this->assertArrayNotHasKey('agency_name', $draft->draft_payload);
        $this->assertNull($draft->draft_payload['campaign'] ?? null);
        $this->assertSame('tandem', $draft->draft_payload['positions'][0]['component_profile'] ?? null);
        $this->assertSame('fixed_price', $draft->draft_payload['positions'][0]['pricing_settlement_mode'] ?? null);
        $this->assertSame('500.00', $draft->draft_payload['positions'][0]['fixed_price_nn'] ?? null);
        $this->assertCount(2, $draft->draft_payload['positions'][0]['components'] ?? []);
    }

    public function test_legacy_v1_and_v2_remain_adoptable_while_invalid_v3_fails_closed(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $pm = User::factory()->role(Role::ProductManagement)->create();
        $sales = User::factory()->role(Role::Sales)->create();
        $this->setSecondPrice($catalog, '2.0000');

        $classic = $this->classicPayload($catalog);
        $offer = $this->writer()->create('Legacy Base', $classic, $pm);
        $draft = $offer->draftVersion;
        $this->assertNotNull($draft);
        $published = $this->writer()->publish($draft, (int) $draft->lock_version, $pm);

        $v1 = $published->fresh();
        $mat = $v1->frozen_materialization;
        unset($mat['materialization_version']);
        unset($mat['positions'][0]['pricing_settlement_mode']);
        $mat['positions'][0]['fixed_price_nn'] = null;
        $mat['positions'][0]['component_profile'] = null;
        $v1->frozen_materialization = $mat;
        $v1->save();
        $this->assertInstanceOf(Calculation::class, $this->writer()->adopt($v1->fresh(), 'Legacy v1', null, null, $sales));

        $v2Offer = $this->writer()->create('v2 Base', $this->classicPayload($catalog, 'fixed_price', '150.00'), $pm);
        $v2Draft = $v2Offer->draftVersion;
        $this->assertNotNull($v2Draft);
        $v2Published = $this->writer()->publish($v2Draft, (int) $v2Draft->lock_version, $pm);
        $v2Mat = $v2Published->frozen_materialization;
        $v2Mat['materialization_version'] = 2;
        $v2Mat['positions'][0]['component_profile'] = null;
        $v2Published->frozen_materialization = $v2Mat;
        $v2Published->save();
        $this->assertInstanceOf(Calculation::class, $this->writer()->adopt($v2Published->fresh(), 'Legacy v2', null, null, $sales));

        $bad = $this->writer()->create('Bad v3', $classic, $pm);
        $badDraft = $bad->draftVersion;
        $this->assertNotNull($badDraft);
        $badPublished = $this->writer()->publish($badDraft, (int) $badDraft->lock_version, $pm);
        $badMat = $badPublished->frozen_materialization;
        $badMat['materialization_version'] = 3;
        $badMat['positions'][0]['component_profile'] = 'tandem';
        $badMat['positions'][0]['components'] = [];
        $badPublished->frozen_materialization = $badMat;
        $badPublished->save();

        $before = Calculation::query()->count();
        $beforePositions = CalculationPosition::query()->count();
        try {
            $this->writer()->adopt($badPublished->fresh(), 'Fail v3', null, null, $sales);
            $this->fail('Unvollständiges v3 hätte scheitern müssen.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('frozen_materialization', $exception->errors());
        }
        $this->assertSame($before, Calculation::query()->count());
        $this->assertSame($beforePositions, CalculationPosition::query()->count());
    }

    public function test_frozen_v3_hydrate_rejects_invalid_profile_components_without_partial_create(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $tandem = $this->attachProfileMedium($catalog, AdvertisingMedium::factory()->tandem()->create());
        $pm = User::factory()->role(Role::ProductManagement)->create();
        $sales = User::factory()->role(Role::Sales)->create();
        $this->setSecondPrice($catalog, '2.0000');

        $offer = $this->writer()->create(
            '03f hydrate base',
            $this->profilePayload($catalog, $tandem, 'tandem', $this->tandemComponents(), 30, 'normal', null),
            $pm,
        );
        $draft = $offer->draftVersion;
        $this->assertNotNull($draft);
        $published = $this->writer()->publish($draft, (int) $draft->lock_version, $pm);
        $baseMat = $published->frozen_materialization;
        $this->assertSame(FrozenCalculationPersistenceContract::CURRENT_WRITE_VERSION, (int) ($baseMat['materialization_version'] ?? 0));
        $this->assertSame('tandem', $baseMat['positions'][0]['component_profile'] ?? null);

        $cases = [
            'duplicate_sort' => static function (array $mat): array {
                $mat['positions'][0]['components'][1]['sort'] = 1;

                return $mat;
            },
            'swapped_sort' => static function (array $mat): array {
                $mat['positions'][0]['components'][0]['sort'] = 2;
                $mat['positions'][0]['components'][1]['sort'] = 1;

                return $mat;
            },
            'sort_string' => static function (array $mat): array {
                $mat['positions'][0]['components'][0]['sort'] = '1';

                return $mat;
            },
            'length_zero' => static function (array $mat): array {
                $mat['positions'][0]['components'][1]['length_seconds'] = 0;
                $mat['positions'][0]['length_seconds'] = 20;

                return $mat;
            },
            'length_float' => static function (array $mat): array {
                $mat['positions'][0]['components'][1]['length_seconds'] = 10.5;

                return $mat;
            },
            'length_sum_mismatch' => static function (array $mat): array {
                $mat['positions'][0]['length_seconds'] = 40;

                return $mat;
            },
            'profile_empty_array' => static function (array $mat): array {
                $mat['positions'][0]['component_profile'] = [];

                return $mat;
            },
        ];

        foreach ($cases as $label => $mutate) {
            $version = $published->fresh();
            $version->frozen_materialization = $mutate($baseMat);
            $version->save();

            $before = Calculation::query()->count();
            $beforePositions = CalculationPosition::query()->count();
            try {
                $this->writer()->adopt($version->fresh(), "Fail {$label}", null, null, $sales);
                $this->fail("Fall {$label} hätte scheitern müssen.");
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('frozen_materialization', $exception->errors(), $label);
            }
            $this->assertSame($before, Calculation::query()->count(), $label);
            $this->assertSame($beforePositions, CalculationPosition::query()->count(), $label);
        }
    }

    public function test_rejects_individual_calendar_mix_and_wrong_roles(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $tandem = $this->attachProfileMedium($catalog, AdvertisingMedium::factory()->tandem()->create());
        $pm = User::factory()->role(Role::ProductManagement)->create();
        $sales = User::factory()->role(Role::Sales)->create();
        $this->setSecondPrice($catalog, '2.0000');

        $individual = $this->profilePayload($catalog, $tandem, 'tandem', $this->tandemComponents(), 30, 'normal', null);
        $individual['positions'][0]['component_calculation_strategy'] = 'individual';
        try {
            $this->writer()->create('Individual', $individual, $pm);
            $this->fail('individual hätte scheitern müssen.');
        } catch (ValidationException $exception) {
            $this->assertTrue(isset($exception->errors()['positions.0.component_calculation_strategy'])
                || collect($exception->errors())->keys()->contains(fn ($k) => str_contains((string) $k, 'component')));
        }

        $wrongRoles = $this->profilePayload($catalog, $tandem, 'tandem', [
            ['role' => 'main_spot', 'label' => 'Hauptspot', 'length_seconds' => 20, 'sort' => 1],
            ['role' => 'allonge', 'label' => 'Allonge', 'length_seconds' => 10, 'sort' => 2],
        ], 30, 'normal', null);
        try {
            $this->writer()->create('Wrong roles', $wrongRoles, $pm);
            $this->fail('Allonge auf Tandem hätte scheitern müssen.');
        } catch (ValidationException $exception) {
            $this->assertNotEmpty($exception->errors());
        }

        $calcPayload = $this->withLiveSchemaFingerprint([
            'planning_mode' => 'manual',
            'customer_name' => 'Mix Kunde',
            'order_discount_percent' => '0',
            'ae_enabled' => false,
            'order_discounts' => [],
            'dynamic_field_values' => [],
            'positions' => [
                $this->positionRow($catalog, $tandem, 'tandem', $this->tandemComponents(), 30, 'normal', null, 'avg'),
                [
                    'client_key' => 'cal',
                    'inventory_id' => $catalog['hamburg']->id,
                    'advertising_medium_id' => $catalog['medium']->id,
                    'spot_method' => 'calendar',
                    'calculation_method_key' => 'calendar',
                    'length_seconds' => 30,
                    'total_spot_count' => 1,
                    'position_discount_percent' => '0',
                    'ae_percent' => '0',
                    'pricing_settlement_mode' => 'normal',
                    'components' => [],
                    'plan_rows' => [],
                    'time_ranges' => [],
                    'planner_entries' => [[
                        'date' => now()->toDateString(),
                        'hour' => 8,
                        'spot_count' => 1,
                    ]],
                    'position_discounts' => [],
                    'dynamic_field_values' => ['period_open' => true],
                ],
            ],
        ]);
        $calculation = app(CalculationWriter::class)->create($calcPayload, $sales);
        $this->actingAs($sales)
            ->post(route('standard-offers.from-calculation', $calculation))
            ->assertSessionHasErrors();
        $this->assertSame(0, StandardOffer::query()->count());
    }

    public function test_rights_pm_cannot_adopt_sales_can_propose(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $tandem = $this->attachProfileMedium($catalog, AdvertisingMedium::factory()->tandem()->create());
        $pm = User::factory()->role(Role::ProductManagement)->create();
        $sales = User::factory()->role(Role::Sales)->create();
        $this->setSecondPrice($catalog, '2.0000');

        $calc = app(CalculationWriter::class)->create(
            $this->withLiveSchemaFingerprint([
                'planning_mode' => 'manual',
                'customer_name' => 'Rechte Kunde',
                'order_discount_percent' => '0',
                'ae_enabled' => false,
                'order_discounts' => [],
                'dynamic_field_values' => [],
                'positions' => [
                    $this->positionRow($catalog, $tandem, 'tandem', $this->tandemComponents(), 30, 'normal', null, 'r1'),
                ],
            ]),
            $sales,
        );

        $this->actingAs($pm)
            ->post(route('standard-offers.from-calculation', $calc))
            ->assertForbidden();

        $this->actingAs($sales)
            ->post(route('standard-offers.from-calculation', $calc))
            ->assertRedirect();

        $offer = StandardOffer::query()->firstOrFail();
        $draft = $offer->draftVersion;
        $this->assertNotNull($draft);
        $published = $this->writer()->publish($draft, (int) $draft->lock_version, $pm);

        $this->actingAs($pm)
            ->post(route('standard-offers.adopt', [
                'standardOffer' => $offer,
                'version' => $published,
            ]), [
                'customer_name' => 'Adopt Versuch',
            ])
            ->assertForbidden();
    }

    public function test_write_version_is_four(): void
    {
        $this->assertSame(4, FrozenCalculationPersistenceContract::CURRENT_WRITE_VERSION);
        $this->assertSame(4, StandardOfferMaterializer::MATERIALIZATION_VERSION);
        $this->assertSame([1, 2, 3, 4], FrozenCalculationPersistenceContract::SUPPORTED_VERSIONS);
    }

    /**
     * @param  array{hamburg: Inventory, medium: AdvertisingMedium, rock: Inventory, organization: mixed}  $catalog
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
     * @param  array{hamburg: Inventory, medium: AdvertisingMedium}  $catalog
     * @param  list<array{role: string, label: string, length_seconds: int, sort: int}>  $components
     * @return array<string, mixed>
     */
    private function profilePayload(
        array $catalog,
        AdvertisingMedium $medium,
        string $profile,
        array $components,
        int $length,
        string $mode,
        ?string $nn,
    ): array {
        return $this->withLiveSchemaFingerprint([
            'planning_mode' => 'manual',
            'order_discount_percent' => '0',
            'ae_enabled' => false,
            'order_discounts' => [],
            'dynamic_field_values' => ['campaign_period' => null],
            'positions' => [
                $this->positionRow($catalog, $medium, $profile, $components, $length, $mode, $nn, 'p1'),
            ],
        ]);
    }

    /**
     * @param  array{hamburg: Inventory}  $catalog
     * @param  list<array{role: string, label: string, length_seconds: int, sort: int}>  $components
     * @return array<string, mixed>
     */
    private function positionRow(
        array $catalog,
        AdvertisingMedium $medium,
        string $profile,
        array $components,
        int $length,
        string $mode,
        ?string $nn,
        string $clientKey,
    ): array {
        $row = [
            'client_key' => $clientKey,
            'inventory_id' => $catalog['hamburg']->id,
            'advertising_medium_id' => $medium->id,
            'spot_method' => 'average',
            'length_seconds' => $length,
            'total_spot_count' => 10,
            'position_discount_percent' => '0',
            'ae_percent' => '0',
            'pricing_settlement_mode' => $mode,
            'component_profile' => $profile,
            'component_calculation_strategy' => ComponentCalculationStrategy::SharedTotalLength->value,
            'components' => $components,
            'plan_rows' => [['hour' => 8, 'day_group' => 'mo_fr']],
            'time_ranges' => [[
                'start_hour' => 8,
                'end_hour_exclusive' => 9,
                'day_group' => 'mo_fr',
                'spot_count' => 10,
            ]],
            'position_discounts' => [],
            'dynamic_field_values' => ['period_open' => true],
        ];
        if ($nn !== null) {
            $row['fixed_price_nn'] = $nn;
        }

        return $row;
    }

    /**
     * @param  array{hamburg: Inventory, medium: AdvertisingMedium}  $catalog
     * @return array<string, mixed>
     */
    private function classicPayload(array $catalog, string $mode = 'normal', ?string $nn = null): array
    {
        $row = [
            'client_key' => 'c1',
            'inventory_id' => $catalog['hamburg']->id,
            'advertising_medium_id' => $catalog['medium']->id,
            'spot_method' => 'average',
            'length_seconds' => 30,
            'total_spot_count' => 10,
            'position_discount_percent' => '0',
            'ae_percent' => '0',
            'pricing_settlement_mode' => $mode,
            'component_profile' => null,
            'components' => [],
            'plan_rows' => [['hour' => 8, 'day_group' => 'mo_fr']],
            'time_ranges' => [[
                'start_hour' => 8,
                'end_hour_exclusive' => 9,
                'day_group' => 'mo_fr',
                'spot_count' => 10,
            ]],
            'position_discounts' => [],
            'dynamic_field_values' => ['period_open' => true],
        ];
        if ($nn !== null) {
            $row['fixed_price_nn'] = $nn;
        }

        return $this->withLiveSchemaFingerprint([
            'planning_mode' => 'manual',
            'order_discount_percent' => '0',
            'ae_enabled' => false,
            'order_discounts' => [],
            'dynamic_field_values' => ['campaign_period' => null],
            'positions' => [$row],
        ]);
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

    /**
     * @param  array{hamburg: Inventory, rock: Inventory}  $catalog
     */
    private function setSecondPrice(array $catalog, string $price): void
    {
        foreach ([$catalog['hamburg'], $catalog['rock']] as $inventory) {
            $list = PriceList::query()
                ->where('inventory_id', $inventory->id)
                ->where('status', PriceListStatus::Active)
                ->first();
            if ($list === null) {
                continue;
            }
            PriceListItem::query()->where('price_list_id', $list->id)->update(['second_price' => $price]);
        }
    }

    /**
     * @param  array{hamburg: Inventory, rock: Inventory, medium: AdvertisingMedium}  $catalog
     */
    private function setRuleStrategy(array $catalog, ComponentCalculationStrategy $strategy): void
    {
        InventoryMediumRule::query()
            ->where('advertising_medium_id', $catalog['medium']->id)
            ->update(['component_calculation_strategy' => $strategy->value]);
    }

    private function writer(): StandardOfferWriter
    {
        return app(StandardOfferWriter::class);
    }
}
