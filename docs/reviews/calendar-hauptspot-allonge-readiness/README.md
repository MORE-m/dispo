# Bericht: Calendar × Hauptspot+Allonge – Implementierung BL-P4-03h

Stand: 5. Oktober 2026
Arbeitsbasis: `origin/main` @ `ff42723e5bdb1d11dccbac5e762b58b001fbd1cb`
Worktree: `dispo-wt-feat-bl-p4-03h` (Branch `feat/bl-p4-03h-calendar-hauptspot-allonge`)
**Kein** Merge/Deploy; **kein** Eingriff in `dispo-main` / Port 8000 / Dev-DB.

## Artefakte

| Datei | Rolle |
|---|---|
| [`docs/entscheidungen/PO-BLP403H-1-calendar-hauptspot-allonge.md`](../../entscheidungen/PO-BLP403H-1-calendar-hauptspot-allonge.md) | PO **Akzeptiert** A1 |
| [`docs/readiness/BL-P4-03h-calendar-hauptspot-allonge-2026-10-05.md`](../../readiness/BL-P4-03h-calendar-hauptspot-allonge-2026-10-05.md) | Readiness READY |
| [`docs/entscheidungen/BL-P4-03d-frozen-persistenzvertrag.md`](../../entscheidungen/BL-P4-03d-frozen-persistenzvertrag.md) | v4-Vertragserweiterung dokumentiert |

## Scope (umgesetzt)

- Calendar × Spot Classic × `normal` × optional Hauptspot+Allonge
- Strategien laut Inventarregel; B1+C1; Mix From-Calc abgewiesen
- Explizite **v4-Vertragserweiterung** (kein v5, keine Schema-Migration)
- Average-Varianten und Calendar-Einzelspot unverändert

## Tests

- Feature: `StandardOfferBlP403hTest` (+ Regression 03b/03c/03g)
- Browser isoliert: `playwright.blp403h.config.ts`, Port **8051**
- CI-Job: Browser BL-P4-03h isolated tests

## Offen

Calendar×Festpreis/Tandem, Budget-Vorlagen, Abbinder/SPT-013.
