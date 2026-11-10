<?php

namespace Tests\Feature\OnlineAudio\Admin;

use App\Enums\EngineCapabilityStatus;
use App\Enums\Role;
use App\Models\AdvertisingMedium;
use App\Models\AuditEvent;
use App\Models\CalculationPositionOaPlatformAllocation;
use App\Models\OaPlatform;
use App\Models\OaPlatformMediumRule;
use App\Models\OaTkpPriceList;
use App\Models\User;
use App\Support\Calculation\EngineProfileRegistry;
use App\Support\OnlineAudio\OaPlatformMediumRules;
use App\Support\OnlineAudio\OaTkpCoreMedia;
use Database\Seeders\InitialCatalogMatCoreSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * PO-CALC-TKP-1D4c-4j-2/3: Admin-Matrix Plattform × OA-Kernmedium.
 */
class PoCalcTkp1d4c4j2PlatformMediumRuleAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(InitialCatalogMatCoreSeeder::class);
    }

    #[Test]
    public function guests_are_unauthorized_and_non_admins_are_forbidden(): void
    {
        $this->getJson(route('administration.oa-tkp.platform-medium-rules.index'))
            ->assertUnauthorized();
        $this->putJson(route('administration.oa-tkp.platform-medium-rules.update'), [])
            ->assertUnauthorized();
        $this->deleteJson(route('administration.oa-tkp.platform-medium-rules.destroy'))
            ->assertUnauthorized();
        $this->getJson(route('administration.oa-tkp.platform-medium-rules.history'))
            ->assertUnauthorized();

        foreach ([Role::Management, Role::Sales, Role::Disposition, Role::ProductManagement] as $role) {
            $user = User::factory()->role($role)->create();
            $this->actingAs($user)
                ->getJson(route('administration.oa-tkp.platform-medium-rules.index'))
                ->assertForbidden();
            $this->actingAs($user)
                ->get(route('administration.oa-tkp.platform-medium-rules.index'))
                ->assertForbidden();
            $this->actingAs($user)
                ->putJson(route('administration.oa-tkp.platform-medium-rules.update'), [
                    'revision_token' => str_repeat('a', 64),
                    'cells' => [],
                ])
                ->assertForbidden();
            $this->actingAs($user)
                ->deleteJson(route('administration.oa-tkp.platform-medium-rules.destroy'))
                ->assertForbidden();
            $this->actingAs($user)
                ->getJson(route('administration.oa-tkp.platform-medium-rules.history'))
                ->assertForbidden();
        }
    }

    #[Test]
    public function admin_reads_exactly_five_core_media_without_native_ad_or_invented_platforms(): void
    {
        $admin = $this->admin();
        $later = $this->platform('plat_j23_later', 'Später', 20);
        $earlyB = $this->platform('plat_j23_b', 'B', 10);
        $earlyA = $this->platform('plat_j23_a', 'A', 10);

        $matrix = $this->matrix($admin);

        $this->assertSame(OaTkpCoreMedia::codes(), array_column($matrix['media'], 'code'));
        $this->assertNotContains('native_ad', array_column($matrix['media'], 'code'));
        foreach ($matrix['media'] as $medium) {
            $stored = AdvertisingMedium::query()->where('code', $medium['code'])->firstOrFail();
            $this->assertSame((int) $stored->id, $medium['id']);
            $this->assertSame((string) $stored->name, $medium['name']);
        }

        $this->assertSame(
            [$earlyA->code, $earlyB->code, $later->code],
            array_column($matrix['platforms'], 'code'),
        );
        foreach ($matrix['platforms'] as $platform) {
            $this->assertCount(5, $platform['cells']);
            foreach ($platform['cells'] as $index => $cell) {
                $this->assertSame(OaTkpCoreMedia::codes()[$index], $cell['medium_code']);
                $this->assertFalse($cell['is_active']);
                $this->assertNull($cell['rule_id']);
            }
        }
        $this->assertSame(0, OaPlatformMediumRule::query()->count());
        $this->assertNotContains('spotify', array_column($matrix['platforms'], 'code'));
        $this->assertNotContains('youtube', array_column($matrix['platforms'], 'code'));
        $this->assertNotContains('deezer', array_column($matrix['platforms'], 'code'));
    }

    #[Test]
    public function empty_platform_list_returns_media_and_creates_no_rules(): void
    {
        $matrix = $this->matrix($this->admin());

        $this->assertSame([], $matrix['platforms']);
        $this->assertCount(5, $matrix['media']);
        $this->assertNotSame('', $matrix['revision_token']);
        $this->assertSame(0, OaPlatformMediumRule::query()->count());
        $this->assertSame('Noch keine Plattformen angelegt.', $matrix['notes']['empty_platforms']);
    }

    #[Test]
    public function inertia_page_exposes_initial_cells_empty_state_and_hides_non_admins(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->get(route('administration.oa-tkp.platform-medium-rules.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('administration/online-audio/platform-medium-rules/index')
                ->has('media', 5)
                ->has('platforms', 0)
                ->where('media.0.code', 'pre_stream')
                ->where('notes.empty_platforms', 'Noch keine Plattformen angelegt.')
                ->missing('platforms.0'));

        $platform = $this->platform('plat_j23_ui', 'UI Plattform', 1);
        $preStream = AdvertisingMedium::query()->where('code', 'pre_stream')->firstOrFail();
        $rule = OaPlatformMediumRule::query()->create([
            'oa_platform_id' => $platform->id,
            'advertising_medium_id' => $preStream->id,
            'is_active' => true,
        ]);

        $this->actingAs($admin)
            ->get(route('administration.oa-tkp.platform-medium-rules.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('platforms', 1)
                ->where('platforms.0.code', 'plat_j23_ui')
                ->where('platforms.0.cells.0.medium_code', 'pre_stream')
                ->where('platforms.0.cells.0.is_active', true)
                ->where('platforms.0.cells.0.rule_id', $rule->id)
                ->where('platforms.0.cells.1.is_active', false)
                ->where('platforms.0.cells.1.rule_id', null));

        $management = User::factory()->role(Role::Management)->create();
        $this->actingAs($management)
            ->get(route('administration.oa-tkp.platform-medium-rules.index'))
            ->assertForbidden();
    }

    #[Test]
    public function activating_deactivating_and_reactivating_reuses_the_unique_pair(): void
    {
        $admin = $this->admin();
        $platform = $this->platform('plat_j23_pair', 'Paar', 1);
        $matrix = $this->matrix($admin);

        $created = $this->putMatrix($admin, $matrix, [
            $platform->id.'|pre_stream' => true,
        ])->assertOk();
        $created->assertJsonPath('message', 'Matrix gespeichert.');

        $rule = OaPlatformMediumRule::query()->firstOrFail();
        $this->assertTrue($rule->is_active);
        $this->assertSame(1, OaPlatformMediumRule::query()->count());

        $activeMatrix = $created->json();
        $this->putMatrix($admin, $activeMatrix, [
            $platform->id.'|pre_stream' => false,
        ])->assertOk()->assertJsonPath('message', 'Matrix gespeichert.');

        $rule->refresh();
        $this->assertFalse($rule->is_active);
        $this->assertSame(1, OaPlatformMediumRule::query()->count());

        $inactiveMatrix = $this->matrix($admin);
        $this->putMatrix($admin, $inactiveMatrix, [
            $platform->id.'|pre_stream' => true,
        ])->assertOk();

        $this->assertSame(1, OaPlatformMediumRule::query()->count());
        $this->assertTrue($rule->fresh()->is_active);
        $this->assertSame($rule->id, OaPlatformMediumRule::query()->value('id'));
    }

    #[Test]
    public function invalid_payloads_roll_back_without_rules_or_audit(): void
    {
        $admin = $this->admin();
        $platform = $this->platform('plat_j23_invalid', 'Ungültig', 1);
        $preStream = AdvertisingMedium::query()->where('code', 'pre_stream')->firstOrFail();
        $existing = OaPlatformMediumRule::query()->create([
            'oa_platform_id' => $platform->id,
            'advertising_medium_id' => $preStream->id,
            'is_active' => true,
        ]);
        $matrix = $this->matrix($admin);
        $auditsBefore = AuditEvent::query()->count();

        $missing = $this->cellsFrom($matrix);
        array_pop($missing);
        $this->putMatrix($admin, $matrix, null, $missing)->assertStatus(422);

        $duplicate = $this->cellsFrom($matrix);
        $duplicate[] = $duplicate[0];
        $this->putMatrix($admin, $matrix, null, $duplicate)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['cells.5']);

        $unknownPlatform = $this->cellsFrom($matrix);
        $unknownPlatform[0]['oa_platform_id'] = 9_999_999;
        $this->putMatrix($admin, $matrix, null, $unknownPlatform)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['cells.0.oa_platform_id']);

        $unknownMedium = $this->cellsFrom($matrix);
        $unknownMedium[1]['medium_code'] = 'not_a_medium';
        $this->putMatrix($admin, $matrix, null, $unknownMedium)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['advertising_medium_id']);

        $native = $this->cellsFrom($matrix);
        $native[2]['medium_code'] = 'native_ad';
        $this->putMatrix($admin, $matrix, null, $native)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['advertising_medium_id']);

        $this->actingAs($admin)
            ->putJson(route('administration.oa-tkp.platform-medium-rules.update'), [
                'cells' => $this->cellsFrom($matrix),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['revision_token']);

        $existing->refresh();
        $this->assertTrue($existing->is_active);
        $this->assertSame(1, OaPlatformMediumRule::query()->count());
        $this->assertSame($auditsBefore, AuditEvent::query()->count());
    }

    #[Test]
    public function inactive_platform_can_store_a_rule_without_becoming_actively_assigned(): void
    {
        $admin = $this->admin();
        $platform = $this->platform('plat_j23_off', 'Inaktiv', 1, false);
        $matrix = $this->matrix($admin);
        $this->assertFalse($matrix['platforms'][0]['is_active']);

        $this->putMatrix($admin, $matrix, [
            $platform->id.'|in_stream' => true,
        ])->assertOk()->assertJsonPath('message', 'Matrix gespeichert.');

        $mediumId = (int) AdvertisingMedium::query()->where('code', 'in_stream')->value('id');
        $this->assertSame(1, OaPlatformMediumRule::query()->count());
        $this->assertTrue(OaPlatformMediumRule::query()->firstOrFail()->is_active);
        $this->assertFalse(OaPlatformMediumRules::isActivelyAssigned((int) $platform->id, $mediumId));
    }

    #[Test]
    public function noop_put_keeps_token_rules_and_audit_unchanged(): void
    {
        $admin = $this->admin();
        $platform = $this->platform('plat_j23_noop', 'Noop', 1);
        $matrix = $this->matrix($admin);

        $this->putMatrix($admin, $matrix)
            ->assertOk()
            ->assertJsonPath('message', 'Keine Änderungen.')
            ->assertJsonPath('revision_token', $matrix['revision_token']);

        $this->assertSame(0, OaPlatformMediumRule::query()->count());
        $this->assertSame(0, AuditEvent::query()->where('auditable_type', OaPlatformMediumRule::class)->count());
        $this->assertSame($matrix['revision_token'], $this->matrix($admin)['revision_token']);

        $saved = $this->putMatrix($admin, $matrix, [
            $platform->id.'|midroll_music_environment' => true,
        ])->assertOk()->json();
        $audits = AuditEvent::query()->where('auditable_type', OaPlatformMediumRule::class)->count();

        $this->putMatrix($admin, $saved)
            ->assertOk()
            ->assertJsonPath('message', 'Keine Änderungen.')
            ->assertJsonPath('revision_token', $saved['revision_token']);

        $this->assertSame($audits, AuditEvent::query()->where('auditable_type', OaPlatformMediumRule::class)->count());
        $this->assertSame(1, OaPlatformMediumRule::query()->count());
        $this->assertSame($saved['revision_token'], $this->matrix($admin)['revision_token']);
    }

    #[Test]
    public function create_and_update_are_audited_with_the_admin_as_actor(): void
    {
        $admin = $this->admin();
        $platform = $this->platform('plat_j23_audit', 'Audit', 1);
        $matrix = $this->matrix($admin);

        $this->putMatrix($admin, $matrix, [
            $platform->id.'|pre_stream' => true,
        ])->assertOk();

        $rule = OaPlatformMediumRule::query()->firstOrFail();
        $created = AuditEvent::query()->where('action', 'oa_platform_medium_rule.created')->firstOrFail();
        $this->assertSame($admin->id, $created->user_id);
        $this->assertNull($created->old_values);
        $this->assertSame($rule->id, $created->new_values['id']);
        $this->assertSame($platform->id, $created->new_values['oa_platform_id']);
        $this->assertSame('pre_stream', $created->new_values['medium_code']);
        $this->assertTrue($created->new_values['is_active']);

        $afterCreate = $this->matrix($admin);
        $this->putMatrix($admin, $afterCreate, [
            $platform->id.'|pre_stream' => false,
        ])->assertOk();

        $updated = AuditEvent::query()->where('action', 'oa_platform_medium_rule.updated')->firstOrFail();
        $this->assertSame($admin->id, $updated->user_id);
        $this->assertTrue($updated->old_values['is_active']);
        $this->assertFalse($updated->new_values['is_active']);
        $this->assertSame($rule->id, $updated->new_values['id']);
        $this->assertSame('pre_stream', $updated->new_values['medium_code']);
        $this->assertSame(1, AuditEvent::query()->where('action', 'oa_platform_medium_rule.created')->count());
        $this->assertSame(1, AuditEvent::query()->where('action', 'oa_platform_medium_rule.updated')->count());
    }

    #[Test]
    public function stale_token_after_rule_change_returns_conflict_without_mutation(): void
    {
        $admin = $this->admin();
        $platform = $this->platform('plat_j23_drift', 'Drift', 1);
        $matrix = $this->matrix($admin);
        $preStream = AdvertisingMedium::query()->where('code', 'pre_stream')->firstOrFail();
        OaPlatformMediumRule::query()->create([
            'oa_platform_id' => $platform->id,
            'advertising_medium_id' => $preStream->id,
            'is_active' => true,
        ]);

        $this->putMatrix($admin, $matrix)
            ->assertStatus(409)
            ->assertJsonPath('message', 'Die Zuordnungsmatrix wurde parallel geändert. Bitte neu laden.');

        $this->assertSame(1, OaPlatformMediumRule::query()->count());
        $this->assertTrue(OaPlatformMediumRule::query()->firstOrFail()->is_active);
        $this->assertSame(0, AuditEvent::query()->where('auditable_type', OaPlatformMediumRule::class)->count());
    }

    #[Test]
    public function stale_token_after_new_platform_returns_conflict_without_mutation(): void
    {
        $admin = $this->admin();
        $this->platform('plat_j23_base', 'Basis', 1);
        $matrix = $this->matrix($admin);
        $added = $this->platform('plat_j23_added', 'Neu', 2);

        $this->putMatrix($admin, $matrix)
            ->assertStatus(409)
            ->assertJsonPath('message', 'Die Zuordnungsmatrix wurde parallel geändert. Bitte neu laden.');

        $this->assertNotNull($added->fresh());
        $this->assertSame(0, OaPlatformMediumRule::query()->count());
        $this->assertSame(0, AuditEvent::query()->where('auditable_type', OaPlatformMediumRule::class)->count());
    }

    #[Test]
    public function platform_name_sort_and_active_flag_do_not_change_the_revision_token(): void
    {
        $admin = $this->admin();
        $platform = $this->platform('plat_j23_meta', 'Alt', 1, true);
        $token = $this->matrix($admin)['revision_token'];

        $platform->update([
            'name' => 'Neu',
            'sort' => 50,
            'is_active' => false,
        ]);

        $this->assertSame($token, $this->matrix($admin)['revision_token']);
    }

    #[Test]
    public function delete_is_rejected_and_history_returns_only_the_latest_rule_events(): void
    {
        $admin = $this->admin();
        $platform = $this->platform('plat_j23_hist', 'Historie', 1);
        $preStream = AdvertisingMedium::query()->where('code', 'pre_stream')->firstOrFail();
        $rule = OaPlatformMediumRule::query()->create([
            'oa_platform_id' => $platform->id,
            'advertising_medium_id' => $preStream->id,
            'is_active' => true,
        ]);

        $this->actingAs($admin)
            ->deleteJson(route('administration.oa-tkp.platform-medium-rules.destroy'))
            ->assertStatus(405)
            ->assertJsonPath('message', 'Hard-Delete ist für Plattform-Medien-Zuordnungen nicht erlaubt.');
        $this->assertNotNull($rule->fresh());

        $newestId = null;
        for ($i = 0; $i < 101; $i++) {
            $event = AuditEvent::query()->create([
                'auditable_type' => OaPlatformMediumRule::class,
                'auditable_id' => $rule->id,
                'action' => 'oa_platform_medium_rule.updated',
                'old_values' => ['is_active' => true],
                'new_values' => ['is_active' => false, 'sequence' => $i],
                'user_id' => $admin->id,
                'correlation_id' => 'hist-'.$i,
                'created_at' => now(),
            ]);
            $newestId = (int) $event->id;
        }
        $other = AuditEvent::query()->create([
            'auditable_type' => OaPlatform::class,
            'auditable_id' => $platform->id,
            'action' => 'oa_platform.updated',
            'old_values' => ['name' => 'Historie'],
            'new_values' => ['name' => 'Anders'],
            'user_id' => $admin->id,
            'correlation_id' => 'platform-other',
            'created_at' => now(),
        ]);

        $history = $this->actingAs($admin)
            ->getJson(route('administration.oa-tkp.platform-medium-rules.history'))
            ->assertOk()
            ->json();

        $this->assertSame(OaPlatformMediumRule::class, $history['auditable_type']);
        $this->assertCount(100, $history['events']);
        $this->assertSame($newestId, $history['events'][0]['id']);
        $this->assertSame(100, $history['events'][0]['new_values']['sequence']);
        $ids = array_column($history['events'], 'id');
        $this->assertNotContains((int) $other->id, $ids);
    }

    #[Test]
    public function saving_rules_does_not_change_price_lists_allocations_or_registry(): void
    {
        $admin = $this->admin();
        $platform = $this->platform('plat_j23_scope', 'Scope', 1);
        $priceLists = OaTkpPriceList::query()->count();
        $allocations = CalculationPositionOaPlatformAllocation::query()->count();
        $matrix = $this->matrix($admin);

        $this->putMatrix($admin, $matrix, [
            $platform->id.'|pre_stream_influencer' => true,
        ])->assertOk();

        $this->assertSame($priceLists, OaTkpPriceList::query()->count());
        $this->assertSame($allocations, CalculationPositionOaPlatformAllocation::query()->count());
        $this->assertSame(
            EngineCapabilityStatus::Released,
            EngineProfileRegistry::pairStatus('oa_tkp', 'tkp'),
        );
    }

    private function admin(): User
    {
        return User::factory()->role(Role::Admin)->create();
    }

    private function platform(string $code, string $name, int $sort, bool $active = true): OaPlatform
    {
        return OaPlatform::query()->create([
            'code' => $code,
            'name' => $name,
            'is_active' => $active,
            'sort' => $sort,
            'is_adserver_relevant' => false,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function matrix(User $admin): array
    {
        $matrix = $this->actingAs($admin)
            ->getJson(route('administration.oa-tkp.platform-medium-rules.index'))
            ->assertOk()
            ->json();
        $this->assertIsArray($matrix);

        return $matrix;
    }

    /**
     * @param  array<string, mixed>  $matrix
     * @param  array<string, bool>|null  $overrides
     * @param  list<array<string, mixed>>|null  $cells
     */
    private function putMatrix(User $admin, array $matrix, ?array $overrides = null, ?array $cells = null): TestResponse
    {
        return $this->actingAs($admin)->putJson(route('administration.oa-tkp.platform-medium-rules.update'), [
            'revision_token' => $matrix['revision_token'],
            'cells' => $cells ?? $this->cellsFrom($matrix, $overrides ?? []),
        ]);
    }

    /**
     * @param  array<string, mixed>  $matrix
     * @param  array<string, bool>  $overrides
     * @return list<array{oa_platform_id: int, medium_code: string, is_active: bool}>
     */
    private function cellsFrom(array $matrix, array $overrides = []): array
    {
        $cells = [];
        foreach ($matrix['platforms'] as $platform) {
            foreach ($platform['cells'] as $cell) {
                $key = $platform['id'].'|'.$cell['medium_code'];
                $cells[] = [
                    'oa_platform_id' => $platform['id'],
                    'medium_code' => $cell['medium_code'],
                    'is_active' => $overrides[$key] ?? $cell['is_active'],
                ];
            }
        }

        return $cells;
    }
}
