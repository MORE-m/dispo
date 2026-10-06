# PO-BLP403H-1 – Calendar × Hauptspot+Allonge in Standardangeboten

Status: **Akzeptiert** (A1; B1+C1 übernommen aus PO-BLP403G-1)
Stand: 5. Oktober 2026
Basis: `origin/main` @ `ff42723e5bdb1d11dccbac5e762b58b001fbd1cb` (nach PR #123)
IDs: `STD-001`–`STD-009`, `VER-004`, `SPT-005`–`SPT-008`, `SPT-014`,
`PRI-002` / `PO-PRI-YEAR-1`, `AUTH-006`/`AUTH-007`,
Vorgänger `PO-BLP403A-1` … `PO-BLP403G-1`
Slice-Kennung: **`BL-P4-03h`**
Readiness: [`docs/readiness/BL-P4-03h-calendar-hauptspot-allonge-2026-10-05.md`](../readiness/BL-P4-03h-calendar-hauptspot-allonge-2026-10-05.md)

## Entscheidung / UX-GATE-D Teilfreigabe

Teilfreigabe für Spot-Classic-**Calendar**-Standardangebote mit Settlement **`normal`**,
weiterhin Einzelspot **und zusätzlich** optional **Hauptspot+Allonge**
(Strategien nur gemäß Inventarregel `shared_total_length` / `individual`).

| ID | Entscheidung |
|---|---|
| **A1** | Scope: Calendar × Spot Classic × `normal` × optional Hauptspot+Allonge; ohne Festpreis/Tandem/Budget |
| **B1** | Konkrete ISO-Termine unverändert übernehmen; **keine** automatische Verschiebung (aus PO-BLP403G-1) |
| **C1** | Adopt = Frozen-Parity (Preise, Pins, Preisjahr, Summen, Komponenten); **keine** Live-Preisauflösung (aus PO-BLP403G-1) |

Ablauf unverändert zu 03b/03g:

1. Draft anlegen/bearbeiten (oder From-Calc reine Calendar-Quelle inkl. Komponenten).
2. PM prüft und veröffentlicht.
3. Vertrieb übernimmt mit Kunde als unabhängige Calc.

## Materialisierungsversion 4 (Vertragserweiterung)

Keine neue Version, **kein** Schema-Migration. Explizite **v4-Vertragserweiterung**:

- Calendar-Positionen dürfen optional `components` + `component_calculation_strategy` tragen
  (Hauptspot+Allonge wie Average-Allonge; ohne `component_profile`/Festpreis).
- Der Reader aus PR #123 weist Calendar-Komponenten ab; erweiterte Snapshots brauchen
  diesen erweiterten Reader (Keys allein ≠ Kompatibilität mit altem Code).
- Bestehende v4 Calendar-Einzelspots (`components: []`), Average-v4 und Legacy v1–v3 bleiben lesbar.

## Vertrag / Verhalten

- Calc-Semantik 02c / Average-Vorlagen 03c wiederverwenden; **keine** neue Preisformel.
- Adopt hydratisiert Frozen inkl. Komponenten ohne Live-Rebind.
- Mix Average+Calendar in From-Calc weiter ganz abgewiesen.
- Rechte/Lifecycle unverändert.

## Nicht-Ziele

- Calendar × Festpreis / Tandem/Tridem
- Budget-Vorlagen, Abbinder / SPT-013
- Auto-Shift, Adopt-Preisjahr-Rebind
- Lifecycle-/Rechteänderungen

## Implementierungsstand

Auf `main` gemergt: **PR #124**, Merge-Commit
`5f13499b451f1fd9037ce00761abc27b5244396f`. Historischer Post-Merge-Run
`37421402438` FAILURE (`npm audit --omit=dev`). Dependency-Fix **PR #125**
(`1a4e72c…`), Post-Merge-CI `37446149806` SUCCESS. **Kein** Deploy.
Calendar×Festpreis/Tandem, Budget, Abbinder bleiben offen
(Festpreis-Einzelspot: `PO-BLP403I-1` A1, PR #126 auf `main`).
