# Readiness: BL-P4-03j – Calendar × Festpreis × Hauptspot+Allonge in Standardangeboten

Status: **READY** (PO-BLP403J-1 akzeptiert A1; auf `main` PR #127)
Stand: 7. Oktober 2026 (Statusnachzug Merge)
Audit-/Implementierungsbasis: `origin/main` @ `e44c1cf436c6e1a63781910c7c1cd46ae019903a`
(Merge PR #127; Post-Merge-CI [`37527679296`](https://github.com/MORE-m/dispo/actions/runs/37527679296) SUCCESS).
Entscheidungsbasis damals: `4a9dd4e…` (PR #126).
IDs: `STD-001`–`STD-009`, `VER-004`, `SPT-005`–`SPT-008`, `SPT-014`, `COM-009`,
`PRI-002`/`PO-PRI-YEAR-1`, `AUTH-006`/`AUTH-007`, **PO-BLP403J-1**, Slice **BL-P4-03j**
Entscheidung: [`docs/entscheidungen/PO-BLP403J-1-calendar-festpreis-hauptspot-allonge.md`](../entscheidungen/PO-BLP403J-1-calendar-festpreis-hauptspot-allonge.md)

## 0. Gate / Ergebnis

| Voraussetzung | Status |
|---|---|
| UX-GATE-D Teilfreigabe Calendar×Festpreis×Komponenten | **akzeptiert** (A1; B1+C1 übernommen) |
| Calendar × `normal` × Allonge (03h) / Festpreis Einzelspot (03i) | auf Main |
| Calc Calendar × N/N-Festpreis × Komponenten | Engine orthogonal; Triple-Tests ergänzt |
| Materialisierung v4 Vertragserweiterung | umgesetzt (kein v5, keine Schema-Migration) |
| Calendar×Tandem / Budget / Abbinder | bewusst außerhalb (Tandem: PO-BLP403K-1 vorgeschlagen) |
| Merge `main` | **PR #127** (`e44c1cf…`) |
| Post-Merge CI | **`37527679296` SUCCESS** |
| Deploy | **kein** Deploy |

**Readiness-Urteil: READY** – Slice auf `main` (PR #127). Reader vor dieser
Erweiterung (PR #126) weist Calendar-Festpreis×Komponenten ab; neue Snapshots brauchen den
erweiterten Reader. **Keine** Deployment-Freigabe.

## 1. Akzeptierter Adoption-Vertrag

- **A1:** Calendar × Spot Classic × Festpreis × optional Hauptspot+Allonge; Strategien laut Inventarregel.
- **B1:** Konkrete Termine unverändert; kein Auto-Shift.
- **C1:** Adopt = Frozen-Parity inkl. Komponenten/`fixed_price_nn`/Pins/Zellen; kein Live-Rebind.
- **v4-Erweiterung:** kein v5; 03h/03i-Varianten bleiben lesbar.
- Shared-Total: Komponenten-`media_gross` = `''`.

Calc-Edit folgt 02a/`PO-PRI-YEAR-1`: Pin ohne Inventar-/Jahr-/Mediumwechsel;
Zellpreise aus gepinnter Liste; Listen-Rebind nur bei Jahr-/Inventar-/Mediumwechsel.

## 2. Umgesetzte Schichten

| Schicht | Inhalt |
|---|---|
| Draft-Contract | Calendar Festpreis + optionale Komponenten; Tandem weiter fail-closed |
| From-Calc | reine Calendar-Festpreis-Quellen inkl. Komponenten; Mix abgewiesen |
| Freeze | v4; Settlement- + Komponentenfelder aus Writer-Totals |
| Hydrate | Calendar Festpreis mit/ohne Komponenten; Reader #126-Stand abweisend dokumentiert |
| UI | Festpreis bei Calendar auch mit Komponenten; Scope-Note 03j |

## 3. Abnahme (Kurz)

Synthetisch: `StandardOfferBlP403jTest`, `SpotClassicCalendarFestpreisComponentsTest`
(+ Regression 03e/03h/03i).
Browser-Smoke isoliert: `playwright.blp403j.config.ts` / Port **8053** —
Shared und Individual jeweils Save → Reload → Publish → Adopt → Calc-Reload.

Fixture: Shared 600,00 / N/N 500,00 (`''`/`''`); Individual 640,00 / N/N 520,00 (420/220).

Review-Nachzug (Feature-Tests, getrennt von Adopt-Freeze und Nachfolgerlisten-Pin):
- Inventarstrategie-Wechsel nach Draft blockiert Publish (kein Freeze / unveröffentlicht).
- Preisjahr 2026 mit ausschließlich 2027-Terminen scheitert Create/Preview/Publish.
- Jahresübertritt 2026-12-31 / 2027-01-01 bleibt eigener Fall.

## 4. Bewusst offen

Calendar×Tandem (Folgeslice `PO-BLP403K-1` / `BL-P4-03k` vorgeschlagen),
Budget-Vorlagen, Abbinder/SPT-013, Deploy.
