# Readiness: BL-P4-03g – Calendar in Spot-Classic-Standardangeboten

Status: **READY** (PO-BLP403G-1 akzeptiert A1+B1+C1; Implementierung auf Draft-PR)  
Stand: 5. Oktober 2026  
Audit-/Implementierungsbasis: `origin/main` @ `68b7d8bf2dd604c44f703374b47d9815c73284ac`  
IDs: `STD-001`–`STD-009`, `VER-004`, `SPT-005`–`SPT-008`, `PRI-002`/`PO-PRI-YEAR-1`,
`AUTH-006`/`AUTH-007`, **PO-BLP403G-1**, Slice **BL-P4-03g**  
Entscheidung: [`docs/entscheidungen/PO-BLP403G-1-calendar-standardangebote.md`](../entscheidungen/PO-BLP403G-1-calendar-standardangebote.md)

## 0. Gate / Urteil

| Voraussetzung | Status |
|---|---|
| UX-GATE-D Teilfreigabe Calendar-Vorlagen | **akzeptiert** (A1+B1+C1) |
| Calc Calendar + SA-Lifecycle 03a–03f | vorhanden |
| Materialisierung v4 Freeze↔Hydrate | umgesetzt |
| Budget / Abbinder / Calendar×Festpreis/Tandem | bewusst außerhalb |

**Readiness-Urteil: READY** für den freigegebenen Slice (Draft-PR).

## 1. Akzeptierter Adoption-Vertrag

- **A1:** Calendar × `normal`, Einzelspot ohne Komponenten/Festpreis/Tandem.
- **B1:** Konkrete Termine unverändert; kein Auto-Shift.
- **C1:** Adopt = Frozen-Parity; Live-Neuberechnung nur nach Calc-Edit.
- **Beispiel:** Adopt 2027 einer März-2026-Vorlage → weiterhin Termine/Preisjahr 2026.

## 2. Umgesetzte Schichten

| Schicht | Inhalt |
|---|---|
| Draft-Contract | Average + Calendar×normal; unzulässige Calendar-Varianten fail-closed |
| From-Calc | reine Average- oder reine Calendar-Quellen; Mix abgewiesen |
| Freeze | v4; `planner_entries` inkl. aufgelöster Preise |
| Hydrate | Planner-Persistenz ohne Live-Preisauflösung; Legacy v1–v3 lesbar |
| UI | Calendar im Vorlagenmodus; Adopt-Hinweis; Budget weiter ausgeblendet |

## 3. Abnahme (Kurz)

Synthetisch: `StandardOfferBlP403gTest` (+ Regression 03a–03f).  
Browser-Smoke isoliert: `playwright.blp403g.config.ts` / Port **8050**, Spec `bl-p4-03g-*.spec.ts` (**BESTANDEN** lokal 3/3: Calendar Adopt inkl. Calc-Reload, Average-Regression, Negativ Spotanzahl 0 – UI-Validierung, kein Jahres-/Preisfehlerbeleg).

Preis-/Jahresfehler und A1-Ausschluss (Komponenten/Tandem/Tridem/Festpreis) über Standardangebote-Pfade (Create/Preview/Publish) und Frozen-Hydrate-Asserts belegt. Create scheitert bei fehlender Preiszelle bereits über `assertResolvable`; korrumpierter Draft-Publish ohne Published-Freeze.  
Keine Dev-DB-Tests.

## 4. Bewusst offen

Calendar×Komponenten/Festpreis/Tandem, Budget-Vorlagen, Abbinder/SPT-013,
Auto-Shift/Adopt-Rebind.
