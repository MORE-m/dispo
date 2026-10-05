# Bericht: Calendar-Standardangebote – Implementierung BL-P4-03g

Stand: 5. Oktober 2026
Arbeitsbasis: `origin/main` @ `68b7d8bf2dd604c44f703374b47d9815c73284ac`
Worktree: `dispo-wt-feat-bl-p4-03g` (Branch `feat/bl-p4-03g-calendar-standard-offers`)
**Kein** Merge/Deploy; **kein** Eingriff in `dispo-main` / Port 8000 / Dev-DB.

## Artefakte

| Datei | Rolle |
|---|---|
| [`docs/entscheidungen/PO-BLP403G-1-calendar-standardangebote.md`](../../entscheidungen/PO-BLP403G-1-calendar-standardangebote.md) | PO **Akzeptiert** A1+B1+C1 |
| [`docs/readiness/BL-P4-03g-calendar-standardangebote-2026-10-05.md`](../../readiness/BL-P4-03g-calendar-standardangebote-2026-10-05.md) | Readiness READY |

## Scope (umgesetzt)

- Calendar × Spot Classic × `normal`, Einzelspot
- Konkrete Termine; Adopt Frozen-Parity; Materialisierung **v4**
- Average-Varianten unverändert; Mix From-Calc weiter abgewiesen

## Tests

- Feature: `StandardOfferBlP403gTest` + Regression `StandardOfferBlP403*`
- Browser isoliert: `playwright.blp403g.config.ts`, Port **8050**
- CI-Job: Browser BL-P4-03g isolated tests

## Offen

Calendar×Festpreis/Tandem/Komponenten, Budget-Vorlagen, Abbinder/SPT-013.
