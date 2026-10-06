# Bericht: Calendar × Hauptspot+Allonge – BL-P4-03h auf Main

Stand: 6. Oktober 2026
Arbeitsbasis: `origin/main` @ `5f13499b451f1fd9037ce00761abc27b5244396f`
**PR #124** gemergt; **kein** Deploy.

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
| Post-Merge [`37421402438`](https://github.com/MORE-m/dispo/actions/runs/37421402438) auf `5f13499` | historisch nach Merge | **FAILURE** an `npm audit --omit=dev` (`source-map-js` GHSA-68fv-2mgg-jv7q); Pint/PHPStan/Pest, `mysql`, `e2e-spt008` grün; Browser-Steps übersprungen |

Der erfolgreiche Advisory-Fix-Run wird im Dependency-Slice (Draft-PR `source-map-js` 1.2.1 → 1.2.2) separat geführt. Dieser historische Post-Merge-Run bleibt fehlgeschlagen.

## Offen

Calendar×Festpreis/Tandem, Budget-Vorlagen, Abbinder/SPT-013.
