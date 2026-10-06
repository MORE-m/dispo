# Bericht: Calendar-Standardangebote – BL-P4-03g auf Main

Stand: 5. Oktober 2026
Arbeitsbasis: `origin/main` @ `ff42723e5bdb1d11dccbac5e762b58b001fbd1cb`
**PR #123** gemergt; Post-Merge-CI **`37338293392` SUCCESS**; **kein** Deploy.

## Artefakte

| Datei | Rolle |
|---|---|
| [`docs/entscheidungen/PO-BLP403G-1-calendar-standardangebote.md`](../../entscheidungen/PO-BLP403G-1-calendar-standardangebote.md) | PO **Akzeptiert** A1+B1+C1 |
| [`docs/readiness/BL-P4-03g-calendar-standardangebote-2026-10-05.md`](../../readiness/BL-P4-03g-calendar-standardangebote-2026-10-05.md) | Readiness READY / auf Main |

## Scope (umgesetzt)

- Calendar × Spot Classic × `normal`, Einzelspot
- Konkrete Termine; Adopt Frozen-Parity; Materialisierung **v4**
- Average-Varianten unverändert; Mix From-Calc weiter abgewiesen

## Tests / CI

- Feature: `StandardOfferBlP403gTest`
- Browser isoliert: `playwright.blp403g.config.ts`, Port **8050**
- Post-Merge-CI: `37338293392` SUCCESS (`ci`/`mysql`/`e2e-spt008`)

## Offen

Calendar×Festpreis/Tandem/Komponenten, Budget-Vorlagen, Abbinder/SPT-013.
Folgevorschlag Docs: [`calendar-hauptspot-allonge-readiness`](../calendar-hauptspot-allonge-readiness/README.md).
