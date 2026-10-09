<?php

namespace App\Services\Crm;

use App\Enums\CrmAccountType;
use App\Enums\CrmAccountVersionSource;
use App\Enums\CrmConflictType;
use App\Models\CrmAccount;
use App\Models\CrmAccountVersion;
use App\Models\CrmConflict;
use App\Models\CrmSharedEmailDomain;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\Crm\CrmDomainNormalizer;
use App\Support\Crm\SalesforceAccountId;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CrmAccountService
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return list<string>
     */
    public function sharedDomains(): array
    {
        $domains = [];
        foreach (
            CrmSharedEmailDomain::query()
                ->where('is_active', true)
                ->orderBy('domain')
                ->pluck('domain') as $domain
        ) {
            $domains[] = (string) $domain;
        }

        return $domains;
    }

    public function isSharedDomain(?string $domain): bool
    {
        if ($domain === null || $domain === '') {
            return false;
        }

        return CrmSharedEmailDomain::query()
            ->where('is_active', true)
            ->where('domain', $domain)
            ->exists();
    }

    /**
     * @param  array{name: string, type: CrmAccountType|string, billing_email?: ?string, matching_domain?: ?string}  $input
     */
    public function createProvisional(array $input, User $actor): CrmAccount
    {
        $type = $input['type'] instanceof CrmAccountType
            ? $input['type']
            : CrmAccountType::from((string) $input['type']);
        $name = trim((string) $input['name']);
        if ($name === '') {
            throw ValidationException::withMessages([
                'name' => 'Firmierung ist Pflicht.',
            ]);
        }

        $email = isset($input['billing_email'])
            ? CrmDomainNormalizer::normalizeEmail((string) $input['billing_email'])
            : null;
        $domain = isset($input['matching_domain']) && trim((string) $input['matching_domain']) !== ''
            ? CrmDomainNormalizer::normalizeDomain((string) $input['matching_domain'])
            : ($email !== null ? CrmDomainNormalizer::domainFromEmail($email) : null);

        return DB::transaction(function () use ($type, $name, $email, $domain, $actor): CrmAccount {
            $account = CrmAccount::query()->create([
                'type' => $type,
                'is_provisional' => true,
                'matching_domain' => $domain,
                'lock_version' => 1,
            ]);

            $version = CrmAccountVersion::query()->create([
                'crm_account_id' => $account->id,
                'version_number' => 1,
                'name' => $name,
                'billing_email' => $email,
                'matching_domain' => $domain,
                'meridian_number' => null,
                'source' => CrmAccountVersionSource::Provisional,
                'created_by_id' => $actor->id,
            ]);

            $account->current_version_id = $version->id;
            $account->save();

            $this->audit->record($account, 'crm_account.provisional_created', $actor, null, [
                'type' => $type->value,
                'name' => $name,
                'matching_domain' => $domain,
            ]);

            return $account->fresh(['currentVersion']) ?? $account;
        });
    }

    /**
     * Manuelle Zuordnung vorläufiger Account → Salesforce-Account.
     * Prüfungen werden nach lockForUpdate auf frisch geladenen Zeilen wiederholt.
     */
    public function linkProvisionalToSalesforce(
        CrmAccount $provisional,
        CrmAccount $salesforceAccount,
        User $actor,
    ): CrmAccount {
        return DB::transaction(function () use ($provisional, $salesforceAccount, $actor): CrmAccount {
            // Deterministische Sperrreihenfolge gegen Deadlocks.
            $ids = [$provisional->id, $salesforceAccount->id];
            sort($ids);
            $locked = CrmAccount::query()
                ->whereIn('id', $ids)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $provisional = $locked->get($provisional->id)
                ?? CrmAccount::query()->lockForUpdate()->findOrFail($provisional->id);
            $target = $locked->get($salesforceAccount->id)
                ?? CrmAccount::query()->lockForUpdate()->findOrFail($salesforceAccount->id);

            $target = $this->resolveCanonicalAccountLocked($target);

            if ($provisional->id === $target->id) {
                $this->afterSuccessfulLink($target, $actor, null);

                return $target->fresh(['currentVersion']) ?? $target;
            }

            // Idempotent: bereits auf dasselbe Ziel gemerged.
            if ($provisional->merged_into_account_id === $target->id) {
                $this->afterSuccessfulLink($target, $actor, null);

                return $target->fresh(['currentVersion']) ?? $target;
            }

            if ($provisional->merged_into_account_id !== null
                && $provisional->merged_into_account_id !== $target->id) {
                $this->openConflict(CrmConflictType::LinkConflict, $provisional, null, [
                    'message' => 'Vorläufiger Account ist bereits mit einem anderen Salesforce-Account verknüpft.',
                    'existing_target_id' => $provisional->merged_into_account_id,
                    'requested_target_id' => $target->id,
                ]);
                throw ValidationException::withMessages([
                    'account' => 'Account ist bereits mit einem anderen Salesforce-Account verknüpft.',
                ]);
            }

            if ($provisional->type !== $target->type) {
                throw ValidationException::withMessages([
                    'account' => 'Kunde darf nur mit Kunde, Agentur nur mit Agentur verknüpft werden.',
                ]);
            }
            if (! $target->isLinkedToSalesforce() || $target->merged_into_account_id !== null) {
                throw ValidationException::withMessages([
                    'account' => 'Zielaccount ist nicht mit Salesforce verknüpft oder bereits zusammengeführt.',
                ]);
            }
            if ($this->wouldCreateMergeCycle($provisional, $target)) {
                throw ValidationException::withMessages([
                    'account' => 'Zuordnung würde einen Merge-Zyklus erzeugen.',
                ]);
            }
            if ($provisional->salesforce_account_id_canonical !== null
                && $provisional->salesforce_account_id_canonical !== $target->salesforce_account_id_canonical) {
                throw ValidationException::withMessages([
                    'account' => 'Vorläufiger Account ist bereits mit einer anderen Salesforce-ID verknüpft.',
                ]);
            }

            $this->repointOrders($provisional->id, $target->id);

            $provisional->merged_into_account_id = $target->id;
            $provisional->is_provisional = false;
            $provisional->lock_version = ((int) $provisional->lock_version) + 1;
            $provisional->save();

            $this->audit->record($provisional, 'crm_account.manual_linked', $actor, null, [
                'merged_into_account_id' => $target->id,
                'salesforce_account_id_canonical' => $target->salesforce_account_id_canonical,
            ]);

            $this->afterSuccessfulLink($target, $actor, null);

            return $target->fresh(['currentVersion']) ?? $target;
        });
    }

    private function resolveCanonicalAccountLocked(CrmAccount $account): CrmAccount
    {
        $current = $account;
        $guard = 0;
        while ($current->merged_into_account_id !== null && $guard < 10) {
            $next = CrmAccount::query()
                ->whereKey($current->merged_into_account_id)
                ->lockForUpdate()
                ->first();
            if ($next === null) {
                break;
            }
            $current = $next;
            $guard++;
        }

        return $current;
    }

    private function wouldCreateMergeCycle(CrmAccount $provisional, CrmAccount $target): bool
    {
        $current = $target;
        $guard = 0;
        while ($current->merged_into_account_id !== null && $guard < 10) {
            if ($current->merged_into_account_id === $provisional->id) {
                return true;
            }
            $next = CrmAccount::query()->find($current->merged_into_account_id);
            if ($next === null) {
                break;
            }
            $current = $next;
            $guard++;
        }

        return false;
    }

    private function afterSuccessfulLink(CrmAccount $target, User $actor, ?int $importId): void
    {
        $meridian = app(CrmMeridianSupplementService::class);
        $meridian->supplementSalesforceIds($target, $actor, $importId);
        $target->loadMissing('currentVersion');
        $number = $target->currentVersion?->meridian_number;
        if ($number !== null && $number !== '') {
            $meridian->supplementMissing($target, $number, $actor, $importId);
        }
    }

    /**
     * Offenen Konflikt auditiert abschließen (keine Nummernüberschreibung).
     */
    public function resolveConflict(CrmConflict $conflict, User $actor, ?string $note = null): CrmConflict
    {
        return DB::transaction(function () use ($conflict, $actor, $note): CrmConflict {
            $conflict = CrmConflict::query()->lockForUpdate()->findOrFail($conflict->id);
            if ($conflict->status === 'resolved') {
                return $conflict;
            }

            $conflict->status = 'resolved';
            $conflict->resolved_by_id = $actor->id;
            $conflict->resolved_at = now();
            $details = $conflict->details ?? [];
            if ($note !== null && $note !== '') {
                $details['resolution_note'] = $note;
            }
            $details['resolution'] = 'acknowledged_without_overwrite';
            $conflict->details = $details;
            $conflict->save();

            $this->audit->record($conflict, 'crm_conflict.resolved', $actor, null, [
                'type' => $conflict->type->value,
                'note' => $note,
            ]);

            return $conflict;
        });
    }

    /**
     * Auto- oder Import-Verknüpfung: setzt SF-ID auf vorläufigem Account oder merged in bestehenden.
     *
     * @param  array{raw: string, canonical: string}  $salesforce
     * @param  array{name: string, billing_email: ?string, matching_domain: ?string, meridian_number: ?string}  $payload
     */
    public function attachSalesforceIdentity(
        CrmAccount $account,
        array $salesforce,
        CrmAccountType $type,
        array $payload,
        User $actor,
        ?int $importId,
        CrmAccountVersionSource $source,
    ): CrmAccount {
        return DB::transaction(function () use ($account, $salesforce, $type, $payload, $actor, $importId, $source): CrmAccount {
            $account = CrmAccount::query()->lockForUpdate()->findOrFail($account->id);

            $existing = CrmAccount::query()
                ->where('salesforce_account_id_canonical', $salesforce['canonical'])
                ->whereNull('merged_into_account_id')
                ->lockForUpdate()
                ->first();

            if ($existing !== null && $existing->id !== $account->id) {
                if ($existing->type !== $type) {
                    $this->openConflict(CrmConflictType::TypeChange, $existing, $importId, [
                        'message' => 'Typwechsel Kunde/Agentur unzulässig.',
                        'existing_type' => $existing->type->value,
                        'incoming_type' => $type->value,
                        'salesforce_canonical' => $salesforce['canonical'],
                    ]);

                    return $existing;
                }

                $this->repointOrders($account->id, $existing->id);
                $account->merged_into_account_id = $existing->id;
                $account->is_provisional = false;
                $account->lock_version = ((int) $account->lock_version) + 1;
                $account->save();

                $this->applyImportedMasterData($existing, $salesforce, $payload, $actor, $importId, $source);

                $this->audit->record($account, 'crm_account.auto_merged', $actor, null, [
                    'merged_into_account_id' => $existing->id,
                    'salesforce_account_id_canonical' => $salesforce['canonical'],
                ]);

                return $existing->fresh(['currentVersion']) ?? $existing;
            }

            if ($account->type !== $type && $account->salesforce_account_id_canonical !== null) {
                $this->openConflict(CrmConflictType::TypeChange, $account, $importId, [
                    'message' => 'Typwechsel Kunde/Agentur unzulässig.',
                    'existing_type' => $account->type->value,
                    'incoming_type' => $type->value,
                ]);

                return $account;
            }

            $account->type = $type;
            $account->salesforce_account_id_raw = $salesforce['raw'];
            $account->salesforce_account_id_canonical = $salesforce['canonical'];
            $account->is_provisional = false;
            $account->matching_domain = $payload['matching_domain'];
            $account->lock_version = ((int) $account->lock_version) + 1;
            $account->save();

            $this->applyImportedMasterData($account, $salesforce, $payload, $actor, $importId, $source);

            return $account->fresh(['currentVersion']) ?? $account;
        });
    }

    /**
     * @param  array{raw: string, canonical: string}  $salesforce
     * @param  array{name: string, billing_email: ?string, matching_domain: ?string, meridian_number: ?string}  $payload
     */
    public function upsertSalesforceAccount(
        array $salesforce,
        CrmAccountType $type,
        array $payload,
        User $actor,
        ?int $importId,
    ): CrmAccount {
        return DB::transaction(function () use ($salesforce, $type, $payload, $actor, $importId): CrmAccount {
            $existing = CrmAccount::query()
                ->where('salesforce_account_id_canonical', $salesforce['canonical'])
                ->whereNull('merged_into_account_id')
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                if ($existing->type !== $type) {
                    $this->openConflict(CrmConflictType::TypeChange, $existing, $importId, [
                        'message' => 'Typwechsel Kunde/Agentur unzulässig.',
                        'existing_type' => $existing->type->value,
                        'incoming_type' => $type->value,
                        'salesforce_canonical' => $salesforce['canonical'],
                    ]);

                    return $existing;
                }

                $this->applyImportedMasterData($existing, $salesforce, $payload, $actor, $importId, CrmAccountVersionSource::Import);

                return $existing->fresh(['currentVersion']) ?? $existing;
            }

            $account = CrmAccount::query()->create([
                'type' => $type,
                'salesforce_account_id_raw' => $salesforce['raw'],
                'salesforce_account_id_canonical' => $salesforce['canonical'],
                'is_provisional' => false,
                'matching_domain' => $payload['matching_domain'],
                'lock_version' => 1,
            ]);

            $version = $this->createVersion($account, 1, $payload, $actor, $importId, CrmAccountVersionSource::Import);
            $account->current_version_id = $version->id;
            $account->save();

            $this->audit->record($account, 'crm_account.imported', $actor, null, [
                'salesforce_account_id_canonical' => $salesforce['canonical'],
                'name' => $payload['name'],
                'type' => $type->value,
            ]);

            return $account->fresh(['currentVersion']) ?? $account;
        });
    }

    /**
     * @return list<CrmAccount>
     */
    public function findSalesforceCandidatesByDomain(CrmAccountType $type, string $domain): array
    {
        if ($this->isSharedDomain($domain)) {
            return [];
        }

        $accounts = [];
        foreach (
            CrmAccount::query()
                ->with('currentVersion')
                ->where('type', $type)
                ->whereNull('merged_into_account_id')
                ->whereNotNull('salesforce_account_id_canonical')
                ->where('matching_domain', $domain)
                ->orderBy('id')
                ->get() as $account
        ) {
            $accounts[] = $account;
        }

        return $accounts;
    }

    /**
     * @return list<CrmAccount>
     */
    public function findProvisionalCandidatesByDomain(CrmAccountType $type, string $domain): array
    {
        if ($this->isSharedDomain($domain)) {
            return [];
        }

        $accounts = [];
        foreach (
            CrmAccount::query()
                ->with('currentVersion')
                ->where('type', $type)
                ->whereNull('merged_into_account_id')
                ->where('is_provisional', true)
                ->whereNull('salesforce_account_id_canonical')
                ->where('matching_domain', $domain)
                ->orderBy('id')
                ->get() as $account
        ) {
            $accounts[] = $account;
        }

        return $accounts;
    }

    public function catalogFingerprint(): string
    {
        $parts = CrmAccount::query()
            ->whereNull('merged_into_account_id')
            ->orderBy('id')
            ->get(['id', 'type', 'salesforce_account_id_canonical', 'matching_domain', 'current_version_id', 'lock_version'])
            ->map(fn (CrmAccount $a): string => implode('|', [
                $a->id,
                $a->type->value,
                $a->salesforce_account_id_canonical ?? '',
                $a->matching_domain ?? '',
                $a->current_version_id ?? '',
                $a->lock_version,
            ]))
            ->all();

        return hash('sha256', implode("\n", $parts));
    }

    /**
     * @param  array{name: string, billing_email: ?string, matching_domain: ?string, meridian_number: ?string}  $payload
     * @param  array{raw: string, canonical: string}  $salesforce
     */
    private function applyImportedMasterData(
        CrmAccount $account,
        array $salesforce,
        array $payload,
        User $actor,
        ?int $importId,
        CrmAccountVersionSource $source,
    ): void {
        $account->loadMissing('currentVersion');
        $current = $account->currentVersion;

        $incomingMeridian = $payload['meridian_number'];
        $existingMeridian = $current?->meridian_number;

        if ($incomingMeridian !== null && $incomingMeridian !== ''
            && $existingMeridian !== null && $existingMeridian !== ''
            && $incomingMeridian !== $existingMeridian) {
            $this->openConflict(CrmConflictType::MeridianMismatch, $account, $importId, [
                'existing_meridian' => $existingMeridian,
                'incoming_meridian' => $incomingMeridian,
                'salesforce_canonical' => $salesforce['canonical'],
            ]);
            $effectiveMeridian = $existingMeridian;
        } elseif (($incomingMeridian === null || $incomingMeridian === '')
            && $existingMeridian !== null && $existingMeridian !== '') {
            $effectiveMeridian = $existingMeridian;
        } else {
            $effectiveMeridian = ($incomingMeridian === '') ? null : $incomingMeridian;
        }

        $account->salesforce_account_id_raw = $salesforce['raw'];
        $account->salesforce_account_id_canonical = $salesforce['canonical'];
        $account->is_provisional = false;
        $account->matching_domain = $payload['matching_domain'];

        $unchanged = $current !== null
            && $current->name === $payload['name']
            && ($current->billing_email ?? null) === ($payload['billing_email'] ?? null)
            && ($current->matching_domain ?? null) === ($payload['matching_domain'] ?? null)
            && ($current->meridian_number ?? null) === ($effectiveMeridian ?? null);

        if ($unchanged) {
            $account->save();

            return;
        }

        $nextNumber = ($current !== null ? (int) $current->version_number : 0) + 1;
        $version = $this->createVersion(
            $account,
            $nextNumber,
            [
                'name' => $payload['name'],
                'billing_email' => $payload['billing_email'],
                'matching_domain' => $payload['matching_domain'],
                'meridian_number' => $effectiveMeridian,
            ],
            $actor,
            $importId,
            $source,
        );
        $account->current_version_id = $version->id;
        $account->lock_version = ((int) $account->lock_version) + 1;
        $account->save();

        $this->audit->record($account, 'crm_account.version_created', $actor, [
            'version' => $current?->version_number,
            'name' => $current?->name,
            'meridian_number' => $current?->meridian_number,
        ], [
            'version' => $version->version_number,
            'name' => $version->name,
            'meridian_number' => $version->meridian_number,
            'source' => $source->value,
        ]);
    }

    /**
     * @param  array{name: string, billing_email: ?string, matching_domain: ?string, meridian_number: ?string}  $payload
     */
    private function createVersion(
        CrmAccount $account,
        int $number,
        array $payload,
        User $actor,
        ?int $importId,
        CrmAccountVersionSource $source,
    ): CrmAccountVersion {
        return CrmAccountVersion::query()->create([
            'crm_account_id' => $account->id,
            'version_number' => $number,
            'name' => $payload['name'],
            'billing_email' => $payload['billing_email'],
            'matching_domain' => $payload['matching_domain'],
            'meridian_number' => $payload['meridian_number'],
            'source' => $source,
            'created_by_id' => $actor->id,
            'crm_import_id' => $importId,
        ]);
    }

    /**
     * @param  array<string, mixed>  $details
     */
    public function openConflict(
        CrmConflictType $type,
        ?CrmAccount $account,
        ?int $importId,
        array $details,
    ): CrmConflict {
        return CrmConflict::query()->create([
            'crm_account_id' => $account?->id,
            'crm_import_id' => $importId,
            'type' => $type,
            'status' => 'open',
            'details' => $details,
        ]);
    }

    private function repointOrders(int $fromAccountId, int $toAccountId): void
    {
        DB::table('calculations')->where('customer_account_id', $fromAccountId)
            ->update(['customer_account_id' => $toAccountId]);
        DB::table('calculations')->where('agency_account_id', $fromAccountId)
            ->update(['agency_account_id' => $toAccountId]);
        DB::table('dispo_orders')->where('customer_account_id', $fromAccountId)
            ->update(['customer_account_id' => $toAccountId]);
        DB::table('dispo_orders')->where('agency_account_id', $fromAccountId)
            ->update(['agency_account_id' => $toAccountId]);
    }

    public function resolveCanonicalAccount(CrmAccount $account): CrmAccount
    {
        $current = $account;
        $guard = 0;
        while ($current->merged_into_account_id !== null && $guard < 10) {
            $next = CrmAccount::query()->find($current->merged_into_account_id);
            if ($next === null) {
                break;
            }
            $current = $next;
            $guard++;
        }

        return $current;
    }

    public static function sameSalesforce(?string $a, ?string $b): bool
    {
        return SalesforceAccountId::sameIdentity($a, $b);
    }
}
