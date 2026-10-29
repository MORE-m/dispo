<?php

namespace Tests\Feature\OnlineAudio\Admin;

use App\Enums\EngineCapabilityStatus;
use App\Enums\OaTargetingCategory;
use App\Enums\PriceListStatus;
use App\Enums\Role;
use App\Models\AuditEvent;
use App\Models\OaPlatform;
use App\Models\OaTargetingDefinition;
use App\Models\OaTkpPriceList;
use App\Models\User;
use App\Support\Calculation\EngineProfileRegistry;
use App\Support\Calculation\OaTkpWizardCapability;
use App\Support\OnlineAudio\OaTkpActiveMediumInventoryPairs;
use Database\Seeders\InitialCatalogMatCoreSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * PO-CALC-TKP-1D4c-4c: Admin-Backend Rechte, Draft, Validierung, Activate, Targeting, Plattform, Audit.
 */
class PoCalcTkp1d4c4cAdminBackendTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        OaTkpWizardCapability::clearHttpMutationFakeForTests();
        parent::tearDown();
    }

    #[Test]
    public function admin_and_management_allowed_other_roles_and_guest_forbidden(): void
    {
        $this->seed(InitialCatalogMatCoreSeeder::class);

        $this->getJson(route('administration.oa-tkp.price-lists.index'))
            ->assertUnauthorized();

        foreach ([Role::Admin, Role::Management] as $role) {
            $user = User::factory()->role($role)->create();
            $this->actingAs($user)
                ->getJson(route('administration.oa-tkp.price-lists.index'))
                ->assertOk();
        }

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
    public function draft_create_update_and_validation_errors(): void
    {
        $this->seed(InitialCatalogMatCoreSeeder::class);
        $admin = User::factory()->role(Role::Admin)->create();

        $create = $this->actingAs($admin)
            ->postJson(route('administration.oa-tkp.price-lists.store'), $this->minimalDraftPayload(2027))
            ->assertCreated()
            ->json();

        $listId = (int) $create['price_list']['id'];
        $list = OaTkpPriceList::query()->findOrFail($listId);
        $this->assertSame(PriceListStatus::Draft, $list->status);
        $this->assertSame(2027, (int) $list->year);
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

        $list->refresh();
        $this->assertSame('OA 2027 Draft v2', $list->name);
        $this->assertSame(1, AuditEvent::query()->where('action', 'oa_tkp_price_list.updated')->count());

        $this->actingAs($admin)
            ->putJson(route('administration.oa-tkp.price-lists.update', $list), [
                'name' => 'bad min',
                'lock_version' => (int) $list->fresh()->lock_version,
                'items' => [[
                    'medium_code' => 'pre_stream',
                    'inventory_code' => 'inv_more_kombi_online_audio',
                    'list_tkp' => '10.0000',
                    'minimum_tkp' => '20.0000',
                ]],
            ])
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'TKP-Preislisten-Entwurf ungültig.']);

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

        $this->actingAs($admin)
            ->postJson(route('administration.oa-tkp.price-lists.store'), [
                'year' => 2027,
                'name' => 'invalid tkp',
                'items' => [[
                    'medium_code' => 'pre_stream',
                    'inventory_code' => 'inv_more_kombi_online_audio',
                    'list_tkp' => '0',
                    'minimum_tkp' => '0',
                ]],
            ])
            ->assertStatus(422);
    }

    #[Test]
    public function active_and_archived_are_immutable_incomplete_cannot_activate(): void
    {
        $this->seed(InitialCatalogMatCoreSeeder::class);
        $admin = User::factory()->role(Role::Admin)->create();

        $created = $this->actingAs($admin)
            ->postJson(route('administration.oa-tkp.price-lists.store'), $this->minimalDraftPayload(2027))
            ->assertCreated()
            ->json('price_list');

        $draft = OaTkpPriceList::query()->findOrFail((int) $created['id']);

        $validate = $this->actingAs($admin)
            ->postJson(route('administration.oa-tkp.price-lists.validate', $draft), [
                'require_complete' => true,
            ])
            ->assertOk()
            ->json();
        $this->assertFalse($validate['complete']);
        $this->assertNotEmpty($validate['missing_pairs']);

        $this->actingAs($admin)
            ->postJson(route('administration.oa-tkp.price-lists.activate', $draft), [
                'lock_version' => (int) $draft->lock_version,
            ])
            ->assertStatus(422);

        $full = $this->actingAs($admin)
            ->postJson(route('administration.oa-tkp.price-lists.store'), $this->completeDraftPayload(2027, 'OA full'))
            ->assertCreated()
            ->json('price_list');
        $fullList = OaTkpPriceList::query()->findOrFail((int) $full['id']);

        $this->actingAs($admin)
            ->postJson(route('administration.oa-tkp.price-lists.activate', $fullList), [
                'lock_version' => (int) $fullList->lock_version,
            ])
            ->assertOk();

        $fullList->refresh();
        $this->assertSame(PriceListStatus::Active, $fullList->status);
        $this->assertSame(1, AuditEvent::query()->where('action', 'oa_tkp_price_list.activated')->count());

        $this->actingAs($admin)
            ->putJson(route('administration.oa-tkp.price-lists.update', $fullList), [
                'name' => 'mutate active',
                'lock_version' => (int) $fullList->lock_version,
                'items' => $this->completeItems('56.0000', '14.0000'),
            ])
            ->assertStatus(422);

        $this->actingAs($admin)
            ->postJson(route('administration.oa-tkp.price-lists.archive', $fullList), [
                'lock_version' => (int) $fullList->lock_version,
            ])
            ->assertOk();
        $fullList->refresh();
        $this->assertSame(PriceListStatus::Archived, $fullList->status);
        $this->assertSame(1, AuditEvent::query()->where('action', 'oa_tkp_price_list.archived')->count());

        $this->actingAs($admin)
            ->putJson(route('administration.oa-tkp.price-lists.update', $fullList), [
                'name' => 'mutate archived',
                'lock_version' => (int) $fullList->lock_version,
                'items' => $this->completeItems('56.0000', '14.0000'),
            ])
            ->assertStatus(422);

        $this->actingAs($admin)
            ->getJson(route('administration.oa-tkp.price-lists.history', $fullList))
            ->assertOk()
            ->assertJsonPath('price_list_id', $fullList->id);
    }

    #[Test]
    public function activate_archives_previous_and_keeps_historical_revision_rows(): void
    {
        $this->seed(InitialCatalogMatCoreSeeder::class);
        $admin = User::factory()->role(Role::Admin)->create();

        $first = OaTkpPriceList::query()->findOrFail((int) $this->actingAs($admin)
            ->postJson(route('administration.oa-tkp.price-lists.store'), $this->completeDraftPayload(2027, 'r1', '56.0000', '14.0000'))
            ->json('price_list.id'));

        $this->actingAs($admin)
            ->postJson(route('administration.oa-tkp.price-lists.activate', $first), [
                'lock_version' => (int) $first->lock_version,
            ])
            ->assertOk();
        $first->refresh();
        $firstItemCount = $first->items()->count();
        $firstItemTkp = (string) $first->items()->firstOrFail()->list_tkp;

        $second = OaTkpPriceList::query()->findOrFail((int) $this->actingAs($admin)
            ->postJson(route('administration.oa-tkp.price-lists.store'), $this->completeDraftPayload(2027, 'r2', '57.0000', '15.0000'))
            ->json('price_list.id'));

        $this->actingAs($admin)
            ->postJson(route('administration.oa-tkp.price-lists.activate', $second), [
                'lock_version' => (int) $second->lock_version,
            ])
            ->assertOk();

        $this->assertSame(PriceListStatus::Archived, $first->fresh()->status);
        $this->assertSame(PriceListStatus::Active, $second->fresh()->status);
        $this->assertSame(1, OaTkpPriceList::query()->where('year', 2027)->where('status', PriceListStatus::Active)->count());
        $this->assertSame($firstItemCount, $first->items()->count());
        $this->assertSame($firstItemTkp, (string) $first->items()->firstOrFail()->list_tkp);
        $this->assertSame(2, OaTkpPriceList::query()->where('year', 2027)->count());
    }

    #[Test]
    public function targeting_list_and_min_surcharge_new_revision_and_audit(): void
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
            ->postJson(route('administration.oa-tkp.targetings.store'), [
                'code' => 'geo',
                'name' => 'Geo',
                'category' => OaTargetingCategory::Technisch->value,
                'tkp_surcharge' => '15.0000',
                'minimum_tkp_surcharge' => '3.0000',
                'revision_number' => 1,
                'is_active' => true,
            ])
            ->assertCreated();

        $this->actingAs($admin)
            ->postJson(route('administration.oa-tkp.targetings.store'), [
                'code' => 'dmp',
                'name' => 'DMP bad',
                'category' => OaTargetingCategory::Dmp->value,
                'tkp_surcharge' => '5.0000',
                'minimum_tkp_surcharge' => '25.0000',
                'revision_number' => 2,
            ])
            ->assertStatus(422);

        $rev = $this->actingAs($admin)
            ->postJson(route('administration.oa-tkp.targetings.revisions.store', ['code' => 'dmp']), [
                'tkp_surcharge' => '26.0000',
                'minimum_tkp_surcharge' => '6.0000',
                'deactivate_previous' => true,
            ])
            ->assertCreated()
            ->json('targeting');

        $this->assertSame(2, (int) $rev['revision_number']);
        $this->assertFalse((bool) OaTargetingDefinition::query()->where('code', 'dmp')->where('revision_number', 1)->value('is_active'));
        $this->assertTrue(AuditEvent::query()->where('action', 'oa_targeting_definition.created')->exists());

        $def = OaTargetingDefinition::query()->where('code', 'dmp')->where('revision_number', 2)->firstOrFail();
        $this->actingAs($admin)
            ->getJson(route('administration.oa-tkp.targetings.history', $def))
            ->assertOk();
    }

    #[Test]
    public function platform_activate_deactivate_no_hard_delete_and_audit(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();

        $created = $this->actingAs($admin)
            ->postJson(route('administration.oa-tkp.platforms.store'), [
                'code' => 'test_plat_d4c4c',
                'name' => 'Test Platform',
                'sort' => 10,
                'is_active' => true,
            ])
            ->assertCreated()
            ->json('platform');

        $platform = OaPlatform::query()->findOrFail((int) $created['id']);

        $this->actingAs($admin)
            ->postJson(route('administration.oa-tkp.platforms.deactivate', $platform))
            ->assertOk()
            ->assertJsonPath('platform.is_active', false);

        $this->actingAs($admin)
            ->postJson(route('administration.oa-tkp.platforms.activate', $platform))
            ->assertOk()
            ->assertJsonPath('platform.is_active', true);

        $this->actingAs($admin)
            ->deleteJson(route('administration.oa-tkp.platforms.destroy', $platform))
            ->assertStatus(405);

        $this->assertSame(1, OaPlatform::query()->where('code', 'test_plat_d4c4c')->count());
        $this->assertTrue(AuditEvent::query()->where('action', 'oa_platform.created')->exists());
        $this->assertTrue(AuditEvent::query()->where('auditable_type', OaPlatform::class)->where('auditable_id', $platform->id)->exists());
    }

    #[Test]
    public function registry_remains_planned_and_cli_activate_still_works(): void
    {
        $this->assertSame(EngineCapabilityStatus::Planned, EngineProfileRegistry::pairStatus('oa_tkp', 'tkp'));
        $this->assertFalse(OaTkpWizardCapability::allowsHttpMutation());

        $this->seed(InitialCatalogMatCoreSeeder::class);
        $path = sys_get_temp_dir().'/oa-d4c4c-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode([
            'year' => 2026,
            'revision_number' => 1,
            'status' => 'draft',
            'name' => 'CLI still ok',
            'version' => '2026.1',
            'items' => [[
                'medium_code' => 'pre_stream',
                'inventory_code' => 'inv_more_kombi_online_audio',
                'list_tkp' => '12.0000',
                'minimum_tkp' => '8.0000',
            ]],
        ], JSON_THROW_ON_ERROR));

        $this->assertSame(0, Artisan::call('oa:import-price-list', ['path' => $path]));
        $this->assertSame(0, Artisan::call('oa:activate-price-list', [
            '--year' => 2026,
            '--revision' => 1,
        ]));
        @unlink($path);
    }

    /**
     * @return array<string, mixed>
     */
    private function minimalDraftPayload(int $year = 2027): array
    {
        return [
            'year' => $year,
            'name' => "OA Draft {$year}",
            'items' => [[
                'medium_code' => 'pre_stream',
                'inventory_code' => 'inv_more_kombi_online_audio',
                'list_tkp' => '56.0000',
                'minimum_tkp' => '14.0000',
            ]],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function completeDraftPayload(
        int $year,
        string $name = 'OA complete',
        string $listTkp = '56.0000',
        string $minimumTkp = '14.0000',
    ): array {
        return [
            'year' => $year,
            'name' => $name,
            'items' => $this->completeItems($listTkp, $minimumTkp),
        ];
    }

    /**
     * @return list<array{medium_code: string, inventory_code: string, list_tkp: string, minimum_tkp: string}>
     */
    private function completeItems(string $listTkp, string $minimumTkp): array
    {
        $items = [];
        foreach (OaTkpActiveMediumInventoryPairs::all() as $pair) {
            $items[] = [
                'medium_code' => $pair['medium_code'],
                'inventory_code' => $pair['inventory_code'],
                'list_tkp' => $listTkp,
                'minimum_tkp' => $minimumTkp,
            ];
        }
        $this->assertGreaterThanOrEqual(41, count($items));

        return $items;
    }
}
