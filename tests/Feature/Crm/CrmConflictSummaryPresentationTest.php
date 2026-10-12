<?php

namespace Tests\Feature\Crm;

use App\Enums\CrmAccountType;
use App\Enums\CrmConflictType;
use App\Enums\DispoOrderStatus;
use App\Enums\Role;
use App\Models\Calculation;
use App\Models\CrmAccount;
use App\Models\CrmConflict;
use App\Models\User;
use App\Services\Crm\CrmAccountService;
use App\Support\Crm\CrmConflictPresentation;
use App\Support\Crm\SalesforceAccountId;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Prüflisten-Konfliktzusammenfassung ohne Informationsverlust (Audit P2 / PR #138).
 */
final class CrmConflictSummaryPresentationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function order_meridian_mismatch_shows_calc_and_dispo_sides_with_numbers(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $sales = User::factory()->role(Role::Sales)->create();
        $account = $this->salesforceAccount($admin, 'Order MM Kunde', 'order-mm.test', '001xx000003ORDR');

        $calcCustomer = Calculation::factory()->create([
            'advisor_id' => $sales->id,
            'number' => 'K-2026-77001',
            'customer_account_id' => $account->id,
            'customer_name' => 'Order MM Kunde',
            'invoice_recipient' => 'customer',
        ]);
        $calcAgency = Calculation::factory()->create([
            'advisor_id' => $sales->id,
            'number' => 'K-2026-77002',
            'agency_account_id' => $account->id,
            'agency_name' => 'Order MM Kunde',
            'invoice_recipient' => 'agency',
        ]);

        $dispoCustomerId = $this->insertMinimalDispo($calcCustomer, $sales, $account, 'DA-2026-77001-01', 'customer');
        $dispoAgencyId = $this->insertMinimalDispo($calcAgency, $sales, $account, 'DA-2026-77002-01', 'agency');

        $detailsCalcCustomer = [
            'entity' => 'calculation',
            'entity_id' => $calcCustomer->id,
            'field' => 'customer_meridian_number',
            'existing' => 'M-OTHER-C',
            'incoming' => 'M-KEEP-C',
        ];
        $detailsCalcAgency = [
            'entity' => 'calculation',
            'entity_id' => $calcAgency->id,
            'field' => 'agency_meridian_number',
            'existing' => 'M-OTHER-A',
            'incoming' => 'M-KEEP-A',
        ];
        $detailsDispoCustomer = [
            'entity' => 'dispo_order',
            'entity_id' => $dispoCustomerId,
            'field' => 'customer_meridian_number',
            'existing' => 'M-OTHER-DC',
            'incoming' => 'M-KEEP-DC',
            'order_status' => DispoOrderStatus::Draft->value,
        ];
        $detailsDispoAgency = [
            'entity' => 'dispo_order',
            'entity_id' => $dispoAgencyId,
            'field' => 'agency_meridian_number',
            'existing' => 'M-OTHER-DA',
            'incoming' => 'M-KEEP-DA',
            'order_status' => DispoOrderStatus::AtDisposition->value,
        ];

        $c1 = $this->openConflict($account, CrmConflictType::OrderMeridianMismatch, $detailsCalcCustomer);
        $c2 = $this->openConflict($account, CrmConflictType::OrderMeridianMismatch, $detailsCalcAgency);
        $c3 = $this->openConflict($account, CrmConflictType::OrderMeridianMismatch, $detailsDispoCustomer);
        $c4 = $this->openConflict($account, CrmConflictType::OrderMeridianMismatch, $detailsDispoAgency);

        $response = $this->actingAs($admin)->get(route('crm.accounts.match-queue'));
        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('crm/accounts/match-queue')
            ->has('conflicts', 4)
            ->where('conflicts', function ($conflicts) use ($c1, $c2, $c3, $c4, $detailsCalcCustomer, $detailsDispoCustomer): bool {
                $byId = collect($conflicts)->keyBy('id');

                $calcC = $byId->get($c1->id);
                $calcA = $byId->get($c2->id);
                $dispoC = $byId->get($c3->id);
                $dispoA = $byId->get($c4->id);
                if ($calcC === null || $calcA === null || $dispoC === null || $dispoA === null) {
                    return false;
                }

                $joined = static fn (array $row): string => implode(' | ', $row['summary_lines']);

                return $calcC['details'] === $detailsCalcCustomer
                    && str_contains($joined($calcC), 'Kalkulation K-2026-77001')
                    && str_contains($joined($calcC), 'Betroffene Seite: Kunde')
                    && str_contains($joined($calcC), 'Bestehende Meridian-Nummer: M-OTHER-C')
                    && str_contains($joined($calcC), 'Eingehende Meridian-Nummer: M-KEEP-C')
                    && is_array($calcC['entity_link'])
                    && str_contains((string) $calcC['entity_link']['url'], '/kalkulationen/')
                    && str_contains($joined($calcA), 'Kalkulation K-2026-77002')
                    && str_contains($joined($calcA), 'Betroffene Seite: Agentur')
                    && $dispoC['details'] === $detailsDispoCustomer
                    && str_contains($joined($dispoC), 'Dispoauftrag DA-2026-77001-01')
                    && str_contains($joined($dispoC), 'Betroffene Seite: Kunde')
                    && str_contains($joined($dispoC), 'Gespeicherter Auftragsstatus (Konfliktstand): Entwurf')
                    && is_array($dispoC['entity_link'])
                    && str_contains($joined($dispoA), 'Dispoauftrag DA-2026-77002-01')
                    && str_contains($joined($dispoA), 'Betroffene Seite: Agentur')
                    && str_contains($joined($dispoA), 'Liegt bei Disposition');
            }));
    }

    #[Test]
    public function order_meridian_respects_view_rights_and_missing_reference(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $account = $this->salesforceAccount($admin, 'Rechte Kunde', 'rechte.test', '001xx000003RGHT');

        $calc = Calculation::factory()->create([
            'advisor_id' => $admin->id,
            'number' => 'K-2026-88001',
            'customer_account_id' => $account->id,
            'customer_name' => 'Rechte Kunde',
            'invoice_recipient' => 'customer',
        ]);

        $missingDetails = [
            'entity' => 'calculation',
            'entity_id' => 9_999_888,
            'field' => 'customer_meridian_number',
            'existing' => 'M-A',
            'incoming' => 'M-B',
        ];
        $okDetails = [
            'entity' => 'calculation',
            'entity_id' => $calc->id,
            'field' => 'customer_meridian_number',
            'existing' => 'M-A',
            'incoming' => 'M-B',
        ];

        $missing = $this->openConflict($account, CrmConflictType::OrderMeridianMismatch, $missingDetails);
        $ok = $this->openConflict($account, CrmConflictType::OrderMeridianMismatch, $okDetails);

        $this->actingAs($admin)
            ->get(route('crm.accounts.match-queue'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('conflicts', function ($conflicts) use ($missing, $ok): bool {
                    $byId = collect($conflicts)->keyBy('id');
                    $miss = $byId->get($missing->id);
                    $allowed = $byId->get($ok->id);
                    if ($miss === null || $allowed === null) {
                        return false;
                    }
                    $missJoined = implode(' | ', $miss['summary_lines']);
                    $okJoined = implode(' | ', $allowed['summary_lines']);

                    return str_contains($missJoined, 'Kalkulation-Referenz nicht mehr verfügbar')
                        && $miss['entity_link'] === null
                        && ! str_contains($missJoined, '9999888')
                        && str_contains($okJoined, 'Kalkulation K-2026-88001')
                        && is_array($allowed['entity_link']);
                }));

        $denied = CrmConflictPresentation::summarize(
            $ok->fresh(),
            collect(),
            collect([$calc->id => (object) ['id' => $calc->id, 'number' => $calc->number]]),
            collect(),
            false,
            false,
        );
        $deniedJoined = implode(' | ', $denied['summary_lines']);
        $this->assertStringContainsString('keine Berechtigung', $deniedJoined);
        $this->assertStringNotContainsString('K-2026-88001', $deniedJoined);
        $this->assertNull($denied['entity_link']);
        $this->assertSame($okDetails, $ok->fresh()->details);
    }

    #[Test]
    public function ambiguous_domain_lists_distinguishable_candidates_and_missing_refs(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $accounts = app(CrmAccountService::class);

        $provisional = $accounts->createProvisional([
            'name' => 'Ambigu Quelle',
            'type' => CrmAccountType::Customer,
            'matching_domain' => 'ambigu.test',
        ], $admin);

        $candA = $this->salesforceAccount($admin, 'Kandidat Alpha', 'ambigu.test', '001xx000003ALFA');
        $candA->currentVersion?->forceFill(['meridian_number' => 'M-ALFA'])->save();
        $candB = $this->salesforceAccount($admin, 'Kandidat Beta', 'ambigu.test', '001xx000003BETA');
        $candB->currentVersion?->forceFill(['meridian_number' => null])->save();

        $details = [
            'domain' => 'ambigu.test',
            'candidate_ids' => [$candA->id, $candB->id, 9_999_777],
        ];
        $conflict = $this->openConflict($provisional, CrmConflictType::AmbiguousDomain, $details);

        $this->actingAs($admin)
            ->get(route('crm.accounts.match-queue'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('conflicts.0.id', $conflict->id)
                ->where('conflicts.0.details', $details)
                ->where('conflicts.0.summary_lines', function ($lines): bool {
                    $joined = implode(' | ', collect($lines)->all());

                    return str_contains($joined, 'Konfliktdomain: ambigu.test')
                        && str_contains($joined, 'Kandidat Alpha')
                        && str_contains($joined, 'Meridian M-ALFA')
                        && str_contains($joined, 'Kandidat Beta')
                        && str_contains($joined, 'Meridian-Nummer folgt')
                        && str_contains($joined, 'Account-Referenz nicht mehr verfügbar')
                        && ! str_contains($joined, 'Kandidat 1: ID ');
                }));
    }

    #[Test]
    public function divergent_domains_without_account_fk_shows_import_context(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $details = [
            'line' => 14,
            'domains' => ['alpha.test', 'beta.test'],
            'salesforce_canonical' => '001XX000003DIVX',
        ];
        $conflict = CrmConflict::query()->create([
            'type' => CrmConflictType::DivergentDomains,
            'status' => 'open',
            'crm_account_id' => null,
            'details' => $details,
        ]);

        $this->actingAs($admin)
            ->get(route('crm.accounts.match-queue'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('conflicts.0.id', $conflict->id)
                ->where('conflicts.0.account', null)
                ->where('conflicts.0.account_expected', false)
                ->where('conflicts.0.details', $details)
                ->where('conflicts.0.summary_lines', function ($lines): bool {
                    $joined = implode(' | ', collect($lines)->all());

                    return str_contains($joined, 'Importkonflikt ohne Account-Zuordnung')
                        && str_contains($joined, 'alpha.test')
                        && str_contains($joined, 'beta.test')
                        && str_contains($joined, 'Exportzeile: 14')
                        && str_contains($joined, 'Salesforce-Identität: 001XX000003DIVX')
                        && ! str_contains($joined, 'Account-Referenz nicht mehr verfügbar');
                }));
    }

    #[Test]
    public function type_change_shows_customer_agency_labels_and_salesforce_context(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $account = $this->salesforceAccount($admin, 'Typwechsel GmbH', 'typwechsel.test', '001xx000003TYPE');
        $details = [
            'message' => 'Typwechsel Kunde/Agentur unzulässig.',
            'existing_type' => 'customer',
            'incoming_type' => 'agency',
            'salesforce_canonical' => '001XX000003TYPE',
        ];
        $conflict = $this->openConflict($account, CrmConflictType::TypeChange, $details);

        $this->actingAs($admin)
            ->get(route('crm.accounts.match-queue'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('conflicts.0.id', $conflict->id)
                ->where('conflicts.0.details', $details)
                ->where('conflicts.0.summary_lines', function ($lines): bool {
                    $joined = implode(' | ', collect($lines)->all());

                    return str_contains($joined, 'Typwechsel Kunde/Agentur unzulässig')
                        && str_contains($joined, 'Bestehender Typ: Kunde')
                        && str_contains($joined, 'Eingehender Typ: Agentur')
                        && str_contains($joined, 'Salesforce-Identität: 001XX000003TYPE');
                }));
    }

    #[Test]
    public function existing_link_and_stammdaten_meridian_summaries_stay_correct_and_details_untouched(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $accounts = app(CrmAccountService::class);

        $source = $accounts->createProvisional([
            'name' => 'Link Quelle',
            'type' => CrmAccountType::Customer,
            'matching_domain' => 'link-quelle.test',
        ], $admin);
        $target = $this->salesforceAccount($admin, 'Link Ziel AG', 'link-ziel.test', '001xx000003LINK');

        $linkDetails = [
            'message' => 'Vorläufiger Account ist bereits mit einem anderen Salesforce-Account verknüpft.',
            'existing_target_id' => $target->id,
            'requested_target_id' => 9_999_666,
        ];
        $meridianDetails = [
            'existing_meridian' => 'M-ALT',
            'incoming_meridian' => 'M-NEU',
            'salesforce_canonical' => '001XX000003LINK',
        ];

        $link = $this->openConflict($source, CrmConflictType::LinkConflict, $linkDetails);
        $meridian = $this->openConflict($target, CrmConflictType::MeridianMismatch, $meridianDetails);

        $this->actingAs($admin)
            ->get(route('crm.accounts.match-queue'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('conflicts', function ($conflicts) use ($link, $meridian, $linkDetails, $meridianDetails): bool {
                    $byId = collect($conflicts)->keyBy('id');
                    $linkRow = $byId->get($link->id);
                    $meridianRow = $byId->get($meridian->id);
                    if ($linkRow === null || $meridianRow === null) {
                        return false;
                    }
                    $linkJoined = implode(' | ', $linkRow['summary_lines']);
                    $meridianJoined = implode(' | ', $meridianRow['summary_lines']);

                    return $linkRow['details'] === $linkDetails
                        && str_contains($linkJoined, 'Vorläufiger Account ist bereits')
                        && str_contains($linkJoined, 'Link Ziel AG')
                        && str_contains($linkJoined, 'Angefragtes Ziel: Account-Referenz nicht mehr verfügbar')
                        && $meridianRow['details'] === $meridianDetails
                        && str_contains($meridianJoined, 'Bestehende Meridian-Nummer: M-ALT')
                        && str_contains($meridianJoined, 'Eingehende Meridian-Nummer: M-NEU')
                        && str_contains($meridianJoined, 'Salesforce-Identität: 001XX000003LINK');
                }));

        $this->assertSame($linkDetails, $link->fresh()->details);
        $this->assertSame($meridianDetails, $meridian->fresh()->details);
    }

    /**
     * @param  array<string, mixed>  $details
     */
    private function openConflict(?CrmAccount $account, CrmConflictType $type, array $details): CrmConflict
    {
        return CrmConflict::query()->create([
            'type' => $type,
            'status' => 'open',
            'crm_account_id' => $account?->id,
            'details' => $details,
        ]);
    }

    private function salesforceAccount(User $actor, string $name, string $domain, string $sfRaw): CrmAccount
    {
        $accounts = app(CrmAccountService::class);
        $account = $accounts->createProvisional([
            'name' => $name,
            'type' => CrmAccountType::Customer,
            'matching_domain' => $domain,
            'billing_email' => 'a@'.$domain,
        ], $actor);
        $sf = SalesforceAccountId::normalize($sfRaw);
        $this->assertNotNull($sf);
        $account->forceFill([
            'salesforce_account_id_raw' => $sf['raw'],
            'salesforce_account_id_canonical' => $sf['canonical'],
            'is_provisional' => false,
        ])->save();

        return $account->fresh(['currentVersion']) ?? $account;
    }

    private function insertMinimalDispo(
        Calculation $calc,
        User $sales,
        CrmAccount $account,
        string $number,
        string $side,
    ): int {
        Schema::disableForeignKeyConstraints();
        $id = DB::table('dispo_orders')->insertGetId([
            'calculation_id' => $calc->id,
            'configuration_snapshot_id' => 1,
            'number' => $number,
            'number_year' => 2026,
            'number_org_seq' => 77001,
            'number_calc_seq' => 1,
            'status' => DispoOrderStatus::Draft->value,
            'created_by_id' => $sales->id,
            'source_calculation_number' => $calc->number,
            'customer_name' => $side === 'customer' ? $account->currentVersion?->name : null,
            'agency_name' => $side === 'agency' ? $account->currentVersion?->name : null,
            'customer_account_id' => $side === 'customer' ? $account->id : null,
            'agency_account_id' => $side === 'agency' ? $account->id : null,
            'customer_version_id' => $side === 'customer' ? $account->current_version_id : null,
            'agency_version_id' => $side === 'agency' ? $account->current_version_id : null,
            'invoice_recipient' => $side === 'agency' ? 'agency' : 'customer',
            'approval_kind' => 'regular',
            'requires_special_approval' => 0,
            'media_gross' => '0.00',
            'position_discount_total' => '0.00',
            'order_discount_total' => '0.00',
            'ae_total' => '0.00',
            'nn_invest' => '0.00',
            'order_discount_percent' => '0',
            'ae_enabled' => 0,
            'lock_version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        Schema::enableForeignKeyConstraints();

        return $id;
    }
}
