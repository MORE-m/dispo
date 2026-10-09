# Bericht: CRM Salesforce / Meridian – BL-P2-03a

Stand: 9. Oktober 2026 (Review-Nachzug PR #133)
Arbeitsbasis: `origin/main` @ `fff472f112882b65abad4633ea8e5177ebe58f42`
(Merge PR [#132](https://github.com/MORE-m/dispo/pull/132))
Feature-Branch: `feat/bl-p2-03a-salesforce-meridian`
Worktree: `dispo-wt-feat-bl-p2-03a`
Draft-PR: [#133](https://github.com/MORE-m/dispo/pull/133)
Final HEAD: `a8ecb302a8e16fdc5b7955e57522eb54cea5b6d1`
CI: grün auf exakt diesem HEAD (Run [`37992762467`](https://github.com/MORE-m/dispo/actions/runs/37992762467); `ci` / `mysql` / `e2e-spt008`, inkl. Browser BL-P2-03a)

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
| P1 Link unter Sperre | Prüfungen nach `lockForUpdate`; Idempotenz gleiches Ziel; widersprüchliches Ziel abgelehnt; Zyklen ausgeschlossen | `competing_manual_links_cannot_split_orders_across_targets` |
| P2 Meridian nach manuellem Link | SF-ID + Meridian nach Link; Folgeimport unverändert nachholend; Order-Mismatch = Konflikt | `manual_link_supplements_…`, `order_meridian_mismatch_…` |
| P2 Veraltete Vorschau | Refresh commitet vor 409; erneut anwendbar | `stale_preview_is_persisted_on_409_and_can_be_reapplied` |
| P2 Vorschau-Wirkungen | Effects + geplante Auto-Matches im Preview | `preview_exposes_effects_and_planned_matches` + UI |
| P2 Manuelle Zuordnung/Konflikte | Suche SF-Ziel unabhängig von Domain; Konflikt abschließen auditiert | `manual_search_link_without_domain_and_conflict_resolve` + E2E |
| P2 Browser + CI | Erweiterte E2E; `playwright.blp203a` in `tests.yml` | `npm run test:e2e:blp203a -- --retries=0` |

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
- CI: Run `37992762467` auf HEAD `a8ecb30` vollständig grün

## Offen

`CRM-004`, operative Datenabnahme, Deploy; erneute Review des Draft-PR (kein Merge).
