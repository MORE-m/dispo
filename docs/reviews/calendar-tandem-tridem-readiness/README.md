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

- Feature: `StandardOfferBlP403kTest`
  - Viererkombination Draft→Publish→Adopt→Reload
  - From-Calc alle vier Quellen (Profil/Slots/Planner/Settlement/Festpreis/kein Kundenleak) + Mix-Ablehnung
  - Freeze nach Live-Preis + DB-Reload; Frozen-Planner-Parität (Spot-Summe, doppelte Zelle)
  - Festpreis-Pin und Normal-Mengenänderung nach Nachfolgerliste B
  - Rebind Jahr/Inventar/Medium; Medium-Switch Tandem→Tridem (Slots/Timing/Preview)
  - Negativ Create/Preview/Publish: individual, Profil/Rollen/Sort/Länge, fehlende Preiszelle inkl. Publish,
    Inventar-Deaktivierung, Planungsregel `must_not_plan`
- Calc: `SpotClassicCalendarTandemTridemTest` (vier Kombinationen Persistenz/Reload)
- Browser-Smoke Port **8054**: Tandem×normal und Tridem×Festpreis Full-Flow
  - Publish wartet auf Response `/veroeffentlichen` und Show-Zustand (`new-draft` / Veröffentlicht)
  - Sales: Adopt-Form sichtbar vor Kundenfeld; Slot-Längen und Planner nach Reload
- Adopt-Freeze und Nachfolgerlisten-Pin getrennt

## Review-Nachzug (PR #128)

- Flake-Ursache: URL `/standardangebote/<id>` gilt schon für Draft; Publish-Abschluss wurde nicht
  synchronisiert → Sales ohne Adopt-UI (`standard-offer-customer` Timeout).
- Korrektur: Response + Published-Show-Zustand; lokal `--retries=0 --repeat-each=3`.

## Offen

Budget, Abbinder. Kein Merge/Deploy.
