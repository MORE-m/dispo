# Bericht: Calendar × Festpreis × Hauptspot+Allonge – BL-P4-03j Draft-PR

Stand: 6. Oktober 2026
Arbeitsbasis: `origin/main` @ `4a9dd4e518d123e01f61e932fc1b34b8e109a87f`
Worktree: `dispo-wt-feat-bl-p4-03j` (Branch `feat/bl-p4-03j-calendar-festpreis-hauptspot-allonge`)
**Kein** Merge/Deploy.

PO-BLP403J-1 **A1 akzeptiert**. 03i auf Main (PR #126 / CI `37483249060` SUCCESS).

## Artefakte

| Datei | Rolle |
|---|---|
| [`docs/entscheidungen/PO-BLP403J-1-calendar-festpreis-hauptspot-allonge.md`](../../entscheidungen/PO-BLP403J-1-calendar-festpreis-hauptspot-allonge.md) | PO **Akzeptiert** A1 |
| [`docs/readiness/BL-P4-03j-calendar-festpreis-hauptspot-allonge-2026-10-06.md`](../../readiness/BL-P4-03j-calendar-festpreis-hauptspot-allonge-2026-10-06.md) | Readiness READY / Draft-PR |

## Scope (umgesetzt, Draft-PR)

- Calendar × Spot Classic × Festpreis × optional Hauptspot+Allonge
- Strategien laut Inventarregel; Shared `media_gross` `''`
- B1+C1; explizite v4-Vertragserweiterung
- Fixture Shared 600/500; Individual 640/520 (420/220)

## Review-Nachzug Abnahmebelege

- Browser-Smoke Shared **und** Individual: Save → Reload → Publish → Adopt → Calc-Reload (Port 8053)
- Publish nach Inventarstrategie-Wechsel: Strategiefehler, Draft unveröffentlicht, kein Freeze
- Preisjahr 2026 / Termine nur 2027: Create/Preview/Publish scheitern; Jahresübertritt-Test getrennt

## Offen

Calendar×Tandem, Budget, Abbinder. Kein Merge/Deploy.
