<?php

namespace App\Services\StandardOffer;

use App\Enums\StandardOfferVersionStatus;
use App\Models\Calculation;
use App\Models\StandardOffer;
use App\Models\StandardOfferVersion;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Calculation\CalculationWriter;
use App\Services\DynamicField\ConfigurationSnapshotFreezeService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * BL-P4-03a/03c/03b/03d / STD-001–STD-009 / VER-004 / AUTH-006 / SPT-014.
 *
 * Publish: Freeze über {@see StandardOfferMaterializer}.
 * Adopt: Hydrate über {@see FrozenCalculationPersistenceContract} (kein
 * CalculationWriter::create(), keine Live-Preisauflösung).
 * From-Calc: Sanitize + neuer Draft, keine Sync/Auto-Publish.
 */
final class StandardOfferWriter
{
    public function __construct(
        private readonly StandardOfferNumberSequencer $offerNumbers,
        private readonly StandardOfferAverageContract $averageContract,
        private readonly StandardOfferFromCalculationSanitizer $fromCalculation,
        private readonly StandardOfferMaterializer $materializer,
        private readonly FrozenCalculationPersistenceContract $frozenPersistence,
        private readonly CalculationWriter $calculations,
        private readonly ConfigurationSnapshotFreezeService $snapshots,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $draftPayload
     */
    public function create(string $title, array $draftPayload, User $user): StandardOffer
    {
        $title = $this->assertTitle($title);
        $payload = $this->averageContract->normalizeDraftPayload($draftPayload);
        $this->fromCalculation->assertNoCustomerLeak($payload);
        $this->assertResolvable($payload, $user);

        return DB::transaction(function () use ($title, $payload, $user): StandardOffer {
            [$year, $seq, $number] = $this->offerNumbers->next();

            $offer = new StandardOffer;
            $offer->number = $number;
            $offer->number_year = $year;
            $offer->number_seq = $seq;
            $offer->title = $title;
            $offer->lock_version = 1;
            $offer->created_by = $user->id;
            $offer->save();

            $version = new StandardOfferVersion;
            $version->standard_offer_id = $offer->id;
            $version->version_number = 1;
            $version->status = StandardOfferVersionStatus::Draft;
            $version->title = $title;
            $version->author_id = $user->id;
            $version->draft_payload = $payload;
            $version->lock_version = 1;
            $version->save();

            $this->audit->record($offer, 'standard_offer.created', $user, null, $this->offerSnapshot($offer->fresh(['versions'])));

            return $offer->fresh(['versions', 'draftVersion', 'publishedVersion']) ?? $offer;
        });
    }

    /**
     * BL-P4-03b: immer neuer SA-Draft aus zugänglicher Kalkulation.
     */
    public function createFromCalculation(Calculation $calculation, User $user): StandardOffer
    {
        $payload = $this->calculations->payloadFromCalculation($calculation);
        $sanitized = $this->fromCalculation->sanitize($calculation, $payload);
        $sanitized['draft_payload'] = $this->rebindLiveSchemaFingerprints($sanitized['draft_payload']);
        $this->assertResolvable($sanitized['draft_payload'], $user);

        return DB::transaction(function () use ($calculation, $sanitized, $user): StandardOffer {
            [$year, $seq, $number] = $this->offerNumbers->next();

            $offer = new StandardOffer;
            $offer->number = $number;
            $offer->number_year = $year;
            $offer->number_seq = $seq;
            $offer->title = $sanitized['title'];
            $offer->lock_version = 1;
            $offer->created_by = $user->id;
            $offer->save();

            $version = new StandardOfferVersion;
            $version->standard_offer_id = $offer->id;
            $version->version_number = 1;
            $version->status = StandardOfferVersionStatus::Draft;
            $version->title = $sanitized['title'];
            $version->author_id = $user->id;
            $version->draft_payload = $sanitized['draft_payload'];
            $version->proposal_review = $sanitized['proposal_review'];
            $version->source_calculation_id = $calculation->id;
            $version->lock_version = 1;
            $version->save();

            $this->audit->record($offer, 'standard_offer.proposed_from_calculation', $user, null, [
                ...$this->offerSnapshot($offer->fresh(['versions']) ?? $offer),
                'source_calculation_id' => $calculation->id,
                'source_calculation_number' => $calculation->number,
            ]);

            return $offer->fresh(['versions', 'draftVersion', 'publishedVersion']) ?? $offer;
        });
    }

    /**
     * @param  array<string, mixed>  $draftPayload
     */
    public function updateDraft(
        StandardOfferVersion $version,
        string $title,
        array $draftPayload,
        int $expectedLockVersion,
        User $user,
    ): StandardOfferVersion {
        $title = $this->assertTitle($title);
        $payload = $this->averageContract->normalizeDraftPayload($draftPayload);
        $this->fromCalculation->assertNoCustomerLeak($payload);
        $this->assertResolvable($payload, $user);

        return DB::transaction(function () use ($version, $title, $payload, $expectedLockVersion, $user): StandardOfferVersion {
            $locked = StandardOfferVersion::query()->whereKey($version->id)->lockForUpdate()->firstOrFail();
            $this->assertDraftEditable($locked, $expectedLockVersion);
            $before = $this->versionSnapshot($locked);

            $locked->title = $title;
            $locked->draft_payload = $payload;
            $locked->author_id = $user->id;
            // Speichern bestätigt die Freitext-Prüfung bewusst nicht.
            $locked->lock_version = $locked->lock_version + 1;
            $locked->save();

            $offer = StandardOffer::query()->whereKey($locked->standard_offer_id)->lockForUpdate()->firstOrFail();
            // Offer-Titel bleibt die sichtbare Published-Fassade für Vertrieb,
            // solange eine veröffentlichte Version existiert (PO-BLP403A-1).
            $hasPublished = StandardOfferVersion::query()
                ->where('standard_offer_id', $offer->id)
                ->where('status', StandardOfferVersionStatus::Published->value)
                ->exists();
            if (! $hasPublished) {
                $offer->title = $title;
            }
            $offer->lock_version = $offer->lock_version + 1;
            $offer->save();

            $fresh = $locked->fresh() ?? $locked;
            $this->audit->record($fresh, 'standard_offer.version.updated', $user, $before, $this->versionSnapshot($fresh));

            return $fresh;
        });
    }

    /**
     * Ausdrückliche Bestätigung der Freitext-Prüfung (kein Auto-Ack bei Save/Publish).
     */
    public function acknowledgeProposalReview(
        StandardOfferVersion $version,
        int $expectedLockVersion,
        User $user,
    ): StandardOfferVersion {
        return DB::transaction(function () use ($version, $expectedLockVersion, $user): StandardOfferVersion {
            $locked = StandardOfferVersion::query()->whereKey($version->id)->lockForUpdate()->firstOrFail();
            $this->assertDraftEditable($locked, $expectedLockVersion);

            $review = $locked->proposal_review;
            if (! is_array($review) || ($review['review_required'] ?? false) !== true) {
                throw ValidationException::withMessages([
                    'proposal_review' => 'Für diesen Entwurf ist keine Freitext-Prüfung erforderlich.',
                ]);
            }

            $before = $this->versionSnapshot($locked);
            $locked->proposal_review = $this->acknowledgedProposalReview($review, $user);
            $locked->lock_version = $locked->lock_version + 1;
            $locked->save();

            $offer = StandardOffer::query()->whereKey($locked->standard_offer_id)->lockForUpdate()->firstOrFail();
            $offer->lock_version = $offer->lock_version + 1;
            $offer->save();

            $fresh = $locked->fresh() ?? $locked;
            $this->audit->record(
                $fresh,
                'standard_offer.version.proposal_review_acknowledged',
                $user,
                $before,
                $this->versionSnapshot($fresh),
            );

            return $fresh;
        });
    }

    public function createDraftFromPublished(StandardOffer $offer, User $user): StandardOfferVersion
    {
        return DB::transaction(function () use ($offer, $user): StandardOfferVersion {
            $lockedOffer = StandardOffer::query()->whereKey($offer->id)->lockForUpdate()->firstOrFail();

            if ($lockedOffer->draftVersion()->exists()) {
                throw ValidationException::withMessages([
                    'status' => 'Es existiert bereits ein Entwurf für dieses Standardangebot.',
                ]);
            }

            $published = StandardOfferVersion::query()
                ->where('standard_offer_id', $lockedOffer->id)
                ->where('status', StandardOfferVersionStatus::Published->value)
                ->lockForUpdate()
                ->first();

            if ($published === null || ! is_array($published->frozen_materialization)) {
                throw ValidationException::withMessages([
                    'status' => 'Keine veröffentlichte Version als Grundlage für einen neuen Entwurf.',
                ]);
            }

            $maxVersion = (int) StandardOfferVersion::query()
                ->where('standard_offer_id', $lockedOffer->id)
                ->max('version_number');

            $draftPayload = $published->frozen_materialization['draft_payload']
                ?? $published->draft_payload
                ?? [];
            if (! is_array($draftPayload)) {
                $draftPayload = [];
            }
            $draftPayload = $this->averageContract->normalizeDraftPayload($draftPayload);

            $version = new StandardOfferVersion;
            $version->standard_offer_id = $lockedOffer->id;
            $version->version_number = $maxVersion + 1;
            $version->status = StandardOfferVersionStatus::Draft;
            $version->title = $published->title;
            $version->author_id = $user->id;
            $version->draft_payload = $draftPayload;
            $version->lock_version = 1;
            $version->save();

            // Offer-Titel bleibt die Published-Fassade; Draft ändert ihn nicht.
            $lockedOffer->lock_version = $lockedOffer->lock_version + 1;
            $lockedOffer->save();

            $this->audit->record($version, 'standard_offer.version.draft_created', $user, null, $this->versionSnapshot($version));

            return $version;
        });
    }

    public function publish(StandardOfferVersion $version, int $expectedLockVersion, User $user): StandardOfferVersion
    {
        return DB::transaction(function () use ($version, $expectedLockVersion, $user): StandardOfferVersion {
            $locked = StandardOfferVersion::query()->whereKey($version->id)->lockForUpdate()->firstOrFail();
            $this->assertDraftEditable($locked, $expectedLockVersion);

            $offer = StandardOffer::query()->whereKey($locked->standard_offer_id)->lockForUpdate()->firstOrFail();
            $this->assertProposalReviewReadyForPublish($locked);
            $payload = $this->averageContract->normalizeDraftPayload($locked->draft_payload ?? []);
            $this->fromCalculation->assertNoCustomerLeak($payload);
            $frozen = $this->materializer->freeze($payload, $user);

            $previousPublished = StandardOfferVersion::query()
                ->where('standard_offer_id', $offer->id)
                ->where('status', StandardOfferVersionStatus::Published->value)
                ->whereKeyNot($locked->id)
                ->lockForUpdate()
                ->get();

            foreach ($previousPublished as $previous) {
                $prevBefore = $this->versionSnapshot($previous);
                $previous->status = StandardOfferVersionStatus::Archived;
                $previous->archived_at = now();
                $previous->lock_version = $previous->lock_version + 1;
                $previous->save();
                $this->audit->record(
                    $previous,
                    'standard_offer.version.archived',
                    $user,
                    $prevBefore,
                    $this->versionSnapshot($previous->fresh() ?? $previous),
                );
            }

            $before = $this->versionSnapshot($locked);
            $locked->status = StandardOfferVersionStatus::Published;
            $locked->published_at = now();
            $locked->author_id = $user->id;
            $locked->draft_payload = $payload;
            $locked->frozen_materialization = $frozen['materialization'];
            $locked->configuration_snapshot_id = $frozen['base_snapshot_id'];
            $locked->proposal_review = $this->clearedProposalReviewAfterPublish($locked->proposal_review, $user);
            $locked->lock_version = $locked->lock_version + 1;
            $locked->save();

            // Erst mit Publish wird der Offer-Titel zur neuen Published-Fassade.
            $offer->title = $locked->title;
            $offer->lock_version = $offer->lock_version + 1;
            $offer->save();

            $fresh = $locked->fresh() ?? $locked;
            $this->audit->record($fresh, 'standard_offer.version.published', $user, $before, $this->versionSnapshot($fresh));

            return $fresh;
        });
    }

    public function archive(StandardOfferVersion $version, int $expectedLockVersion, User $user): StandardOfferVersion
    {
        return DB::transaction(function () use ($version, $expectedLockVersion, $user): StandardOfferVersion {
            $locked = StandardOfferVersion::query()->whereKey($version->id)->lockForUpdate()->firstOrFail();

            if ((int) $locked->lock_version !== $expectedLockVersion) {
                throw ValidationException::withMessages([
                    'lock_version' => 'Die Version wurde parallel geändert. Bitte neu laden.',
                ]);
            }

            if ($locked->status === StandardOfferVersionStatus::Archived) {
                return $locked;
            }

            if (! in_array($locked->status, [
                StandardOfferVersionStatus::Draft,
                StandardOfferVersionStatus::Published,
            ], true)) {
                throw ValidationException::withMessages([
                    'status' => 'Nur Entwürfe oder veröffentlichte Versionen können archiviert werden.',
                ]);
            }

            $before = $this->versionSnapshot($locked);
            $locked->status = StandardOfferVersionStatus::Archived;
            $locked->archived_at = now();
            $locked->lock_version = $locked->lock_version + 1;
            $locked->save();

            $offer = StandardOffer::query()->whereKey($locked->standard_offer_id)->lockForUpdate()->firstOrFail();
            $offer->lock_version = $offer->lock_version + 1;
            $offer->save();

            $fresh = $locked->fresh() ?? $locked;
            $this->audit->record($fresh, 'standard_offer.version.archived', $user, $before, $this->versionSnapshot($fresh));

            return $fresh;
        });
    }

    public function adopt(
        StandardOfferVersion $version,
        string $customerName,
        ?string $agencyName,
        ?string $campaign,
        User $user,
    ): Calculation {
        $customerName = trim($customerName);
        if ($customerName === '') {
            throw ValidationException::withMessages([
                'customer_name' => 'Kunde ist bei der Übernahme Pflicht.',
            ]);
        }

        return DB::transaction(function () use ($version, $customerName, $agencyName, $campaign, $user): Calculation {
            $locked = StandardOfferVersion::query()->whereKey($version->id)->lockForUpdate()->firstOrFail();

            if (! $locked->status->isAdoptable() || ! is_array($locked->frozen_materialization)) {
                throw ValidationException::withMessages([
                    'status' => 'Nur veröffentlichte Versionen können übernommen werden.',
                ]);
            }

            $materialization = $locked->frozen_materialization;
            // Fail-closed vor Persistenz: unbekannte Version / unvollständige Frozen-Daten.
            $this->frozenPersistence->assertHydratable($materialization);

            $agency = $agencyName !== null && trim($agencyName) !== '' ? trim($agencyName) : null;
            $campaignValue = $campaign !== null && trim($campaign) !== '' ? trim($campaign) : null;

            $fresh = $this->frozenPersistence->hydrateAdoptedCalculation($materialization, [
                'customer_name' => $customerName,
                'agency_name' => $agency,
                'campaign' => $campaignValue,
                'advisor' => $user,
                'origin_version' => $locked,
            ]);

            $this->audit->record($fresh, 'standard_offer.adopted', $user, null, [
                'calculation_id' => $fresh->id,
                'calculation_number' => $fresh->number,
                'origin_standard_offer_version_id' => $locked->id,
                'standard_offer_id' => $locked->standard_offer_id,
            ]);
            $this->audit->record($fresh, 'calculation.created', $user, null, [
                'number' => $fresh->number,
                'origin_standard_offer_version_id' => $locked->id,
            ]);

            return $fresh;
        });
    }

    /**
     * Vorlagen-Draft bindet an Live-Schema (Publish-Freeze), nicht an den
     * historischen Calc-Fingerprint – Quelle bleibt ungekoppelt (STD-005).
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function rebindLiveSchemaFingerprints(array $payload): array
    {
        $live = $this->snapshots->resolveLiveSchemaForCalculationV3();
        $payload['schema_fingerprint'] = $live['schema_fingerprint'];

        $positions = $payload['positions'] ?? [];
        if (! is_array($positions)) {
            return $payload;
        }

        foreach ($positions as $index => $position) {
            if (! is_array($position)) {
                continue;
            }
            $mediumId = (int) ($position['advertising_medium_id'] ?? 0);
            if ($mediumId <= 0) {
                continue;
            }
            $positions[$index]['schema_fingerprint'] = $this->snapshots
                ->resolveLivePositionSchema($mediumId)['schema_fingerprint'];
        }
        $payload['positions'] = $positions;

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function assertResolvable(array $payload, User $user): void
    {
        $this->calculations->preview($payload, $user);
    }

    /**
     * @param  array<string, mixed>|null  $review
     * @return array<string, mixed>|null
     */
    private function acknowledgedProposalReview(?array $review, User $user): ?array
    {
        if ($review === null) {
            return null;
        }

        return [
            ...$review,
            'review_required' => false,
            'acknowledged_at' => now()->toIso8601String(),
            'acknowledged_by' => $user->id,
        ];
    }

    /**
     * Nach Publish keine Quell-Feldliste mehr nötig; Bestätigungsmeta bleibt auditierbar.
     *
     * @param  array<string, mixed>|null  $review
     * @return array<string, mixed>|null
     */
    private function clearedProposalReviewAfterPublish(?array $review, User $user): ?array
    {
        if ($review === null) {
            return null;
        }

        return [
            'field_keys_requiring_review' => [],
            'review_required' => false,
            'acknowledged_at' => $review['acknowledged_at'] ?? now()->toIso8601String(),
            'acknowledged_by' => $review['acknowledged_by'] ?? $user->id,
            'cleared_at_publish' => now()->toIso8601String(),
        ];
    }

    private function assertProposalReviewReadyForPublish(StandardOfferVersion $version): void
    {
        $review = $version->proposal_review;
        if (! is_array($review)) {
            return;
        }

        if (($review['review_required'] ?? false) === true && empty($review['acknowledged_at'])) {
            throw ValidationException::withMessages([
                'proposal_review' => 'Die Freitext-Prüfung muss vor der Veröffentlichung ausdrücklich bestätigt werden.',
            ]);
        }
    }

    private function assertTitle(string $title): string
    {
        $title = trim($title);
        if ($title === '') {
            throw ValidationException::withMessages([
                'title' => 'Titel ist erforderlich.',
            ]);
        }

        return $title;
    }

    private function assertDraftEditable(StandardOfferVersion $version, int $expectedLockVersion): void
    {
        if ($version->status !== StandardOfferVersionStatus::Draft) {
            throw ValidationException::withMessages([
                'status' => 'Nur Entwürfe dürfen bearbeitet oder veröffentlicht werden.',
            ]);
        }

        if ((int) $version->lock_version !== $expectedLockVersion) {
            throw ValidationException::withMessages([
                'lock_version' => 'Die Version wurde parallel geändert. Bitte neu laden.',
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function offerSnapshot(StandardOffer $offer): array
    {
        return [
            'id' => $offer->id,
            'number' => $offer->number,
            'title' => $offer->title,
            'lock_version' => $offer->lock_version,
            'versions' => $offer->versions->map(fn (StandardOfferVersion $version): array => [
                'id' => $version->id,
                'version_number' => $version->version_number,
                'status' => $version->status->value,
            ])->all(),
        ];
    }

    /**
     * AUD-001: nachvollziehbare Old/New inkl. Entwurfsinhalt (Positionen/Mengen).
     *
     * @return array<string, mixed>
     */
    private function versionSnapshot(StandardOfferVersion $version): array
    {
        return [
            'id' => $version->id,
            'standard_offer_id' => $version->standard_offer_id,
            'version_number' => $version->version_number,
            'status' => $version->status->value,
            'title' => $version->title,
            'author_id' => $version->author_id,
            'published_at' => $version->published_at?->toIso8601String(),
            'archived_at' => $version->archived_at?->toIso8601String(),
            'configuration_snapshot_id' => $version->configuration_snapshot_id,
            'lock_version' => $version->lock_version,
            'draft_payload' => $version->draft_payload,
        ];
    }
}
