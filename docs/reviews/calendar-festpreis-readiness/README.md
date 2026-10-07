# Bericht: Calendar × Festpreis – BL-P4-03i auf Main

Stand: 6. Oktober 2026
Arbeitsbasis: `origin/main` @ `4a9dd4e518d123e01f61e932fc1b34b8e109a87f`
**PR #126** MERGED; Post-Merge-CI [`37483249060`](https://github.com/MORE-m/dispo/actions/runs/37483249060) SUCCESS.
**Kein** Deploy.

PO-BLP403I-1 **A1 akzeptiert** und umgesetzt. 03h auf Main (PR #124); Dependency-Fix PR #125 /
CI `37446149806` SUCCESS; historischer Run `37421402438` bleibt FAILURE.

## Artefakte

| Datei | Rolle |
|---|---|
| [`docs/entscheidungen/PO-BLP403I-1-calendar-festpreis.md`](../../entscheidungen/PO-BLP403I-1-calendar-festpreis.md) | PO **Akzeptiert** A1 |
| [`docs/readiness/BL-P4-03i-calendar-festpreis-2026-10-06.md`](../../readiness/BL-P4-03i-calendar-festpreis-2026-10-06.md) | Readiness READY / `main` |

## Scope (umgesetzt, `main`)

- Calendar × Spot Classic × Festpreis, nur Einzelspot
- 03h Calendar × `normal` × Allonge unverändert
- B1+C1; explizite v4-Vertragserweiterung
- Fixture Brutto 600.00 / N/N 500.00

## Offen

Calendar×Festpreis×Komponenten (Folgeslice [`calendar-festpreis-hauptspot-allonge-readiness`](../calendar-festpreis-hauptspot-allonge-readiness/README.md),
`PO-BLP403J-1` auf `main` PR #127), Tandem am Calendar
([`calendar-tandem-tridem-readiness`](../calendar-tandem-tridem-readiness/README.md),
`PO-BLP403K-1` vorgeschlagen), Budget, Abbinder.
Kein Deploy.
