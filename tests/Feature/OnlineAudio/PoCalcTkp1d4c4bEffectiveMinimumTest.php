<?php

namespace Tests\Feature\OnlineAudio;

use App\Enums\OaTargetingCategory;
use App\Enums\PriceListStatus;
use App\Enums\Role;
use App\Enums\SpecialApprovalReasonCode;
use App\Models\AdvertisingMedium;
use App\Models\Inventory;
use App\Models\OaPlatform;
use App\Models\OaTargetingDefinition;
use App\Models\OaTkpPriceList;
use App\Models\OaTkpPriceListItem;
use App\Models\User;
use App\Services\Calculation\CalculationWriter;
use App\Services\OnlineAudio\Import\OaTkpImportDecimal;
use App\Support\PriceList\PriceListCalendar;
use Database\Seeders\InitialCatalogMatCoreSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * PO-CALC-TKP-1D4c-4b: Gesamt-Mindest-TKP (FD-D4c-4b) über Writer + Persistenz.
 *
 * Isolierte Fixture-Werte (Pre-Stream 56/14, DMP 25/5, Geo 15/3) – keine Prod-Daten.
 */
class PoCalcTkp1d4c4bEffectiveMinimumTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function writer_persists_list_and_effective_minimum_and_targeting_min_snapshots(): void
    {
        $ctx = $this->seedContext();
        $writer = app(CalculationWriter::class);

        $calculation = $writer->create($this->payload($ctx, [
            'basis_tkp' => '20.0000',
            'oa_targeting_selections' => [
                ['targeting_code' => 'dmp_fixture'],
                ['targeting_code' => 'geo_fixture'],
            ],
        ]), $ctx['user']);

        $position = $calculation->positions->firstOrFail();
        $this->assertSame(0, OaTkpImportDecimal::compare((string) $position->final_tkp, '60.0000'));
        $this->assertSame(0, OaTkpImportDecimal::compare((string) $position->minimum_tkp_snapshot, '22.0000'));
        $this->assertSame(0, OaTkpImportDecimal::compare((string) $position->price_list_minimum_tkp_snapshot, '14.0000'));
        $this->assertSame(0, OaTkpImportDecimal::compare((string) $position->targeting_surcharge_total, '40.0000'));
        $this->assertFalse((bool) $position->below_minimum_tkp);
        $this->assertFalse((bool) $calculation->requires_special_approval);

        $selections = $position->oaTargetingSelections->sortBy('sort')->values();
        $this->assertCount(2, $selections);
        $this->assertSame(0, OaTkpImportDecimal::compare((string) $selections[0]->tkp_surcharge_snapshot, '25.0000'));
        $this->assertSame(0, OaTkpImportDecimal::compare((string) $selections[0]->minimum_tkp_surcharge_snapshot, '5.0000'));
        $this->assertSame(0, OaTkpImportDecimal::compare((string) $selections[1]->tkp_surcharge_snapshot, '15.0000'));
        $this->assertSame(0, OaTkpImportDecimal::compare((string) $selections[1]->minimum_tkp_surcharge_snapshot, '3.0000'));
    }

    #[Test]
    public function basis_below_list_min_with_sufficient_list_surcharge_needs_no_approval(): void
    {
        $ctx = $this->seedContext();
        $writer = app(CalculationWriter::class);

        $calculation = $writer->create($this->payload($ctx, [
            'basis_tkp' => '12.0000',
            'oa_targeting_selections' => [
                ['targeting_code' => 'dmp_fixture'],
            ],
        ]), $ctx['user']);

        $position = $calculation->positions->firstOrFail();
        // basis 12 < list-min 14, final 37 ≥ effective 19
        $this->assertTrue(OaTkpImportDecimal::compare((string) $position->basis_tkp, '14.0000') < 0);
        $this->assertSame(0, OaTkpImportDecimal::compare((string) $position->final_tkp, '37.0000'));
        $this->assertSame(0, OaTkpImportDecimal::compare((string) $position->minimum_tkp_snapshot, '19.0000'));
        $this->assertFalse((bool) $position->below_minimum_tkp);
        $this->assertFalse((bool) $calculation->requires_special_approval);
    }

    #[Test]
    public function final_below_effective_minimum_requires_special_approval(): void
    {
        $ctx = $this->seedContext();
        $writer = app(CalculationWriter::class);

        // basis 1 + Geo 15 = final 16 < effective 17
        $preview = $writer->preview($this->payload($ctx, [
            'basis_tkp' => '1.0000',
            'oa_targeting_selections' => [
                ['targeting_code' => 'geo_fixture'],
            ],
        ]), $ctx['user']);

        $this->assertTrue($preview->requiresSpecialApproval);
        $this->assertSame(
            SpecialApprovalReasonCode::OaTkpBasisBelowMinimum->value,
            $preview->specialApprovalReasons[0]['code'],
        );
        $this->assertSame('16.0000', $preview->specialApprovalReasons[0]['final_tkp']);
        $this->assertSame('17.0000', $preview->specialApprovalReasons[0]['minimum_tkp']);

        $calculation = $writer->create($this->payload($ctx, [
            'basis_tkp' => '1.0000',
            'oa_targeting_selections' => [
                ['targeting_code' => 'geo_fixture'],
            ],
        ]), $ctx['user']);

        $this->assertTrue((bool) $calculation->requires_special_approval);
        $position = $calculation->positions->firstOrFail();
        $this->assertTrue((bool) $position->below_minimum_tkp);
        $this->assertSame(0, OaTkpImportDecimal::compare((string) $position->final_tkp, '16.0000'));
        $this->assertSame(0, OaTkpImportDecimal::compare((string) $position->minimum_tkp_snapshot, '17.0000'));
    }

    #[Test]
    public function changing_targetings_recomputes_effective_minimum_and_invalidates_stale_under_min_flag(): void
    {
        $ctx = $this->seedContext();
        $writer = app(CalculationWriter::class);

        $calculation = $writer->create($this->payload($ctx, [
            'basis_tkp' => '1.0000',
            'oa_targeting_selections' => [
                ['targeting_code' => 'geo_fixture'],
            ],
        ]), $ctx['user']);
        $this->assertTrue((bool) $calculation->requires_special_approval);

        $payload = $writer->payloadFromCalculation($calculation->fresh());
        $payload['lock_version'] = $calculation->lock_version;
        // basis 1 + DMP 25 + Geo 15 = 41 ≥ effective 22
        $payload['positions'][0]['oa_targeting_selections'] = [
            ['targeting_code' => 'dmp_fixture'],
            ['targeting_code' => 'geo_fixture'],
        ];

        $updated = $writer->update($calculation->fresh(), $payload, $ctx['user']);
        $position = $updated->positions->firstOrFail();
        $this->assertSame(0, OaTkpImportDecimal::compare((string) $position->final_tkp, '41.0000'));
        $this->assertSame(0, OaTkpImportDecimal::compare((string) $position->minimum_tkp_snapshot, '22.0000'));
        $this->assertFalse((bool) $position->below_minimum_tkp);
        $this->assertFalse((bool) $updated->requires_special_approval);
    }

    #[Test]
    public function client_price_overrides_are_ignored(): void
    {
        $ctx = $this->seedContext();
        $writer = app(CalculationWriter::class);

        $calculation = $writer->create($this->payload($ctx, [
            'basis_tkp' => '20.0000',
            'list_tkp' => '1.0000',
            'minimum_tkp' => '1.0000',
            'final_tkp' => '1.0000',
            'targeting_surcharge_total' => '99.0000',
            'oa_targeting_selections' => [
                [
                    'targeting_code' => 'dmp_fixture',
                    'tkp_surcharge' => '99.0000',
                    'minimum_tkp_surcharge' => '99.0000',
                    'targeting_name' => 'Hacked',
                ],
            ],
        ]), $ctx['user']);

        $position = $calculation->positions->firstOrFail();
        $this->assertSame(0, OaTkpImportDecimal::compare((string) $position->list_tkp_snapshot, '56.0000'));
        $this->assertSame(0, OaTkpImportDecimal::compare((string) $position->price_list_minimum_tkp_snapshot, '14.0000'));
        $this->assertSame(0, OaTkpImportDecimal::compare((string) $position->final_tkp, '45.0000'));
        $selection = $position->oaTargetingSelections->firstOrFail();
        $this->assertSame('DMP Fixture', $selection->targeting_name);
        $this->assertSame(0, OaTkpImportDecimal::compare((string) $selection->tkp_surcharge_snapshot, '25.0000'));
        $this->assertSame(0, OaTkpImportDecimal::compare((string) $selection->minimum_tkp_surcharge_snapshot, '5.0000'));
    }

    #[Test]
    public function inactive_targeting_is_rejected_on_create(): void
    {
        $ctx = $this->seedContext();
        $ctx['dmp']->update(['is_active' => false]);
        $writer = app(CalculationWriter::class);

        $this->expectException(ValidationException::class);
        $writer->create($this->payload($ctx, [
            'basis_tkp' => '20.0000',
            'oa_targeting_selections' => [
                ['targeting_code' => 'dmp_fixture'],
            ],
        ]), $ctx['user']);
    }

    /**
     * @param  array<string, mixed>  $positionOverride
     * @return array<string, mixed>
     */
    private function payload(array $ctx, array $positionOverride = []): array
    {
        return $this->withLiveSchemaFingerprint([
            'planning_mode' => 'manual',
            'order_discount_percent' => '0',
            'ae_enabled' => false,
            'positions' => [array_merge([
                'inventory_id' => (int) $ctx['inventory']->id,
                'advertising_medium_id' => (int) $ctx['medium']->id,
                'calculation_method_key' => 'tkp',
                'impressions' => 100_000,
                'basis_tkp' => '20.0000',
                'oa_platform_allocations' => [
                    ['oa_platform_id' => (int) $ctx['platform']->id, 'impressions' => 100_000],
                ],
                'oa_targeting_selections' => [],
                'position_discount_percent' => '0',
                'ae_percent' => '0',
            ], $positionOverride)],
        ]);
    }

    /**
     * @return array{
     *     user: User,
     *     medium: AdvertisingMedium,
     *     inventory: Inventory,
     *     list: OaTkpPriceList,
     *     dmp: OaTargetingDefinition,
     *     geo: OaTargetingDefinition,
     *     platform: OaPlatform
     * }
     */
    private function seedContext(): array
    {
        $this->seed(InitialCatalogMatCoreSeeder::class);
        $user = User::factory()->role(Role::Sales)->create();
        $medium = AdvertisingMedium::query()->where('code', 'pre_stream')->firstOrFail();
        $inventory = Inventory::query()->where('code', 'inv_more_kombi_online_audio')->firstOrFail();

        $list = OaTkpPriceList::query()->create([
            'year' => PriceListCalendar::currentYear(),
            'name' => 'D4c4b Fixture',
            'version' => PriceListCalendar::currentYear().'.1',
            'revision_number' => 1,
            'lock_version' => 1,
            'status' => PriceListStatus::Active,
            'published_at' => now(),
        ]);
        OaTkpPriceListItem::query()->create([
            'oa_tkp_price_list_id' => $list->id,
            'advertising_medium_id' => $medium->id,
            'inventory_id' => $inventory->id,
            'list_tkp' => '56.0000',
            'minimum_tkp' => '14.0000',
        ]);

        $dmp = OaTargetingDefinition::query()->create([
            'code' => 'dmp_fixture',
            'name' => 'DMP Fixture',
            'category' => OaTargetingCategory::Dmp,
            'tkp_surcharge' => '25.0000',
            'minimum_tkp_surcharge' => '5.0000',
            'revision_number' => 1,
            'is_active' => true,
        ]);
        $geo = OaTargetingDefinition::query()->create([
            'code' => 'geo_fixture',
            'name' => 'Geo Fixture',
            'category' => OaTargetingCategory::Technisch,
            'tkp_surcharge' => '15.0000',
            'minimum_tkp_surcharge' => '3.0000',
            'revision_number' => 1,
            'is_active' => true,
        ]);
        $platform = OaPlatform::query()->create([
            'code' => 'plat_d4c4b',
            'name' => 'Platform D4c4b',
            'is_active' => true,
            'sort' => 1,
            'is_adserver_relevant' => false,
        ]);

        return compact('user', 'medium', 'inventory', 'list', 'dmp', 'geo', 'platform');
    }
}
