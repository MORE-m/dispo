# Review: Idempotenz `order_meridian_mismatch`

Stand: 13. Oktober 2026 (Nachzug P2 Sperrreihenfolge + Auto-Match-Report)  
Art: **Produktfix** (kein Deploy, keine Mutation in `dispo_mat_core`)  
Basis: `origin/main` @ `64096081bcc4de4756f827bb75d6ff8da95f770e`  
Branch / Worktree: `fix/crm-order-meridian-conflict-idempotency` / `dispo-wt-fix-crm-order-meridian-conflict-idempotency`  
Draft-PR: [#139](https://github.com/MORE-m/dispo/pull/139)

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

Einheitlich für Supplement, Import und manuellen/automatischen Link:

1. **Accounts** (Kanon + Aliasse / Merge-Kette) in aufsteigender `id`-Reihenfolge, Identität unter Sperre erneut aufgelöst  
2. **Kalkulationen** (`orderBy id` + `lockForUpdate`)  
3. **Dispoaufträge** (`orderBy id` + `lockForUpdate`)  
4. **Konflikte** (offene `order_meridian_mismatch` des Accounts)

`repointOrders` sperrt betroffene Aufträge vor dem Update. Keine pauschalen Retries.

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

- `CrmMeridianSupplementService`: Account-first-Sperren, idempotentes `openOrderConflict`, Zähler `conflicts` / `conflicts_created`
- `CrmImportService`: Entflechtung Apply/Folgeimport; Auto-Match-Nachtrag zentral + Report
- `CrmAccountService`: Link liefert `{account, supplements}`; optionales `importId`; `repointOrders` mit Auftragssperren

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
