<?php

namespace Tests\Feature\Advertising;

use App\Enums\CalculationKind;
use App\Enums\CalculationMethodMode;
use App\Enums\Role;
use App\Models\AdvertisingCategory;
use App\Models\AdvertisingMedium;
use App\Models\AdvertisingMediumCalculationMethod;
use App\Models\AuditEvent;
use App\Models\CalculationMethod;
use App\Models\User;
use App\Services\Advertising\Admin\AdvertisingMediumCalculationMethodImpactPreviewService;
use App\Support\Advertising\CanonicalAdvertisingCategories;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * ADV-001c3c: Medium-Methoden Desired State (Mode/Default/Assignments).
 */
class CatalogAdminAdv001c3cTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_allowed_sales_forbidden_on_preview_and_apply(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $sales = User::factory()->role(Role::Sales)->create();
        $medium = $this->spotMedium('c3c_perm');
        $payload = $this->buildDesiredFromCurrent($medium);

        $this->actingAs($admin)
            ->postJson(route('administration.catalog.media.calculation-methods-preview', $medium), $payload)
            ->assertOk();

        $this->actingAs($sales)
            ->postJson(route('administration.catalog.media.calculation-methods-preview', $medium), $payload)
            ->assertForbidden();

        $this->actingAs($sales)
            ->putJson(route('administration.catalog.media.calculation-methods', $medium), array_merge($payload, [
                'fingerprint' => str_repeat('a', 64),
            ]))
            ->assertForbidden();
    }

    public function test_show_exposes_calculation_methods_and_routes(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $medium = $this->spotMedium('c3c_show');

        $this->actingAs($admin)
            ->get(route('administration.catalog.media.show', $medium))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('administration/katalog/media/show')
                ->has('calculationMethods')
                ->has('categoryCalculationMethods')
                ->where('methodsBoundaryNote', fn (string $note): bool => str_contains($note, 'Desired State'))
                ->has('routes.calculationMethodsPreview')
                ->has('routes.calculationMethodsReplace')
                ->where('medium.calculation_method_mode', 'inherit')
                ->where('medium.effective_source', 'Oberkategorie'));
    }

    public function test_preview_without_fingerprint_ok_with_fingerprint_422(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $medium = $this->spotMedium('c3c_prev_fp');
        $payload = $this->buildDesiredFromCurrent($medium);

        $this->actingAs($admin)
            ->postJson(route('administration.catalog.media.calculation-methods-preview', $medium), $payload)
            ->assertOk()
            ->assertJsonPath('action', AdvertisingMediumCalculationMethodImpactPreviewService::ACTION_MEDIUM_CALCULATION_METHODS_REPLACE)
            ->assertJsonPath('has_changes', false)
            ->assertJson(fn ($json) => $json->has('fingerprint')->has('stored_before')->has('effective_after')->etc());

        $this->actingAs($admin)
            ->postJson(route('administration.catalog.media.calculation-methods-preview', $medium), array_merge($payload, [
                'fingerprint' => str_repeat('b', 64),
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('fingerprint');
    }

    public function test_apply_requires_fingerprint_64_hex(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $medium = $this->spotMedium('c3c_apply_fp');
        $payload = $this->buildDesiredFromCurrent($medium);

        $this->actingAs($admin)
            ->putJson(route('administration.catalog.media.calculation-methods', $medium), $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('fingerprint');
    }

    public function test_inherit_effective_source_is_category_stored_overrides_ineffective(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $average = $this->method('average');
        $medium = $this->spotMedium('c3c_inherit_store');
        AdvertisingMediumCalculationMethod::factory()->create([
            'advertising_medium_id' => $medium->id,
            'calculation_method_id' => $average->id,
            'engine_profile_key' => 'spot_classic',
            'is_active' => true,
            'sort' => 10,
        ]);
        $medium->forceFill([
            'default_calculation_method_id' => $average->id,
        ])->save();

        $payload = $this->buildDesiredFromCurrent($medium->fresh());
        $payload['calculation_method_mode'] = 'inherit';

        $preview = $this->actingAs($admin)
            ->postJson(route('administration.catalog.media.calculation-methods-preview', $medium), $payload)
            ->assertOk()
            ->json();

        $this->assertSame('Oberkategorie', $preview['evaluation']['source_after']);
        $this->assertFalse($preview['stored_after']['is_operative']);
        $this->assertSame('Oberkategorie', $preview['effective_after']['source']);
        $this->assertTrue($preview['evaluation']['bookable_after']);
    }

    public function test_mode_switch_does_not_delete_stored_assignments_or_default(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $average = $this->method('average');
        $medium = $this->spotMedium('c3c_mode_keep');
        AdvertisingMediumCalculationMethod::factory()->create([
            'advertising_medium_id' => $medium->id,
            'calculation_method_id' => $average->id,
            'engine_profile_key' => 'spot_classic',
            'is_active' => true,
            'sort' => 10,
        ]);
        $medium->forceFill([
            'calculation_method_mode' => CalculationMethodMode::Override,
            'default_calculation_method_id' => $average->id,
        ])->save();

        $payload = $this->buildDesiredFromCurrent($medium->fresh());
        $payload['calculation_method_mode'] = 'inherit';
        $preview = $this->previewPayload($medium, $payload);

        $this->actingAs($admin)
            ->putJson(route('administration.catalog.media.calculation-methods', $medium), array_merge($payload, [
                'fingerprint' => $preview['fingerprint'],
            ]))
            ->assertOk()
            ->assertJsonPath('has_changes', true);

        $medium->refresh();
        $this->assertSame(CalculationMethodMode::Inherit, $medium->calculation_method_mode);
        $this->assertSame((int) $average->id, (int) $medium->default_calculation_method_id);
        $this->assertTrue(
            AdvertisingMediumCalculationMethod::query()
                ->where('advertising_medium_id', $medium->id)
                ->where('calculation_method_id', $average->id)
                ->where('is_active', true)
                ->exists(),
        );
    }

    public function test_new_assignment_gets_null_profile_existing_profile_preserved_on_reactivate(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $average = $this->method('average');
        $tkp = $this->method('tkp');
        $medium = $this->unbookableMedium('c3c_profile');

        $existing = AdvertisingMediumCalculationMethod::factory()->create([
            'advertising_medium_id' => $medium->id,
            'calculation_method_id' => $average->id,
            'engine_profile_key' => 'spot_classic',
            'is_active' => false,
            'sort' => 10,
        ]);

        $payload = [
            'lock_version' => (int) $medium->lock_version,
            'calculation_method_mode' => 'override',
            'default_calculation_method_id' => (int) $average->id,
            'assignments' => [
                [
                    'calculation_method_id' => (int) $average->id,
                    'is_active' => true,
                    'sort' => 10,
                ],
                [
                    'calculation_method_id' => (int) $tkp->id,
                    'is_active' => true,
                    'sort' => 40,
                ],
            ],
        ];
        $preview = $this->previewPayload($medium, $payload);

        $this->actingAs($admin)
            ->putJson(route('administration.catalog.media.calculation-methods', $medium), array_merge($payload, [
                'fingerprint' => $preview['fingerprint'],
            ]))
            ->assertOk();

        $existing->refresh();
        $this->assertTrue($existing->is_active);
        $this->assertSame('spot_classic', $existing->engine_profile_key);

        $tkpRow = AdvertisingMediumCalculationMethod::query()
            ->where('advertising_medium_id', $medium->id)
            ->where('calculation_method_id', $tkp->id)
            ->firstOrFail();
        $this->assertNull($tkpRow->engine_profile_key);
    }

    public function test_omit_existing_assignment_deactivates_not_deletes(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $average = $this->method('average');
        $calendar = $this->method('calendar');
        $medium = $this->unbookableMedium('c3c_omit');

        AdvertisingMediumCalculationMethod::factory()->create([
            'advertising_medium_id' => $medium->id,
            'calculation_method_id' => $average->id,
            'engine_profile_key' => 'spot_classic',
            'is_active' => true,
            'sort' => 10,
        ]);
        AdvertisingMediumCalculationMethod::factory()->create([
            'advertising_medium_id' => $medium->id,
            'calculation_method_id' => $calendar->id,
            'engine_profile_key' => null,
            'is_active' => true,
            'sort' => 20,
        ]);
        $medium->forceFill([
            'calculation_method_mode' => CalculationMethodMode::Override,
            'default_calculation_method_id' => $average->id,
        ])->save();

        $payload = [
            'lock_version' => (int) $medium->fresh()->lock_version,
            'calculation_method_mode' => 'override',
            'default_calculation_method_id' => (int) $average->id,
            'assignments' => [
                [
                    'calculation_method_id' => (int) $average->id,
                    'is_active' => true,
                    'sort' => 10,
                ],
            ],
        ];
        $preview = $this->previewPayload($medium->fresh(), $payload);

        $this->actingAs($admin)
            ->putJson(route('administration.catalog.media.calculation-methods', $medium), array_merge($payload, [
                'fingerprint' => $preview['fingerprint'],
            ]))
            ->assertOk();

        $calendarRow = AdvertisingMediumCalculationMethod::query()
            ->where('advertising_medium_id', $medium->id)
            ->where('calculation_method_id', $calendar->id)
            ->firstOrFail();
        $this->assertFalse($calendarRow->is_active);
    }

    public function test_new_inactive_only_desired_row_is_not_created(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $tkp = $this->method('tkp');
        $medium = $this->spotMedium('c3c_inactive_new');
        $payload = $this->buildDesiredFromCurrent($medium);
        $payload['assignments'][] = [
            'calculation_method_id' => (int) $tkp->id,
            'is_active' => false,
            'sort' => 99,
        ];
        $preview = $this->previewPayload($medium, $payload);

        $this->actingAs($admin)
            ->putJson(route('administration.catalog.media.calculation-methods', $medium), array_merge($payload, [
                'fingerprint' => $preview['fingerprint'],
            ]))
            ->assertOk();

        $this->assertFalse(
            AdvertisingMediumCalculationMethod::query()
                ->where('advertising_medium_id', $medium->id)
                ->where('calculation_method_id', $tkp->id)
                ->exists(),
        );
    }

    public function test_duplicate_unknown_inactive_global_and_prohibited_fields_422(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $average = $this->method('average');
        $medium = $this->spotMedium('c3c_val');
        $base = $this->buildDesiredFromCurrent($medium);

        $dup = $base;
        $dup['assignments'][] = [
            'calculation_method_id' => (int) $average->id,
            'is_active' => true,
            'sort' => 1,
        ];
        $dup['assignments'][] = [
            'calculation_method_id' => (int) $average->id,
            'is_active' => true,
            'sort' => 2,
        ];
        $this->actingAs($admin)
            ->postJson(route('administration.catalog.media.calculation-methods-preview', $medium), $dup)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('assignments');

        $unknown = $base;
        $unknown['assignments'][] = [
            'calculation_method_id' => 999999,
            'is_active' => true,
            'sort' => 1,
        ];
        $this->actingAs($admin)
            ->postJson(route('administration.catalog.media.calculation-methods-preview', $medium), $unknown)
            ->assertUnprocessable();

        $free = $this->method('free_position');
        $free->forceFill(['is_active' => false])->save();
        $inactive = $base;
        $inactive['assignments'][] = [
            'calculation_method_id' => (int) $free->id,
            'is_active' => true,
            'sort' => 1,
        ];
        $this->actingAs($admin)
            ->postJson(route('administration.catalog.media.calculation-methods-preview', $medium), $inactive)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('calculation_method_id');

        $this->actingAs($admin)
            ->postJson(route('administration.catalog.media.calculation-methods-preview', $medium), array_merge($base, [
                'engine_profile_key' => 'spot_classic',
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('engine_profile_key');

        $nested = $base;
        $nested['assignments'][0]['engine_profile_key'] = 'spot_classic';
        $this->actingAs($admin)
            ->postJson(route('administration.catalog.media.calculation-methods-preview', $medium), $nested)
            ->assertUnprocessable();
    }

    public function test_same_sort_values_allowed(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $average = $this->method('average');
        $tkp = $this->method('tkp');
        $medium = $this->unbookableMedium('c3c_sort');
        AdvertisingMediumCalculationMethod::factory()->create([
            'advertising_medium_id' => $medium->id,
            'calculation_method_id' => $average->id,
            'engine_profile_key' => 'spot_classic',
            'is_active' => true,
            'sort' => 5,
        ]);

        $payload = [
            'lock_version' => (int) $medium->lock_version,
            'calculation_method_mode' => 'override',
            'default_calculation_method_id' => (int) $average->id,
            'assignments' => [
                [
                    'calculation_method_id' => (int) $average->id,
                    'is_active' => true,
                    'sort' => 5,
                ],
                [
                    'calculation_method_id' => (int) $tkp->id,
                    'is_active' => true,
                    'sort' => 5,
                ],
            ],
        ];
        $preview = $this->previewPayload($medium, $payload);

        $this->actingAs($admin)
            ->putJson(route('administration.catalog.media.calculation-methods', $medium), array_merge($payload, [
                'fingerprint' => $preview['fingerprint'],
            ]))
            ->assertOk();
    }

    public function test_override_default_requires_released_profile_membership(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $average = $this->method('average');
        $calendar = $this->method('calendar');
        $medium = $this->unbookableMedium('c3c_def');

        AdvertisingMediumCalculationMethod::factory()->create([
            'advertising_medium_id' => $medium->id,
            'calculation_method_id' => $average->id,
            'engine_profile_key' => 'spot_classic',
            'is_active' => true,
            'sort' => 10,
        ]);
        AdvertisingMediumCalculationMethod::factory()->create([
            'advertising_medium_id' => $medium->id,
            'calculation_method_id' => $calendar->id,
            'engine_profile_key' => 'spot_classic',
            'is_active' => true,
            'sort' => 20,
        ]);

        $ok = [
            'lock_version' => (int) $medium->lock_version,
            'calculation_method_mode' => 'override',
            'default_calculation_method_id' => (int) $average->id,
            'assignments' => [
                [
                    'calculation_method_id' => (int) $average->id,
                    'is_active' => true,
                    'sort' => 10,
                ],
                [
                    'calculation_method_id' => (int) $calendar->id,
                    'is_active' => true,
                    'sort' => 20,
                ],
            ],
        ];
        $this->actingAs($admin)
            ->postJson(route('administration.catalog.media.calculation-methods-preview', $medium), $ok)
            ->assertOk();

        $planned = $ok;
        $planned['default_calculation_method_id'] = (int) $calendar->id;
        $this->actingAs($admin)
            ->postJson(route('administration.catalog.media.calculation-methods-preview', $medium), $planned)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('default_calculation_method_id');

        $nullProfile = $ok;
        AdvertisingMediumCalculationMethod::query()
            ->where('advertising_medium_id', $medium->id)
            ->where('calculation_method_id', $average->id)
            ->update(['engine_profile_key' => null]);
        $this->actingAs($admin)
            ->postJson(route('administration.catalog.media.calculation-methods-preview', $medium->fresh()), $nullProfile)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('default_calculation_method_id');

        $noMembership = [
            'lock_version' => (int) $medium->fresh()->lock_version,
            'calculation_method_mode' => 'override',
            'default_calculation_method_id' => (int) $average->id,
            'assignments' => [
                [
                    'calculation_method_id' => (int) $calendar->id,
                    'is_active' => true,
                    'sort' => 20,
                ],
            ],
        ];
        $this->actingAs($admin)
            ->postJson(route('administration.catalog.media.calculation-methods-preview', $medium->fresh()), $noMembership)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('default_calculation_method_id');
    }

    public function test_empty_override_blocked_for_bookable_allowed_for_unbookable(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $bookable = $this->spotMedium('c3c_empty_book');
        $empty = [
            'lock_version' => (int) $bookable->lock_version,
            'calculation_method_mode' => 'override',
            'default_calculation_method_id' => null,
            'assignments' => [],
        ];

        $this->actingAs($admin)
            ->postJson(route('administration.catalog.media.calculation-methods-preview', $bookable), $empty)
            ->assertOk()
            ->assertJsonPath('can_proceed', false);

        $preview = $this->previewPayload($bookable, $empty);
        $this->actingAs($admin)
            ->putJson(route('administration.catalog.media.calculation-methods', $bookable), array_merge($empty, [
                'fingerprint' => $preview['fingerprint'],
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('assignments');

        $unbookable = $this->unbookableMedium('c3c_empty_unbook');
        $emptyUnbook = [
            'lock_version' => (int) $unbookable->lock_version,
            'calculation_method_mode' => 'override',
            'default_calculation_method_id' => null,
            'assignments' => [],
        ];
        $previewUnbook = $this->previewPayload($unbookable, $emptyUnbook);
        $this->actingAs($admin)
            ->putJson(route('administration.catalog.media.calculation-methods', $unbookable), array_merge($emptyUnbook, [
                'fingerprint' => $previewUnbook['fingerprint'],
            ]))
            ->assertOk()
            ->assertJsonPath('has_changes', true);

        $unbookable->refresh();
        $this->assertSame(CalculationMethodMode::Override, $unbookable->calculation_method_mode);
        $this->assertNull($unbookable->default_calculation_method_id);
    }

    public function test_bookable_inherit_cannot_switch_to_unprovisioned_override(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $average = $this->method('average');
        $medium = $this->spotMedium('c3c_block_switch');

        $payload = [
            'lock_version' => (int) $medium->lock_version,
            'calculation_method_mode' => 'override',
            'default_calculation_method_id' => null,
            'assignments' => [
                [
                    'calculation_method_id' => (int) $average->id,
                    'is_active' => true,
                    'sort' => 10,
                ],
            ],
        ];

        // default null with active assignment without profile → membership fails for default,
        // but with default=null and active assignment without profile: override default ok null,
        // bookability lost → can_proceed false.
        $this->actingAs($admin)
            ->postJson(route('administration.catalog.media.calculation-methods-preview', $medium), $payload)
            ->assertOk()
            ->assertJsonPath('can_proceed', false);

        // With default set but null profile on new row → default 422 before bookability.
        $withDefault = $payload;
        $withDefault['default_calculation_method_id'] = (int) $average->id;
        $this->actingAs($admin)
            ->postJson(route('administration.catalog.media.calculation-methods-preview', $medium), $withDefault)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('default_calculation_method_id');
    }

    public function test_noop_identical_state_no_lock_bump_no_audit(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $medium = $this->spotMedium('c3c_noop');
        $payload = $this->buildDesiredFromCurrent($medium);
        $preview = $this->previewPayload($medium, $payload);
        $lockBefore = (int) $medium->lock_version;

        $this->actingAs($admin)
            ->putJson(route('administration.catalog.media.calculation-methods', $medium), array_merge($payload, [
                'fingerprint' => $preview['fingerprint'],
            ]))
            ->assertOk()
            ->assertJsonPath('has_changes', false)
            ->assertJsonPath('message', 'Keine Änderungen');

        $medium->refresh();
        $this->assertSame($lockBefore, (int) $medium->lock_version);
        $this->assertSame(
            0,
            AuditEvent::query()->where('action', 'advertising_medium.calculation_methods_replaced')->count(),
        );
    }

    public function test_fingerprint_and_lock_version_drift_409(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $medium = $this->spotMedium('c3c_drift');
        $payload = $this->buildDesiredFromCurrent($medium);
        $payload['assignments'][] = [
            'calculation_method_id' => (int) $this->method('tkp')->id,
            'is_active' => true,
            'sort' => 88,
        ];
        $preview = $this->previewPayload($medium, $payload);

        $this->actingAs($admin)
            ->putJson(route('administration.catalog.media.calculation-methods', $medium), array_merge($payload, [
                'fingerprint' => str_repeat('c', 64),
            ]))
            ->assertStatus(409);

        $staleLock = $payload;
        $staleLock['lock_version'] = (int) $medium->lock_version + 5;
        $this->actingAs($admin)
            ->putJson(route('administration.catalog.media.calculation-methods', $medium), array_merge($staleLock, [
                'fingerprint' => $preview['fingerprint'],
            ]))
            ->assertStatus(409);
    }

    public function test_audit_on_successful_change_and_no_partial_mutation_on_422(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $average = $this->method('average');
        $medium = $this->unbookableMedium('c3c_audit');
        AdvertisingMediumCalculationMethod::factory()->create([
            'advertising_medium_id' => $medium->id,
            'calculation_method_id' => $average->id,
            'engine_profile_key' => 'spot_classic',
            'is_active' => true,
            'sort' => 10,
        ]);

        $payload = [
            'lock_version' => (int) $medium->lock_version,
            'calculation_method_mode' => 'override',
            'default_calculation_method_id' => (int) $average->id,
            'assignments' => [
                [
                    'calculation_method_id' => (int) $average->id,
                    'is_active' => true,
                    'sort' => 15,
                ],
            ],
        ];
        $preview = $this->previewPayload($medium, $payload);

        $this->actingAs($admin)
            ->putJson(route('administration.catalog.media.calculation-methods', $medium), array_merge($payload, [
                'fingerprint' => $preview['fingerprint'],
            ]))
            ->assertOk();

        $this->assertSame(
            1,
            AuditEvent::query()->where('action', 'advertising_medium.calculation_methods_replaced')->count(),
        );

        $lockBefore = (int) $medium->fresh()->lock_version;
        $countBefore = AdvertisingMediumCalculationMethod::query()
            ->where('advertising_medium_id', $medium->id)
            ->count();
        $free = $this->method('free_position');
        $free->forceFill(['is_active' => false])->save();
        $bad = $this->buildDesiredFromCurrent($medium->fresh());
        $bad['assignments'][] = [
            'calculation_method_id' => (int) $free->id,
            'is_active' => true,
            'sort' => 70,
        ];
        $this->actingAs($admin)
            ->postJson(route('administration.catalog.media.calculation-methods-preview', $medium->fresh()), $bad)
            ->assertUnprocessable();

        $medium->refresh();
        $this->assertSame($lockBefore, (int) $medium->lock_version);
        $this->assertSame(
            $countBefore,
            AdvertisingMediumCalculationMethod::query()->where('advertising_medium_id', $medium->id)->count(),
        );
    }

    public function test_successful_override_with_provisioned_profile_keeps_bookable(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $average = $this->method('average');
        $medium = $this->spotMedium('c3c_ok_override');
        AdvertisingMediumCalculationMethod::factory()->create([
            'advertising_medium_id' => $medium->id,
            'calculation_method_id' => $average->id,
            'engine_profile_key' => 'spot_classic',
            'is_active' => true,
            'sort' => 10,
        ]);

        $payload = [
            'lock_version' => (int) $medium->lock_version,
            'calculation_method_mode' => 'override',
            'default_calculation_method_id' => (int) $average->id,
            'assignments' => [
                [
                    'calculation_method_id' => (int) $average->id,
                    'is_active' => true,
                    'sort' => 10,
                ],
            ],
        ];
        $preview = $this->previewPayload($medium, $payload);
        $this->assertTrue($preview['can_proceed']);
        $this->assertTrue($preview['evaluation']['bookable_after']);
        $this->assertSame('Werbemittel-Override', $preview['evaluation']['source_after']);

        $this->actingAs($admin)
            ->putJson(route('administration.catalog.media.calculation-methods', $medium), array_merge($payload, [
                'fingerprint' => $preview['fingerprint'],
            ]))
            ->assertOk()
            ->assertJsonPath('calculation_method_mode', 'override');

        $medium->refresh();
        $this->assertSame(CalculationMethodMode::Override, $medium->calculation_method_mode);
    }

    /**
     * @return array{
     *     lock_version: int,
     *     calculation_method_mode: string,
     *     default_calculation_method_id: int|null,
     *     assignments: list<array{calculation_method_id: int, is_active: bool, sort: int}>
     * }
     */
    private function buildDesiredFromCurrent(AdvertisingMedium $medium): array
    {
        $assignments = AdvertisingMediumCalculationMethod::query()
            ->where('advertising_medium_id', $medium->id)
            ->orderBy('id')
            ->get()
            ->map(static fn (AdvertisingMediumCalculationMethod $row): array => [
                'calculation_method_id' => (int) $row->calculation_method_id,
                'is_active' => (bool) $row->is_active,
                'sort' => (int) $row->sort,
            ])
            ->values()
            ->all();

        return [
            'lock_version' => (int) $medium->lock_version,
            'calculation_method_mode' => $medium->calculation_method_mode->value,
            'default_calculation_method_id' => $medium->default_calculation_method_id !== null
                ? (int) $medium->default_calculation_method_id
                : null,
            'assignments' => $assignments,
        ];
    }

    /**
     * @param  array<string, mixed>  $desired
     * @return array<string, mixed>
     */
    private function previewPayload(AdvertisingMedium $medium, array $desired): array
    {
        return app(AdvertisingMediumCalculationMethodImpactPreviewService::class)
            ->preview($medium->fresh([
                'category.defaultCalculationMethod',
                'category.calculationMethodAssignments.calculationMethod',
                'defaultCalculationMethod',
                'calculationMethodAssignments.calculationMethod',
            ]), $desired);
    }

    private function spotMedium(string $code): AdvertisingMedium
    {
        return AdvertisingMedium::factory()->create([
            'category_id' => $this->spots()->id,
            'code' => $code,
            'kind' => CalculationKind::SpotClassic,
            'calculation_method_mode' => CalculationMethodMode::Inherit,
            'is_active' => true,
        ]);
    }

    private function unbookableMedium(string $code): AdvertisingMedium
    {
        return AdvertisingMedium::factory()->create([
            'category_id' => $this->spots()->id,
            'code' => $code,
            'kind' => null,
            'calculation_method_mode' => CalculationMethodMode::Inherit,
            'is_active' => true,
        ]);
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
