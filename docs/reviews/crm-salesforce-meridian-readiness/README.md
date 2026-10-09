# Bericht: CRM Salesforce / Meridian – BL-P2-03a

Stand: 9. Oktober 2026
Arbeitsbasis: `origin/main` @ `fff472f112882b65abad4633ea8e5177ebe58f42`
(Merge PR [#132](https://github.com/MORE-m/dispo/pull/132))
Feature-Branch: `feat/bl-p2-03a-salesforce-meridian`
Worktree: `dispo-wt-feat-bl-p2-03a`

**Kein** Deploy. Port 8000 / `dispo-main` / `dispo_mat_core` / `.env` unberührt.
Migrationen nur in isolierten Test-/E2E-DBs.

## Artefakte

| Datei | Rolle |
|---|---|
| [`docs/entscheidungen/PO-BLP203-1-crm-salesforce-meridian.md`](../../entscheidungen/PO-BLP203-1-crm-salesforce-meridian.md) | PO **Akzeptiert** |
| [`docs/readiness/BL-P2-03-crm-salesforce-meridian-2026-10-09.md`](../../readiness/BL-P2-03-crm-salesforce-meridian-2026-10-09.md) | Readiness (Vorbedingungen erfüllt für Slice) |

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
- Feature: `CrmSalesforceMeridianBlP203aTest`
- E2E: `npm run test:e2e:blp203a` (Port **8060**)

## Offen

`CRM-004`, operative Datenabnahme, Deploy; Review des Draft-PR.
