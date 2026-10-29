<?php

namespace Tests\Feature\OnlineAudio\Admin;

use App\Enums\EngineCapabilityStatus;
use App\Enums\OaTargetingCategory;
use App\Enums\PriceListStatus;
use App\Enums\Role;
use App\Models\AuditEvent;
use App\Models\OaPlatform;
use App\Models\OaTkpPriceList;
use App\Models\User;
use App\Support\Calculation\EngineProfileRegistry;
use App\Support\Calculation\OaTkpWizardCapability;
use App\Support\OnlineAudio\OaTkpActiveMediumInventoryPairs;
use Database\Seeders\InitialCatalogMatCoreSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\EnsuresOaTkpInventoryMediumRules;
use Tests\TestCase;

/**
 * PO-CALC-TKP-1D4c-4c: Admin-Backend Rechte (Admin-only), Draft, Activate, Targeting, Plattform.
 */
class PoCalcTkp1d4c4cAdminBackendTest extends TestCase
{
    use EnsuresOaTkpInventoryMediumRules;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        OaTkpWizardCapability::clearHttpMutationFakeForTests();
        parent::tearDown();
    }

    #[Test]
    public function only_admin_may_read_and_write_oa_master_data_management_forbidden(): void
    {
        $this->seed(InitialCatalogMatCoreSeeder::class);
        $this->ensureDefaultOaPriceListPairRule();

        $this->getJson(route('administration.oa-tkp.price-lists.index'))
            ->assertUnauthorized();

        $admin = User::factory()->role(Role::Admin)->create();
        $this->actingAs($admin)
            ->getJson(route('administration.oa-tkp.price-lists.index'))
            ->assertOk();

        $management = User::factory()->role(Role::Management)->create();
        $this->actingAs($management)
            ->getJson(route('administration.oa-tkp.price-lists.index'))
            ->assertForbidden();
        $this->actingAs($management)
            ->postJson(route('administration.oa-tkp.price-lists.store'), $this->minimalDraftPayload())
            ->assertForbidden();
        $this->actingAs($management)
            ->postJson(route('administration.oa-tkp.targetings.store'), [
                'code' => 'mgmt_blocked',
                'name' => 'Blocked',
                'category' => OaTargetingCategory::Dmp->value,
                'tkp_surcharge' => '1.0000',
                'revision_number' => 1,
            ])
            ->assertForbidden();
        $this->actingAs($management)
            ->postJson(route('administration.oa-tkp.platforms.store'), [
                'code' => 'mgmt_plat',
                'name' => 'Blocked',
            ])
            ->assertForbidden();

        foreach ([Role::Sales, Role::Disposition, Role::ProductManagement] as $role) {
            $user = User::factory()->role($role)->create();
            $this->actingAs($user)
                ->getJson(route('administration.oa-tkp.price-lists.index'))
                ->assertForbidden();
            $this->actingAs($user)
                ->postJson(route('administration.oa-tkp.price-lists.store'), $this->minimalDraftPayload())
                ->assertForbidden();
        }
    }

    #[Test]
    public function management_cannot_activate_or_archive(): void
    {
        $this->seed(InitialCatalogMatCoreSeeder::class);
        $this->ensureDefaultOaPriceListPairRule();
        $admin = User::factory()->role(Role::Admin)->create();
        $management = User::factory()->role(Role::Management)->create();

        $created = $this->actingAs($admin)
            ->postJson(route('administration.oa-tkp.price-lists.store'), $this->minimalDraftPayload(2027))
            ->assertCreated()
            ->json('price_list');
        $list = OaTkpPriceList::query()->findOrFail((int) $created['id']);

        $this->actingAs($admin)
            ->postJson(route('administration.oa-tkp.price-lists.activate', $list), [
                'lock_version' => (int) $list->lock_version,
            ])
            ->assertOk();
        $list->refresh();

        $this->actingAs($management)
            ->postJson(route('administration.oa-tkp.price-lists.archive', $list), [
                'lock_version' => (int) $list->lock_version,
            ])
            ->assertForbidden();

        $draft2 = OaTkpPriceList::query()->findOrFail((int) $this->actingAs($admin)
            ->postJson(route('administration.oa-tkp.price-lists.store'), $this->minimalDraftPayload(2027))
            ->json('price_list.id'));

        $this->actingAs($management)
            ->postJson(route('administration.oa-tkp.price-lists.activate', $draft2), [
                'lock_version' => (int) $draft2->lock_version,
            ])
            ->assertForbidden();
        $this->assertSame(PriceListStatus::Draft, $draft2->fresh()->status);
    }

    #[Test]
    public function draft_create_update_and_validation_errors(): void
    {
        $this->seed(InitialCatalogMatCoreSeeder::class);
        $this->ensureDefaultOaPriceListPairRule();
        $admin = User::factory()->role(Role::Admin)->create();

        $create = $this->actingAs($admin)
            ->postJson(route('administration.oa-tkp.price-lists.store'), $this->minimalDraftPayload(2027))
            ->assertCreated()
            ->json();

        $list = OaTkpPriceList::query()->findOrFail((int) $create['price_list']['id']);
        $this->assertSame(PriceListStatus::Draft, $list->status);
        $this->assertSame(1, AuditEvent::query()->where('action', 'oa_tkp_price_list.created')->count());

        $this->actingAs($admin)
            ->putJson(route('administration.oa-tkp.price-lists.update', $list), [
                'name' => 'OA 2027 Draft v2',
                'lock_version' => (int) $list->lock_version,
                'items' => [[
                    'medium_code' => 'pre_stream',
                    'inventory_code' => 'inv_more_kombi_online_audio',
                    'list_tkp' => '56.0000',
                    'minimum_tkp' => '14.0000',
                ]],
            ])
            ->assertOk();

        $this->assertSame(1, AuditEvent::query()->where('action', 'oa_tkp_price_list.updated')->count());

        $this->actingAs($admin)
            ->putJson(route('administration.oa-tkp.price-lists.update', $list->fresh()), [
                'name' => 'bad min',
                'lock_version' => (int) $list->fresh()->lock_version,
                'items' => [[
                    'medium_code' => 'pre_stream',
                    'inventory_code' => 'inv_more_kombi_online_audio',
                    'list_tkp' => '10.0000',
                    'minimum_tkp' => '20.0000',
                ]],
            ])
            ->assertStatus(422);

        $this->actingAs($admin)
            ->postJson(route('administration.oa-tkp.price-lists.store'), [
                'year' => 2027,
                'name' => 'dup pair',
                'items' => [
                    [
                        'medium_code' => 'pre_stream',
                        'inventory_code' => 'inv_more_kombi_online_audio',
                        'list_tkp' => '56.0000',
                        'minimum_tkp' => '14.0000',
                    ],
                    [
                        'medium_code' => 'pre_stream',
                        'inventory_code' => 'inv_more_kombi_online_audio',
                        'list_tkp' => '56.0000',
                        'minimum_tkp' => '14.0000',
                    ],
                ],
            ])
            ->assertStatus(422);
    }

    #[Test]
    public function incomplete_matrix_blocked_for_admin_and_cli_identically(): void
    {
        $this->seed(InitialCatalogMatCoreSeeder::class);
        $this->ensureOaInventoryMediumRules([
            ['pre_stream', 'inv_more_kombi_online_audio'],
            ['in_stream', 'inv_more_kombi_online_audio'],
        ]);
        $admin = User::factory()->role(Role::Admin)->create();

        $this->assertCount(2, OaTkpActiveMediumInventoryPairs::all());

        $partial = OaTkpPriceList::query()->findOrFail((int) $this->actingAs($admin)
            ->postJson(route('administration.oa-tkp.price-lists.store'), $this->minimalDraftPayload(2027))
            ->json('price_list.id'));

        $validate = $this->actingAs($admin)
            ->postJson(route('administration.oa-tkp.price-lists.validate', $partial), [
                'require_complete' => true,
            ])
            ->assertOk()
            ->json();
        $this->assertFalse($validate['complete']);
        $this->assertNotEmpty($validate['missing_pairs']);

        $this->actingAs($admin)
            ->postJson(route('administration.oa-tkp.price-lists.activate', $partial), [
                'lock_version' => (int) $partial->lock_version,
            ])
            ->assertStatus(422);
        $this->assertSame(PriceListStatus::Draft, $partial->fresh()->status);

        $path = sys_get_temp_dir().'/oa-d4c4c-partial-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode([
            'year' => 2026,
            'revision_number' => 1,
            'status' => 'draft',
            'name' => 'CLI partial',
            'version' => '2026.1',
            'items' => [[
                'medium_code' => 'pre_stream',
                'inventory_code' => 'inv_more_kombi_online_audio',
                'list_tkp' => '12.0000',
                'minimum_tkp' => '8.0000',
            ]],
        ], JSON_THROW_ON_ERROR));
        $this->assertSame(0, Artisan::call('oa:import-price-list', ['path' => $path]));
        $this->assertSame(1, Artisan::call('oa:activate-price-list', [
            '--year' => 2026,
            '--revision' => 1,
        ]));
        @unlink($path);
        $this->assertSame(
            PriceListStatus::Draft,
            OaTkpPriceList::query()->where('year', 2026)->firstOrFail()->status,
        );
    }

    #[Test]
    public function active_and_archived_immutable_complete_activate_archives_previous(): void
    {
        $this->seed(InitialCatalogMatCoreSeeder::class);
        $this->ensureDefaultOaPriceListPairRule();
        $admin = User::factory()->role(Role::Admin)->create();

        $first = OaTkpPriceList::query()->findOrFail((int) $this->actingAs($admin)
            ->postJson(route('administration.oa-tkp.price-lists.store'), $this->minimalDraftPayload(2027, 'r1', '56.0000', '14.0000'))
            ->json('price_list.id'));

        $this->actingAs($admin)
            ->postJson(route('administration.oa-tkp.price-lists.activate', $first), [
                'lock_version' => (int) $first->lock_version,
            ])
            ->assertOk();
        $first->refresh();
        $this->assertSame(PriceListStatus::Active, $first->status);
        $this->assertSame(1, AuditEvent::query()->where('action', 'oa_tkp_price_list.activated')->count());

        $this->actingAs($admin)
            ->putJson(route('administration.oa-tkp.price-lists.update', $first), [
                'name' => 'mutate active',
                'lock_version' => (int) $first->lock_version,
                'items' => $this->minimalItems('56.0000', '14.0000'),
            ])
            ->assertStatus(422);

        $second = OaTkpPriceList::query()->findOrFail((int) $this->actingAs($admin)
            ->postJson(route('administration.oa-tkp.price-lists.store'), $this->minimalDraftPayload(2027, 'r2', '57.0000', '15.0000'))
            ->json('price_list.id'));

        $this->actingAs($admin)
            ->postJson(route('administration.oa-tkp.price-lists.activate', $second), [
                'lock_version' => (int) $second->lock_version,
            ])
            ->assertOk();

        $this->assertSame(PriceListStatus::Archived, $first->fresh()->status);
        $this->assertSame(PriceListStatus::Active, $second->fresh()->status);

        $this->actingAs($admin)
            ->postJson(route('administration.oa-tkp.price-lists.archive', $second), [
                'lock_version' => (int) $second->fresh()->lock_version,
            ])
            ->assertOk();
        $this->assertSame(PriceListStatus::Archived, $second->fresh()->status);

        $this->actingAs($admin)
            ->putJson(route('administration.oa-tkp.price-lists.update', $second->fresh()), [
                'name' => 'mutate archived',
                'lock_version' => (int) $second->fresh()->lock_version,
                'items' => $this->minimalItems('56.0000', '14.0000'),
            ])
            ->assertStatus(422);

        $this->actingAs($admin)
            ->getJson(route('administration.oa-tkp.price-lists.history', $second))
            ->assertOk();
    }

    #[Test]
    public function targeting_platform_audit_and_registry_planned(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();

        $this->actingAs($admin)
            ->postJson(route('administration.oa-tkp.targetings.store'), [
                'code' => 'dmp',
                'name' => 'DMP',
                'category' => OaTargetingCategory::Dmp->value,
                'tkp_surcharge' => '25.0000',
                'minimum_tkp_surcharge' => '5.0000',
                'revision_number' => 1,
                'is_active' => true,
            ])
            ->assertCreated();

        $this->actingAs($admin)
            ->postJson(route('administration.oa-tkp.targetings.revisions.store', ['code' => 'dmp']), [
                'tkp_surcharge' => '26.0000',
                'minimum_tkp_surcharge' => '6.0000',
                'deactivate_previous' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('targeting.revision_number', 2);

        $platform = OaPlatform::query()->findOrFail((int) $this->actingAs($admin)
            ->postJson(route('administration.oa-tkp.platforms.store'), [
                'code' => 'test_plat_d4c4c',
                'name' => 'Test Platform',
                'is_active' => true,
            ])
            ->json('platform.id'));

        $this->actingAs($admin)
            ->postJson(route('administration.oa-tkp.platforms.deactivate', $platform))
            ->assertOk()
            ->assertJsonPath('platform.is_active', false);

        $this->actingAs($admin)
            ->deleteJson(route('administration.oa-tkp.platforms.destroy', $platform))
            ->assertStatus(405);

        $this->assertTrue(AuditEvent::query()->where('action', 'oa_targeting_definition.created')->exists());
        $this->assertTrue(AuditEvent::query()->where('action', 'oa_platform.created')->exists());
        $this->assertSame(EngineCapabilityStatus::Planned, EngineProfileRegistry::pairStatus('oa_tkp', 'tkp'));
        $this->assertFalse(OaTkpWizardCapability::allowsHttpMutation());
    }

    /**
     * @return array<string, mixed>
     */
    private function minimalDraftPayload(
        int $year = 2027,
        string $name = 'OA Draft',
        string $listTkp = '56.0000',
        string $minimumTkp = '14.0000',
    ): array {
        return [
            'year' => $year,
            'name' => $name,
            'items' => $this->minimalItems($listTkp, $minimumTkp),
        ];
    }

    /**
     * @return list<array{medium_code: string, inventory_code: string, list_tkp: string, minimum_tkp: string}>
     */
    private function minimalItems(string $listTkp, string $minimumTkp): array
    {
        return [[
            'medium_code' => 'pre_stream',
            'inventory_code' => 'inv_more_kombi_online_audio',
            'list_tkp' => $listTkp,
            'minimum_tkp' => $minimumTkp,
        ]];
    }
}
