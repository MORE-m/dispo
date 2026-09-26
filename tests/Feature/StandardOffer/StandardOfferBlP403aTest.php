<?php

namespace Tests\Feature\StandardOffer;

use App\Enums\Role;
use App\Enums\StandardOfferVersionStatus;
use App\Models\AuditEvent;
use App\Models\Inventory;
use App\Models\PriceListItem;
use App\Models\StandardOffer;
use App\Models\StandardOfferVersion;
use App\Models\User;
use App\Services\Calculation\CalculationWriter;
use App\Services\StandardOffer\StandardOfferWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesSpotClassicCatalog;
use Tests\TestCase;

/**
 * BL-P4-03a / STD-001–STD-009 / AUTH-006 / AUTH-007 / VER-004 / AT-28–AT-31.
 */
class StandardOfferBlP403aTest extends TestCase
{
    use CreatesSpotClassicCatalog;
    use RefreshDatabase;

    public function test_roles_visibility_and_auth_007(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $pm = User::factory()->role(Role::ProductManagement)->create();
        $sales = User::factory()->role(Role::Sales)->create();
        $disposition = User::factory()->role(Role::Disposition)->create();
        $offer = $this->createPublishedOffer($catalog, $pm);

        $this->actingAs($disposition)->get(route('standard-offers.index'))->assertForbidden();
        $this->actingAs($pm)->get(route('standard-offers.index'))->assertOk();
        $this->actingAs($sales)->get(route('standard-offers.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('standard-offers/index')
                ->where('canManage', false)
                ->has('offers', 1));

        $this->actingAs($pm)->get(route('calculations.index'))->assertForbidden();
        $this->actingAs($pm)->get(route('dispo-orders.index'))->assertForbidden();

        $draft = $this->writer()->createDraftFromPublished($offer, $pm);
        $this->actingAs($sales)->get(route('standard-offers.show', [
            'standardOffer' => $offer,
            'version' => $draft->id,
        ]))->assertForbidden();
    }

    public function test_at_31_publish_archive_and_audit(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $pm = User::factory()->role(Role::ProductManagement)->create();

        $offer = $this->writer()->create('AT-31 Vorlage', $this->draftPayload($catalog), $pm);
        $draft = $offer->draftVersion;
        $this->assertNotNull($draft);

        $published = $this->writer()->publish($draft, (int) $draft->lock_version, $pm);
        $this->assertSame(StandardOfferVersionStatus::Published, $published->status);
        $this->assertNotNull($published->published_at);
        $this->assertNotNull($published->frozen_materialization);
        $this->assertTrue(AuditEvent::query()->where('action', 'standard_offer.version.published')->exists());

        $archived = $this->writer()->archive($published, (int) $published->lock_version, $pm);
        $this->assertSame(StandardOfferVersionStatus::Archived, $archived->status);
        $this->assertTrue(AuditEvent::query()->where('action', 'standard_offer.version.archived')->exists());
    }

    public function test_publish_archives_previous_published_atomically(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $pm = User::factory()->role(Role::ProductManagement)->create();
        $offer = $this->createPublishedOffer($catalog, $pm);
        $first = $offer->publishedVersion;
        $this->assertNotNull($first);

        $draft = $this->writer()->createDraftFromPublished($offer, $pm);
        $second = $this->writer()->publish($draft, (int) $draft->lock_version, $pm);

        $this->assertSame(StandardOfferVersionStatus::Published, $second->fresh()->status);
        $this->assertSame(StandardOfferVersionStatus::Archived, $first->fresh()->status);
        $this->assertSame(1, StandardOfferVersion::query()
            ->where('standard_offer_id', $offer->id)
            ->where('status', StandardOfferVersionStatus::Published->value)
            ->count());
    }

    public function test_concurrent_publish_second_fails_lock_version(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $pm = User::factory()->role(Role::ProductManagement)->create();
        $offer = $this->writer()->create('Konkurrenz', $this->draftPayload($catalog), $pm);
        $draft = $offer->draftVersion;
        $this->assertNotNull($draft);
        $staleLock = (int) $draft->lock_version;

        $this->writer()->publish($draft, $staleLock, $pm);

        $this->expectException(ValidationException::class);
        $this->writer()->publish($draft->fresh(), $staleLock, $pm);
    }

    public function test_at_28_adopt_isolation_both_directions(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $pm = User::factory()->role(Role::ProductManagement)->create();
        $sales = User::factory()->role(Role::Sales)->create();
        $offer = $this->createPublishedOffer($catalog, $pm);
        $published = $offer->publishedVersion;
        $this->assertNotNull($published);

        $nnBefore = (string) ($published->frozen_materialization['nn_invest'] ?? '');
        $calculation = $this->writer()->adopt($published, 'Kunde AT-28', null, 'Kampagne', $sales);

        $this->assertSame('Kunde AT-28', $calculation->customer_name);
        $this->assertSame($published->id, $calculation->origin_standard_offer_version_id);
        $this->assertSame($nnBefore, (string) $calculation->nn_invest);
        $this->assertNotNull($calculation->positions()->first()?->price_list_id);

        $position = $calculation->positions()->firstOrFail();
        $pinnedListId = (int) $position->price_list_id;
        $originalSecond = (string) $position->planRows()->firstOrFail()->second_price;

        PriceListItem::query()->where('price_list_id', $pinnedListId)->update(['second_price' => '9.9999']);
        $catalog['hamburg']->update(['name' => 'UMBENANNT']);

        $calculation->refresh()->load('positions.planRows');
        $this->assertSame($originalSecond, (string) $calculation->positions->first()->planRows->first()->second_price);
        $this->assertSame('Radio Hamburg', $calculation->positions->first()->inventory_name);

        $draft = $this->writer()->createDraftFromPublished($offer->fresh(), $pm);
        $payload = $draft->draft_payload;
        $payload['positions'][0]['total_spot_count'] = 99;
        $payload['positions'][0]['time_ranges'][0]['spot_count'] = 99;
        $this->writer()->updateDraft($draft, $draft->title, $payload, (int) $draft->lock_version, $pm);
        $this->writer()->publish($draft->fresh(), (int) $draft->fresh()->lock_version, $pm);

        $calculation->refresh();
        $this->assertSame(10, (int) $calculation->positions()->first()->total_spot_count);
        $this->assertSame($nnBefore, (string) $calculation->nn_invest);

        $calculation->positions()->first()->update(['total_spot_count' => 3]);
        $publishedAfter = StandardOfferVersion::query()
            ->where('standard_offer_id', $offer->id)
            ->where('status', StandardOfferVersionStatus::Published->value)
            ->firstOrFail();
        $this->assertNotSame(3, (int) ($publishedAfter->frozen_materialization['positions'][0]['total_spot_count'] ?? 0));
    }

    public function test_at_29_dispo_not_from_standard_offer_and_pm_cannot_adopt(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $pm = User::factory()->role(Role::ProductManagement)->create();
        $offer = $this->createPublishedOffer($catalog, $pm);
        $published = $offer->publishedVersion;
        $this->assertNotNull($published);

        $this->actingAs($pm)->post(route('standard-offers.adopt', [
            'standardOffer' => $offer,
            'version' => $published,
        ]), [
            'customer_name' => 'Unerlaubt',
        ])->assertForbidden();

        $this->assertFalse(
            collect(Route::getRoutes())->contains(
                fn ($route): bool => str_contains((string) $route->uri(), 'standardangebote')
                    && str_contains((string) $route->uri(), 'dispo'),
            ),
        );
    }

    public function test_rejects_non_average_content(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $pm = User::factory()->role(Role::ProductManagement)->create();
        $payload = $this->draftPayload($catalog);
        $payload['positions'][0]['spot_method'] = 'calendar';

        $this->expectException(ValidationException::class);
        $this->writer()->create('Ungültig', $payload, $pm);
    }

    public function test_adopt_requires_customer_name(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $pm = User::factory()->role(Role::ProductManagement)->create();
        $sales = User::factory()->role(Role::Sales)->create();
        $offer = $this->createPublishedOffer($catalog, $pm);

        $this->expectException(ValidationException::class);
        $this->writer()->adopt($offer->publishedVersion, '  ', null, null, $sales);
    }

    public function test_navigation_available_for_standard_offers(): void
    {
        $sales = User::factory()->role(Role::Sales)->create();
        $this->actingAs($sales)
            ->get(route('standard-offers.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('standard-offers/index'));
    }

    public function test_pm_create_uses_calculation_wizard_template_mode(): void
    {
        $this->createSpotClassicCatalog();
        $pm = User::factory()->role(Role::ProductManagement)->create();

        $this->actingAs($pm)
            ->get(route('standard-offers.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('calculations/wizard')
                ->where('canCreateDispoOrder', false)
                ->where('standardOffer.mode', 'create')
                ->where('standardOffer.allowed_spot_methods.0', 'average'));
    }

    public function test_multi_position_draft_roundtrip_preserves_average_fields(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $pm = User::factory()->role(Role::ProductManagement)->create();
        $payload = $this->draftPayload($catalog);
        $second = $payload['positions'][0];
        $second['total_spot_count'] = 4;
        $second['time_ranges'][0]['spot_count'] = 4;
        $second['ae_percent'] = '10';
        $second['position_discounts'] = [[
            'type' => 'special',
            'custom_label' => null,
            'percent' => '5',
        ]];
        $payload['positions'][] = $second;
        $payload['order_discounts'] = [[
            'type' => 'special',
            'custom_label' => null,
            'percent' => '2',
        ]];
        $payload['campaign'] = 'Kampagne Multi';
        $payload['product_title'] = 'Produkt Multi';
        $payload['briefing'] = 'Briefing Text';
        $payload['dynamic_field_values'] = ['campaign_period' => null];

        $offer = $this->writer()->create('Multi Vorlage', $payload, $pm);
        $draft = $offer->draftVersion;
        $this->assertNotNull($draft);
        $this->assertCount(2, $draft->draft_payload['positions'] ?? []);
        $this->assertSame('Kampagne Multi', $draft->draft_payload['campaign'] ?? null);
        $this->assertSame('2', (string) ($draft->draft_payload['order_discounts'][0]['percent'] ?? ''));
        $this->assertSame(4, (int) ($draft->draft_payload['positions'][1]['total_spot_count'] ?? 0));

        $this->actingAs($pm)
            ->get(route('standard-offers.show', [
                'standardOffer' => $offer,
                'version' => $draft->id,
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('calculations/wizard')
                ->where('standardOffer.mode', 'edit')
                ->has('calculation.positions', 2)
                ->where('calculation.campaign', 'Kampagne Multi')
                ->where('calculation.product_title', 'Produkt Multi')
                ->where('calculation.briefing', 'Briefing Text')
                ->where('calculation.positions.1.total_spot_count', 4));
    }

    public function test_parallel_draft_does_not_change_sales_published_face(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $pm = User::factory()->role(Role::ProductManagement)->create();
        $sales = User::factory()->role(Role::Sales)->create();
        $offer = $this->createPublishedOffer($catalog, $pm);
        $published = $offer->publishedVersion;
        $this->assertNotNull($published);
        $publishedTitle = $published->title;
        $publishedSpotCount = (int) ($published->frozen_materialization['positions'][0]['total_spot_count'] ?? 0);

        $draft = $this->writer()->createDraftFromPublished($offer->fresh(), $pm);
        $payload = $draft->draft_payload ?? [];
        $payload['positions'][0]['total_spot_count'] = 77;
        $payload['positions'][0]['time_ranges'][0]['spot_count'] = 77;
        $this->writer()->updateDraft(
            $draft,
            'DRAFT-TITEL-ABWEICHEND',
            $payload,
            (int) $draft->lock_version,
            $pm,
        );

        $offer->refresh()->load(['publishedVersion', 'draftVersion']);
        $this->assertSame($publishedTitle, $offer->title);
        $this->assertSame('DRAFT-TITEL-ABWEICHEND', $offer->draftVersion?->title);
        $this->assertSame($publishedTitle, $offer->publishedVersion?->title);

        $this->actingAs($sales)->get(route('standard-offers.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('standard-offers/index')
                ->where('canManage', false)
                ->has('offers', 1)
                ->where('offers.0.title', $publishedTitle)
                ->where('offers.0.published_version_id', $published->id)
                ->where('offers.0.draft_version_id', null)
                ->where('offers.0.has_draft', false));

        $this->actingAs($sales)->get(route('standard-offers.show', [
            'standardOffer' => $offer,
        ]))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('standard-offers/show')
                ->where('offer.title', $publishedTitle)
                ->where('version.id', $published->id)
                ->where('version.title', $publishedTitle)
                ->where('version.status', StandardOfferVersionStatus::Published->value)
                ->has('versions', 1)
                ->where('versions.0.id', $published->id)
                ->where(
                    'version.draft_payload.positions.0.total_spot_count',
                    $publishedSpotCount,
                ));
    }

    public function test_draft_content_change_is_audited_with_old_and_new_values(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $pm = User::factory()->role(Role::ProductManagement)->create();
        $offer = $this->writer()->create('Audit Vorlage', $this->draftPayload($catalog), $pm);
        $draft = $offer->draftVersion;
        $this->assertNotNull($draft);

        $payload = $draft->draft_payload ?? [];
        $this->assertSame(10, (int) ($payload['positions'][0]['total_spot_count'] ?? 0));
        $payload['positions'][0]['total_spot_count'] = 42;
        $payload['positions'][0]['time_ranges'][0]['spot_count'] = 42;

        $this->writer()->updateDraft(
            $draft,
            $draft->title,
            $payload,
            (int) $draft->lock_version,
            $pm,
        );

        $audit = AuditEvent::query()
            ->where('action', 'standard_offer.version.updated')
            ->where('auditable_type', StandardOfferVersion::class)
            ->where('auditable_id', $draft->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($audit);
        $this->assertIsArray($audit->old_values);
        $this->assertIsArray($audit->new_values);
        $this->assertSame(10, (int) ($audit->old_values['draft_payload']['positions'][0]['total_spot_count'] ?? 0));
        $this->assertSame(42, (int) ($audit->new_values['draft_payload']['positions'][0]['total_spot_count'] ?? 0));
        $this->assertNotSame(
            $audit->old_values['lock_version'] ?? null,
            $audit->new_values['lock_version'] ?? null,
        );
    }

    public function test_adopt_then_calculation_writer_update_recalculates_without_mutating_template(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $pm = User::factory()->role(Role::ProductManagement)->create();
        $sales = User::factory()->role(Role::Sales)->create();
        $offer = $this->createPublishedOffer($catalog, $pm);
        $published = $offer->publishedVersion;
        $this->assertNotNull($published);

        $nnBefore = (string) ($published->frozen_materialization['nn_invest'] ?? '');
        $spotBefore = (int) ($published->frozen_materialization['positions'][0]['total_spot_count'] ?? 0);
        $priceListId = (int) ($published->frozen_materialization['positions'][0]['price_list_id'] ?? 0);
        $this->assertGreaterThan(0, $spotBefore);
        $this->assertGreaterThan(0, $priceListId);

        $calculation = $this->writer()->adopt($published, 'Kunde Update', null, null, $sales);
        $calcWriter = app(CalculationWriter::class);
        $payload = $calcWriter->payloadFromCalculation($calculation->fresh([
            'positions.planRows',
            'positions.timeRanges',
            'positions.discounts',
            'orderDiscounts',
            'configurationSnapshot',
            'fieldValues',
        ]));
        $payload['lock_version'] = $calculation->lock_version;
        $payload['positions'][0]['total_spot_count'] = 25;
        if (isset($payload['positions'][0]['time_ranges'][0])) {
            $payload['positions'][0]['time_ranges'][0]['spot_count'] = 25;
        }

        $updated = $calcWriter->update($calculation->fresh(), $payload, $sales);
        $updatedPosition = $updated->positions()->firstOrFail();

        $this->assertSame(25, (int) $updatedPosition->total_spot_count);
        $this->assertSame($priceListId, (int) $updatedPosition->price_list_id);
        $this->assertNotSame($nnBefore, (string) $updated->nn_invest);
        $this->assertSame($published->id, $updated->origin_standard_offer_version_id);

        $publishedAfter = $published->fresh();
        $this->assertSame(
            $spotBefore,
            (int) ($publishedAfter->frozen_materialization['positions'][0]['total_spot_count'] ?? 0),
        );
        $this->assertSame($nnBefore, (string) ($publishedAfter->frozen_materialization['nn_invest'] ?? ''));
        $this->assertSame(StandardOfferVersionStatus::Published, $publishedAfter->status);
    }

    /**
     * @param  array{hamburg: Inventory, medium: mixed}  $catalog
     */
    private function createPublishedOffer(array $catalog, User $author): StandardOffer
    {
        $offer = $this->writer()->create('Pub Vorlage', $this->draftPayload($catalog), $author);
        $draft = $offer->draftVersion;
        $this->assertNotNull($draft);
        $this->writer()->publish($draft, (int) $draft->lock_version, $author);

        return $offer->fresh(['publishedVersion', 'draftVersion']) ?? $offer;
    }

    /**
     * @param  array{hamburg: Inventory, medium: mixed}  $catalog
     * @return array<string, mixed>
     */
    private function draftPayload(array $catalog): array
    {
        return $this->withLiveSchemaFingerprint([
            'planning_mode' => 'manual',
            'order_discount_percent' => '0',
            'ae_enabled' => false,
            'positions' => [[
                'inventory_id' => $catalog['hamburg']->id,
                'advertising_medium_id' => $catalog['medium']->id,
                'spot_method' => 'average',
                'length_seconds' => 30,
                'total_spot_count' => 10,
                'position_discount_percent' => '0',
                'ae_percent' => '0',
                'pricing_settlement_mode' => 'normal',
                'plan_rows' => [[
                    'hour' => 8,
                    'day_group' => 'mo_fr',
                ]],
                'time_ranges' => [[
                    'start_hour' => 8,
                    'end_hour_exclusive' => 9,
                    'day_group' => 'mo_fr',
                    'spot_count' => 10,
                ]],
            ]],
        ]);
    }

    private function writer(): StandardOfferWriter
    {
        return app(StandardOfferWriter::class);
    }
}
