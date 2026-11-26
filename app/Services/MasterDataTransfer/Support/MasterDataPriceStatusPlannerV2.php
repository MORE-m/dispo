<?php

namespace App\Services\MasterDataTransfer\Support;

use App\Enums\ProductionType;
use App\Models\OaTkpPriceList;
use App\Services\MasterDataTransfer\MasterDataTransferContractV2;
use App\Services\OnlineAudio\Import\OaTkpPriceListActivationService;
use App\Services\OnlineAudio\Import\OaTkpStammdatenImportException;
use App\Support\PriceList\PriceListItemContract;
use Illuminate\Validation\ValidationException;

/**
 * Slice 7: schreibfreie, paketgetriebene Planung der Preisstatus-Phase (Spot / Produktion / OA-TKP).
 *
 * Aktionen je Liste: activate | archive | activate_with_archive_predecessor | unchanged | keep_draft | blocked.
 * Entscheidungsgrundlage: Paket-`source_status` gegen Staging-Ist-Status, Fingerabdruck + Metadaten,
 * Pins, Vollständigkeit (S7-PO-04), Slot-Eindeutigkeit (S7-PO-01). Es werden keine Preise erfunden.
 */
final class MasterDataPriceStatusPlannerV2
{
    public const ACTION_ACTIVATE = 'activate';

    public const ACTION_ARCHIVE = 'archive';

    public const ACTION_ACTIVATE_WITH_ARCHIVE_PREDECESSOR = 'activate_with_archive_predecessor';

    public const ACTION_UNCHANGED = 'unchanged';

    public const ACTION_KEEP_DRAFT = 'keep_draft';

    public const ACTION_BLOCKED = 'blocked';

    public function __construct(
        private readonly MasterDataPriceListIndexV2 $index = new MasterDataPriceListIndexV2,
    ) {}

    /**
     * @param  array<string, mixed>  $packageData
     * @param  array{digest: string, lists: array<string, array<string, mixed>>}  $pins
     * @return array{
     *     processing_contract_version: string,
     *     schema_version: string,
     *     package_sha256: string,
     *     database_name: string,
     *     pin_digest: string,
     *     lists: list<array<string, mixed>>,
     *     counts_by_action: array<string, int>,
     *     blocked_count: int,
     *     blocked_messages: list<string>,
     *     plan_hash: string
     * }
     */
    public function plan(array $packageData, string $packageSha256, string $databaseName, array $pins): array
    {
        $entries = MasterDataPriceListIndexV2::fromPackage($packageData);

        $sourceActivePerSlot = [];
        foreach ($entries as $entry) {
            if ($entry['source_status'] === 'active') {
                $sourceActivePerSlot[$entry['family'].'|'.$entry['slot']][] = $entry['key'];
            }
        }

        $rows = [];
        foreach ($entries as $entry) {
            $rows[] = $this->decide($entry, $pins, $sourceActivePerSlot);
        }

        // Zweiter Durchlauf: aktive Staging-Vorgänger, die durch einen Nachfolger atomar archiviert werden.
        $archivedAsPredecessor = [];
        foreach ($rows as $row) {
            if ($row['action'] === self::ACTION_ACTIVATE_WITH_ARCHIVE_PREDECESSOR && is_array($row['predecessor'])) {
                $archivedAsPredecessor[$row['family'].':'.$row['predecessor']['id']] = $row['key'];
            }
        }
        foreach ($rows as $i => $row) {
            if (($row['pending_cover'] ?? false) !== true) {
                continue;
            }
            $coverKey = $row['family'].':'.$row['staging_id'];
            if (isset($archivedAsPredecessor[$coverKey])) {
                $rows[$i]['action'] = self::ACTION_UNCHANGED;
                $rows[$i]['reason'] = 'wird atomar durch Nachfolger archiviert: '.$archivedAsPredecessor[$coverKey];
                $rows[$i]['archived_by_successor'] = $archivedAsPredecessor[$coverKey];
            } else {
                $rows[$i]['action'] = self::ACTION_BLOCKED;
                $rows[$i]['reason'] = 'active→archived ist nur über activate_with_archive_predecessor eines Nachfolgers erlaubt (S7-PO-01).';
            }
            unset($rows[$i]['pending_cover']);
        }
        foreach ($rows as $i => $row) {
            unset($rows[$i]['pending_cover']);
        }

        $counts = [];
        $blocked = [];
        foreach ($rows as $row) {
            $counts[$row['action']] = ($counts[$row['action']] ?? 0) + 1;
            if ($row['action'] === self::ACTION_BLOCKED) {
                $blocked[] = sprintf('[%s] %s: %s', $row['family'], $row['key'], (string) $row['reason']);
            }
        }
        ksort($counts);

        $plan = [
            'processing_contract_version' => MasterDataTransferContractV2::PROCESSING_CONTRACT_VERSION,
            'schema_version' => MasterDataTransferContractV2::SCHEMA_VERSION,
            'package_sha256' => strtolower($packageSha256),
            'database_name' => $databaseName,
            'pin_digest' => $pins['digest'],
            'lists' => $rows,
        ];

        return array_merge($plan, [
            'counts_by_action' => $counts,
            'blocked_count' => count($blocked),
            'blocked_messages' => $blocked,
            'plan_hash' => hash('sha256', MasterDataProofSignerV2::canonicalJson($plan)),
        ]);
    }

    /**
     * @param  array{family: string, key: string, slot: string, source_status: string, fingerprint: string, metadata: array<string, mixed>, year: int, revision: int, inventory_code: ?string, production_type: ?string}  $entry
     * @param  array{lists: array<string, array<string, mixed>>}  $pins
     * @param  array<string, list<string>>  $sourceActivePerSlot
     * @return array<string, mixed>
     */
    private function decide(array $entry, array $pins, array $sourceActivePerSlot): array
    {
        $row = [
            'family' => $entry['family'],
            'key' => $entry['key'],
            'slot' => $entry['slot'],
            'source_status' => $entry['source_status'],
            'package_fingerprint' => $entry['fingerprint'],
            'staging_id' => null,
            'staging_status' => null,
            'staging_lock_version' => null,
            'staging_fingerprint' => null,
            'pinned' => false,
            'completeness' => 'not_required',
            'predecessor' => null,
            'action' => self::ACTION_BLOCKED,
            'reason' => null,
        ];

        $source = $entry['source_status'];
        if (! in_array($source, ['draft', 'active', 'archived'], true)) {
            $row['reason'] = 'source_status ungültig: '.($source === '' ? '(leer)' : $source);

            return $row;
        }

        if ($source === 'active' && count($sourceActivePerSlot[$entry['family'].'|'.$entry['slot']] ?? []) > 1) {
            $row['reason'] = 'Mehrere Quelllisten mit source_status=active im selben Slot ('.$entry['slot'].').';

            return $row;
        }

        $staging = $this->index->stagingFor($entry);
        if ($staging === null) {
            $row['reason'] = 'Staging-Liste fehlt (Slice 7 legt keine Listen an; zuerst Inhalts-Apply).';

            return $row;
        }

        $row['staging_id'] = $staging['id'];
        $row['staging_status'] = $staging['status'];
        $row['staging_lock_version'] = $staging['lock_version'];
        $row['staging_fingerprint'] = $staging['fingerprint'];
        $pinned = isset($pins['lists'][$entry['family'].':'.$staging['id']]);
        $row['pinned'] = $pinned;

        if ($staging['fingerprint'] !== $entry['fingerprint'] || $staging['metadata'] !== $entry['metadata']) {
            $row['reason'] = 'Fingerabdruck/Metadaten der Staging-Liste weichen vom Paket ab.';

            return $row;
        }

        $current = (string) $staging['status'];
        if ($current === $source) {
            $row['action'] = $current === 'draft' ? self::ACTION_KEEP_DRAFT : self::ACTION_UNCHANGED;

            return $row;
        }

        if ($pinned) {
            $row['reason'] = 'Liste ist gepinnt (Kalkulation/Dispo) — kein Statuswechsel.';

            return $row;
        }

        if ($current === 'draft' && $source === 'archived') {
            $row['action'] = self::ACTION_ARCHIVE;

            return $row;
        }

        if ($current === 'draft' && $source === 'active') {
            $completeness = $this->completeness($entry, $staging);
            $row['completeness'] = $completeness === null ? 'pass' : 'fail';
            if ($completeness !== null) {
                $row['reason'] = 'Unvollständige Liste, Aktivierung gesperrt (S7-PO-04): '.$completeness;

                return $row;
            }

            $actives = array_values(array_filter(
                $this->index->activeInSlot($entry),
                fn (array $a): bool => $a['id'] !== $staging['id'],
            ));
            if ($actives === []) {
                $row['action'] = self::ACTION_ACTIVATE;

                return $row;
            }
            if (count($actives) > 1) {
                $row['reason'] = 'Mehrere aktive Listen im Slot '.$entry['slot'].' — manuelle Klärung nötig.';

                return $row;
            }

            $predecessor = $actives[0];
            if (isset($pins['lists'][$entry['family'].':'.$predecessor['id']])) {
                $row['reason'] = 'Aktiver Vorgänger '.$predecessor['key'].' ist gepinnt — kein Archivieren (S7-PO-01).';

                return $row;
            }
            if ($predecessor['slot'] !== $entry['slot']) {
                $row['reason'] = 'Vorgänger gehört nicht zur selben Slot-Identität.';

                return $row;
            }

            $row['predecessor'] = [
                'id' => $predecessor['id'],
                'key' => $predecessor['key'],
                'status' => $predecessor['status'],
                'lock_version' => $predecessor['lock_version'],
                'fingerprint' => $predecessor['fingerprint'],
            ];
            $row['action'] = self::ACTION_ACTIVATE_WITH_ARCHIVE_PREDECESSOR;

            return $row;
        }

        if ($current === 'active' && $source === 'archived') {
            $row['pending_cover'] = true;

            return $row;
        }

        $row['reason'] = match (true) {
            $current === 'active' && $source === 'draft' => 'active→draft ist verboten.',
            $current === 'archived' && $source === 'active' => 'Reaktivieren archivierter Listen ist verboten.',
            $current === 'archived' && $source === 'draft' => 'archived→draft ist verboten.',
            default => sprintf('Statuswechsel %s→%s ist nicht erlaubt.', $current, $source),
        };

        return $row;
    }

    /**
     * @param  array<string, mixed>  $entry
     * @param  array<string, mixed>  $staging
     * @return string|null Fehlermeldung oder null, wenn aktivierbar
     */
    private function completeness(array $entry, array $staging): ?string
    {
        switch ($entry['family']) {
            case MasterDataPriceListIndexV2::FAMILY_SPOT:
                try {
                    PriceListItemContract::normalizeBaseItems(
                        is_array($staging['items'] ?? null) ? $staging['items'] : [],
                        forActivation: true,
                    );
                } catch (ValidationException $e) {
                    return (string) ($e->errors()['items'][0] ?? 'Basispreise unvollständig.');
                }

                return null;

            case MasterDataPriceListIndexV2::FAMILY_PRODUCTION:
                if (ProductionType::tryFrom((string) ($staging['production_type'] ?? '')) === null) {
                    return 'Produktionsart ungültig.';
                }
                $unit = (string) ($staging['unit_price'] ?? '');
                if (preg_match('/^\d{1,12}\.\d{2}$/', $unit) !== 1) {
                    return 'Produktionspreis fehlt oder ist ungültig.';
                }

                return null;

            case MasterDataPriceListIndexV2::FAMILY_OA_TKP:
                $model = OaTkpPriceList::query()->find((int) $staging['id']);
                if (! $model instanceof OaTkpPriceList) {
                    return 'OA-TKP-Liste nicht gefunden.';
                }
                try {
                    app(OaTkpPriceListActivationService::class)->assertActivatable($model);
                } catch (OaTkpStammdatenImportException $e) {
                    return implode(' | ', array_slice($e->errors !== [] ? $e->errors : [$e->getMessage()], 0, 3));
                }

                return null;
        }

        return 'Unbekannte Familie.';
    }
}
