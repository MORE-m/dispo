# Cursor-Auftrag: PO-SPOT-SURCHARGE-1 – Erst- und Letztplatzierung

Status: **Phase 2 in Umsetzung** (Draft-PR; Average + Calendar auf `spot_classic`, Fachaufschlag +30 %)  
Stand: 18. Oktober 2026  
Grundlage: [`PO-GOLIVE-CUT-1-DEC`](../entscheidungen/PO-GOLIVE-CUT-1-DEC-operative-v1.md)  
IDs: `PO-SPOT-SURCHARGE-1`, `spot_first`, `spot_last`, `SPT-011`, `PO-MAT-CORE-CATALOG-1`

### Phase-1-Go (verbindlich)

- Aufschlag Erst/Letzt: **+30 %** für alle freigegebenen Inventory×Medium-Regeln
- Import-`0` ist kein operativer Fachwert → Migration/Importer setzen `30`; Runtime fail-closed bei `NULL`/`≤0`
- Average + Calendar gemeinsam über Kategorie-Inherit (`kind=spot_classic`)
- Single-Spot bleibt ausgeschlossen

## Ziel

`spot_first` (Werbespot erstplatziert) und `spot_last` (Werbespot letztplatziert) auf dem bestehenden Engine-Profil `spot_classic` operativ buchbar machen – mit korrektem Aufschlag, Average und Calendar, ohne bestehende Spotvarianten zu verändern.

**Nicht** in diesem Auftrag: Single-Spot, Promo/Moderation, neues Engine-Profil, Deploy.

## Phase 1 – Werte-/Konfigurations-Readiness (keine Produktcode-Änderung)

### Fachliche Fragestellungen

1. Gilt der dokumentierte Soll-Aufschlag **+30 %** (`SPT-011`) verbindlich für alle Inventare mit Matrixzeile Erst/Letzt, oder gibt es inventarspezifische Abweichungen?
2. Für welche der acht Spotlisten-Inventare (bzw. Matrix-Inventare) müssen Erst/Letzt aktivierbar sein?
3. Wie werden bestehende Kombinationszeilen mit Import-Aufschlag `0` behandelt? (`0` ist ausdrücklicher Null-Aufschlag, nicht „unbekannt“.)
4. Öffnen `kind=spot_classic` Average und Calendar gemeinsam (Kategorie-Inherit) – bestätigt?

### Betroffene Codebereiche (nur lesen in Phase 1)

- `app/Support/InventoryMediumRule/Catalog/InitialCatalogDefinitions.php`
- `app/Support/Advertising/AdvertisingMediumLiveBookability.php`
- `app/Support/Calculation/EngineProfileRegistry.php`
- Kombinationsregeln / Matrix-Import (`surcharge_percent`)
- Spot-Calc/Dispo-Snapshot-Pfade (Aufschlag bereits im Spot-Pfad)

### Entscheidungsoptionen

| Option | Inhalt |
|---|---|
| A | +30 % auf allen freigegebenen Erst/Letzt-Inventaren; Average+Calendar via Inherit |
| B | inventarspezifische Aufschlagliste liefert Fachseite vor Aktivierung |
| C | Auftrag verschieben |

**Empfehlung aus CUT-1-DEC:** A, sofern keine abweichende Lieferliste kommt; ohne bestätigte Werte **keine** Implementierung.

### Akzeptanzkriterien Phase 1 (Readiness-Abschluss)

- dokumentierte Aufschlagmatrix je betroffenem Inventar (Wert + Quelle)
- ausdrücklicher Umgang mit heutigen `0`-Importwerten
- Go/No-Go für Phase 2 schriftlich
- Single-Spot bleibt ausgeschlossen

## Phase 2 – Implementierungsslice (nur nach Phase-1-Go)

### Umfang

- `kind=spot_classic` für `spot_first` und `spot_last` (Bootstrap/Migration, rückwärtskompatibel, fail-closed)
- Aufschläge in den freigegebenen Kombinationen setzen (kein Belassen bei stillen Nullen, wenn Soll +30 %)
- Average und Calendar über bestehenden Spot-Pfad
- keine Komponentenprofile; Index unverändert
- bestehende Medien `spot_classic` / Tandem / Tridem unverändert

### Katalog-Nachzug (nicht PO-MAT-CORE-CATALOG-1 umschreiben)

Historisch setzte `PO-MAT-CORE-CATALOG-1` Erst/Letzt auf `kind=null`. Dieser Slice dokumentiert den **separaten Nachzug**:
`InitialCatalogDefinitions` + Migration `2026_10_18_180000_po_spot_surcharge_1_first_last_catalog`
setzen `kind=spot_classic` und `surcharge_percent=30` nur für `spot_first`/`spot_last`.

### Akzeptanzkriterien Phase 2

- korrekte Aufschläge in Preview, Save, Reload und Calc-/Dispo-Snapshot
- Average und Calendar jeweils abgedeckt
- Preview = Save = Reload
- Feature-, MySQL- und E2E-Abdeckung
- fail-closed bei fehlenden oder ungültigen Aufschlagwerten (`NULL`/fehlende Konfiguration; kein stilles `0` als Ersatz für „nicht geliefert“)
- UX-GATE-B bleibt gültig; kein Deploy in diesem Slice

### Explizit außerhalb

- `spot_single`, Showsponsoring, Jobspot
- Registry-`fixed_price`-Methode
- Standardangebote-Erweiterung auf Erst/Letzt (eigener späterer Schnitt falls nötig)
- Produktiv-Deploy

## Abhängigkeiten

- `PO-GOLIVE-CUT-1-DEC` (akzeptiert)
- Spot Classic Engine released (`average`, `calendar`)
- Matrix-/Kombinationsdaten für Erst/Letzt
- Keine Abhängigkeit zu Promo/Allonge-Profilen
