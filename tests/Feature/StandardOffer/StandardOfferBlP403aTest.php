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
