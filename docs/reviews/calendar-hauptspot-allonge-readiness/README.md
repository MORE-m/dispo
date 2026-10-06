# Bericht: Calendar × Hauptspot+Allonge – BL-P4-03h auf Main

Stand: 6. Oktober 2026
Arbeitsbasis: `origin/main` @ `1a4e72cd478a8edafa63c8c97722596169994b82`
**PR #124** gemergt; Dependency-Fix **PR #125**; **kein** Deploy.

## Artefakte

| Datei | Rolle |
|---|---|
| [`docs/entscheidungen/PO-BLP403H-1-calendar-hauptspot-allonge.md`](../../entscheidungen/PO-BLP403H-1-calendar-hauptspot-allonge.md) | PO **Akzeptiert** A1 |
| [`docs/readiness/BL-P4-03h-calendar-hauptspot-allonge-2026-10-05.md`](../../readiness/BL-P4-03h-calendar-hauptspot-allonge-2026-10-05.md) | Readiness READY / auf Main |

## Scope (umgesetzt)

- Calendar × Spot Classic × `normal`, Einzelspot und optional Hauptspot+Allonge
- Konkrete Termine; Adopt Frozen-Parity; explizite v4-Vertragserweiterung

## CI-Nachzug

| Run | Bedeutung | Ergebnis |
|---|---|---|
| Pre-Merge [`37375074999`](https://github.com/MORE-m/dispo/actions/runs/37375074999) auf `6751aae` | Abnahme PR #124 | **success** (`ci` inkl. Browser BL-P4-03h, `mysql`, `e2e-spt008`) |
| Post-Merge [`37421402438`](https://github.com/MORE-m/dispo/actions/runs/37421402438) auf `5f13499` | historisch nach Merge 03h | **FAILURE** an `npm audit --omit=dev` (`source-map-js` GHSA-68fv-2mgg-jv7q); Pint/PHPStan/Pest, `mysql`, `e2e-spt008` grün; Browser-Steps übersprungen |
| Post-Merge [`37446149806`](https://github.com/MORE-m/dispo/actions/runs/37446149806) auf `1a4e72c` | nach PR #125 | **SUCCESS** |

Der historische 03h-Post-Merge-Run `37421402438` bleibt fehlgeschlagen. Der Advisory-Fix ist als **PR #125** auf `main`.

## Offen

Calendar×Festpreis×Komponenten/Tandem, Budget-Vorlagen, Abbinder/SPT-013.
Folgeslice Festpreis-Einzelspot: [`calendar-festpreis-readiness`](../calendar-festpreis-readiness/README.md) (`PO-BLP403I-1` A1, PR #126 auf `main`).
Folgeslice Festpreis×Allonge: [`calendar-festpreis-hauptspot-allonge-readiness`](../calendar-festpreis-hauptspot-allonge-readiness/README.md) (`PO-BLP403J-1` Vorgeschlagen).
