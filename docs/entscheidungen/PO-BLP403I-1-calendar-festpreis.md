# PO-BLP403I-1 – Calendar × Festpreis in Standardangeboten

Status: **Akzeptiert** (A1; B1+C1 übernommen aus PO-BLP403G-1)
Stand: 6. Oktober 2026
Basis: `origin/main` @ `1a4e72cd478a8edafa63c8c97722596169994b82` (nach PR #125)
IDs: `STD-001`–`STD-009`, `VER-004`, `SPT-005`–`SPT-008`, `SPT-014`, `COM-009`,
`PRI-002` / `PO-PRI-YEAR-1`, `AUTH-006`/`AUTH-007`,
Vorgänger `PO-BLP403A-1` … `PO-BLP403H-1`
Slice-Kennung: **`BL-P4-03i`**
Readiness: [`docs/readiness/BL-P4-03i-calendar-festpreis-2026-10-06.md`](../readiness/BL-P4-03i-calendar-festpreis-2026-10-06.md)

## Entscheidung / UX-GATE-D Teilfreigabe

Schmale Teilfreigabe **nur** für Spot-Classic-**Calendar**-Standardangebote mit
Settlement **`fixed_price`**, **Einzelspot** ohne Komponenten/Tandem/Budget.

| ID | Entscheidung |
|---|---|
| **A1** | Scope: Calendar × Spot Classic × Festpreis, nur Einzelspot; 03h Calendar×`normal`×Allonge bleibt; ohne Festpreis×Komponenten/Tandem/Budget |
| **B1** | Konkrete ISO-Termine unverändert übernehmen; **keine** automatische Verschiebung (aus PO-BLP403G-1) |
| **C1** | Adopt = Frozen-Parity (Preise, Pins, Planner-Zellen, `fixed_price_nn`, Summen); **keine** Live-Preisauflösung (aus PO-BLP403G-1) |

A2 (Festpreis×Allonge) und A3 (keine Erweiterung) sind **nicht** gewählt.

Ablauf unverändert zu 03b/03g:

1. Draft anlegen/bearbeiten (oder From-Calc reine Calendar-Festpreis-Einzelspot-Quelle).
2. PM prüft und veröffentlicht.
3. Vertrieb übernimmt mit Kunde als unabhängige Calc.

## Materialisierungsversion 4 (Vertragserweiterung)

Keine neue Version, **keine** Schema-Migration. Explizite **v4-Vertragserweiterung**:

- Calendar-Einzelspot (`components` leer) darf `pricing_settlement_mode` `normal`|`fixed_price`
  inkl. `fixed_price_nn` (02d/03e-Semantik).
- Der Reader aus PR #124 weist Calendar-Festpreis ab; erweiterte Snapshots brauchen
  diesen erweiterten Reader (Keys allein ≠ Kompatibilität mit altem Code).
- Bestehende v4 Calendar×`normal` (Einzelspot und 03h-Allonge), Average-v4 und Legacy v1–v3 bleiben lesbar.

## Vertrag / Verhalten

- Calc-Semantik 02d / Average-Vorlagen 03e wiederverwenden; **keine** neue Preisformel.
- `fixed_price_nn` = verbindlicher N/N; Mediabrutto referenzbasiert aus Calendar-Zellen;
  keine Forward-Rabatte; AE rückwärts.
- Adopt hydratisiert Frozen ohne Live-Rebind.
- Calc-Edit: Pin ohne Inventar-/Jahr-/Mediumwechsel; Zellpreise aus gepinnter Liste;
  Listen-Rebind bei Jahr-/Inventar-/Mediumwechsel.
- Mix Average+Calendar in From-Calc weiter ganz abgewiesen.
- Rechte/Lifecycle unverändert.

## Nicht-Ziele

- Calendar × Festpreis × Komponenten (A2)
- Calendar × Tandem/Tridem
- Budget-Vorlagen, Abbinder / SPT-013
- Auto-Shift, Adopt-Preisjahr-Rebind
- Lifecycle-/Rechteänderungen
- Änderung der From-Calc-Mix-Ablehnung

## Implementierungsstand

Auf Feature-Branch / Draft-PR umgesetzt (`BL-P4-03i`). **Kein** Merge/Deploy in diesem Auftrag.
Calendar×Festpreis×Komponenten/Tandem, Budget, Abbinder bleiben offen.
