<?php

namespace Tests\Feature\Advertising;

use App\Enums\CalculationKind;
use App\Enums\CalculationMethodMode;
use App\Enums\Role;
use App\Models\AdvertisingCategory;
use App\Models\AdvertisingCategoryCalculationMethod;
use App\Models\AdvertisingMedium;
use App\Models\AdvertisingMediumCalculationMethod;
use App\Models\AuditEvent;
use App\Models\CalculationMethod;
use App\Models\User;
use App\Services\Advertising\Admin\AdvertisingCategoryCalculationMethodImpactPreviewService;
use App\Support\Advertising\CanonicalAdvertisingCategories;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * ADV-001c3b2: Kategorie-Methoden Desired State (Preview/Apply).
 */
class CatalogAdminAdv001c3b2Test extends TestCase
{
    use RefreshDatabase;

    public function test_admin_allowed_sales_forbidden_on_preview_and_apply(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $sales = User::factory()->role(Role::Sales)->create();
        $spots = $this->spots();
        $payload = $this->buildDesiredFromCurrent($spots);

        $this->actingAs($admin)
            ->postJson(route('administration.catalog.categories.calculation-methods-preview', $spots), $payload)
            ->assertOk();

        $this->actingAs($sales)
            ->postJson(route('administration.catalog.categories.calculation-methods-preview', $spots), $payload)
            ->assertForbidden();

        $this->actingAs($sales)
            ->putJson(route('administration.catalog.categories.calculation-methods', $spots), array_merge($payload, [
                'fingerprint' => str_repeat('a', 64),
            ]))
            ->assertForbidden();
    }

    public function test_show_exposes_calculation_methods_and_routes(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $spots = $this->spots();

        $this->actingAs($admin)
            ->get(route('administration.catalog.categories.show', $spots))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('administration/katalog/categories/show')
                ->has('calculationMethods')
                ->where('boundaryNote', fn (string $note): bool => str_contains($note, 'Desired State'))
                ->has('routes.calculationMethodsPreview')
                ->has('routes.calculationMethodsReplace')
                ->where(
                    'calculationMethods',
                    fn ($rows): bool => collect($rows)->contains(fn (array $r): bool => $r['key'] === 'average'),
                ));
    }

    public function test_preview_without_fingerprint_ok_with_fingerprint_422(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $spots = $this->spots();
        $payload = $this->buildDesiredFromCurrent($spots);

        $this->actingAs($admin)
            ->postJson(route('administration.catalog.categories.calculation-methods-preview', $spots), $payload)
            ->assertOk()
            ->assertJsonPath('action', AdvertisingCategoryCalculationMethodImpactPreviewService::ACTION_CATEGORY_CALCULATION_METHODS_REPLACE)
            ->assertJsonPath('has_changes', false)
            ->assertJson(fn ($json) => $json->has('fingerprint')->etc());

        $this->actingAs($admin)
            ->postJson(route('administration.catalog.categories.calculation-methods-preview', $spots), array_merge($payload, [
                'fingerprint' => str_repeat('b', 64),
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('fingerprint');
    }

    public function test_apply_requires_fingerprint_64_hex(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $spots = $this->spots();
        $payload = $this->buildDesiredFromCurrent($spots);

        $this->actingAs($admin)
            ->putJson(route('administration.catalog.categories.calculation-methods', $spots), $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('fingerprint');

        $this->actingAs($admin)
            ->putJson(route('administration.catalog.categories.calculation-methods', $spots), array_merge($payload, [
                'fingerprint' => str_repeat('g', 64),
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('fingerprint');

        $this->actingAs($admin)
            ->putJson(route('administration.catalog.categories.calculation-methods', $spots), array_merge($payload, [
                'fingerprint' => str_repeat('a', 32),
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('fingerprint');
    }

    public function test_valid_desired_state_adds_tkp_active_prepared_with_null_profile(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $spots = $this->spots();
        $tkp = $this->method('tkp');
        $desired = $this->buildDesiredFromCurrent($spots);
        $desired['assignments'][] = [
            'calculation_method_id' => (int) $tkp->id,
            'is_active' => true,
            'sort' => 90,
        ];

        $preview = $this->previewPayload($spots, $desired);
        $this->assertTrue($preview['has_changes']);
        $this->assertTrue($preview['can_proceed']);

        $this->actingAs($admin)
            ->putJson(route('administration.catalog.categories.calculation-methods', $spots), array_merge($desired, [
                'fingerprint' => $preview['fingerprint'],
            ]))
            ->assertOk()
            ->assertJsonPath('has_changes', true)
            ->assertJsonPath('message', 'Berechnungsmethoden gespeichert.');

        $row = AdvertisingCategoryCalculationMethod::query()
            ->where('advertising_category_id', $spots->id)
            ->where('calculation_method_id', $tkp->id)
            ->firstOrFail();
        $this->assertTrue($row->is_active);
        $this->assertNull($row->engine_profile_key);
        $this->assertSame(90, (int) $row->sort);
    }

    public function test_noop_identical_state_no_lock_bump_no_audit(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $spots = $this->spots();
        $lockBefore = (int) $spots->lock_version;
        $desired = $this->buildDesiredFromCurrent($spots);
        $preview = $this->previewPayload($spots, $desired);
        $this->assertFalse($preview['has_changes']);

        $auditsBefore = AuditEvent::query()
            ->where('action', 'advertising_category.calculation_methods_replaced')
            ->count();

        $this->actingAs($admin)
            ->putJson(route('administration.catalog.categories.calculation-methods', $spots), array_merge($desired, [
                'fingerprint' => $preview['fingerprint'],
            ]))
            ->assertOk()
            ->assertJsonPath('has_changes', false)
            ->assertJsonPath('message', 'Keine Änderungen')
            ->assertJsonPath('lock_version', $lockBefore);

        $spots->refresh();
        $this->assertSame($lockBefore, (int) $spots->lock_version);
        $this->assertSame(
            $auditsBefore,
            AuditEvent::query()->where('action', 'advertising_category.calculation_methods_replaced')->count(),
        );
    }

    public function test_new_inactive_only_desired_row_is_not_created(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $spots = $this->spots();
        $tkp = $this->method('tkp');
        $desired = $this->buildDesiredFromCurrent($spots);
        $desired['assignments'][] = [
            'calculation_method_id' => (int) $tkp->id,
            'is_active' => false,
            'sort' => 99,
        ];

        $preview = $this->previewPayload($spots, $desired);
        $this->assertFalse($preview['has_changes']);

        $this->actingAs($admin)
            ->putJson(route('administration.catalog.categories.calculation-methods', $spots), array_merge($desired, [
                'fingerprint' => $preview['fingerprint'],
            ]))
            ->assertOk()
            ->assertJsonPath('has_changes', false);

        $this->assertFalse(
            AdvertisingCategoryCalculationMethod::query()
                ->where('advertising_category_id', $spots->id)
                ->where('calculation_method_id', $tkp->id)
                ->exists(),
        );
    }

    public function test_existing_engine_profile_key_unchanged_on_update_and_reactivate(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $spots = $this->spots();
        $calendar = $this->method('calendar');
        $assignment = AdvertisingCategoryCalculationMethod::query()
            ->where('advertising_category_id', $spots->id)
            ->where('calculation_method_id', $calendar->id)
            ->firstOrFail();
        $this->assertSame('spot_classic', $assignment->engine_profile_key);

        $desired = $this->buildDesiredFromCurrent($spots);
        foreach ($desired['assignments'] as &$row) {
            if ((int) $row['calculation_method_id'] === (int) $calendar->id) {
                $row['sort'] = (int) $row['sort'] + 5;
            }
        }
        unset($row);

        $preview = $this->previewPayload($spots, $desired);
        $this->actingAs($admin)
            ->putJson(route('administration.catalog.categories.calculation-methods', $spots), array_merge($desired, [
                'fingerprint' => $preview['fingerprint'],
            ]))
            ->assertOk();

        $assignment->refresh();
        $this->assertSame('spot_classic', $assignment->engine_profile_key);
        $this->assertTrue($assignment->is_active);

        $desiredOff = $this->buildDesiredFromCurrent($spots->fresh());
        foreach ($desiredOff['assignments'] as &$row) {
            if ((int) $row['calculation_method_id'] === (int) $calendar->id) {
                $row['is_active'] = false;
            }
        }
        unset($row);
        $previewOff = $this->previewPayload($spots->fresh(), $desiredOff);
        $this->actingAs($admin)
            ->putJson(route('administration.catalog.categories.calculation-methods', $spots), array_merge($desiredOff, [
                'fingerprint' => $previewOff['fingerprint'],
            ]))
            ->assertOk();

        $desiredOn = $this->buildDesiredFromCurrent($spots->fresh());
        foreach ($desiredOn['assignments'] as &$row) {
            if ((int) $row['calculation_method_id'] === (int) $calendar->id) {
                $row['is_active'] = true;
            }
        }
        unset($row);
        $previewOn = $this->previewPayload($spots->fresh(), $desiredOn);
        $this->actingAs($admin)
            ->putJson(route('administration.catalog.categories.calculation-methods', $spots), array_merge($desiredOn, [
                'fingerprint' => $previewOn['fingerprint'],
            ]))
            ->assertOk();

        $assignment->refresh();
        $this->assertTrue($assignment->is_active);
        $this->assertSame('spot_classic', $assignment->engine_profile_key);
    }

    public function test_reactivate_existing_inactive_assignment(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $spots = $this->spots();
        $tkp = $this->method('tkp');
        $assignment = AdvertisingCategoryCalculationMethod::factory()->create([
            'advertising_category_id' => $spots->id,
            'calculation_method_id' => $tkp->id,
            'is_active' => false,
            'engine_profile_key' => null,
            'sort' => 88,
            'lock_version' => 1,
        ]);

        $desired = $this->buildDesiredFromCurrent($spots->fresh());
        foreach ($desired['assignments'] as &$row) {
            if ((int) $row['calculation_method_id'] === (int) $tkp->id) {
                $row['is_active'] = true;
            }
        }
        unset($row);

        $preview = $this->previewPayload($spots->fresh(), $desired);
        $this->assertTrue($preview['has_changes']);

        $this->actingAs($admin)
            ->putJson(route('administration.catalog.categories.calculation-methods', $spots), array_merge($desired, [
                'fingerprint' => $preview['fingerprint'],
            ]))
            ->assertOk()
            ->assertJsonPath('has_changes', true);

        $assignment->refresh();
        $this->assertTrue($assignment->is_active);
        $this->assertSame(2, (int) $assignment->lock_version);
        $this->assertNull($assignment->engine_profile_key);
    }

    public function test_omit_existing_assignment_deactivates_not_deletes(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $spots = $this->spots();
        $fixed = $this->method('fixed_price');
        $assignment = AdvertisingCategoryCalculationMethod::query()
            ->where('advertising_category_id', $spots->id)
            ->where('calculation_method_id', $fixed->id)
            ->firstOrFail();
        $assignmentId = (int) $assignment->id;

        $desired = $this->buildDesiredFromCurrent($spots);
        $desired['assignments'] = array_values(array_filter(
            $desired['assignments'],
            static fn (array $row): bool => (int) $row['calculation_method_id'] !== (int) $fixed->id,
        ));

        $preview = $this->previewPayload($spots, $desired);
        $this->actingAs($admin)
            ->putJson(route('administration.catalog.categories.calculation-methods', $spots), array_merge($desired, [
                'fingerprint' => $preview['fingerprint'],
            ]))
            ->assertOk();

        $assignment->refresh();
        $this->assertSame($assignmentId, (int) $assignment->id);
        $this->assertFalse($assignment->is_active);
        $this->assertTrue(
            AdvertisingCategoryCalculationMethod::query()->whereKey($assignmentId)->exists(),
        );
    }

    public function test_only_mutated_assignment_lock_versions_increment(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $spots = $this->spots();
        $average = $this->method('average');
        $calendar = $this->method('calendar');
        $fixed = $this->method('fixed_price');

        $avgAsg = AdvertisingCategoryCalculationMethod::query()
            ->where('advertising_category_id', $spots->id)
            ->where('calculation_method_id', $average->id)
            ->firstOrFail();
        $calAsg = AdvertisingCategoryCalculationMethod::query()
            ->where('advertising_category_id', $spots->id)
            ->where('calculation_method_id', $calendar->id)
            ->firstOrFail();
        $fixAsg = AdvertisingCategoryCalculationMethod::query()
            ->where('advertising_category_id', $spots->id)
            ->where('calculation_method_id', $fixed->id)
            ->firstOrFail();

        $avgLock = (int) $avgAsg->lock_version;
        $calLock = (int) $calAsg->lock_version;
        $fixLock = (int) $fixAsg->lock_version;
        $catLock = (int) $spots->lock_version;

        $desired = $this->buildDesiredFromCurrent($spots);
        foreach ($desired['assignments'] as &$row) {
            if ((int) $row['calculation_method_id'] === (int) $calendar->id) {
                $row['sort'] = (int) $row['sort'] + 7;
            }
        }
        unset($row);

        $preview = $this->previewPayload($spots, $desired);
        $this->actingAs($admin)
            ->putJson(route('administration.catalog.categories.calculation-methods', $spots), array_merge($desired, [
                'fingerprint' => $preview['fingerprint'],
            ]))
            ->assertOk();

        $avgAsg->refresh();
        $calAsg->refresh();
        $fixAsg->refresh();
        $spots->refresh();

        $this->assertSame($avgLock, (int) $avgAsg->lock_version);
        $this->assertSame($fixLock, (int) $fixAsg->lock_version);
        $this->assertSame($calLock + 1, (int) $calAsg->lock_version);
        $this->assertSame($catLock + 1, (int) $spots->lock_version);
    }

    public function test_duplicate_method_id_unknown_method_and_inactive_global_422(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $spots = $this->spots();
        $average = $this->method('average');
        $desired = $this->buildDesiredFromCurrent($spots);
        $desired['assignments'][] = [
            'calculation_method_id' => (int) $average->id,
            'is_active' => true,
            'sort' => 1,
        ];

        $this->actingAs($admin)
            ->postJson(route('administration.catalog.categories.calculation-methods-preview', $spots), $desired)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('assignments');

        $desiredUnknown = $this->buildDesiredFromCurrent($spots);
        $desiredUnknown['assignments'][] = [
            'calculation_method_id' => 999999,
            'is_active' => true,
            'sort' => 1,
        ];
        $this->actingAs($admin)
            ->postJson(route('administration.catalog.categories.calculation-methods-preview', $spots), $desiredUnknown)
            ->assertUnprocessable();

        $free = $this->method('free_position');
        $free->forceFill(['is_active' => false])->save();
        $desiredInactive = $this->buildDesiredFromCurrent($spots->fresh());
        $desiredInactive['assignments'][] = [
            'calculation_method_id' => (int) $free->id,
            'is_active' => true,
            'sort' => 70,
        ];
        $this->actingAs($admin)
            ->postJson(route('administration.catalog.categories.calculation-methods-preview', $spots), $desiredInactive)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('calculation_method_id');
    }

    public function test_prohibited_top_level_and_nested_engine_profile_key_422(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $spots = $this->spots();
        $desired = $this->buildDesiredFromCurrent($spots);

        $this->actingAs($admin)
            ->postJson(route('administration.catalog.categories.calculation-methods-preview', $spots), array_merge($desired, [
                'engine_profile_key' => 'spot_classic',
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('engine_profile_key');

        $desired['assignments'][0]['engine_profile_key'] = 'spot_classic';
        $this->actingAs($admin)
            ->postJson(route('administration.catalog.categories.calculation-methods-preview', $spots), $desired)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('assignments.0.engine_profile_key');
    }

    public function test_same_sort_values_allowed(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $spots = $this->spots();
        $desired = $this->buildDesiredFromCurrent($spots);
        foreach ($desired['assignments'] as &$row) {
            $row['sort'] = 10;
        }
        unset($row);

        $preview = $this->previewPayload($spots, $desired);
        $this->assertTrue($preview['can_proceed']);

        $this->actingAs($admin)
            ->putJson(route('administration.catalog.categories.calculation-methods', $spots), array_merge($desired, [
                'fingerprint' => $preview['fingerprint'],
            ]))
            ->assertOk()
            ->assertJsonPath('has_changes', true);
    }

    public function test_released_average_default_ok_planned_calendar_default_422(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $spots = $this->spots();
        $average = $this->method('average');
        $calendar = $this->method('calendar');

        $desiredAvg = $this->buildDesiredFromCurrent($spots);
        $desiredAvg['default_calculation_method_id'] = (int) $average->id;
        $previewAvg = $this->previewPayload($spots, $desiredAvg);
        $this->assertFalse($previewAvg['has_changes']);
        $this->actingAs($admin)
            ->putJson(route('administration.catalog.categories.calculation-methods', $spots), array_merge($desiredAvg, [
                'fingerprint' => $previewAvg['fingerprint'],
            ]))
            ->assertOk();

        $desiredCal = $this->buildDesiredFromCurrent($spots->fresh());
        $desiredCal['default_calculation_method_id'] = (int) $calendar->id;
        $this->actingAs($admin)
            ->postJson(route('administration.catalog.categories.calculation-methods-preview', $spots), $desiredCal)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('default_calculation_method_id');
    }

    public function test_default_without_active_desired_assignment_422(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $spots = $this->spots();
        $tkp = $this->method('tkp');
        $desired = $this->buildDesiredFromCurrent($spots);
        $desired['default_calculation_method_id'] = (int) $tkp->id;

        $this->actingAs($admin)
            ->postJson(route('administration.catalog.categories.calculation-methods-preview', $spots), $desired)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('default_calculation_method_id');
    }

    public function test_default_null_with_bookable_inherit_medium_422(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $spots = $this->spots();
        AdvertisingMedium::factory()->create([
            'category_id' => $spots->id,
            'code' => 'c3b2_bookable_null_default',
            'kind' => CalculationKind::SpotClassic,
            'calculation_method_mode' => CalculationMethodMode::Inherit,
            'is_active' => true,
        ]);

        $desired = $this->buildDesiredFromCurrent($spots);
        $desired['default_calculation_method_id'] = null;

        $this->actingAs($admin)
            ->postJson(route('administration.catalog.categories.calculation-methods-preview', $spots), $desired)
            ->assertOk()
            ->assertJsonPath('can_proceed', false);

        $preview = $this->previewPayload($spots, $desired);
        $this->actingAs($admin)
            ->putJson(route('administration.catalog.categories.calculation-methods', $spots), array_merge($desired, [
                'fingerprint' => $preview['fingerprint'],
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('assignments');

        $spots->refresh();
        $this->assertNotNull($spots->default_calculation_method_id);
    }

    public function test_protect_bookable_inherit_medium_deactivate_average_while_default(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $spots = $this->spots();
        $average = $this->method('average');
        AdvertisingMedium::factory()->create([
            'category_id' => $spots->id,
            'code' => 'c3b2_protect_avg',
            'kind' => CalculationKind::SpotClassic,
            'calculation_method_mode' => CalculationMethodMode::Inherit,
            'is_active' => true,
        ]);

        $desired = $this->buildDesiredFromCurrent($spots);
        $desired['default_calculation_method_id'] = (int) $average->id;
        foreach ($desired['assignments'] as &$row) {
            if ((int) $row['calculation_method_id'] === (int) $average->id) {
                $row['is_active'] = false;
            }
        }
        unset($row);

        $this->actingAs($admin)
            ->postJson(route('administration.catalog.categories.calculation-methods-preview', $spots), $desired)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('default_calculation_method_id');
    }

    public function test_null_kind_medium_can_stay_prepared(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $spots = $this->spots();
        AdvertisingMedium::factory()->create([
            'category_id' => $spots->id,
            'code' => 'c3b2_null_kind',
            'kind' => null,
            'is_active' => true,
        ]);
        $tkp = $this->method('tkp');
        $desired = $this->buildDesiredFromCurrent($spots);
        $desired['assignments'][] = [
            'calculation_method_id' => (int) $tkp->id,
            'is_active' => true,
            'sort' => 91,
        ];

        $preview = $this->previewPayload($spots, $desired);
        $this->assertTrue($preview['can_proceed']);

        $this->actingAs($admin)
            ->putJson(route('administration.catalog.categories.calculation-methods', $spots), array_merge($desired, [
                'fingerprint' => $preview['fingerprint'],
            ]))
            ->assertOk()
            ->assertJsonPath('has_changes', true);
    }

    public function test_override_medium_unchanged_by_category_apply(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $spots = $this->spots();
        $average = $this->method('average');
        $override = AdvertisingMedium::factory()->create([
            'category_id' => $spots->id,
            'code' => 'c3b2_override',
            'kind' => CalculationKind::SpotClassic,
            'calculation_method_mode' => CalculationMethodMode::Override,
            'default_calculation_method_id' => $average->id,
            'is_active' => true,
        ]);
        AdvertisingMediumCalculationMethod::factory()->create([
            'advertising_medium_id' => $override->id,
            'calculation_method_id' => $average->id,
            'is_active' => true,
            'engine_profile_key' => 'spot_classic',
            'sort' => 10,
        ]);
        $overrideLock = (int) $override->lock_version;

        $tkp = $this->method('tkp');
        $desired = $this->buildDesiredFromCurrent($spots);
        $desired['assignments'][] = [
            'calculation_method_id' => (int) $tkp->id,
            'is_active' => true,
            'sort' => 92,
        ];

        $preview = $this->previewPayload($spots, $desired);
        $this->assertGreaterThanOrEqual(1, (int) $preview['override_media_count']);
        $this->assertTrue($preview['can_proceed']);

        $this->actingAs($admin)
            ->putJson(route('administration.catalog.categories.calculation-methods', $spots), array_merge($desired, [
                'fingerprint' => $preview['fingerprint'],
            ]))
            ->assertOk();

        $override->refresh();
        $this->assertSame($overrideLock, (int) $override->lock_version);
        $this->assertSame(CalculationMethodMode::Override, $override->calculation_method_mode);
        $this->assertSame((int) $average->id, (int) $override->default_calculation_method_id);
    }

    public function test_fingerprint_drift_returns_409(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $spots = $this->spots();
        $desired = $this->buildDesiredFromCurrent($spots);
        $desired['assignments'][0]['sort'] = (int) $desired['assignments'][0]['sort'] + 1;
        $preview = $this->previewPayload($spots, $desired);

        $this->actingAs($admin)
            ->putJson(route('administration.catalog.categories.calculation-methods', $spots), array_merge($desired, [
                'fingerprint' => str_repeat('c', 64),
            ]))
            ->assertStatus(409);

        $this->assertNotSame($preview['fingerprint'], str_repeat('c', 64));
        $spots->refresh();
        $this->assertSame(1, (int) $spots->lock_version);
    }

    public function test_lock_version_conflict_returns_409(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $spots = $this->spots();
        $desired = $this->buildDesiredFromCurrent($spots);
        $desired['assignments'][0]['sort'] = (int) $desired['assignments'][0]['sort'] + 2;
        $preview = $this->previewPayload($spots, $desired);
        $desired['lock_version'] = (int) $spots->lock_version + 9;

        $this->actingAs($admin)
            ->putJson(route('administration.catalog.categories.calculation-methods', $spots), array_merge($desired, [
                'fingerprint' => $preview['fingerprint'],
            ]))
            ->assertStatus(409);
    }

    public function test_audit_on_successful_change_with_before_after(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $spots = $this->spots();
        $tkp = $this->method('tkp');
        $desired = $this->buildDesiredFromCurrent($spots);
        $desired['assignments'][] = [
            'calculation_method_id' => (int) $tkp->id,
            'is_active' => true,
            'sort' => 93,
        ];
        $preview = $this->previewPayload($spots, $desired);

        $this->actingAs($admin)
            ->putJson(route('administration.catalog.categories.calculation-methods', $spots), array_merge($desired, [
                'fingerprint' => $preview['fingerprint'],
            ]))
            ->assertOk();

        $audit = AuditEvent::query()
            ->where('action', 'advertising_category.calculation_methods_replaced')
            ->latest('id')
            ->first();
        $this->assertNotNull($audit);
        $this->assertIsArray($audit->old_values);
        $this->assertIsArray($audit->new_values);
        $this->assertArrayHasKey('assignments', $audit->old_values);
        $this->assertArrayHasKey('assignments', $audit->new_values);
        $this->assertArrayHasKey('default_calculation_method_id', $audit->old_values);
        $this->assertArrayHasKey('default_calculation_method_id', $audit->new_values);
        $afterIds = collect($audit->new_values['assignments'] ?? [])
            ->pluck('calculation_method_id')
            ->map(fn ($id) => (int) $id)
            ->all();
        $this->assertContains((int) $tkp->id, $afterIds);
    }

    public function test_no_partial_mutation_on_422(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $spots = $this->spots();
        $lockBefore = (int) $spots->lock_version;
        $assignmentCount = AdvertisingCategoryCalculationMethod::query()
            ->where('advertising_category_id', $spots->id)
            ->count();

        $free = $this->method('free_position');
        $free->forceFill(['is_active' => false])->save();

        $desired = $this->buildDesiredFromCurrent($spots);
        foreach ($desired['assignments'] as &$row) {
            $row['sort'] = (int) $row['sort'] + 11;
        }
        unset($row);
        $desired['assignments'][] = [
            'calculation_method_id' => (int) $free->id,
            'is_active' => true,
            'sort' => 77,
        ];

        $this->actingAs($admin)
            ->postJson(route('administration.catalog.categories.calculation-methods-preview', $spots), $desired)
            ->assertUnprocessable();

        $spots->refresh();
        $this->assertSame($lockBefore, (int) $spots->lock_version);
        $this->assertSame(
            $assignmentCount,
            AdvertisingCategoryCalculationMethod::query()
                ->where('advertising_category_id', $spots->id)
                ->count(),
        );
        $this->assertFalse(
            AdvertisingCategoryCalculationMethod::query()
                ->where('advertising_category_id', $spots->id)
                ->where('calculation_method_id', $free->id)
                ->exists(),
        );
        $this->assertSame(
            0,
            AuditEvent::query()->where('action', 'advertising_category.calculation_methods_replaced')->count(),
        );
    }

    /**
     * @return array{
     *     lock_version: int,
     *     default_calculation_method_id: int|null,
     *     assignments: list<array{calculation_method_id: int, is_active: bool, sort: int}>
     * }
     */
    private function buildDesiredFromCurrent(AdvertisingCategory $category): array
    {
        $assignments = AdvertisingCategoryCalculationMethod::query()
            ->where('advertising_category_id', $category->id)
            ->orderBy('id')
            ->get()
            ->map(static fn (AdvertisingCategoryCalculationMethod $row): array => [
                'calculation_method_id' => (int) $row->calculation_method_id,
                'is_active' => (bool) $row->is_active,
                'sort' => (int) $row->sort,
            ])
            ->values()
            ->all();

        return [
            'lock_version' => (int) $category->lock_version,
            'default_calculation_method_id' => $category->default_calculation_method_id !== null
                ? (int) $category->default_calculation_method_id
                : null,
            'assignments' => $assignments,
        ];
    }

    /**
     * @param  array<string, mixed>  $desired
     * @return array<string, mixed>
     */
    private function previewPayload(AdvertisingCategory $category, array $desired): array
    {
        return app(AdvertisingCategoryCalculationMethodImpactPreviewService::class)
            ->preview($category->fresh(), $desired);
    }

    private function spots(): AdvertisingCategory
    {
        return AdvertisingCategory::query()
            ->where('key', CanonicalAdvertisingCategories::SPOTS)
            ->firstOrFail();
    }

    private function method(string $key): CalculationMethod
    {
        return CalculationMethod::query()->where('key', $key)->firstOrFail();
    }
}
