# PO-BLP403K-1 – Calendar × Tandem/Tridem in Standardangeboten

Status: **Akzeptiert** (A1; B1+C1 übernommen aus PO-BLP403G-1)
Stand: 7. Oktober 2026
Basis: `origin/main` @ `e44c1cf436c6e1a63781910c7c1cd46ae019903a` (nach PR #127)
IDs: `STD-001`–`STD-009`, `VER-004`, `SPT-005`–`SPT-008`, `SPT-012`, `COM-009`,
`PRI-002` / `PO-PRI-YEAR-1`, `AUTH-006`/`AUTH-007`,
Vorgänger `PO-BLP403A-1` … `PO-BLP403J-1`
Slice-Kennung: **`BL-P4-03k`**
Readiness: [`docs/readiness/BL-P4-03k-calendar-tandem-tridem-2026-10-07.md`](../readiness/BL-P4-03k-calendar-tandem-tridem-2026-10-07.md)

## Entscheidung / UX-GATE-D Teilfreigabe

Schmale Teilfreigabe für Spot-Classic-**Calendar**-Standardangebote mit
**Tandem und Tridem** × Settlement **`normal` und `fixed_price`**.

| ID | Entscheidung |
|---|---|
| **A1** | Scope: Calendar × Spot Classic × Tandem und Tridem × `normal` und Festpreis; verbindlich `shared_total_length`; kanonische Slots; 03f Average- und 03g–03j-Calendar-Varianten bleiben; ohne Budget/Abbinder |
| **B1** | Konkrete ISO-Termine unverändert; **keine** automatische Verschiebung (aus PO-BLP403G-1) |
| **C1** | Adopt = Frozen-Parity (Preise, Pins, Planner-Zellen, Profil/Slots, `fixed_price_nn`, Summen); **keine** Live-Preisauflösung (aus PO-BLP403G-1) |

A2 (engerer Teilscope) und A3 (keine Erweiterung) sind **nicht** gewählt.

Ablauf unverändert zu 03b/03f/03g–03j:

1. Draft anlegen/bearbeiten (oder From-Calc reine Calendar-Profil-Quelle).
2. PM prüft und veröffentlicht.
3. Vertrieb übernimmt mit Kunde als unabhängige Calc.

## Materialisierungsversion 4 (Vertragserweiterung)

Keine neue Version, **keine** Schema-Migration. Explizite **v4-Vertragserweiterung**:

- Calendar darf optional `component_profile` `tandem`\|`tridem` inkl. Settlement
  `normal`\|`fixed_price` und `fixed_price_nn` (02e/03f-Semantik).
- Verbindlich `shared_total_length`; Slot-Asserts wie Average-v3+; Shared-Total
  Komponenten-`media_gross` bleibt `''`.
- Der Reader aus PR #127 weist Calendar×`component_profile` ab; erweiterte Snapshots
  brauchen diesen erweiterten Reader (Keys allein ≠ Kompatibilität mit altem Code).
- Bestehende v4 Calendar 03g–03j, Average-v4 und Legacy v1–v3 bleiben lesbar.

## Vertrag / Verhalten

- Calc-Semantik 02e / Vorlagenmuster 03f/03g–03j wiederverwenden; **keine** neue Preisformel.
- Mengen = Einheiten; Airings nur abgeleitet; keine Preis-×2/×3.
- `fixed_price_nn` = verbindlicher N/N auf Positionsebene.
- Adopt hydratisiert Frozen ohne Live-Rebind.
- Calc-Edit: Pin ohne Inventar-/Jahr-/Mediumwechsel; Zellpreise aus gepinnter Liste;
  Listen-Rebind bei Jahr-/Inventar-/Mediumwechsel.
- Mix Average+Calendar in From-Calc weiter ganz abgewiesen.
- Rechte/Lifecycle unverändert.

## Fixture-Beträge (Abnahme)

| Profil | Mediabrutto | N/N (Festpreis, gewählt) | Komponenten-`media_gross` | Airings (10 Einheiten) |
|---|---|---|---|---|
| Tandem (20+10 s, Index 100) | 600,00 | 888,50 | `''` / `''` | 20 |
| Tridem (20+10+10 s, Index 95) | 760,00 | 1200,00 | `''` / `''` / `''` | 30 |

## Nicht-Ziele

- Budget-Vorlagen, Abbinder / SPT-013
- Auto-Shift, Adopt-Preisjahr-Rebind
- Lifecycle-/Rechteänderungen
- Änderung der From-Calc-Mix-Ablehnung
- Aufweichung von `individual`-Verbot für Profile

## Implementierungsstand

Auf Feature-Branch / Draft-PR umgesetzt (`BL-P4-03k`). **Kein** Merge/Deploy in diesem Auftrag.
Budget und Abbinder bleiben offen.
