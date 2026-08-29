<?php

namespace Tests\Feature\Calculation;

use App\Enums\Role;
use App\Models\Inventory;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesSpotClassicCatalog;
use Tests\TestCase;

class InventoryLogoTest extends TestCase
{
    use CreatesSpotClassicCatalog;
    use RefreshDatabase;

    public function test_wizard_catalog_liefert_logo_path_fuer_sender_mit_logo(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $catalog['hamburg']->update([
            'logo_path' => '/images/senders/radio-hamburg.png',
        ]);

        $oldie = Inventory::factory()->create([
            'organization_id' => $catalog['organization']->id,
            'name' => '80er 90er OLDIE ANTENNE Hamburg',
            'code' => 'OAH',
            'sort' => 3,
            'logo_path' => '/images/senders/80er-90er-oldie-antenne-hamburg.png',
        ]);

        $user = User::factory()->role(Role::Sales)->create();

        $this->actingAs($user)
            ->get(route('calculations.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('calculations/wizard')
                ->has('catalog.inventories', 3)
                ->where('catalog.inventories.0.logo_path', '/images/senders/radio-hamburg.png')
                ->where('catalog.inventories.1.logo_path', null)
                ->where('catalog.inventories.2.logo_path', '/images/senders/80er-90er-oldie-antenne-hamburg.png')
                ->where('catalog.inventories.2.name', $oldie->name));
    }

    public function test_logo_path_backfill_setzt_pfade_fuer_rh_und_oah(): void
    {
        $this->createSpotClassicCatalog();

        Inventory::factory()->create([
            'name' => '80er 90er OLDIE ANTENNE Hamburg',
            'code' => 'OAH',
            'sort' => 3,
        ]);

        /** @var Migration $migration */
        $migration = require database_path('migrations/2026_08_29_170000_backfill_inventory_logo_paths.php');
        $migration->up();

        $this->assertSame(
            '/images/senders/radio-hamburg.png',
            Inventory::query()->where('code', 'RH')->value('logo_path'),
        );
        $this->assertSame(
            '/images/senders/80er-90er-oldie-antenne-hamburg.png',
            Inventory::query()->where('code', 'OAH')->value('logo_path'),
        );
        $this->assertNull(
            Inventory::query()->where('code', 'RAH')->value('logo_path'),
        );
    }
}
