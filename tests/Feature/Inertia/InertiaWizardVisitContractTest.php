<?php

namespace Tests\Feature\Inertia;

use App\Enums\Role;
use App\Models\Calculation;
use App\Models\CrmAccount;
use App\Models\User;
use App\Services\Calculation\CalculationWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Inertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Vertrag der Wizard-Visits gegen inertiajs/inertia-laravel 3.5.1.
 *
 * Deferred Props werden im Projekt nicht registriert. BigInt-Erhalt bleibt
 * aus (config inertia.preserve_big_integers fehlt, Standard false).
 */
final class InertiaWizardVisitContractTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function initial_html_escapes_tags_inside_the_page_json(): void
    {
        $sales = User::factory()->role(Role::Sales)->create();
        $calculation = $this->storedCalculation($sales, 'Sichtbarer Kunde');
        $breakout = 'Hinweis <!--<script>alert(1)</script>';
        $calculation->forceFill(['customer_name' => $breakout])->save();

        $html = $this->actingAs($sales)
            ->get(route('calculations.edit', $calculation))
            ->assertOk()
            ->getContent();

        $this->assertIsString($html);
        $this->assertStringNotContainsString('<!--<script>', $html);
        $this->assertStringContainsString('\\u003Cscript\\u003E', $html);

        $page = $this->actingAs($sales)
            ->withHeaders($this->inertiaHeaders())
            ->get(route('calculations.edit', $calculation))
            ->assertOk()
            ->json();

        $this->assertSame($breakout, $page['props']['calculation']['customer_name']);
        $this->assertIsInt($page['props']['calculation']['id']);
        $this->assertArrayNotHasKey('preserveBigIntegers', $page);
    }

    #[Test]
    public function wizard_save_redirects_and_reload_keeps_the_binding(): void
    {
        $sales = User::factory()->role(Role::Sales)->create();

        $response = $this->actingAs($sales)
            ->withHeaders($this->inertiaHeaders())
            ->post(route('calculations.store'), $this->headerPayload('Redirect Kunde GmbH'));

        $calculation = Calculation::query()->firstOrFail();
        $response->assertRedirect(route('calculations.edit', $calculation));
        $response->assertSessionHas('success', 'Kalkulation gespeichert.');

        $reloaded = $this->actingAs($sales)
            ->withHeaders($this->inertiaHeaders())
            ->get(route('calculations.edit', $calculation))
            ->assertOk()
            ->json();

        $this->assertSame($calculation->id, $reloaded['props']['calculation']['id']);
        $this->assertSame($calculation->customer_account_id, $reloaded['props']['calculation']['customer_account_id']);
        $this->assertSame('Redirect Kunde GmbH', $reloaded['props']['calculation']['customer_name']);
        $this->assertSame('Kalkulation gespeichert.', $reloaded['props']['flash']['success']);

        $again = $this->actingAs($sales)
            ->withHeaders($this->inertiaHeaders())
            ->get(route('calculations.edit', $calculation))
            ->assertOk()
            ->json();

        $this->assertSame($calculation->id, $again['props']['calculation']['id']);
        $this->assertSame($calculation->customer_account_id, $again['props']['calculation']['customer_account_id']);
        $this->assertNull($again['props']['flash']['success']);
    }

    #[Test]
    public function repeated_save_does_not_add_accounts_or_positions(): void
    {
        $sales = User::factory()->role(Role::Sales)->create();
        $calculation = $this->storedCalculation($sales, 'Nochmals GmbH');
        $accountId = $calculation->customer_account_id;
        $accounts = CrmAccount::query()->count();
        $positions = $calculation->positions()->count();

        $payload = $this->headerPayload('Nochmals GmbH');
        $payload['customer_account_id'] = $accountId;
        $payload['lock_version'] = $calculation->lock_version;

        $this->actingAs($sales)
            ->from(route('calculations.edit', $calculation))
            ->withHeaders($this->inertiaHeaders())
            ->put(route('calculations.update', $calculation), $payload)
            ->assertRedirect(route('calculations.edit', $calculation));

        $fresh = $calculation->fresh();
        $this->assertSame($accounts, CrmAccount::query()->count());
        $this->assertSame($positions, $fresh->positions()->count());
        $this->assertSame($accountId, $fresh->customer_account_id);
        $this->assertSame($calculation->lock_version + 1, $fresh->lock_version);
    }

    #[Test]
    public function validation_error_keeps_the_saved_calculation_and_exposes_the_message(): void
    {
        $sales = User::factory()->role(Role::Sales)->create();
        $calculation = $this->storedCalculation($sales, 'Bleibt GmbH');
        $accounts = CrmAccount::query()->count();

        $payload = $this->headerPayload('Bleibt GmbH');
        $payload['customer_account_id'] = $calculation->customer_account_id;
        $payload['invoice_recipient'] = 'agency';
        $payload['lock_version'] = $calculation->lock_version;

        $this->actingAs($sales)
            ->from(route('calculations.edit', $calculation))
            ->withHeaders($this->inertiaHeaders())
            ->put(route('calculations.update', $calculation), $payload)
            ->assertRedirect(route('calculations.edit', $calculation))
            ->assertSessionHasErrors('invoice_recipient');

        $fresh = $calculation->fresh();
        $this->assertSame('Bleibt GmbH', $fresh->customer_name);
        $this->assertSame($calculation->lock_version, $fresh->lock_version);
        $this->assertSame($accounts, CrmAccount::query()->count());
        $this->assertSame($calculation->customer_account_id, $fresh->customer_account_id);
    }

    #[Test]
    public function stale_lock_version_stays_a_readable_validation_error(): void
    {
        $sales = User::factory()->role(Role::Sales)->create();
        $calculation = $this->storedCalculation($sales, 'Lock GmbH');

        $payload = $this->headerPayload('Lock GmbH');
        $payload['customer_account_id'] = $calculation->customer_account_id;
        $payload['lock_version'] = $calculation->lock_version + 4;

        $this->actingAs($sales)
            ->from(route('calculations.edit', $calculation))
            ->withHeaders($this->inertiaHeaders())
            ->put(route('calculations.update', $calculation), $payload)
            ->assertRedirect(route('calculations.edit', $calculation))
            ->assertSessionHasErrors([
                'lock_version' => 'Die Kalkulation wurde parallel geändert. Bitte neu laden.',
            ]);

        $this->assertSame($calculation->lock_version, $calculation->fresh()->lock_version);
        $this->assertSame('Lock GmbH', $calculation->fresh()->customer_name);
    }

    #[Test]
    public function schema_drift_stays_a_json_conflict_without_persisting(): void
    {
        $sales = User::factory()->role(Role::Sales)->create();
        $payload = $this->headerPayload('Konflikt GmbH');
        $payload['schema_fingerprint'] = str_repeat('a', 64);

        $this->actingAs($sales)
            ->withHeaders($this->inertiaHeaders())
            ->post(route('calculations.store'), $payload)
            ->assertStatus(409)
            ->assertJson([
                'message' => 'Die Feldkonfiguration hat sich geändert. Bitte neu laden und erneut speichern.',
            ]);

        $this->assertSame(0, Calculation::query()->count());
        $this->assertSame(0, CrmAccount::query()->count());
    }

    #[Test]
    public function partial_reload_replaces_only_the_requested_prop(): void
    {
        $sales = User::factory()->role(Role::Sales)->create();
        $calculation = $this->storedCalculation($sales, 'Partial GmbH');

        $page = $this->actingAs($sales)
            ->withHeaders($this->inertiaHeaders([
                'X-Inertia-Partial-Component' => 'calculations/wizard',
                'X-Inertia-Partial-Data' => 'calculation',
            ]))
            ->get(route('calculations.edit', $calculation))
            ->assertOk()
            ->json();

        $this->assertSame('calculations/wizard', $page['component']);
        $this->assertSame($calculation->id, $page['props']['calculation']['id']);
        $this->assertSame('Partial GmbH', $page['props']['calculation']['customer_name']);
        $this->assertArrayNotHasKey('catalog', $page['props']);
        $this->assertArrayNotHasKey('fieldSchema', $page['props']);
        $this->assertArrayNotHasKey('deferredProps', $page);
    }

    /**
     * @param  array<string, string>  $extra
     * @return array<string, string>
     */
    private function inertiaHeaders(array $extra = []): array
    {
        return [
            'X-Inertia' => 'true',
            'X-Requested-With' => 'XMLHttpRequest',
            'X-Inertia-Version' => (string) Inertia::getVersion(),
            ...$extra,
        ];
    }

    private function storedCalculation(User $sales, string $customerName): Calculation
    {
        return app(CalculationWriter::class)->create($this->headerPayload($customerName), $sales);
    }

    /**
     * @return array<string, mixed>
     */
    private function headerPayload(string $customerName): array
    {
        return $this->withLiveSchemaFingerprint([
            'planning_mode' => 'manual',
            'customer_name' => $customerName,
            'customer_account_id' => null,
            'agency_name' => null,
            'agency_account_id' => null,
            'invoice_recipient' => null,
            'campaign' => 'Inertia Vertrag',
            'product_title' => 'Titel',
            'order_discount_percent' => '0',
            'ae_enabled' => false,
            'positions' => [],
        ]);
    }
}
