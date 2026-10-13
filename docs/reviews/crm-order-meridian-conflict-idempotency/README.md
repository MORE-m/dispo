# Review: Idempotenz `order_meridian_mismatch`

Stand: 13. Oktober 2026  
Art: **Produktfix** (kein Deploy, keine Mutation in `dispo_mat_core`)  
Basis: `origin/main` @ `64096081bcc4de4756f827bb75d6ff8da95f770e`  
Branch / Worktree: `fix/crm-order-meridian-conflict-idempotency` / `dispo-wt-fix-crm-order-meridian-conflict-idempotency`

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
| `supplements.conflicts` | erkannte Widersprüche (fachlich, auch bei Wiederholung) |
| `supplements.conflicts_created` | neu angelegte offene Konfliktdatensätze |

## Umsetzung

- `CrmMeridianSupplementService::openOrderConflict()`: Account-`lockForUpdate`, Abgleich offener Konflikte, Create nur bei fehlender Identität; Rückgabe `bool`.
- `supplementMissing()` liefert `conflicts` und `conflicts_created`.
- `CrmImportService`: Nachtragspfad entflochten (`$meridianSupplementDone`); Aggregation beider Zähler.

## Tests

| Nachweis | Beleg |
|---|---|
| Calc-Mismatch → genau 1 offener Konflikt | `CrmOrderMeridianConflictIdempotencyTest::apply_with_calc_mismatch_…` |
| Dispo-Mismatch → genau 1 | `…apply_with_dispo_mismatch_…` |
| Service-Wiederholung + unveränderter Folgeimport → kein Duplikat | `…repeated_service_call_…` |
| Kunde/Agentur × Calc/Dispo getrennt | `…customer_agency_calc_dispo_…` |
| Nach Resolve erneut → neuer offener Satz (R5) | `…resolved_conflict_reopens_…` |
| Fehlende Meridian wird nachgetragen | `…missing_meridian_is_still_supplemented_…` |
| Bestehender Nachzug-Test zählt exakt 1 | `order_meridian_mismatch_opens_conflict_without_overwrite` |
| MySQL parallel → genau 1 Create | `CrmOrderMeridianConflictConcurrencyTest` + Worker |
| Manueller Link regressionsfrei (MySQL) | `CrmManualLinkConcurrencyTest` |

Lokal: SQLite-CRM-Suite 73 passed / 2 skipped; MySQL-Filter (Idempotenz+Concurrency+Manual-Link) 9 passed.

## Browser (synthetisch)

- DB-Kopie: `dispo_accept_crm_omm_idempotency_20261013` (Quelle `dispo_mat_core` leer; Katalog nur in Kopie aktiviert)
- Port **8068** (beendet); Port 8000 / `.env` unberührt
- Apply1: `conflicts=1`, `conflicts_created=1`; Apply2: `conflicts=1`, `conflicts_created=0`; open=1
- Prüfliste: genau eine Karte (Vorgang K-2026-00001, Seite Kunde, M-OMM-OTHER → M-OMM-KEEP); Calc Meridian/Name unverändert
- Belege außerhalb Git: `local-backups/crm-omm-idempotency-browser-20261013/`

**Keine Bestand-Abnahme** der gefüllten operativen Katalogdaten – Quelle war leer; Prüfung ist synthetisch.

## Nicht geändert

Matching-Regeln, Preis-/Firmierungs-/Status-/Freigabelogik, allgemeine Konfliktbereinigung, Restore/`dispo_mat_core`.
