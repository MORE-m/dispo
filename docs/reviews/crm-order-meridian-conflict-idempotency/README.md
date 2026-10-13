# Review: Idempotenz `order_meridian_mismatch`

Stand: 13. Oktober 2026 (Nachzug P2 Alias-Sperren vor Link/Repoint)
Art: **Produktfix** (kein Deploy, keine Mutation in `dispo_mat_core`)
Basis: `origin/main` @ `64096081bcc4de4756f827bb75d6ff8da95f770e`
Ausgangs-HEAD: `3352cd0ab932ca9a9ea9c9eb94cf0c218541635e`
Final-HEAD: `adf25a427f8aa6bd4ec7eaad499ea8d75abe0359`
Branch / Worktree: `fix/crm-order-meridian-conflict-idempotency` / `dispo-wt-fix-crm-order-meridian-conflict-idempotency`
Draft-PR: [#139](https://github.com/MORE-m/dispo/pull/139)
CI: [38416434899](https://github.com/MORE-m/dispo/actions/runs/38416434899) SUCCESS (`ci`/`mysql`/`e2e-spt008`)

## Befund (vorher)

Ein CRM-Import-Apply erzeugte zwei offene `order_meridian_mismatch` mit gleicher fachlicher Identität.

Ursache (belegt):

1. `CrmImportService::applyAction()` ruft `CrmMeridianSupplementService::supplementMissing()` auf.
2. Derselbe Apply rief denselben Service erneut über den Folgeimport-Pfad auf.
3. `openOrderConflict()` legte stets einen neuen Datensatz an (nicht idempotent).

## Vertrag (nachher)

Fachliche Identität eines **offenen** Auftrags-Meridian-Konflikts:

- Account (`crm_account_id`)
- Vorgangstyp (`entity`: `calculation` | `dispo_order`)
- Vorgang (`entity_id`)
- Seite (`field`: `customer_meridian_number` | `agency_meridian_number`)
- bestehende und eingehende Meridian (`existing`, `incoming`)

Nicht Teil der Identität: `crm_import_id`, `order_status`, vollständige JSON-Textgleichheit.

Abgeschlossene Konflikte bleiben unberührt. Erneuter Widerspruch nach Abschluss öffnet einen **neuen** offenen Datensatz (R5: Abweichung = Konflikt, keine Unterdrückung).

Report-Zähler:

| Schlüssel | Bedeutung |
|---|---|
| `supplements.calculations` / `dispo_orders` | tatsächlich ergänzte Vorgänge |
| `supplements.conflicts` | erkannte Widersprüche (fachlich) |
| `supplements.conflicts_created` | neu angelegte offene Konfliktdatensätze |

## Sperrreihenfolge (P2)

Gemeinsam über `CrmAccountIdentityLocker` für Supplement, Import und manuellen/automatischen Link:

1. **Accounts** (Kanon + bestehende Aliasse + Merge-Kette + Link-Quelle) in aufsteigender `id`-Reihenfolge **vor** `repointOrders` / Auftragszugriff
2. Unter Sperre: Identität erneut prüfen (`lockForUpdate` = InnoDB Current Read, kein reiner RR-Snapshot)
3. Fehlende IDs → `CrmAccountLockSetExpandedException`, Transaktion kontrolliert mit vereinigter Menge neu (max. 1 Expansion); **kein** Nachsperren kleinerer IDs bei gehaltenen größeren Locks
4. **Kalkulationen** (`orderBy id` + `lockForUpdate`)
5. **Dispoaufträge** (`orderBy id` + `lockForUpdate`)
6. **Konflikte** (offene `order_meridian_mismatch` des Accounts)

Keine pauschalen Retries als Ersatz für korrekte Reihenfolge.

## Auto-Match-Report (P2)

- `linkProvisionalToSalesforce(..., ?importId, supplementAfterLink=false)` beim Import-Auto-Match
- Nach allen Links: **ein** Nachtrag je Zielaccount; Ergebnisse in den Import-Report
- Bereits in `applyAction` gezählte Ziele: bei Re-Nachtrag nur `conflicts_created` zu `conflicts` addieren (keine Doppelzählung)
- Manueller Link: weiterhin Nachtrag mit `supplementAfterLink=true`, `importId=null` möglich

Beispiel Soll/Ist Auto-Match (Provisional + leere Meridian → SF mit `M-BAM-KEEP`):

| Zähler | Soll | Ist (Browser/Setup) |
|---|---|---|
| calculations | 1 | 1 |
| dispo_orders | 0 | 0 |
| conflicts | 0 | 0 |
| conflicts_created | 0 | 0 |

Mismatch-Auto-Match: `conflicts=1`, `conflicts_created=1`, `crm_import_id` = verursachender Import.

## Umsetzung

- `CrmAccountIdentityLocker` + `CrmAccountLockSetExpandedException`: gemeinsame Identitäts-Sperren
- `CrmMeridianSupplementService`: Locker; nested Link-Transaktion ohne zweiten Expansion-Wrapper
- `CrmImportService`: Entflechtung Apply/Folgeimport; Auto-Match-Nachtrag zentral + Report
- `CrmAccountService`: `lockForLink` vor Repoint/Nachtrag; `{account, supplements}`; optionales `importId`

## Tests

| Nachweis | Beleg |
|---|---|
| Calc/Dispo-Mismatch → genau 1 offener Konflikt | `CrmOrderMeridianConflictIdempotencyTest` |
| Service-Wiederholung + Folgeimport | idem |
| Auto-Match Report Calc/Dispo | `CrmImportAutoMatchSupplementReportTest::auto_match_supplements_…` |
| Auto-Match Mismatch + `crm_import_id` | `…auto_match_mismatch_…` |
| Mehrere Provisionals → ein Ziel | `…multiple_provisionals_…` |
| Unveränderter Folgeimport | `…unchanged_reimport_…` |
| MySQL Supplement∥Supplement | `CrmOrderMeridianConflictConcurrencyTest` |
| MySQL Supplement∥Import | `…concurrent_supplement_and_import_…` |
| MySQL Supplement∥Manual-Link | `…concurrent_supplement_and_manual_link_…` |
| MySQL älterer Alias ∩ Manual-Link (Barrieren an Sperrstellen) | `…concurrent_older_alias_supplement_and_manual_link_overlap_…` |
| Manueller Link Regression | `CrmManualLinkConcurrencyTest` |

## Browser (synthetisch)

- DB: `dispo_accept_crm_omm_idempotency_20261013` (bestehende Kopie; keine Löschung alter Konflikte)
- Port **8069** (beendet); Port 8000 / `.env` / `dispo_mat_core` unberührt
- Import→Auto-Match: Report `calculations=1`; UI Calc K-2026-00002 zeigt `Meridian: M-BAM-KEEP`
- Früherer Idempotenz-Check Port 8068 unverändert gültig
- Belege: `local-backups/crm-omm-idempotency-browser-20261013/`

**Keine Bestand-Abnahme** – Quelle `dispo_mat_core` bleibt leer (separater Datenbefund).

## Nicht geändert

Matching-Regeln, Preis-/Firmierungs-/Status-/Freigabelogik, allgemeine Konfliktbereinigung, Restore/`dispo_mat_core`.
