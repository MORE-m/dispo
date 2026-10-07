# Bericht: Calendar × Festpreis × Hauptspot+Allonge – BL-P4-03j auf `main`

Stand: 7. Oktober 2026 (Statusnachzug Merge)
Arbeitsbasis: `origin/main` @ `e44c1cf436c6e1a63781910c7c1cd46ae019903a`
(Merge PR #127; Post-Merge-CI [`37527679296`](https://github.com/MORE-m/dispo/actions/runs/37527679296) SUCCESS)
**Kein** Deploy.

PO-BLP403J-1 **A1 akzeptiert**. Slice auf Main (PR #127).

## Artefakte

| Datei | Rolle |
|---|---|
| [`docs/entscheidungen/PO-BLP403J-1-calendar-festpreis-hauptspot-allonge.md`](../../entscheidungen/PO-BLP403J-1-calendar-festpreis-hauptspot-allonge.md) | PO **Akzeptiert** A1; auf `main` |
| [`docs/readiness/BL-P4-03j-calendar-festpreis-hauptspot-allonge-2026-10-06.md`](../../readiness/BL-P4-03j-calendar-festpreis-hauptspot-allonge-2026-10-06.md) | Readiness READY / `main` |

## Scope (umgesetzt, auf `main`)

- Calendar × Spot Classic × Festpreis × optional Hauptspot+Allonge
- Strategien laut Inventarregel; Shared `media_gross` `''`
- B1+C1; explizite v4-Vertragserweiterung
- Fixture Shared 600/500; Individual 640/520 (420/220)

## Review-Nachzug Abnahmebelege

- Browser-Smoke Shared **und** Individual: Save → Reload → Publish → Adopt → Calc-Reload (Port 8053)
- Publish nach Inventarstrategie-Wechsel: Strategiefehler, Draft unveröffentlicht, kein Freeze
- Preisjahr 2026 / Termine nur 2027: Create/Preview/Publish scheitern; Jahresübertritt-Test getrennt

## Offen

Calendar×Tandem (Folgeslice `PO-BLP403K-1` / `BL-P4-03k` vorgeschlagen), Budget, Abbinder.
Kein Deploy.
