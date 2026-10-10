# Bericht: CRM Salesforce / Meridian – BL-P2-03a

Stand: 10. Oktober 2026 (Review-Nachzug Restbefunde PR #133)
Arbeitsbasis: `origin/main` @ `fff472f112882b65abad4633ea8e5177ebe58f42`
(Merge PR [#132](https://github.com/MORE-m/dispo/pull/132))
Feature-Branch: `feat/bl-p2-03a-salesforce-meridian`
Worktree: `dispo-wt-feat-bl-p2-03a`
Draft-PR: [#133](https://github.com/MORE-m/dispo/pull/133)

**Kein** Deploy. Port 8000 / `dispo-main` / `dispo_mat_core` / `.env` unberührt.
Migrationen nur in isolierten Test-/E2E-DBs.
PO A1/B1/C1/D-CSV/E1/F1/G1/H1 unverändert akzeptiert.

## Artefakte

| Datei | Rolle |
|---|---|
| [`docs/entscheidungen/PO-BLP203-1-crm-salesforce-meridian.md`](../../entscheidungen/PO-BLP203-1-crm-salesforce-meridian.md) | PO **Akzeptiert** |
| [`docs/readiness/BL-P2-03-crm-salesforce-meridian-2026-10-09.md`](../../readiness/BL-P2-03-crm-salesforce-meridian-2026-10-09.md) | Readiness |

## Review-Nachzug (Ergebnisse)

| Punkt | Ergebnis | Testbeleg |
|---|---|---|
| P1 Stammdatensnapshots | Bei unveränderter Account-Identität bleiben Version/Firmierung; Canonical-ID nach Merge ok; Meridian technisch getrennt | `calc_save_keeps_historical_snapshot_after_master_data_version_change` |
| P1 Link unter Sperre (sequenziell) | Zweite Zuordnung auf anderes Ziel abgelehnt | `sequential_second_manual_link_to_other_target_is_rejected` |
| P1 Link unter Sperre (parallel, MySQL) | Zwei Worker mit getrennten DB-Verbindungen (`DatabaseMigrations`); genau ein Gewinner | `CrmManualLinkConcurrencyTest::concurrent_manual_links_serialize_to_one_target` |
| P2 Meridian nach manuellem Link | SF-ID + Meridian nach Link; Folgeimport unverändert nachholend; Order-Mismatch = Konflikt | `manual_link_supplements_…`, `order_meridian_mismatch_…` |
| P2 Veraltete Vorschau | Refresh commitet vor 409; erneut anwendbar | `stale_preview_is_persisted_on_409_and_can_be_reapplied` + E2E Zwei-Tab-409 |
| P2 Vorschau-Wirkungen | Effects + geplante Auto-Matches; Domainwechsel am Bestand in Preview | `preview_exposes_effects_…`, `domain_change_preview_*` |
| P2 Manuelle Zuordnung/Konflikte | Suche SF-Ziel unabhängig von Domain; Konflikt abschließen auditiert | `manual_search_link_…` + E2E |
| P1 Wizard-Payload | Dependencies für Domain/E-Mail/Agentur-Vorläufig vollständig; Save nutzt letzte Eingabe | E2E `Wizard: Domain zuletzt setzen…` |
| P2 Browser + CI | Vertikal Calc→Dispo→Meridian; manueller Link; 409 Zwei-Tab; `playwright.blp203a` in `tests.yml` | `npm run test:e2e:blp203a -- --retries=0` |

## Scope (A1)

- Manueller Salesforce-**CSV**-Import (UTF-8, Semikolon)
- Typmapping `Account KUNDE` / `Account AGENTUR`
- Stammdatenversionen, vorläufige Accounts, Domain+Typ-Zuordnung/Prüfliste
- Calc/Dispo-Anbindung inkl. Rechnungsempfänger (F1)
- Meridian-Nachtrag statusunabhängig ohne Freigabeinvalidierung (E1)
- UX-GATE-D Teilfreigabe B1 nur für diesen Slice

## Nicht im Slice

Kontakte (`CRM-004`), Salesforce-/Meridian-API, E-Mail-Ingest, XLSX/Numbers,
pauschale Erledigung von `BL-P2-03`, Deploy.

## Tests

- Unit: `SalesforceAccountIdTest`
- Feature: `CrmSalesforceMeridianBlP203aTest`, `CrmSalesforceMeridianReviewNachzugTest`
- E2E: `npm run test:e2e:blp203a` (Port **8060**, CI-Job vorhanden)
- Parallelität: MySQL-Worker `tests/concurrency/crm_manual_link_worker.php`

## Offen

`CRM-004`, operative Datenabnahme, Deploy; erneute Review des Draft-PR (kein Merge).
