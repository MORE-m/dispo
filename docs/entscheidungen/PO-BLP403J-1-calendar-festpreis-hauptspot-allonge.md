# PO-BLP403J-1 – Calendar × Festpreis × Hauptspot+Allonge in Standardangeboten

Status: **Akzeptiert** (A1; B1+C1 übernommen aus PO-BLP403G-1); auf `main` (PR #127)
Stand: 7. Oktober 2026 (Statusnachzug Merge)
Basis Implementierung: Feature auf `origin/main` @ `e44c1cf436c6e1a63781910c7c1cd46ae019903a`
(Merge PR #127; Post-Merge-CI [`37527679296`](https://github.com/MORE-m/dispo/actions/runs/37527679296) SUCCESS).
Entscheidungsbasis damals: `4a9dd4e…` (nach PR #126).
IDs: `STD-001`–`STD-009`, `VER-004`, `SPT-005`–`SPT-008`, `SPT-014`, `COM-009`,
`PRI-002` / `PO-PRI-YEAR-1`, `AUTH-006`/`AUTH-007`,
Vorgänger `PO-BLP403A-1` … `PO-BLP403I-1`
Slice-Kennung: **`BL-P4-03j`**
Readiness: [`docs/readiness/BL-P4-03j-calendar-festpreis-hauptspot-allonge-2026-10-06.md`](../readiness/BL-P4-03j-calendar-festpreis-hauptspot-allonge-2026-10-06.md)

## Entscheidung / UX-GATE-D Teilfreigabe

Schmale Teilfreigabe für Spot-Classic-**Calendar**-Standardangebote mit Settlement
**`fixed_price`** und optionaler **Hauptspot+Allonge**; Strategien ausschließlich laut
Inventarregel (`shared_total_length` / `individual`).

| ID | Entscheidung |
|---|---|
| **A1** | Scope: Calendar × Spot Classic × Festpreis × optional Hauptspot+Allonge; 03h×`normal`×Allonge und 03i-Festpreis-Einzelspot bleiben; ohne Tandem/Budget/Abbinder |
| **B1** | Konkrete ISO-Termine unverändert; **keine** automatische Verschiebung (aus PO-BLP403G-1) |
| **C1** | Adopt = Frozen-Parity (Preise, Pins, Planner-Zellen, Komponenten, `fixed_price_nn`, Summen); **keine** Live-Preisauflösung (aus PO-BLP403G-1) |

A2 (engerer Teilscope) und A3 (keine Erweiterung) sind **nicht** gewählt.

Ablauf unverändert zu 03b/03g/03i:

1. Draft anlegen/bearbeiten (oder From-Calc reine Calendar-Festpreis-Quelle inkl. Komponenten).
2. PM prüft und veröffentlicht.
3. Vertrieb übernimmt mit Kunde als unabhängige Calc.

## Materialisierungsversion 4 (Vertragserweiterung)

Keine neue Version, **keine** Schema-Migration. Explizite **v4-Vertragserweiterung**:

- Calendar darf `pricing_settlement_mode` `normal`|`fixed_price` inkl. `fixed_price_nn`
  mit optionaler Hauptspot+Allonge und Strategie laut Inventarregel.
- Shared-Total: Komponenten-`media_gross` bleibt `''` (Brutto positionsseitig).
- Der Reader aus PR #126 weist Calendar-Festpreis×Komponenten ab; erweiterte Snapshots
  brauchen diesen erweiterten Reader (Keys allein ≠ Kompatibilität mit altem Code).
- Bestehende v4 Calendar×`normal` (Einzelspot und 03h-Allonge), Calendar×Festpreis-Einzelspot
  (03i), Average-v4 und Legacy v1–v3 bleiben lesbar.

## Vertrag / Verhalten

- Calc-Semantik 02c+02d / Vorlagenmuster 03e/03h/03i wiederverwenden; **keine** neue Preisformel.
- `fixed_price_nn` = verbindlicher N/N auf Positionsebene; Mediabrutto referenzbasiert;
  keine Forward-Rabatte; AE rückwärts.
- Adopt hydratisiert Frozen ohne Live-Rebind.
- Calc-Edit: Pin ohne Inventar-/Jahr-/Mediumwechsel; Zellpreise aus gepinnter Liste;
  Listen-Rebind bei Jahr-/Inventar-/Mediumwechsel.
- Mix Average+Calendar in From-Calc weiter ganz abgewiesen.
- Rechte/Lifecycle unverändert.

## Fixture-Beträge (Abnahme)

| Strategie | Mediabrutto | N/N (gewählt) | Komponenten-`media_gross` |
|---|---|---|---|
| Shared (20+10 s, 10 Spots, 2,00 €/s, Index 100) | 600,00 | 500,00 | `''` / `''` |
| Individual (Indizes 105/110) | 640,00 | 520,00 | 420,00 / 220,00 |

## Nicht-Ziele

- Calendar × Tandem/Tridem *(damalige Scope-Grenze dieses Gates; Folgeslice
  `PO-BLP403K-1` / `BL-P4-03k` vorgeschlagen, nicht freigegeben)*
- Budget-Vorlagen, Abbinder / SPT-013
- Auto-Shift, Adopt-Preisjahr-Rebind
- Lifecycle-/Rechteänderungen
- Änderung der From-Calc-Mix-Ablehnung
- Willkürliche Beschränkung auf nur eine Komponentenstrategie

## Implementierungsstand

Auf `main`: **PR #127** (`e44c1cf…`), Post-Merge-CI `37527679296` SUCCESS.
**Kein** Deploy. Calendar×Tandem, Budget, Abbinder bleiben offen
(Tandem: Readiness `PO-BLP403K-1` vorgeschlagen).
