# Fortschritt V1

Stand: 4. September 2026 (DF-3.1 in Arbeit – kein Abschluss von DF-3)

## Aktuelle Phase

Phase 3 (Versionen, dynamische Felder und Snapshots) – **DF-1 und DF-2 auf
`main`**. **DF-3.1** (Admin-Versionierung geschützter Systemfelder und
Kern-Feldsets) auf Feature-Branch; Gesamtziel DF-3 bleibt offen.

Phase 8 (Dispoauftrag) bleibt mit Vier-Augen-Freigabe und Nummernableitung auf
`main`; UX-GATE-D ist weiterhin nicht vollständig abgeschlossen, enthält aber
Teilfreigaben für Dispo/Vier-Augen und Dynamische-Felder-Admin.

## Aktuelle Aufgabe

Feature-Branch `feat/df3-1-system-field-admin` – DF-3.1 Admin für Systemfeld-
Revisionen und Kern-Feldset Draft/Activate/Vorschau. **DF-3 ist damit nicht
abgeschlossen** (Custom Fields, Assignments, Optionen, Regelmatrix folgen).

## Zuletzt abgeschlossene Aufgabe

DF-2 Nachpflege – PR
[#17](https://github.com/MORE-m/dispo/pull/17) gemergt in `main`
(`ced673d`, Post-Merge-CI Run `33859778546` grün: `ci` + `mysql`).

Davor: DF-2 Dispo-Config-Snapshot – PR
[#16](https://github.com/MORE-m/dispo/pull/16) (`ac0d533`).

## DF-3.1 – Admin Systemfelder / Kern-Feldsets (September 2026)

| Kriterium | Status |
|---|---|
| UX-GATE-D Teilfreigabe „Administration dynamischer Felder“ | freigegeben |
| Revision geschützter Systemfelder (Label/Hilfe/Gruppe/Sort/reportable) | umgesetzt |
| Draft/Activate/Copy-as-template für zwei Kern-Feldsets | umgesetzt |
| Statische Vorschau mit Beispielwerten; Regeln nur lesbar | umgesetzt |
| Audit + `lock_version` + AT-14 (Historie unverändert) | umgesetzt |
| Custom Fields / Optionen / Assignments / Regel-Editor | **nicht** in DF-3.1 |

## DF-2 – Dispo-Config-Snapshot und Hinweise (September 2026)

| Kriterium | Status |
|---|---|
| Compose-Snapshot aus Calc-Snapshot + `system_dispo_order_core` | umgesetzt |
| `source_configuration_snapshot_id` (Herkunft, kein Laufzeit-Fallback) | umgesetzt |
| Felder `billing_special_features`, `disposition_notes` (Draft editierbar) | umgesetzt |
| Calc-origin `campaign_period` / `period_open` / `position_flight_period` read-only | umgesetzt |
| Revision: Texte aus Vorgänger (PO-DF2-1), Calc-Werte frisch | umgesetzt |
| Legacy-Backfill ohne erfundene Dyn-Werte | umgesetzt |
| Draft-Sync fehlender Calc-Dyn-Werte | umgesetzt |
| Admin-UI / Custom Fields | **nicht** in DF-2 |

### Verbindliche PO-Entscheidungen

- **PO-A1:** unverändert (Kalkulation).
- **PO-B2:** Hinweise nur im Dispo-Entwurf.
- **PO-DF2-1:** Nachfolge-Draft kopiert beide Texte per Schlüssel aus dem
  abgelehnten Vorgänger; neuer Snapshot; Vorgänger unverändert.
- **PO-DF3.1:** UX-GATE-D Dyn-Feld-Admin; Vorschau statisch mit Beispielwerten;
  Regeln in DF-3.1 nur lesbar.

## DF-1 – Dynamische Systemfelder Kalkulation (September 2026)

| Kriterium | Status |
|---|---|
| Systemfelder `campaign_period`, `period_open`, `position_flight_period` | umgesetzt |
| Feldset `system_calculation_core` v1 + aktive Version | umgesetzt |
| Revision über `current_revision_id` / Pin in Set-Version | umgesetzt |
| Unveränderlicher Config-Snapshot bei neuer Kalkulation | umgesetzt |
| Legacy-Backfill: Snapshot + `period_open = true` | umgesetzt |
| Dynamische Kopf-/Positionswerte (eigene Value-Tabellen) | umgesetzt |
| Wizard: Kampagnenzeitraum, Toggle „Zeitraum offen“, Flight-Period | umgesetzt |
| Snapshot-Regel `period_open=false → require position_flight_period` | umgesetzt |
| Admin-Feld-UI / Custom Fields / Dispo-Werte | **nicht** in DF-1 (DF-2+) |

## Bewusst offen nach DF-3.1

- Custom Fields anlegen/ändern/deaktivieren und Formularnutzung (DF-3.2+)
- Feldset-Assignments / Vererbung (braucht BL-P2-02)
- Optionen, Auswahltypen, volle Regelmatrix
- übrige UX-GATE-D-Adminmodule (Inventare, Kataloge, Preislisten, …)
- operative Disposition, Material, Kommentare, Status ab `In Bearbeitung`

## Echte Blocker

| ID | Thema |
|---|---|
| BLK-005 | UX-GATE-C |
| BLK-006 | UX-GATE-D (Rest; Dyn-Feld-Admin teilfreigegeben) |
| BLK-001 | Initialkataloge Kapitel 27 |
| BLK-002 | Speedit-Parameter vor Produktiv-Deploy |
