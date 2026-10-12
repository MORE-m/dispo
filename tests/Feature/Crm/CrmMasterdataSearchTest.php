<?php

namespace Tests\Feature\Crm;

use App\Enums\CrmAccountType;
use App\Enums\CrmConflictType;
use App\Enums\Role;
use App\Models\CrmAccount;
use App\Models\CrmConflict;
use App\Models\User;
use App\Services\Crm\CrmAccountService;
use App\Support\Crm\SalesforceAccountId;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Stammdaten-Textsuche + Prüflisten-Labels (Audit P3 / PO-BLP203-1).
 */
final class CrmMasterdataSearchTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function index_search_finds_account_beyond_first_200_by_name_meridian_domain_and_salesforce_id(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $accounts = app(CrmAccountService::class);

        $needle = $accounts->createProvisional([
            'name' => 'Zeta Needle GmbH',
            'type' => CrmAccountType::Customer,
            'matching_domain' => 'zeta-needle.test',
            'billing_email' => 'a@zeta-needle.test',
        ], $admin);
        $needle->currentVersion?->forceFill(['meridian_number' => 'M-NEEDLE-9'])->save();
        $sf = SalesforceAccountId::normalize('001xx000003NEED');
        $this->assertNotNull($sf);
        $needle->forceFill([
            'salesforce_account_id_raw' => $sf['raw'],
            'salesforce_account_id_canonical' => $sf['canonical'],
            'is_provisional' => false,
        ])->save();

        for ($i = 0; $i < 201; $i++) {
            $accounts->createProvisional([
                'name' => sprintf('Filler Account %03d', $i),
                'type' => CrmAccountType::Customer,
            ], $admin);
        }

        $this->assertTrue(
            CrmAccount::query()->whereNull('merged_into_account_id')->count() > 200,
        );

        $withoutSearch = $this->actingAs($admin)->get(route('crm.accounts.index'));
        $withoutSearch->assertOk();
        $withoutSearch->assertInertia(fn (Assert $page) => $page
            ->component('crm/accounts/index')
            ->has('accounts', 50)
            ->where('pagination.total', fn ($total) => $total > 200)
            ->where('accounts', fn ($rows) => collect($rows)->every(
                fn (array $row): bool => $row['name'] !== 'Zeta Needle GmbH',
            )));

        foreach (['Zeta Needle', 'M-NEEDLE-9', 'zeta-needle.test', $sf['raw']] as $q) {
            $this->actingAs($admin)
                ->get(route('crm.accounts.index', ['q' => $q]))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->where('filters.q', $q)
                    ->where('pagination.total', 1)
                    ->has('accounts', 1)
                    ->where('accounts.0.name', 'Zeta Needle GmbH')
                    ->where('accounts.0.label.title', 'Zeta Needle GmbH'));
        }
    }

    #[Test]
    public function index_combines_search_with_type_and_provisional_filters_and_paginates(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $accounts = app(CrmAccountService::class);

        $customer = $accounts->createProvisional([
            'name' => 'Filterkunde Alpha',
            'type' => CrmAccountType::Customer,
        ], $admin);
        $accounts->createProvisional([
            'name' => 'Filterkunde Beta',
            'type' => CrmAccountType::Customer,
        ], $admin);
        $accounts->createProvisional([
            'name' => 'Filteragentur Alpha',
            'type' => CrmAccountType::Agency,
        ], $admin);

        $sf = SalesforceAccountId::normalize('001xx000003FILT');
        $this->assertNotNull($sf);
        $customer->forceFill([
            'salesforce_account_id_raw' => $sf['raw'],
            'salesforce_account_id_canonical' => $sf['canonical'],
            'is_provisional' => false,
        ])->save();

        $this->actingAs($admin)
            ->get(route('crm.accounts.index', [
                'q' => 'Filterkunde',
                'type' => 'customer',
                'provisional' => '1',
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.type', 'customer')
                ->where('filters.provisional', true)
                ->where('filters.q', 'Filterkunde')
                ->where('pagination.total', 1)
                ->where('accounts.0.name', 'Filterkunde Beta')
                ->where('accounts.0.is_provisional', true));

        for ($i = 0; $i < 55; $i++) {
            $accounts->createProvisional([
                'name' => sprintf('Page Filler %02d', $i),
                'type' => CrmAccountType::Agency,
            ], $admin);
        }

        $this->actingAs($admin)
            ->get(route('crm.accounts.index', ['type' => 'agency', 'page' => 2]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.type', 'agency')
                ->where('pagination.current_page', 2)
                ->where('pagination.per_page', 50)
                ->where('pagination.last_page', fn ($last) => $last >= 2));
    }

    #[Test]
    public function index_empty_search_and_forbidden_without_right(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $sales = User::factory()->role(Role::Sales)->create();
        app(CrmAccountService::class)->createProvisional([
            'name' => 'Vorhandener Account',
            'type' => CrmAccountType::Customer,
        ], $admin);

        $this->actingAs($admin)
            ->get(route('crm.accounts.index', ['q' => 'GibtsNichtXYZ']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('pagination.total', 0)
                ->has('accounts', 0));

        $this->actingAs($sales)
            ->get(route('crm.accounts.index', ['q' => 'Vorhandener']))
            ->assertOk();

        $pm = User::factory()->role(Role::ProductManagement)->create();
        $this->actingAs($pm)
            ->get(route('crm.accounts.index', ['q' => 'Vorhandener']))
            ->assertForbidden();
    }

    #[Test]
    public function match_queue_exposes_human_labels_and_conflict_summary_without_mutating_details(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $accounts = app(CrmAccountService::class);

        $source = $accounts->createProvisional([
            'name' => 'Gleicher Name GmbH',
            'type' => CrmAccountType::Customer,
            'matching_domain' => 'gleicher-name-a.test',
        ], $admin);

        $twin = $accounts->createProvisional([
            'name' => 'Gleicher Name GmbH',
            'type' => CrmAccountType::Customer,
            'matching_domain' => 'gleicher-name-b.test',
        ], $admin);
        $twin->currentVersion?->forceFill([
            'meridian_number' => 'M-TWIN',
            'salesforce_record_type' => 'Account KUNDE',
        ])->save();

        $target = $accounts->createProvisional([
            'name' => 'Salesforce Ziel AG',
            'type' => CrmAccountType::Customer,
            'matching_domain' => 'sf-ziel.test',
        ], $admin);
        $sf = SalesforceAccountId::normalize('001xx000003ZIEL');
        $this->assertNotNull($sf);
        $target->forceFill([
            'salesforce_account_id_raw' => $sf['raw'],
            'salesforce_account_id_canonical' => $sf['canonical'],
            'is_provisional' => false,
        ])->save();

        $missingTargetId = 9_999_999;
        $details = [
            'message' => 'Vorläufiger Account ist bereits mit einem anderen Salesforce-Account verknüpft.',
            'existing_target_id' => $target->id,
            'requested_target_id' => $missingTargetId,
        ];
        $conflict = CrmConflict::query()->create([
            'type' => CrmConflictType::LinkConflict,
            'status' => 'open',
            'crm_account_id' => $source->id,
            'details' => $details,
        ]);

        $this->actingAs($admin)
            ->get(route('crm.accounts.match-queue'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('crm/accounts/match-queue')
                ->has('rows')
                ->where('rows', function ($rows) use ($source, $twin): bool {
                    $byId = collect($rows)->keyBy(fn ($row) => $row['account']['id']);
                    $sourceRow = $byId->get($source->id);
                    $twinRow = $byId->get($twin->id);
                    if ($sourceRow === null || $twinRow === null) {
                        return false;
                    }

                    return $sourceRow['account']['label']['title'] === 'Gleicher Name GmbH'
                        && in_array('gleicher-name-a.test', $sourceRow['account']['label']['meta'], true)
                        && in_array('Meridian-Nummer folgt', $sourceRow['account']['label']['meta'], true)
                        && $twinRow['account']['label']['title'] === 'Gleicher Name GmbH'
                        && in_array('gleicher-name-b.test', $twinRow['account']['label']['meta'], true)
                        && in_array('Meridian M-TWIN', $twinRow['account']['label']['meta'], true)
                        && in_array('Account KUNDE', $twinRow['account']['label']['meta'], true);
                })
                ->where('conflicts.0.id', $conflict->id)
                ->where('conflicts.0.details', $details)
                ->where('conflicts.0.summary_lines', function ($lines): bool {
                    $list = collect($lines)->values()->all();
                    $joined = implode(' | ', $list);

                    return str_contains($joined, 'Vorläufiger Account ist bereits')
                        && str_contains($joined, 'Salesforce Ziel AG')
                        && str_contains($joined, 'Bestehendes Ziel:')
                        && str_contains($joined, 'Angefragtes Ziel: Account-Referenz nicht mehr verfügbar')
                        && ! str_contains($joined, 'Bestehendes Ziel: ID ');
                }));
    }

    #[Test]
    public function json_search_still_supports_manual_link_targets_without_domain(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $accounts = app(CrmAccountService::class);

        $provisional = $accounts->createProvisional([
            'name' => 'Ohne Domain Quelle',
            'type' => CrmAccountType::Customer,
        ], $admin);
        $this->assertNull($provisional->matching_domain);

        $target = $accounts->createProvisional([
            'name' => 'Ohne Domain Ziel',
            'type' => CrmAccountType::Customer,
        ], $admin);
        $sf = SalesforceAccountId::normalize('001xx000003NODM');
        $this->assertNotNull($sf);
        $target->forceFill([
            'salesforce_account_id_raw' => $sf['raw'],
            'salesforce_account_id_canonical' => $sf['canonical'],
            'is_provisional' => false,
        ])->save();

        $search = $this->actingAs($admin)->getJson(
            '/crm/accounts/suche?salesforce_only=1&type=customer&q=Ohne%20Domain%20Ziel',
        );
        $search->assertOk();
        $search->assertJsonPath('accounts.0.name', 'Ohne Domain Ziel');
        $search->assertJsonPath('accounts.0.label.title', 'Ohne Domain Ziel');

        $this->actingAs($admin)->postJson("/crm/accounts/{$provisional->id}/verknuepfen", [
            'salesforce_account_id' => $target->id,
        ])->assertOk();
    }
}
