# PO-BLP403G-1 – Calendar in Spot-Classic-Standardangeboten

Status: **Akzeptiert** (A1 + B1 + C1)  
Stand: 5. Oktober 2026  
Basis: `origin/main` @ `68b7d8bf2dd604c44f703374b47d9815c73284ac` (nach PR #122)  
IDs: `STD-001`–`STD-009`, `VER-004`, `SPT-005`–`SPT-008`, `PRI-002` / `PO-PRI-YEAR-1`,
`AUTH-006`/`AUTH-007`, Vorgänger `PO-BLP403A-1` … `PO-BLP403F-1`  
Slice-Kennung: **`BL-P4-03g`**  
Readiness: [`docs/readiness/BL-P4-03g-calendar-standardangebote-2026-10-05.md`](../readiness/BL-P4-03g-calendar-standardangebote-2026-10-05.md)

## Entscheidung / UX-GATE-D Teilfreigabe

Schmale Teilfreigabe **nur** für Spot-Classic-**Calendar**-Standardangebote mit
Settlement **`normal`**, Einzelspot ohne Komponenten/Festpreis/Tandem/Tridem.

| ID | Entscheidung |
|---|---|
| **A1** | Scope: Calendar × Spot Classic × `normal`, ohne Komponenten/Festpreis/Tandem |
| **B1** | Konkrete ISO-Termine unverändert übernehmen; **keine** automatische Verschiebung |
| **C1** | Adopt = Frozen-Parity (Preise, Pins, Preisjahr, Summen); **keine** Live-Preisauflösung |

**Beispiel (verbindlich):** Übernahme im Jahr 2027 einer Vorlage mit März-2026-Terminen
bedeutet zunächst weiterhin Termine und Preisjahr **2026**. Das aktuelle Kalenderjahr
ist **kein** neues Zielpreisjahr. Neue Termine/Jahre werden anschließend über den
bestehenden Calc-Edit-Pfad angepasst und neu berechnet.

Ablauf unverändert zu 03b:

1. Draft anlegen/bearbeiten (oder From-Calc reine Calendar-Quelle).
2. PM prüft und veröffentlicht.
3. Vertrieb übernimmt mit Kunde als unabhängige Calc.

## Materialisierungsversion 4

| Version | Bedeutung |
|---------|-----------|
| **1** (Legacy) | Average + optionale Komponenten; Settlement nur `normal` |
| **2** | wie v1, plus `normal`\|`fixed_price` |
| **3** | wie v2, plus optional `component_profile` `tandem`\|`tridem` |
| **4** (aktueller Freeze) | wie v3 für Average; plus optional `spot_method=calendar` mit `planner_entries` |

Neue Freezes schreiben immer **Version 4**. Legacy **v1–v3** bleiben lesbar/übernehmbar.
Freeze und Hydrate bleiben zwei gepflegte Seiten (ADR 03d).

## Vertrag / Verhalten

- Calendar-Semantik = Calc 02b (konkrete Daten/Stunden/Spots, Jahresvertrag, Fail-closed Preise).
- Adopt hydratisiert Frozen-Zellen inkl. aufgelöster Sekundenpreise/Summen/Pins ohne Live-Rebind.
- Quellvorlage unverändert (STD-005 / VER-004).
- Mix Average+Calendar in From-Calc weiter ganz abgewiesen.
- Average-Varianten (Komponenten/Festpreis/Tandem) bleiben unverändert nutzbar.
- Rechte unverändert zu 03b.

## Nicht-Ziele

- Calendar × Festpreis / Tandem / Komponenten
- Budget-Vorlagen, Abbinder / SPT-013
- Auto-Shift, Zielstart-Picker, Adopt-Preisjahr-Rebind
- Lifecycle-/Rechteänderungen

## Implementierungsstand

Auf Feature-Branch / Draft-PR umgesetzt (`BL-P4-03g`). **Kein** pauschales
„Standardangebote vollständig“ – Restpunkte bleiben offen.
