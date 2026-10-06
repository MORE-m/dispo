# Readiness: BL-P4-03h – Calendar × Hauptspot+Allonge in Standardangeboten

Status: **READY** (PO-BLP403H-1 akzeptiert A1; Implementierung auf Draft-PR)
Stand: 5. Oktober 2026
Audit-/Implementierungsbasis: `origin/main` @ `ff42723e5bdb1d11dccbac5e762b58b001fbd1cb`
IDs: `STD-001`–`STD-009`, `VER-004`, `SPT-005`–`SPT-008`, `SPT-014`,
`PRI-002`/`PO-PRI-YEAR-1`, `AUTH-006`/`AUTH-007`, **PO-BLP403H-1**, Slice **BL-P4-03h**
Entscheidung: [`docs/entscheidungen/PO-BLP403H-1-calendar-hauptspot-allonge.md`](../entscheidungen/PO-BLP403H-1-calendar-hauptspot-allonge.md)

## 0. Gate / Ergebnis

| Voraussetzung | Status |
|---|---|
| UX-GATE-D Teilfreigabe Calendar×Komponenten | **akzeptiert** (A1; B1+C1 übernommen) |
| Calendar × normal × Einzelspot (BL-P4-03g) | auf Main (PR #123) |
| Calc Calendar × Hauptspot+Allonge (02c) | vorhanden |
| Materialisierung v4 Vertragserweiterung | umgesetzt |
| Budget / Calendar×Festpreis / Tandem / Abbinder | bewusst außerhalb |

**Readiness-Urteil: READY** für den freigegebenen Slice (Draft-PR).

## 1. Akzeptierter Adoption-Vertrag

- **A1:** Calendar × `normal` × optional Hauptspot+Allonge; Strategien laut Inventarregel.
- **B1:** Konkrete Termine unverändert; kein Auto-Shift.
- **C1:** Adopt = Frozen-Parity inkl. Komponenten; Live-Neuberechnung nur nach Calc-Edit.
- **v4-Erweiterung:** kein v5; Reader #123 lehnt Calendar-Komponenten ab – erweiterte
  Snapshots brauchen den erweiterten Reader.

## 2. Umgesetzte Schichten

| Schicht | Inhalt |
|---|---|
| Draft-Contract | Calendar optional Komponenten via `ComponentValidator`; Profil/Festpreis fail-closed |
| From-Calc | reine Calendar-Quellen inkl. Komponenten; Mix abgewiesen |
| Freeze | v4; `components` + `planner_entries` aus Writer |
| Hydrate | Komponenten-Asserts + Planner; Legacy/Einzelspot lesbar |
| UI | Spot-Komponenten auch bei Calendar-Vorlagen; Tandem weiter ausgeblendet |

## 3. Abnahme (Kurz)

Synthetisch: `StandardOfferBlP403hTest` (+ Regression 03b/03c/03g).
Browser-Smoke isoliert: `playwright.blp403h.config.ts` / Port **8051**.

## 4. Bewusst offen

Calendar×Festpreis/Tandem, Budget-Vorlagen, Abbinder/SPT-013,
Auto-Shift/Adopt-Rebind.
