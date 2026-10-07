# Bericht: Calendar × Tandem/Tridem – BL-P4-03k Draft-PR

Stand: 7. Oktober 2026
Arbeitsbasis: `origin/main` @ `e44c1cf436c6e1a63781910c7c1cd46ae019903a`
Worktree: `dispo-wt-feat-bl-p4-03k` (Branch `feat/bl-p4-03k-calendar-tandem-tridem`)
**Kein** Merge/Deploy.

PO-BLP403K-1 **A1 akzeptiert**. 03j auf Main (PR #127 / CI `37527679296` SUCCESS).

## Artefakte

| Datei | Rolle |
|---|---|
| [`docs/entscheidungen/PO-BLP403K-1-calendar-tandem-tridem.md`](../../entscheidungen/PO-BLP403K-1-calendar-tandem-tridem.md) | PO **Akzeptiert** A1 |
| [`docs/readiness/BL-P4-03k-calendar-tandem-tridem-2026-10-07.md`](../../readiness/BL-P4-03k-calendar-tandem-tridem-2026-10-07.md) | Readiness READY / Draft-PR |

## Scope (umgesetzt, Draft-PR)

- Calendar × Spot Classic × Tandem und Tridem × normal und Festpreis
- Verbindlich `shared_total_length`; Shared `media_gross` `''`
- B1+C1; explizite v4-Vertragserweiterung (Reader #127 weist Calendar×Profil ab)
- Fixture Tandem 600/888.50; Tridem 760/1200

## Abnahmebelege

- Feature: `StandardOfferBlP403kTest` (Viererkombination, Freeze, Pin, From-Calc, Negativ)
- Calc: `SpotClassicCalendarTandemTridemTest` (vier Kombinationen Persistenz/Reload)
- Browser-Smoke Port **8054**: Tandem×normal und Tridem×Festpreis Full-Flow
- Adopt-Freeze und Nachfolgerlisten-Pin getrennt

## Offen

Budget, Abbinder. Kein Merge/Deploy.
