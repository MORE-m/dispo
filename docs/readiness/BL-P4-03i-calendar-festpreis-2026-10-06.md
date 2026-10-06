# Readiness: BL-P4-03i – Calendar × Festpreis in Standardangeboten

Status: **READY** (PO-BLP403I-1 akzeptiert A1; auf `main`)
Stand: 6. Oktober 2026
Audit-/Implementierungsbasis: `origin/main` @ `4a9dd4e518d123e01f61e932fc1b34b8e109a87f`
(Merge PR #126; Post-Merge-CI [`37483249060`](https://github.com/MORE-m/dispo/actions/runs/37483249060) SUCCESS)
IDs: `STD-001`–`STD-009`, `VER-004`, `SPT-005`–`SPT-008`, `SPT-014`, `COM-009`,
`PRI-002`/`PO-PRI-YEAR-1`, `AUTH-006`/`AUTH-007`, **PO-BLP403I-1**, Slice **BL-P4-03i**
Entscheidung: [`docs/entscheidungen/PO-BLP403I-1-calendar-festpreis.md`](../entscheidungen/PO-BLP403I-1-calendar-festpreis.md)

## 0. Gate / Ergebnis

| Voraussetzung | Status |
|---|---|
| UX-GATE-D Teilfreigabe Calendar×Festpreis Einzelspot | **akzeptiert** (A1; B1+C1 übernommen) |
| Calendar × `normal` × Einzelspot (03g) / Allonge (03h) | auf Main |
| Calc Calendar × N/N-Festpreis (02d) | vorhanden |
| Materialisierung v4 Vertragserweiterung | umgesetzt (kein v5, keine Schema-Migration) |
| Calendar×Festpreis×Komponenten / Tandem / Budget / Abbinder | bewusst außerhalb |
| Merge `main` | **PR #126** / `4a9dd4e…` |
| Post-Merge-CI | **`37483249060` SUCCESS** |
| Deploy | **kein** Deploy |

**Readiness-Urteil: READY** – Slice auf `main` (PR #126). Reader vor dieser
Erweiterung (PR #124) weist Calendar-Festpreis ab; neue Snapshots brauchen den
erweiterten Reader. **Keine** Deployment-Freigabe.

## 1. Akzeptierter Adoption-Vertrag

- **A1:** Calendar × Spot Classic × Festpreis, nur Einzelspot.
- **B1:** Konkrete Termine unverändert; kein Auto-Shift.
- **C1:** Adopt = Frozen-Parity inkl. `fixed_price_nn`/Pins/Zellen; kein Live-Rebind.
- **v4-Erweiterung:** kein v5; 03h Calendar×`normal`×Allonge bleibt lesbar.

Calc-Edit folgt 02a/`PO-PRI-YEAR-1`: Pin ohne Inventar-/Jahr-/Mediumwechsel;
Zellpreise aus gepinnter Liste; Listen-Rebind nur bei Jahr-/Inventar-/Mediumwechsel.

## 2. Umgesetzte Schichten

| Schicht | Inhalt |
|---|---|
| Draft-Contract | Calendar-Einzelspot `fixed_price` + `fixed_price_nn`; Allonge×Festpreis fail-closed |
| From-Calc | reine Calendar-Festpreis-Einzelspot-Quellen; Mix und Festpreis×Komponenten abgewiesen |
| Freeze | v4; Settlement-Felder aus Writer-Totals |
| Hydrate | Calendar-Einzelspot Festpreis-Asserts; Komponenten+Festpreis abgewiesen |
| UI | Festpreis bei Calendar ohne Komponenten; Scope-Note 03i |

## 3. Abnahme (Kurz)

Synthetisch: `StandardOfferBlP403iTest` (+ Regression 03a/03e/03g/03h).
Browser-Smoke isoliert: `playwright.blp403i.config.ts` / Port **8052**.

## 4. Bewusst offen

Calendar×Festpreis×Komponenten (Folgeslice **PO-BLP403J-1** / **BL-P4-03j** vorgeschlagen),
Calendar×Tandem, Budget-Vorlagen, Abbinder/SPT-013, Deploy.
