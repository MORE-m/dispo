<?php

namespace Tests\Feature\PriceList;

use App\Enums\DayGroup;
use App\Enums\InventoryType;
use App\Enums\PriceListStatus;
use App\Enums\Role;
use App\Models\AuditEvent;
use App\Models\Inventory;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\User;
use App\Services\Calculation\CatalogResolver;
use App\Services\PriceList\Admin\PriceListImpactPreviewService;
use App\Support\PriceList\PriceListCalendar;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesSpotClassicCatalog;
use Tests\TestCase;

class PriceListAdminLifecycleTest extends TestCase
{
    use CreatesSpotClassicCatalog;
    use RefreshDatabase;

    public function test_admin_and_management_can_open_price_list_admin_other_roles_forbidden(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $management = User::factory()->role(Role::Management)->create();

        $this->actingAs($admin)
            ->get(route('administration.price-lists.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('administration/price-lists/index'));

        $this->actingAs($management)
            ->get(route('administration.price-lists.create'))
            ->assertOk();

        foreach ([Role::Sales, Role::Disposition, Role::ProductManagement] as $role) {
            $user = User::factory()->role($role)->create();
            $this->actingAs($user)
                ->get(route('administration.price-lists.index'))
                ->assertForbidden();
            $this->actingAs($user)
                ->post(route('administration.price-lists.store'), [
                    'inventory_id' => 1,
                    'year' => 2026,
                    'name' => 'Verboten',
                ])
                ->assertForbidden();
        }
    }

    public function test_create_copy_update_activate_and_archive_draft(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $admin = User::factory()->role(Role::Admin)->create();
        $year = PriceListCalendar::currentYear();

        $this->actingAs($admin)
            ->post(route('administration.price-lists.store'), [
                'inventory_id' => $catalog['hamburg']->id,
                'year' => $year,
                'name' => 'RH Entwurf',
                'items' => $this->hourItems(8, '1,2500'),
            ])
            ->assertRedirect();

        $draft = PriceList::query()->where('name', 'RH Entwurf')->firstOrFail();
        $this->assertSame(PriceListStatus::Draft, $draft->status);
        $this->assertSame($year, (int) $draft->year);
        $this->assertSame((string) $draft->revision_number, $draft->version);
        $this->assertNull($draft->valid_from);
        $this->assertSame(3, $draft->items()->count());
        $this->assertSame(
            '1.2500',
            (string) $draft->items()->where('day_group', DayGroup::MoFr)->value('second_price'),
        );

        $inspected = $this->actingAs($admin)
            ->postJson(route('administration.price-lists.inspect', $draft), [
                'items' => $this->hourItems(8, '1,2500'),
            ])
            ->assertOk()
            ->json();
        $derived = collect($inspected['derived'] ?? []);
        $this->assertTrue($derived->contains(
            fn (array $row): bool => $row['hour'] === 8 && $row['day_group'] === DayGroup::MoSa->value,
        ));
        $this->assertTrue($derived->contains(
            fn (array $row): bool => $row['hour'] === 8 && $row['day_group'] === DayGroup::MoSo->value,
        ));
        $this->assertSame(
            '1.2500',
            $derived->first(fn (array $row): bool => $row['hour'] === 8 && $row['day_group'] === DayGroup::MoSa->value)['second_price'] ?? null,
        );

        $this->actingAs($admin)
            ->put(route('administration.price-lists.update', $draft), [
                'name' => 'RH Entwurf',
                'lock_version' => $draft->lock_version,
                'items' => $this->hourItems(8, '1,2500'),
            ])
            ->assertRedirect();
        $draft->refresh();
        $this->assertSame(1, (int) $draft->lock_version);
        $this->assertSame(0, AuditEvent::query()->where('action', 'price_list.updated')->count());

        $this->actingAs($admin)
            ->put(route('administration.price-lists.update', $draft), [
                'name' => 'RH Entwurf v2',
                'lock_version' => $draft->lock_version,
                'items' => array_merge($this->hourItems(8, '1.2500'), $this->hourItems(9, '2.0000')),
            ])
            ->assertRedirect();
        $draft->refresh();
        $this->assertSame(2, (int) $draft->lock_version);
        $this->assertSame('RH Entwurf v2', $draft->name);
        $this->assertSame(6, $draft->items()->count());
        $this->assertSame(0, $draft->items()->whereIn('day_group', [DayGroup::MoSa, DayGroup::MoSo])->count());

        $copyResponse = $this->actingAs($admin)
            ->post(route('administration.price-lists.store'), [
                'inventory_id' => $catalog['hamburg']->id,
                'year' => $year,
                'name' => 'RH Kopie',
                'copy_from_id' => $draft->id,
            ]);
        $copyResponse->assertRedirect();
        $copy = PriceList::query()->where('name', 'RH Kopie')->firstOrFail();
        $this->assertSame(PriceListStatus::Draft, $copy->status);
        $this->assertNotSame($draft->id, $copy->id);
        $this->assertNotSame($draft->version, $copy->version);
        $this->assertSame(6, $copy->items()->count());

        $preview = $this->actingAs($admin)
            ->postJson(route('administration.price-lists.activate-preview', $draft))
            ->assertOk()
            ->json();
        $this->assertTrue($preview['can_proceed']);
        $this->assertNotEmpty($preview['replaced_active']);

        $this->actingAs($admin)
            ->postJson(route('administration.price-lists.activate', $draft), [
                'lock_version' => $draft->lock_version,
                'fingerprint' => $preview['fingerprint'],
            ])
            ->assertOk();

        $draft->refresh();
        $this->assertSame(PriceListStatus::Active, $draft->status);
        $catalogList = PriceList::query()->where('version', '2026-RH')->firstOrFail();
        $this->assertSame(PriceListStatus::Archived, $catalogList->status);

        $this->actingAs($admin)
            ->putJson(route('administration.price-lists.update', $draft), [
                'name' => 'Darf nicht',
                'lock_version' => $draft->lock_version,
                'items' => $this->hourItems(8, '9.0000'),
            ])
            ->assertUnprocessable();
        $this->assertSame('RH Entwurf v2', $draft->fresh()->name);

        $archivePreview = $this->actingAs($admin)
            ->postJson(route('administration.price-lists.archive-preview', $draft))
            ->assertOk()
            ->json();
        $this->actingAs($admin)
            ->postJson(route('administration.price-lists.archive', $draft), [
                'lock_version' => $draft->lock_version,
                'fingerprint' => $archivePreview['fingerprint'],
            ])
            ->assertOk();
        $this->assertSame(PriceListStatus::Archived, $draft->fresh()->status);

        $this->actingAs($admin)
            ->delete(route('administration.price-lists.destroy', $draft))
            ->assertStatus(405);
        $this->assertTrue(PriceList::query()->whereKey($draft->id)->exists());
        $this->assertGreaterThan(0, PriceListItem::query()->where('price_list_id', $draft->id)->count());
    }

    public function test_legacy_numeric_version_is_skipped_without_rewriting_history(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $admin = User::factory()->role(Role::Admin)->create();
        $inventory = Inventory::factory()->create([
            'organization_id' => $catalog['hamburg']->organization_id,
            'code' => 'LG2',
        ]);
        $year = PriceListCalendar::currentYear();
        $legacy = PriceList::factory()->create([
            'inventory_id' => $inventory->id,
            'year' => $year,
            'version' => '2',
            'revision_number' => 1,
            'status' => PriceListStatus::Archived,
            'name' => 'Legacy zwei',
        ]);
        $legacyPrice = '4.2500';
        PriceListItem::factory()->create([
            'price_list_id' => $legacy->id,
            'hour' => 8,
            'day_group' => DayGroup::MoFr,
            'second_price' => $legacyPrice,
        ]);

        $this->actingAs($admin)->post(route('administration.price-lists.store'), [
            'inventory_id' => $inventory->id,
            'year' => $year,
            'name' => 'Nach Legacy',
            'items' => $this->hourItems(8, '1.0000'),
        ])->assertRedirect();

        $draft = PriceList::query()->where('name', 'Nach Legacy')->firstOrFail();
        $this->assertSame(2, (int) $draft->revision_number);
        $this->assertSame('3', $draft->version);
        $this->assertSame('2', $legacy->fresh()->version);
        $this->assertSame(1, (int) $legacy->fresh()->revision_number);
        $this->assertSame(
            $legacyPrice,
            (string) PriceListItem::query()->where('price_list_id', $legacy->id)->value('second_price'),
        );

        $this->actingAs($admin)->post(route('administration.price-lists.store'), [
            'inventory_id' => $inventory->id,
            'year' => $year,
            'name' => 'Kopie Legacy',
            'copy_from_id' => $legacy->id,
        ])->assertRedirect();
        $copy = PriceList::query()->where('name', 'Kopie Legacy')->firstOrFail();
        $this->assertSame($year, (int) $copy->year);
        $this->assertSame('4', $copy->version);
        $this->assertNotSame('2', $copy->version);
        $this->assertSame('2', $legacy->fresh()->version);
    }

    public function test_non_numeric_legacy_version_and_copy_into_other_year(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $admin = User::factory()->role(Role::Admin)->create();
        $year = PriceListCalendar::currentYear();
        $source = PriceList::query()->where('inventory_id', $catalog['hamburg']->id)->firstOrFail();
        $this->assertSame('2026-RH', $source->version);

        $this->actingAs($admin)->post(route('administration.price-lists.store'), [
            'inventory_id' => $catalog['hamburg']->id,
            'year' => $year,
            'name' => 'Draft neben e2e',
            'items' => $this->hourItems(8, '1.0000'),
        ])->assertRedirect();
        $first = PriceList::query()->where('name', 'Draft neben e2e')->firstOrFail();
        $this->assertSame((string) $first->revision_number, $first->version);
        $this->assertNotSame('2026-RH', $first->version);

        $this->actingAs($admin)->post(route('administration.price-lists.store'), [
            'inventory_id' => $catalog['hamburg']->id,
            'year' => $year,
            'name' => 'Zweiter Draft',
            'items' => $this->hourItems(8, '1.1000'),
        ])->assertRedirect();
        $second = PriceList::query()->where('name', 'Zweiter Draft')->firstOrFail();
        $this->assertNotSame($first->version, $second->version);
        $this->assertSame('2026-RH', $source->fresh()->version);

        $this->actingAs($admin)->post(route('administration.price-lists.store'), [
            'inventory_id' => $catalog['hamburg']->id,
            'year' => $year + 1,
            'name' => 'Kopie anderes Jahr',
            'copy_from_id' => $source->id,
        ])->assertRedirect();
        $otherYear = PriceList::query()->where('name', 'Kopie anderes Jahr')->firstOrFail();
        $this->assertSame($year + 1, (int) $otherYear->year);
        $this->assertSame('1', $otherYear->version);
        $this->assertSame($source->items()->count(), $otherYear->items()->count());
        $this->assertSame('2026-RH', $source->fresh()->version);
    }

    public function test_po_pri_hours_1_sparse_hours_activation_and_derived_preview(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $admin = User::factory()->role(Role::Admin)->create();
        $year = PriceListCalendar::currentYear();
        $resolver = app(CatalogResolver::class);
        $preview = app(PriceListImpactPreviewService::class);

        // A: nur MoFr Stunde 6
        $this->actingAs($admin)->post(route('administration.price-lists.store'), [
            'inventory_id' => $catalog['rock']->id,
            'year' => $year,
            'name' => 'Nur MoFr 6',
            'items' => [
                ['hour' => 6, 'day_group' => DayGroup::MoFr->value, 'second_price' => '1.0000'],
            ],
        ])->assertRedirect();
        $onlyMoFr = PriceList::query()->where('name', 'Nur MoFr 6')->firstOrFail();
        $this->assertTrue(
            $this->actingAs($admin)
                ->postJson(route('administration.price-lists.activate-preview', $onlyMoFr))
                ->assertOk()
                ->json('can_proceed'),
        );
        $onlyMoFr->load('items');
        $this->assertSame('1.0000', $resolver->findSecondPrice($onlyMoFr, 6, DayGroup::MoFr));
        $this->assertNull($resolver->findSecondPrice($onlyMoFr, 6, DayGroup::Sa));
        $this->assertNull($resolver->findSecondPrice($onlyMoFr, 6, DayGroup::So));
        $this->assertNull($resolver->findSecondPrice($onlyMoFr, 6, DayGroup::MoSa));
        $this->assertNull($resolver->findSecondPrice($onlyMoFr, 6, DayGroup::MoSo));
        $derivedA = collect($preview->derivedPrices([
            ['hour' => 6, 'day_group' => DayGroup::MoFr, 'second_price' => '1.0000'],
        ]));
        $this->assertTrue($derivedA->isEmpty());

        // B: MoFr + Sa Stunde 8
        $itemsB = [
            ['hour' => 8, 'day_group' => DayGroup::MoFr->value, 'second_price' => '1.0000'],
            ['hour' => 8, 'day_group' => DayGroup::Sa->value, 'second_price' => '2.0000'],
        ];
        $this->actingAs($admin)->post(route('administration.price-lists.store'), [
            'inventory_id' => $catalog['rock']->id,
            'year' => $year,
            'name' => 'MoFr Sa 8',
            'items' => $itemsB,
        ])->assertRedirect();
        $moFrSa = PriceList::query()->where('name', 'MoFr Sa 8')->firstOrFail();
        $this->assertTrue(
            $this->actingAs($admin)
                ->postJson(route('administration.price-lists.activate-preview', $moFrSa))
                ->assertOk()
                ->json('can_proceed'),
        );
        $moFrSa->load('items');
        $this->assertSame('1.1667', $resolver->findSecondPrice($moFrSa, 8, DayGroup::MoSa));
        $this->assertNull($resolver->findSecondPrice($moFrSa, 8, DayGroup::MoSo));
        $derivedB = collect($preview->derivedPrices([
            ['hour' => 8, 'day_group' => DayGroup::MoFr, 'second_price' => '1.0000'],
            ['hour' => 8, 'day_group' => DayGroup::Sa, 'second_price' => '2.0000'],
        ]));
        $this->assertSame(
            '1.1667',
            $derivedB->firstWhere('day_group', DayGroup::MoSa->value)['second_price'] ?? null,
        );
        $this->assertNull($derivedB->firstWhere('day_group', DayGroup::MoSo->value));

        // C: alle drei Stunde 10
        $derivedC = collect($preview->derivedPrices([
            ['hour' => 10, 'day_group' => DayGroup::MoFr, 'second_price' => '1.0000'],
            ['hour' => 10, 'day_group' => DayGroup::Sa, 'second_price' => '1.0000'],
            ['hour' => 10, 'day_group' => DayGroup::So, 'second_price' => '1.0000'],
        ]));
        $this->assertSame(
            '1.0000',
            $derivedC->firstWhere('day_group', DayGroup::MoSa->value)['second_price'] ?? null,
        );
        $this->assertSame(
            '1.0000',
            $derivedC->firstWhere('day_group', DayGroup::MoSo->value)['second_price'] ?? null,
        );

        // D: unterschiedliche Zeitfenster
        $windowItems = [];
        for ($hour = 6; $hour <= 22; $hour++) {
            $windowItems[] = ['hour' => $hour, 'day_group' => DayGroup::MoFr->value, 'second_price' => '1.0000'];
        }
        for ($hour = 8; $hour <= 20; $hour++) {
            $windowItems[] = ['hour' => $hour, 'day_group' => DayGroup::Sa->value, 'second_price' => '1.5000'];
        }
        for ($hour = 10; $hour <= 18; $hour++) {
            $windowItems[] = ['hour' => $hour, 'day_group' => DayGroup::So->value, 'second_price' => '0.8000'];
        }
        $this->actingAs($admin)->post(route('administration.price-lists.store'), [
            'inventory_id' => $catalog['rock']->id,
            'year' => $year,
            'name' => 'Fenster',
            'items' => $windowItems,
        ])->assertRedirect();
        $windows = PriceList::query()->where('name', 'Fenster')->firstOrFail();
        $this->activate($admin, $windows);
        $windows->load('items');
        $this->assertSame('1.0000', $resolver->findSecondPrice($windows, 6, DayGroup::MoFr));
        $this->assertNull($resolver->findSecondPrice($windows, 6, DayGroup::Sa));
        $this->assertSame('1.5000', $resolver->findSecondPrice($windows, 8, DayGroup::Sa));
        $this->assertNull($resolver->findSecondPrice($windows, 8, DayGroup::So));
        $this->assertSame('0.8000', $resolver->findSecondPrice($windows, 10, DayGroup::So));
        $this->assertNull($resolver->findSecondPrice($windows, 22, DayGroup::Sa));
        $this->assertNull($resolver->findSecondPrice($windows, 9, DayGroup::So));

        // E: leere Liste – Draft ok, Aktivierung blockiert
        $this->actingAs($admin)->post(route('administration.price-lists.store'), [
            'inventory_id' => $catalog['rock']->id,
            'year' => $year,
            'name' => 'Leer',
            'items' => [],
        ])->assertRedirect();
        $empty = PriceList::query()->where('name', 'Leer')->firstOrFail();
        $emptyPreview = $this->actingAs($admin)
            ->postJson(route('administration.price-lists.activate-preview', $empty))
            ->assertOk()
            ->json();
        $this->assertFalse($emptyPreview['can_proceed']);
        $this->assertStringContainsString(
            'mindestens ein gültiger Basispreis',
            (string) ($emptyPreview['blocking_reasons'][0]['message'] ?? ''),
        );
    }

    public function test_validation_rejects_invalid_duplicate_and_derived_prices(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $admin = User::factory()->role(Role::Admin)->create();
        $year = PriceListCalendar::currentYear();

        $this->actingAs($admin)
            ->post(route('administration.price-lists.store'), [
                'inventory_id' => $catalog['hamburg']->id,
                'year' => $year,
                'name' => 'Ungültig',
                'items' => [
                    ['hour' => 8, 'day_group' => 'mo_fr', 'second_price' => '-1'],
                ],
            ])
            ->assertSessionHasErrors('items');

        $this->actingAs($admin)
            ->post(route('administration.price-lists.store'), [
                'inventory_id' => $catalog['hamburg']->id,
                'year' => $year,
                'name' => 'Duplikat',
                'items' => [
                    ['hour' => 8, 'day_group' => 'mo_fr', 'second_price' => '1.0'],
                    ['hour' => 8, 'day_group' => 'mo_fr', 'second_price' => '2.0'],
                ],
            ])
            ->assertSessionHasErrors('items');

        $this->actingAs($admin)
            ->post(route('administration.price-lists.store'), [
                'inventory_id' => $catalog['hamburg']->id,
                'year' => $year,
                'name' => 'Abgeleitet',
                'items' => [
                    ['hour' => 8, 'day_group' => 'mo_sa', 'second_price' => '1.0'],
                ],
            ])
            ->assertSessionHasErrors('items');

        $this->actingAs($admin)
            ->post(route('administration.price-lists.store'), [
                'inventory_id' => $catalog['hamburg']->id,
                'year' => $year,
                'name' => 'Präzision',
                'items' => [
                    ['hour' => 8, 'day_group' => 'mo_fr', 'second_price' => '1.23456'],
                ],
            ])
            ->assertSessionHasErrors('items');
    }

    public function test_json_update_returns_base_items_and_advances_lock_version(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $admin = User::factory()->role(Role::Admin)->create();
        $year = PriceListCalendar::currentYear();

        $this->actingAs($admin)->post(route('administration.price-lists.store'), [
            'inventory_id' => $catalog['rock']->id,
            'year' => $year,
            'name' => 'JSON Draft',
            'items' => $this->hourItems(8, '1.0000'),
        ])->assertRedirect();
        $draft = PriceList::query()->where('name', 'JSON Draft')->firstOrFail();

        $first = $this->actingAs($admin)
            ->putJson(route('administration.price-lists.update', $draft), [
                'name' => 'JSON Draft v2',
                'lock_version' => $draft->lock_version,
                'items' => $this->hourItems(8, '1.5000'),
            ])
            ->assertOk()
            ->json();
        $this->assertSame(2, $first['lock_version']);
        $this->assertSame('JSON Draft v2', $first['priceList']['name']);
        $this->assertNotEmpty($first['baseItems']);
        $this->assertSame('1.5000', $first['baseItems'][0]['second_price'] ?? null);

        $second = $this->actingAs($admin)
            ->putJson(route('administration.price-lists.update', $draft), [
                'name' => 'JSON Draft v3',
                'lock_version' => $first['lock_version'],
                'items' => $this->hourItems(8, '1.7500'),
            ])
            ->assertOk()
            ->json();
        $this->assertSame(3, $second['lock_version']);
        $this->assertSame('1.7500', $second['baseItems'][0]['second_price'] ?? null);
    }

    public function test_cardinality_allows_multiple_drafts_and_archives_but_not_two_actives(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $admin = User::factory()->role(Role::Admin)->create();
        $year = PriceListCalendar::currentYear();
        $kombi = Inventory::factory()->create([
            'organization_id' => $catalog['hamburg']->organization_id,
            'name' => 'Hamburg Kombi',
            'code' => 'HK',
            'type' => InventoryType::Kombi,
        ]);

        $this->actingAs($admin)->post(route('administration.price-lists.store'), [
            'inventory_id' => $kombi->id,
            'year' => $year,
            'name' => 'Kombi Draft A',
            'items' => $this->hourItems(10, '3.0000'),
        ])->assertRedirect();
        $this->actingAs($admin)->post(route('administration.price-lists.store'), [
            'inventory_id' => $kombi->id,
            'year' => $year,
            'name' => 'Kombi Draft B',
            'items' => $this->hourItems(10, '4.0000'),
        ])->assertRedirect();

        $this->assertSame(2, PriceList::query()->where('inventory_id', $kombi->id)->where('status', PriceListStatus::Draft)->count());

        $first = PriceList::query()->where('name', 'Kombi Draft A')->firstOrFail();
        $second = PriceList::query()->where('name', 'Kombi Draft B')->firstOrFail();
        $this->activate($admin, $first);

        $future = PriceList::factory()->create([
            'inventory_id' => $kombi->id,
            'year' => $year + 1,
            'status' => PriceListStatus::Active,
            'name' => 'Kombi Zukunft',
        ]);
        PriceListItem::factory()->create([
            'price_list_id' => $future->id,
            'hour' => 10,
            'day_group' => DayGroup::MoFr,
            'second_price' => '5.0000',
        ]);

        $this->assertSame(1, PriceList::query()->where('inventory_id', $kombi->id)->where('year', $year)->where('status', PriceListStatus::Active)->count());
        $this->assertTrue(PriceList::query()->whereKey($future->id)->where('status', PriceListStatus::Active)->exists());

        $this->activate($admin, $second);
        $this->assertSame(PriceListStatus::Archived, $first->fresh()->status);
        $this->assertSame(PriceListStatus::Active, $second->fresh()->status);
        $this->assertSame(PriceListStatus::Active, $future->fresh()->status);

        $this->expectException(QueryException::class);
        PriceList::factory()->create([
            'inventory_id' => $kombi->id,
            'year' => $year,
            'status' => PriceListStatus::Active,
            'name' => 'Zweite Active',
        ]);
    }

    public function test_stale_preview_and_missing_lock_version_are_conflicts(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $admin = User::factory()->role(Role::Admin)->create();
        $year = PriceListCalendar::currentYear();

        $this->actingAs($admin)->post(route('administration.price-lists.store'), [
            'inventory_id' => $catalog['rock']->id,
            'year' => $year,
            'name' => 'Draft A',
            'items' => $this->hourItems(8, '1.0000'),
        ]);
        $this->actingAs($admin)->post(route('administration.price-lists.store'), [
            'inventory_id' => $catalog['rock']->id,
            'year' => $year,
            'name' => 'Draft B',
            'items' => $this->hourItems(8, '2.0000'),
        ]);
        $draftA = PriceList::query()->where('name', 'Draft A')->firstOrFail();
        $draftB = PriceList::query()->where('name', 'Draft B')->firstOrFail();

        $previewA = $this->actingAs($admin)
            ->postJson(route('administration.price-lists.activate-preview', $draftA))
            ->json();
        $this->activate($admin, $draftB);

        $this->actingAs($admin)
            ->postJson(route('administration.price-lists.activate', $draftA), [
                'lock_version' => $draftA->lock_version,
                'fingerprint' => $previewA['fingerprint'],
            ])
            ->assertStatus(409);

        $this->actingAs($admin)
            ->putJson(route('administration.price-lists.update', $draftA), [
                'name' => 'Draft A',
                'items' => $this->hourItems(8, '1.0000'),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('lock_version');
    }

    public function test_kombi_type_remains_visible_and_independent(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $admin = User::factory()->role(Role::Admin)->create();
        $kombi = Inventory::factory()->create([
            'organization_id' => $catalog['hamburg']->organization_id,
            'type' => InventoryType::Kombi,
            'name' => 'Kombi Nord',
            'code' => 'KN',
        ]);

        $this->actingAs($admin)->post(route('administration.price-lists.store'), [
            'inventory_id' => $kombi->id,
            'year' => PriceListCalendar::currentYear(),
            'name' => 'Kombi Liste',
            'items' => $this->hourItems(11, '0.5000'),
        ])->assertRedirect();

        $list = PriceList::query()->where('name', 'Kombi Liste')->firstOrFail();
        $this->actingAs($admin)
            ->get(route('administration.price-lists.show', $list))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('administration/price-lists/show')
                ->where('priceList.inventory.type', InventoryType::Kombi->value)
                ->where('priceList.inventory.type_label', 'Kombi'));
    }

    /**
     * @return list<array{hour: int, day_group: string, second_price: string}>
     */
    private function hourItems(int $hour, string $price): array
    {
        return [
            ['hour' => $hour, 'day_group' => DayGroup::MoFr->value, 'second_price' => $price],
            ['hour' => $hour, 'day_group' => DayGroup::Sa->value, 'second_price' => $price],
            ['hour' => $hour, 'day_group' => DayGroup::So->value, 'second_price' => $price],
        ];
    }

    private function activate(User $admin, PriceList $list): void
    {
        $preview = $this->actingAs($admin)
            ->postJson(route('administration.price-lists.activate-preview', $list))
            ->json();

        $this->actingAs($admin)
            ->postJson(route('administration.price-lists.activate', $list), [
                'lock_version' => $list->fresh()->lock_version,
                'fingerprint' => $preview['fingerprint'],
            ])
            ->assertOk();
    }
}
