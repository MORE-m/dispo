<?php

namespace Tests\Feature\OnlineAudio\Admin;

use App\Enums\EngineCapabilityStatus;
use App\Enums\OaTargetingCategory;
use App\Enums\PriceListStatus;
use App\Enums\Role;
use App\Models\OaPlatform;
use App\Models\OaTkpPriceList;
use App\Models\User;
use App\Support\Calculation\EngineProfileRegistry;
use App\Support\Calculation\OaTkpWizardCapability;
use App\Support\OnlineAudio\OaTkpActiveMediumInventoryPairs;
use Database\Seeders\InitialCatalogMatCoreSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\EnsuresOaTkpInventoryMediumRules;
use Tests\TestCase;

/**
 * PO-CALC-TKP-1D4c-4d: OA-TKP Admin-Oberfläche (Inertia) + Rechte.
 */
class PoCalcTkp1d4c4dAdminUiTest extends TestCase
{
    use EnsuresOaTkpInventoryMediumRules;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        OaTkpWizardCapability::clearHttpMutationFakeForTests();
        parent::tearDown();
    }

    #[Test]
    public function admin_sees_online_audio_hub_and_pages_management_does_not(): void
    {
        $this->seed(InitialCatalogMatCoreSeeder::class);
        $this->ensureDefaultOaPriceListPairRule();

        $admin = User::factory()->role(Role::Admin)->create();
        $management = User::factory()->role(Role::Management)->create();

        $this->assertTrue($admin->can('manage-oa-tkp-master-data'));
        $this->assertFalse($management->can('manage-oa-tkp-master-data'));

        $this->actingAs($admin)
            ->get(route('administration.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('modules', function ($modules): bool {
                    foreach ($modules as $module) {
                        if (data_get($module, 'key') !== 'online-audio') {
                            continue;
                        }

                        return data_get($module, 'available') === true
                            && data_get($module, 'href') === '/administration/online-audio';
                    }

                    return false;
                }));

        $this->actingAs($management)
            ->get(route('administration.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('modules', function ($modules): bool {
                    foreach ($modules as $module) {
                        if (data_get($module, 'key') !== 'online-audio') {
                            continue;
                        }

                        return data_get($module, 'available') === false
                            && data_get($module, 'href') === null;
                    }

                    return false;
                }));

        $this->actingAs($admin)
            ->get(route('administration.oa-tkp.hub'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('administration/online-audio/index')
                ->has('sections', 3));

        $this->actingAs($management)
            ->get(route('administration.oa-tkp.hub'))
            ->assertForbidden();

        foreach ([
            'administration.oa-tkp.price-lists.index',
            'administration.oa-tkp.price-lists.create',
            'administration.oa-tkp.targetings.index',
            'administration.oa-tkp.platforms.index',
        ] as $routeName) {
            $this->actingAs($admin)
                ->get(route($routeName))
                ->assertOk();
            $this->actingAs($management)
                ->get(route($routeName))
                ->assertForbidden();
        }
    }

    #[Test]
    public function price_list_show_exposes_editable_only_for_draft_and_siblings(): void
    {
        $this->seed(InitialCatalogMatCoreSeeder::class);
        $this->ensureDefaultOaPriceListPairRule();
        $admin = User::factory()->role(Role::Admin)->create();

        $created = $this->actingAs($admin)
            ->postJson(route('administration.oa-tkp.price-lists.store'), $this->completeDraftPayload(2027))
            ->assertCreated()
            ->json('price_list');
        $draft = OaTkpPriceList::query()->findOrFail((int) $created['id']);

        $this->actingAs($admin)
            ->get(route('administration.oa-tkp.price-lists.show', $draft))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('administration/online-audio/price-lists/show')
                ->where('priceList.status', 'draft')
                ->where('priceList.editable', true)
                ->where('priceList.status_label', 'Entwurf')
                ->has('requiredPairs')
                ->has('siblingRevisions', 1)
                ->has('routes.update')
                ->has('routes.activate'));

        $this->actingAs($admin)
            ->postJson(route('administration.oa-tkp.price-lists.activate', $draft), [
                'lock_version' => (int) $draft->lock_version,
            ])
            ->assertOk();

        $active = $draft->fresh();
        $this->assertSame(PriceListStatus::Active, $active->status);

        $this->actingAs($admin)
            ->get(route('administration.oa-tkp.price-lists.show', $active))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('priceList.status', 'active')
                ->where('priceList.editable', false)
                ->where('priceList.status_label', 'Aktiv'));

        $this->actingAs($admin)
            ->putJson(route('administration.oa-tkp.price-lists.update', $active), [
                'name' => 'should fail',
                'lock_version' => (int) $active->lock_version,
                'items' => [[
                    'medium_code' => 'pre_stream',
                    'inventory_code' => 'inv_more_kombi_online_audio',
                    'list_tkp' => '56.0000',
                    'minimum_tkp' => '14.0000',
                ]],
            ])
            ->assertStatus(422);
    }

    #[Test]
    public function invalid_decimals_and_minimum_above_list_are_rejected_server_side(): void
    {
        $this->seed(InitialCatalogMatCoreSeeder::class);
        $this->ensureDefaultOaPriceListPairRule();
        $admin = User::factory()->role(Role::Admin)->create();

        $this->actingAs($admin)
            ->postJson(route('administration.oa-tkp.price-lists.store'), [
                'year' => 2027,
                'name' => 'Bad decimal',
                'items' => [[
                    'medium_code' => 'pre_stream',
                    'inventory_code' => 'inv_more_kombi_online_audio',
                    'list_tkp' => 'abc',
                    'minimum_tkp' => '14.0000',
                ]],
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['message', 'errors', 'field_errors']);

        $created = $this->actingAs($admin)
            ->postJson(route('administration.oa-tkp.price-lists.store'), [
                'year' => 2027,
                'name' => 'Min above list',
                'items' => [[
                    'medium_code' => 'pre_stream',
                    'inventory_code' => 'inv_more_kombi_online_audio',
                    'list_tkp' => '10.0000',
                    'minimum_tkp' => '20.0000',
                ]],
            ]);

        // Mindest > Liste: entweder Create-422 oder später Validate/Update – Vertrag serverseitig.
        if ($created->status() === 201) {
            $list = OaTkpPriceList::query()->findOrFail((int) $created->json('price_list.id'));
            $this->actingAs($admin)
                ->postJson(route('administration.oa-tkp.price-lists.validate', $list), [
                    'require_complete' => false,
                ])
                ->assertOk();
            $this->actingAs($admin)
                ->putJson(route('administration.oa-tkp.price-lists.update', $list), [
                    'name' => 'Min above list',
                    'lock_version' => (int) $list->lock_version,
                    'items' => [[
                        'medium_code' => 'pre_stream',
                        'inventory_code' => 'inv_more_kombi_online_audio',
                        'list_tkp' => '10.0000',
                        'minimum_tkp' => '20.0000',
                    ]],
                ])
                ->assertStatus(422);
        } else {
            $created->assertStatus(422);
        }
    }

    #[Test]
    public function incomplete_matrix_blocks_activation_and_dry_run_exposes_archive_preview(): void
    {
        $this->seed(InitialCatalogMatCoreSeeder::class);
        $this->ensureOaInventoryMediumRules([
            ['pre_stream', 'inv_more_kombi_online_audio'],
            ['in_stream', 'inv_more_kombi_online_audio'],
        ]);
        $admin = User::factory()->role(Role::Admin)->create();

        $partial = $this->actingAs($admin)
            ->postJson(route('administration.oa-tkp.price-lists.store'), [
                'year' => 2027,
                'name' => 'Partial',
                'items' => [[
                    'medium_code' => 'pre_stream',
                    'inventory_code' => 'inv_more_kombi_online_audio',
                    'list_tkp' => '56.0000',
                    'minimum_tkp' => '14.0000',
                ]],
            ])
            ->assertCreated()
            ->json('price_list');
        $partialList = OaTkpPriceList::query()->findOrFail((int) $partial['id']);

        $validate = $this->actingAs($admin)
            ->postJson(route('administration.oa-tkp.price-lists.validate', $partialList), [
                'require_complete' => true,
            ])
            ->assertOk()
            ->json();
        $this->assertFalse($validate['valid'] ?? true);
        $this->assertFalse($validate['complete'] ?? true);
        $this->assertNotEmpty($validate['missing_pairs'] ?? []);

        $this->actingAs($admin)
            ->postJson(route('administration.oa-tkp.price-lists.activate', $partialList), [
                'lock_version' => (int) $partialList->lock_version,
                'dry_run' => true,
            ])
            ->assertStatus(422);

        $first = $this->actingAs($admin)
            ->postJson(route('administration.oa-tkp.price-lists.store'), $this->completeDraftPayload(2027, 'Full 1'))
            ->assertCreated()
            ->json('price_list');
        $firstList = OaTkpPriceList::query()->findOrFail((int) $first['id']);

        $this->actingAs($admin)
            ->postJson(route('administration.oa-tkp.price-lists.activate', $firstList), [
                'lock_version' => (int) $firstList->lock_version,
            ])
            ->assertOk();

        $second = $this->actingAs($admin)
            ->postJson(route('administration.oa-tkp.price-lists.store'), $this->completeDraftPayload(2027, 'Full 2'))
            ->assertCreated()
            ->json('price_list');
        $secondList = OaTkpPriceList::query()->findOrFail((int) $second['id']);

        $preview = $this->actingAs($admin)
            ->postJson(route('administration.oa-tkp.price-lists.activate', $secondList), [
                'lock_version' => (int) $secondList->lock_version,
                'dry_run' => true,
            ])
            ->assertOk()
            ->json('result');

        $this->assertContains((int) $firstList->id, $preview['archived_previous_ids'] ?? []);
        $this->assertTrue($preview['dry_run'] ?? false);
    }

    #[Test]
    public function targeting_and_platform_inertia_pages_and_mutations_work(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();

        $this->actingAs($admin)
            ->get(route('administration.oa-tkp.targetings.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('administration/online-audio/targetings/index')
                ->has('categories')
                ->has('notes'));

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
            ->postJson(route('administration.oa-tkp.targetings.revisions.store', ['code' => 'geo']), [
                'tkp_surcharge' => '16.0000',
                'minimum_tkp_surcharge' => '3.5000',
                'deactivate_previous' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('targeting.revision_number', 2);

        $this->actingAs($admin)
            ->get(route('administration.oa-tkp.platforms.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('administration/online-audio/platforms/index')
                ->has('notes'));

        $platformId = (int) $this->actingAs($admin)
            ->postJson(route('administration.oa-tkp.platforms.store'), [
                'code' => 'spotify_ui_test',
                'name' => 'Spotify UI',
                'is_active' => true,
            ])
            ->assertCreated()
            ->json('platform.id');

        $platform = OaPlatform::query()->findOrFail($platformId);
        $this->actingAs($admin)
            ->putJson(route('administration.oa-tkp.platforms.update', $platform), [
                'name' => 'Spotify UI renamed',
                'is_active' => true,
                'sort' => 10,
            ])
            ->assertOk()
            ->assertJsonPath('platform.name', 'Spotify UI renamed');
    }

    #[Test]
    public function registry_remains_planned_and_json_contract_unchanged_for_api_clients(): void
    {
        $this->seed(InitialCatalogMatCoreSeeder::class);
        $this->ensureDefaultOaPriceListPairRule();
        $admin = User::factory()->role(Role::Admin)->create();

        $this->actingAs($admin)
            ->getJson(route('administration.oa-tkp.price-lists.index'))
            ->assertOk()
            ->assertJsonStructure(['price_lists', 'required_pairs', 'filters']);

        $this->assertSame(EngineCapabilityStatus::Planned, EngineProfileRegistry::pairStatus('oa_tkp', 'tkp'));
        $this->assertFalse(OaTkpWizardCapability::allowsHttpMutation());
    }

    /**
     * @return array<string, mixed>
     */
    private function completeDraftPayload(int $year = 2027, string $name = 'OA Complete'): array
    {
        $pairs = OaTkpActiveMediumInventoryPairs::all();
        $this->assertNotEmpty($pairs, 'Test benötigt operative Medium×Inventar-Regeln.');

        $items = [];
        foreach ($pairs as $pair) {
            $medium = $pair['medium_code'];
            [$list, $min] = match ($medium) {
                'pre_stream', 'pre_stream_influencer' => ['56.0000', '14.0000'],
                'in_stream', 'in_stream_influencer' => ['44.0000', '8.0000'],
                'midroll_music_environment' => ['80.0000', '20.0000'],
                default => ['56.0000', '14.0000'],
            };
            $items[] = [
                'medium_code' => $medium,
                'inventory_code' => $pair['inventory_code'],
                'list_tkp' => $list,
                'minimum_tkp' => $min,
            ];
        }

        return [
            'year' => $year,
            'name' => $name,
            'items' => $items,
        ];
    }
}
